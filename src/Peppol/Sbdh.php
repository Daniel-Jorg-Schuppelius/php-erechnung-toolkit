<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Sbdh.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use RuntimeException;

/**
 * Peppol Business Message Envelope (SBDH) - Builder und Parser.
 *
 * Der Standard Business Document Header (UN/CEFACT SBDH 1.3) umschließt das
 * fachliche Dokument (UBL-Rechnung, -Gutschrift, -Bestellung) mit den
 * Transportangaben, die ein Access Point für die Zustellung braucht: Absender-
 * und Empfängerkennung, Dokumenttyp, Prozess, Instanz-Identifikation und
 * Erstellungszeitpunkt.
 *
 * Umgesetzt ist Peppol Business Message Envelope 2.0: die Scopes DOCUMENTID,
 * PROCESSID und COUNTRY_C1 (Land des Absenders, seit 2023 verpflichtend) samt
 * expliziter Schema-Angabe im Scope-`Identifier`.
 *
 * Beispiel:
 * ```php
 * $sbdh = Sbdh::forUbl($invoiceXml, ParticipantId::germanVatId('DE123456789'), ParticipantId::leitwegId('04011000-12345-67'), 'DE');
 * $envelope = $sbdh->envelope($invoiceXml);
 * ```
 *
 * @see https://docs.peppol.eu/edelivery/envelope/ Peppol Business Message Envelope
 */
final class Sbdh {
    use ErrorLog;

    /** Namespace des Standard Business Document Headers. */
    public const NS = 'http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader';

    /** Von Peppol vorgeschriebene Header-Version. */
    public const HEADER_VERSION = '1.0';

    /** Scope-Typ der Dokumenttypkennung. */
    public const SCOPE_DOCUMENT_ID = 'DOCUMENTID';

    /** Scope-Typ der Prozesskennung. */
    public const SCOPE_PROCESS_ID = 'PROCESSID';

    /** Scope-Typ des Absenderlands (Corner 1). */
    public const SCOPE_COUNTRY_C1 = 'COUNTRY_C1';

    /** Standardschema für Prozesskennungen. */
    public const PROCESS_SCHEME = 'cenbii-procid-ubl';

    /** Prozesskennung von Peppol BIS Billing 3.0. */
    public const PROCESS_BILLING = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    public function __construct(
        private readonly ParticipantId $sender,
        private readonly ParticipantId $receiver,
        private readonly DocumentTypeId $documentTypeId,
        private readonly string $processId,
        private readonly string $standard,
        private readonly string $type,
        private readonly string $typeVersion,
        private readonly string $instanceIdentifier,
        private readonly DateTimeImmutable $creationDateAndTime,
        private readonly ?string $senderCountry = null,
        private readonly string $processScheme = self::PROCESS_SCHEME,
    ) {
        if ($this->processId === '' || $this->standard === '' || $this->type === ''
            || $this->typeVersion === '' || $this->instanceIdentifier === '') {
            throw new InvalidArgumentException('SBDH-Kopfdaten sind unvollständig.');
        }

        if ($this->senderCountry !== null && preg_match('/^[A-Z]{2}$/', $this->senderCountry) !== 1) {
            throw new InvalidArgumentException("COUNTRY_C1 erwartet einen ISO-3166-1-alpha-2-Code, erhalten: \"{$this->senderCountry}\".");
        }
    }

    /**
     * Baut den Header zu einem UBL-Dokument.
     *
     * Dokumenttyp, Standard (Root-Namespace), Wurzelelement und Syntaxversion
     * werden aus dem Dokument abgeleitet; die Prozesskennung stammt - sofern
     * nicht übergeben - aus `cbc:ProfileID`.
     *
     * @param string      $ublXml             Das fachliche UBL-Dokument.
     * @param string|null $senderCountry      Land des Absenders (COUNTRY_C1), z.B. "DE".
     * @param string|null $processId          Prozesskennung; Vorgabe: cbc:ProfileID des Dokuments.
     * @param string|null $instanceIdentifier Eindeutige Kennung der Übertragung; Vorgabe: UUID v4.
     */
    public static function forUbl(
        string $ublXml,
        ParticipantId $sender,
        ParticipantId $receiver,
        ?string $senderCountry = null,
        ?string $processId = null,
        ?string $instanceIdentifier = null,
        ?DateTimeImmutable $creationDateAndTime = null,
    ): self {
        $documentTypeId = DocumentTypeId::fromUbl($ublXml);

        return new self(
            $sender,
            $receiver,
            $documentTypeId,
            $processId ?? self::profileIdOf($ublXml) ?? self::PROCESS_BILLING,
            $documentTypeId->getRootNamespace(),
            $documentTypeId->getLocalName(),
            $documentTypeId->getVersion(),
            $instanceIdentifier ?? self::uuidV4(),
            $creationDateAndTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $senderCountry
        );
    }

