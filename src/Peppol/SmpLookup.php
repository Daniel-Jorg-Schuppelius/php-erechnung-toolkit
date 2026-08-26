<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpLookup.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Contracts\{DnsNaptrResolverInterface, SmpHttpClientInterface};
use ERechnungToolkit\Enums\SmlZone;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Auflösung von Peppol-Teilnehmern über SML (DNS) und SMP (HTTP).
 *
 * Zwei Schritte:
 * 1. **SML** - aus der Teilnehmerkennung wird ein DNS-Name gebildet. Das
 *    NAPTR-Verfahren (BDXL) hasht den kleingeschriebenen Wert mit SHA-256 und
 *    kodiert ihn Base32 ohne Füllzeichen; das ältere CNAME-Verfahren nutzt
 *    `B-` + MD5-Hex. Beide sind während der Peppol-Migration im Einsatz.
 * 2. **SMP** - unter der so gefundenen Adresse liefert der SMP die
 *    registrierten Dokumenttypen (`/{participant}`) und je Dokumenttyp die
 *    Endpunkte mit Transportprofil, Zustelladresse und AP-Zertifikat
 *    (`/{participant}/services/{documentType}`).
 *
 * Der Abruf läuft über die injizierte {@see SmpHttpClientInterface}; das Toolkit
 * bringt keinen HTTP-Stack mit und öffnet in Tests keine Verbindungen.
 *
 * Beispiel:
 * ```php
 * $lookup = new SmpLookup(new Psr18SmpClient($guzzle, $requestFactory));
 * $group  = $lookup->fetchServiceGroup($participant, 'https://smp.example.org');
 * if ($group->supports(DocumentTypeId::peppolBisBillingInvoice())) {
 *     $endpoint = $lookup->resolveEndpoint($participant, DocumentTypeId::peppolBisBillingInvoice(), 'https://smp.example.org');
 * }
 * ```
 *
 * Nicht umgesetzt: Prüfung der XML-Signatur der SMP-Antwort und
 * `Redirect`-Einträge - beides bleibt dem Access-Point-Provider überlassen.
 */
final class SmpLookup {
    use ErrorLog;

    /** Präfix des DNS-Namens im CNAME-Verfahren. */
    public const CNAME_PREFIX = 'B-';

    /** Base32-Alphabet nach RFC 4648. */
    private const BASE32_ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public function __construct(
        private readonly SmpHttpClientInterface $httpClient,
        private readonly ?DnsNaptrResolverInterface $dnsResolver = null,
    ) {}

    /**
     * DNS-Name des Teilnehmers nach dem NAPTR-Verfahren (BDXL).
     *
     * `strip-padding(base32(sha256(lowercase(wert)))) . "." . schema . "." . zone`
     */
    public static function dnsName(ParticipantId $participantId, SmlZone|string $zone = SmlZone::PRODUCTION): string {
        return self::base32(hash('sha256', strtolower($participantId->getValue()), true))
            . '.' . $participantId->getScheme()
            . '.' . self::zoneName($zone);
    }

    /**
     * DNS-Name des Teilnehmers nach dem älteren CNAME-Verfahren.
     *
     * `"B-" . md5(lowercase(wert)) . "." . schema . "." . zone`
     */
    public static function legacyDnsName(ParticipantId $participantId, SmlZone|string $zone = SmlZone::LEGACY_PRODUCTION): string {
        return self::CNAME_PREFIX . md5(strtolower($participantId->getValue()))
            . '.' . $participantId->getScheme()
            . '.' . self::zoneName($zone);
    }

    /**
     * Ermittelt die SMP-Basis-URL des Teilnehmers.
     *
     * NAPTR-Zonen brauchen einen {@see DnsNaptrResolverInterface}; in den
     * CNAME-Zonen ist der DNS-Name selbst der SMP-Host.
     *
     * @throws RuntimeException wenn für eine NAPTR-Zone kein Resolver gesetzt ist.
     */
    public function resolveSmpBaseUrl(ParticipantId $participantId, SmlZone|string $zone = SmlZone::PRODUCTION): ?string {
        $usesNaptr = $zone instanceof SmlZone ? $zone->usesNaptr() : true;

        if (!$usesNaptr) {
            return 'http://' . self::legacyDnsName($participantId, $zone);
        }

        if ($this->dnsResolver === null) {
            $this->logErrorAndThrow(
                RuntimeException::class,
                'Für NAPTR-Zonen wird ein DnsNaptrResolverInterface benötigt.'
            );
        }

        /** @var DnsNaptrResolverInterface $resolver */
        $resolver = $this->dnsResolver;
        $urls = $resolver->resolveSmpUrls(self::dnsName($participantId, $zone));

        return $urls === [] ? null : rtrim($urls[0], '/');
    }

    /** URL der Service Group eines Teilnehmers. */
    public static function serviceGroupUrl(ParticipantId $participantId, string $smpBaseUrl): string {
        return rtrim($smpBaseUrl, '/') . '/' . $participantId->urlEncoded();
    }