    /**
     * Erzeugt den Umschlag `StandardBusinessDocument` um ein fachliches Dokument.
     *
     * @param string $payloadXml Das fachliche Dokument (UBL-XML).
     */
    public function envelope(string $payloadXml): string {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->preserveWhiteSpace = false;

        $root = $dom->createElementNS(self::NS, 'StandardBusinessDocument');
        $dom->appendChild($root);
        $root->appendChild($this->buildHeader($dom));

        $payload = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
            if (!$payload->loadXML($payloadXml, LIBXML_NONET) || $payload->documentElement === null) {
                $this->logErrorAndThrow(InvalidArgumentException::class, 'Nutzlast konnte nicht als XML geladen werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        /** @var DOMElement $payloadRoot */
        $payloadRoot = $payload->documentElement;
        $imported = $dom->importNode($payloadRoot, true);
        $root->appendChild($imported);

        $xml = $dom->saveXML();
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, 'SBDH-Umschlag konnte nicht serialisiert werden.');
        }

        return (string) $xml;
    }

    /**
     * Serialisiert nur den Header (ohne Nutzlast).
     */
    public function toXml(): string {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->appendChild($this->buildHeader($dom));

        $xml = $dom->saveXML();
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, 'SBDH konnte nicht serialisiert werden.');
        }

        return (string) $xml;
    }

    /**
     * Liest einen Header aus einem `StandardBusinessDocument` oder einem
     * alleinstehenden `StandardBusinessDocumentHeader`.
     *
     * @throws InvalidArgumentException wenn das XML keinen lesbaren SBDH enthält.
     */
    public static function parse(string $xml): self {
        $header = self::headerElement(self::loadDocument($xml));

        $sender = self::identifier($header, 'Sender');
        $receiver = self::identifier($header, 'Receiver');
        $identification = self::child($header, 'DocumentIdentification');

        if ($sender === null || $receiver === null || $identification === null) {
            throw new InvalidArgumentException('SBDH ohne Sender, Receiver oder DocumentIdentification.');
        }

        $scopes = self::scopes($header);
        $documentIdValue = $scopes[self::SCOPE_DOCUMENT_ID]['value'] ?? null;
        $processIdValue = $scopes[self::SCOPE_PROCESS_ID]['value'] ?? null;

        if ($documentIdValue === null || $processIdValue === null) {
            throw new InvalidArgumentException('SBDH ohne DOCUMENTID- oder PROCESSID-Scope.');
        }

        $creation = self::text($identification, 'CreationDateAndTime');
        $creationDate = $creation !== null ? DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $creation) : false;
        if ($creationDate === false) {
            $creationDate = $creation !== null ? new DateTimeImmutable($creation) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        return new self(
            $sender,
            $receiver,
            new DocumentTypeId(
                $documentIdValue,
                $scopes[self::SCOPE_DOCUMENT_ID]['scheme'] ?? DocumentTypeId::DEFAULT_SCHEME
            ),
            $processIdValue,
            self::text($identification, 'Standard') ?? '',
            self::text($identification, 'Type') ?? '',
            self::text($identification, 'TypeVersion') ?? '',
            self::text($identification, 'InstanceIdentifier') ?? '',
            $creationDate,
            $scopes[self::SCOPE_COUNTRY_C1]['value'] ?? null,
            $scopes[self::SCOPE_PROCESS_ID]['scheme'] ?? self::PROCESS_SCHEME
        );
    }

    /**
     * Löst die Nutzlast aus einem `StandardBusinessDocument`.
     *
     * @throws InvalidArgumentException wenn der Umschlag kein fachliches Dokument enthält.
     */
    public static function payloadOf(string $xml): string {
        $dom = self::loadDocument($xml);
        $root = $dom->documentElement;

        if (!$root instanceof DOMElement || $root->localName !== 'StandardBusinessDocument') {
            throw new InvalidArgumentException('Kein StandardBusinessDocument.');
        }

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName !== 'StandardBusinessDocumentHeader') {
                $payload = new DOMDocument('1.0', 'UTF-8');
                $payload->appendChild($payload->importNode($child, true));
                $result = $payload->saveXML();
                if ($result === false) {
                    throw new InvalidArgumentException('Nutzlast konnte nicht serialisiert werden.');
                }

                return $result;
            }
        }

        throw new InvalidArgumentException('StandardBusinessDocument ohne fachliche Nutzlast.');
    }

    public function getSender(): ParticipantId {
        return $this->sender;
    }

    public function getReceiver(): ParticipantId {
        return $this->receiver;
    }

    public function getDocumentTypeId(): DocumentTypeId {
        return $this->documentTypeId;
    }

    public function getProcessId(): string {
        return $this->processId;
    }

    public function getProcessScheme(): string {
        return $this->processScheme;
    }

    /** Root-Namespace des fachlichen Dokuments. */
    public function getStandard(): string {
        return $this->standard;
    }

    /** Wurzelelement des fachlichen Dokuments, z.B. "Invoice". */
    public function getType(): string {
        return $this->type;
    }

    /** Syntaxversion des fachlichen Dokuments, z.B. "2.1". */
    public function getTypeVersion(): string {
        return $this->typeVersion;
    }

    /** Eindeutige Kennung dieser Übertragung. */
    public function getInstanceIdentifier(): string {
        return $this->instanceIdentifier;
    }

    public function getCreationDateAndTime(): DateTimeImmutable {
        return $this->creationDateAndTime;
    }

    /** Land des Absenders (COUNTRY_C1) oder null. */
    public function getSenderCountry(): ?string {
        return $this->senderCountry;
    }

    /**
     * Baut den `StandardBusinessDocumentHeader` in das übergebene Dokument.
     */
    private function buildHeader(DOMDocument $dom): DOMElement {
        $header = $dom->createElementNS(self::NS, 'StandardBusinessDocumentHeader');
        $this->element($dom, $header, 'HeaderVersion', self::HEADER_VERSION);

        foreach (['Sender' => $this->sender, 'Receiver' => $this->receiver] as $name => $participant) {
            $party = $dom->createElementNS(self::NS, $name);
            $identifier = $this->element($dom, $party, 'Identifier', $participant->getValue());
            $identifier->setAttribute('Authority', $participant->getScheme());
            $header->appendChild($party);
        }

        $identification = $dom->createElementNS(self::NS, 'DocumentIdentification');
        $this->element($dom, $identification, 'Standard', $this->standard);
        $this->element($dom, $identification, 'TypeVersion', $this->typeVersion);
        $this->element($dom, $identification, 'InstanceIdentifier', $this->instanceIdentifier);
        $this->element($dom, $identification, 'Type', $this->type);
        $this->element($dom, $identification, 'CreationDateAndTime', $this->creationDateAndTime->format(DateTimeInterface::ATOM));
        $header->appendChild($identification);

        $businessScope = $dom->createElementNS(self::NS, 'BusinessScope');
        $this->scope($dom, $businessScope, self::SCOPE_DOCUMENT_ID, $this->documentTypeId->getValue(), $this->documentTypeId->getScheme());
        $this->scope($dom, $businessScope, self::SCOPE_PROCESS_ID, $this->processId, $this->processScheme);
        if ($this->senderCountry !== null) {
            $this->scope($dom, $businessScope, self::SCOPE_COUNTRY_C1, $this->senderCountry, null);
        }
        $header->appendChild($businessScope);

        return $header;
    }

    private function scope(DOMDocument $dom, DOMElement $parent, string $type, string $value, ?string $scheme): void {
        $scope = $dom->createElementNS(self::NS, 'Scope');
        $this->element($dom, $scope, 'Type', $type);
        $this->element($dom, $scope, 'InstanceIdentifier', $value);
        if ($scheme !== null) {
            $this->element($dom, $scope, 'Identifier', $scheme);
        }
        $parent->appendChild($scope);
    }

    private function element(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        $element = $dom->createElementNS(self::NS, $name);
        $element->appendChild($dom->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private static function loadDocument(string $xml): DOMDocument {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
            if (!$dom->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('SBDH-XML konnte nicht geladen werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private static function headerElement(DOMDocument $dom): DOMElement {
        $root = $dom->documentElement;
        if (!$root instanceof DOMElement) {
            throw new InvalidArgumentException('SBDH-XML ohne Wurzelelement.');
        }

        if ($root->localName === 'StandardBusinessDocumentHeader') {
            return $root;
        }

        $header = self::child($root, 'StandardBusinessDocumentHeader');
        if ($header === null) {
            throw new InvalidArgumentException('Kein StandardBusinessDocumentHeader gefunden.');
        }

        return $header;
    }

    private static function child(DOMElement $parent, string $localName): ?DOMElement {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    private static function text(DOMElement $parent, string $localName): ?string {
        $child = self::child($parent, $localName);
        if ($child === null) {
            return null;
        }

        $value = trim($child->textContent);

        return $value === '' ? null : $value;
    }

    private static function identifier(DOMElement $header, string $partyName): ?ParticipantId {
        $party = self::child($header, $partyName);
        if ($party === null) {
            return null;
        }

        $identifier = self::child($party, 'Identifier');
        if ($identifier === null) {
            return null;
        }

        $authority = $identifier->getAttribute('Authority');
        $value = trim($identifier->textContent);

        return new ParticipantId($value, $authority !== '' ? $authority : ParticipantId::DEFAULT_SCHEME);
    }

    /**
     * @return array<string, array{value: string, scheme: string|null}>
     */
    private static function scopes(DOMElement $header): array {
        $businessScope = self::child($header, 'BusinessScope');
        if ($businessScope === null) {
            return [];
        }

        $scopes = [];
        foreach ($businessScope->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'Scope') {
                continue;
            }

            $type = self::text($child, 'Type');
            $value = self::text($child, 'InstanceIdentifier');
            if ($type === null || $value === null) {
                continue;
            }

            $scopes[$type] = ['value' => $value, 'scheme' => self::text($child, 'Identifier')];
        }

        return $scopes;
    }

    /**
     * Liest `cbc:ProfileID` aus einem UBL-Dokument.
     */
    private static function profileIdOf(string $ublXml): ?string {
        try {
            $root = self::loadDocument($ublXml)->documentElement;
        } catch (InvalidArgumentException) {
            return null;
        }

        return $root instanceof DOMElement ? self::text($root, 'ProfileID') : null;
    }

    /**
     * Erzeugt eine UUID v4 als Instanz-Identifikator.
     */
    private static function uuidV4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