    /** URL der Service-Metadaten zu einem Dokumenttyp. */
    public static function serviceMetadataUrl(ParticipantId $participantId, DocumentTypeId $documentTypeId, string $smpBaseUrl): string {
        return self::serviceGroupUrl($participantId, $smpBaseUrl) . '/services/' . $documentTypeId->urlEncoded();
    }

    /**
     * Liest die im SMP registrierten Dokumenttypen des Teilnehmers.
     *
     * @throws RuntimeException wenn der SMP nicht antwortet oder die Antwort unlesbar ist.
     */
    public function fetchServiceGroup(ParticipantId $participantId, string $smpBaseUrl): SmpServiceGroup {
        $url = self::serviceGroupUrl($participantId, $smpBaseUrl);
        $response = $this->httpClient->get($url);

        if (!$response->isSuccessful()) {
            $this->logErrorAndThrow(
                RuntimeException::class,
                sprintf('SMP-Abruf fehlgeschlagen (HTTP %d): %s', $response->getStatusCode(), $url)
            );
        }

        return $this->parseServiceGroup($response->getBody());
    }

    /**
     * Prüft, ob der Teilnehmer im SMP registriert ist (HTTP 404 = nein).
     */
    public function isRegistered(ParticipantId $participantId, string $smpBaseUrl): bool {
        return $this->httpClient->get(self::serviceGroupUrl($participantId, $smpBaseUrl))->isSuccessful();
    }

    /**
     * Liest die Endpunkte zu einem Dokumenttyp.
     *
     * @throws RuntimeException wenn der SMP nicht antwortet oder die Antwort unlesbar ist.
     */
    public function fetchServiceMetadata(ParticipantId $participantId, DocumentTypeId $documentTypeId, string $smpBaseUrl): SmpServiceMetadata {
        $url = self::serviceMetadataUrl($participantId, $documentTypeId, $smpBaseUrl);
        $response = $this->httpClient->get($url);

        if (!$response->isSuccessful()) {
            $this->logErrorAndThrow(
                RuntimeException::class,
                sprintf('SMP-Abruf fehlgeschlagen (HTTP %d): %s', $response->getStatusCode(), $url)
            );
        }

        return $this->parseServiceMetadata($response->getBody());
    }

    /**
     * Sucht den zustellfähigen Endpunkt für Dokumenttyp und Prozess.
     */
    public function resolveEndpoint(
        ParticipantId $participantId,
        DocumentTypeId $documentTypeId,
        string $smpBaseUrl,
        ?string $processId = null,
        string $transportProfile = SmpEndpoint::TRANSPORT_AS4,
        ?DateTimeImmutable $moment = null,
    ): ?SmpEndpoint {
        return $this->fetchServiceMetadata($participantId, $documentTypeId, $smpBaseUrl)
            ->findEndpoint($processId, $transportProfile, $moment);
    }

    /**
     * Parst eine `ServiceGroup`-Antwort (busdox- oder OASIS-BDXR-Namespace).
     */
    public function parseServiceGroup(string $xml): SmpServiceGroup {
        $root = $this->rootElement($xml, 'ServiceGroup');

        $participantId = $this->participantOf($root);
        if ($participantId === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'ServiceGroup ohne ParticipantIdentifier.');
        }

        $references = [];
        $documentTypeIds = [];
        foreach ($this->descendants($root, 'ServiceMetadataReference') as $reference) {
            $href = $reference->getAttribute('href');
            if ($href === '') {
                continue;
            }

            $references[] = $href;
            $documentTypeId = self::documentTypeIdFromHref($href);
            if ($documentTypeId !== null) {
                $documentTypeIds[] = $documentTypeId;
            }
        }

        /** @var ParticipantId $participantId */
        return new SmpServiceGroup($participantId, $documentTypeIds, $references, $xml);
    }

    /**
     * Parst eine `SignedServiceMetadata`- bzw. `ServiceMetadata`-Antwort.
     */
    public function parseServiceMetadata(string $xml): SmpServiceMetadata {
        $document = $this->loadDocument($xml);
        $root = $document->documentElement;

        if (!$root instanceof DOMElement) {
            $this->logErrorAndThrow(RuntimeException::class, 'SMP-Antwort ohne Wurzelelement.');
        }

        /** @var DOMElement $root */
        $signed = $root->localName === 'SignedServiceMetadata';

        $information = $this->descendants($root, 'ServiceInformation')[0] ?? null;
        if ($information === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'SMP-Antwort ohne ServiceInformation.');
        }

        /** @var DOMElement $information */
        $participantId = $this->participantOf($information);
        $documentIdentifier = $this->descendants($information, 'DocumentIdentifier')[0] ?? null;

        if ($participantId === null || $documentIdentifier === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'ServiceInformation ohne Teilnehmer- oder Dokumenttypkennung.');
        }

        /** @var DOMElement $documentIdentifier */
        $scheme = $documentIdentifier->getAttribute('scheme');
        $documentTypeId = new DocumentTypeId(
            trim($documentIdentifier->textContent),
            $scheme !== '' ? $scheme : DocumentTypeId::DEFAULT_SCHEME
        );

        $endpoints = [];
        foreach ($this->descendants($information, 'Process') as $process) {
            $processIdentifier = $this->descendants($process, 'ProcessIdentifier')[0] ?? null;
            $processId = $processIdentifier !== null ? trim($processIdentifier->textContent) : '';
            $processScheme = $processIdentifier !== null ? $processIdentifier->getAttribute('scheme') : '';

            foreach ($this->descendants($process, 'Endpoint') as $endpoint) {
                $endpoints[] = new SmpEndpoint(
                    $processId,
                    $processScheme !== '' ? $processScheme : Sbdh::PROCESS_SCHEME,
                    $endpoint->getAttribute('transportProfile'),
                    $this->endpointAddress($endpoint),
                    $this->childText($endpoint, 'Certificate'),
                    $this->childDate($endpoint, 'ServiceActivationDate'),
                    $this->childDate($endpoint, 'ServiceExpirationDate'),
                    $this->childText($endpoint, 'ServiceDescription'),
                    $this->childText($endpoint, 'TechnicalContactUrl'),
                    strtolower($this->childText($endpoint, 'RequireBusinessLevelSignature') ?? 'false') === 'true'
                );
            }
        }

        /** @var ParticipantId $participantId */
        return new SmpServiceMetadata($participantId, $documentTypeId, $endpoints, $signed, $xml);
    }

    /**
     * Liest die Dokumenttypkennung aus einer ServiceMetadataReference-URL.
     */
    public static function documentTypeIdFromHref(string $href): ?DocumentTypeId {
        $position = strrpos($href, '/services/');
        if ($position === false) {
            return null;
        }

        $encoded = substr($href, $position + strlen('/services/'));

        try {
            return DocumentTypeId::parse(rawurldecode($encoded));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Base32 nach RFC 4648, kleingeschrieben und ohne Füllzeichen.
     */
    private static function base32(string $binary): string {
        $bits = '';
        foreach (str_split($binary) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            // Fünf Bit ergeben immer einen Wert zwischen 0 und 31, also einen int.
            $index = (int) bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT));
            $encoded .= self::BASE32_ALPHABET[$index];
        }

        return $encoded;
    }

    private static function zoneName(SmlZone|string $zone): string {
        return trim($zone instanceof SmlZone ? $zone->dnsZone() : $zone, " \t.");
    }

    private function loadDocument(string $xml): DOMDocument {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                $this->logErrorAndThrow(RuntimeException::class, 'SMP-Antwort konnte nicht als XML geladen werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function rootElement(string $xml, string $expectedLocalName): DOMElement {
        $root = $this->loadDocument($xml)->documentElement;

        if (!$root instanceof DOMElement || $root->localName !== $expectedLocalName) {
            $this->logErrorAndThrow(
                RuntimeException::class,
                sprintf('SMP-Antwort ist kein %s-Dokument.', $expectedLocalName)
            );
        }

        /** @var DOMElement $root */
        return $root;
    }

    private function participantOf(DOMElement $context): ?ParticipantId {
        $element = $this->descendants($context, 'ParticipantIdentifier')[0] ?? null;
        if ($element === null) {
            return null;
        }

        $scheme = $element->getAttribute('scheme');

        try {
            return new ParticipantId(
                trim($element->textContent),
                $scheme !== '' ? $scheme : ParticipantId::DEFAULT_SCHEME
            );
        } catch (InvalidArgumentException $exception) {
            $this->logWarning('SMP-Antwort mit ungültiger Teilnehmerkennung: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Zustelladresse eines Endpunkts: `wsa:EndpointReference/wsa:Address`
     * (Peppol-SMP) oder `EndpointURI` (OASIS BDXR SMP).
     */
    private function endpointAddress(DOMElement $endpoint): string {
        foreach (['Address', 'EndpointURI', 'EndpointReference'] as $localName) {
            $element = $this->descendants($endpoint, $localName)[0] ?? null;
            if ($element !== null) {
                $value = trim($element->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return list<DOMElement>
     */
    private function descendants(DOMElement $context, string $localName): array {
        $elements = [];
        foreach ($context->getElementsByTagNameNS('*', $localName) as $element) {
            $elements[] = $element;
        }

        return $elements;
    }

    private function childText(DOMElement $context, string $localName): ?string {
        $element = $this->descendants($context, $localName)[0] ?? null;
        if ($element === null) {
            return null;
        }

        $value = trim($element->textContent);

        return $value === '' ? null : $value;
    }

    private function childDate(DOMElement $context, string $localName): ?DateTimeImmutable {
        $value = $this->childText($context, $localName);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            $this->logWarning("Unlesbares Datum im SMP-Endpunkt ($localName): $value");

            return null;
        }
    }
}
