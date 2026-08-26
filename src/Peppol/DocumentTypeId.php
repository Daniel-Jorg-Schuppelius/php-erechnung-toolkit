<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DocumentTypeId.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DOMDocument;
use DOMElement;
use ERechnungToolkit\Enums\ERechnungProfile;
use InvalidArgumentException;
use Stringable;

/**
 * Peppol-Dokumenttypkennung (Document Type Identifier).
 *
 * Der Wert setzt sich aus Root-Namespace, Wurzelelement, Customization-ID und
 * Syntaxversion zusammen:
 * `<root-namespace>::<root-element>##<customization-id>::<version>`, z.B.
 * `urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1`.
 *
 * Das zugehörige Schema ist `busdox-docid-qns`; für Wildcard-Registrierungen
 * (Peppol-Wildcard-Konzept, z.B. Peppol-PINT) `peppol-doctype-wildcard`.
 */
final class DocumentTypeId implements Stringable {
    /** Standardschema für Dokumenttypkennungen. */
    public const DEFAULT_SCHEME = 'busdox-docid-qns';

    /** Schema für Wildcard-Registrierungen. */
    public const WILDCARD_SCHEME = 'peppol-doctype-wildcard';

    /** Trenner zwischen Schema und Wert in der kanonischen Form. */
    public const SEPARATOR = '::';

    /** UBL-2.1-Namespace der Rechnung. */
    public const UBL_INVOICE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    /** UBL-2.1-Namespace der Gutschrift. */
    public const UBL_CREDIT_NOTE_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    /** Customization-ID von Peppol BIS Billing 3.0. */
    public const BIS_BILLING_3 = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

    /** Voreingestellte UBL-Syntaxversion. */
    public const DEFAULT_VERSION = '2.1';

    private readonly string $scheme;

    private readonly string $rootNamespace;

    private readonly string $localName;

    private readonly string $customizationId;

    private readonly string $version;

    /**
     * @throws InvalidArgumentException wenn der Wert nicht dem Peppol-Aufbau entspricht.
     */
    public function __construct(string $value, string $scheme = self::DEFAULT_SCHEME) {
        $value = trim($value);
        $scheme = trim($scheme);

        if ($scheme === '') {
            throw new InvalidArgumentException('Schema der Dokumenttypkennung darf nicht leer sein.');
        }

        $nsSeparator = strpos($value, self::SEPARATOR);
        $hashSeparator = strpos($value, '##');
        $versionSeparator = strrpos($value, self::SEPARATOR);

        if ($nsSeparator === false || $hashSeparator === false || $versionSeparator === false
            || $hashSeparator < $nsSeparator || $versionSeparator <= $hashSeparator) {
            throw new InvalidArgumentException(
                "Dokumenttypkennung muss die Form \"<namespace>::<element>##<customization>::<version>\" haben, erhalten: \"$value\"."
            );
        }

        $this->scheme = $scheme;
        $this->rootNamespace = substr($value, 0, $nsSeparator);
        $this->localName = substr($value, $nsSeparator + 2, $hashSeparator - $nsSeparator - 2);
        $this->customizationId = substr($value, $hashSeparator + 2, $versionSeparator - $hashSeparator - 2);
        $this->version = substr($value, $versionSeparator + 2);

        if ($this->rootNamespace === '' || $this->localName === '' || $this->customizationId === '' || $this->version === '') {
            throw new InvalidArgumentException("Dokumenttypkennung ist unvollständig: \"$value\".");
        }
    }

    /**
     * Zerlegt die kanonische Schreibweise `<schema>::<wert>`.
     *
     * Ohne Schema-Präfix gilt {@see DEFAULT_SCHEME}. Da der Wert selbst `::`
     * enthält, wird nur ein bekanntes Schema als Präfix erkannt.
     */
    public static function parse(string $canonical): self {
        $canonical = trim($canonical);

        foreach ([self::DEFAULT_SCHEME, self::WILDCARD_SCHEME] as $scheme) {
            $prefix = $scheme . self::SEPARATOR;
            if (str_starts_with($canonical, $prefix)) {
                return new self(substr($canonical, strlen($prefix)), $scheme);
            }
        }

        return new self($canonical);
    }

    /**
     * Baut die Kennung aus ihren Bestandteilen.
     */
    public static function forCustomization(
        string $customizationId,
        string $rootNamespace = self::UBL_INVOICE_NS,
        string $localName = 'Invoice',
        string $version = self::DEFAULT_VERSION,
        string $scheme = self::DEFAULT_SCHEME,
    ): self {
        return new self(
            $rootNamespace . self::SEPARATOR . $localName . '##' . $customizationId . self::SEPARATOR . $version,
            $scheme
        );
    }

    /**
     * Leitet die Kennung aus einem UBL-Dokument ab.
     *
     * Root-Namespace und Wurzelelement stammen aus dem Dokument, die
     * Customization-ID aus `cbc:CustomizationID`, die Version aus
     * `cbc:UBLVersionID` (Vorgabe 2.1).
     *
     * @throws InvalidArgumentException wenn das XML nicht lesbar ist oder die Customization-ID fehlt.
     */
    public static function fromUbl(string $xml, string $scheme = self::DEFAULT_SCHEME): self {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
            if (!$dom->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('UBL-Dokument konnte nicht geladen werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $dom->documentElement;
        if (!$root instanceof DOMElement || $root->namespaceURI === null || $root->localName === null) {
            throw new InvalidArgumentException('UBL-Dokument ohne Wurzelelement im Dokument-Namespace.');
        }

        $customizationId = self::firstValue($root, 'CustomizationID');
        if ($customizationId === null) {
            throw new InvalidArgumentException('UBL-Dokument ohne cbc:CustomizationID.');
        }

        return self::forCustomization(
            $customizationId,
            $root->namespaceURI,
            $root->localName,
            self::firstValue($root, 'UBLVersionID') ?? self::DEFAULT_VERSION,
            $scheme
        );
    }

    /** Peppol BIS Billing 3.0 - Rechnung. */
    public static function peppolBisBillingInvoice(): self {
        return self::forCustomization(self::BIS_BILLING_3);
    }

    /** Peppol BIS Billing 3.0 - Gutschrift. */
    public static function peppolBisBillingCreditNote(): self {
        return self::forCustomization(self::BIS_BILLING_3, self::UBL_CREDIT_NOTE_NS, 'CreditNote');
    }

    /** XRechnung 3.0 (CIUS) als UBL-Rechnung. */
    public static function xrechnungInvoice(): self {
        return self::forCustomization(ERechnungProfile::XRECHNUNG->value);
    }

    public function getScheme(): string {
        return $this->scheme;
    }

    public function getRootNamespace(): string {
        return $this->rootNamespace;
    }

    public function getLocalName(): string {
        return $this->localName;
    }

    public function getCustomizationId(): string {
        return $this->customizationId;
    }

    public function getVersion(): string {
        return $this->version;
    }

    /** Ob die Kennung als Wildcard registriert ist. */
    public function isWildcard(): bool {
        return $this->scheme === self::WILDCARD_SCHEME;
    }

    /** Der Wert ohne Schema-Präfix. */
    public function getValue(): string {
        return $this->rootNamespace . self::SEPARATOR . $this->localName
            . '##' . $this->customizationId . self::SEPARATOR . $this->version;
    }

    /** Kanonische Schreibweise `<schema>::<wert>`. */
    public function canonical(): string {
        return $this->scheme . self::SEPARATOR . $this->getValue();
    }

    /** Kanonische Schreibweise für die Verwendung in einem SMP-URL-Pfadsegment. */
    public function urlEncoded(): string {
        return rawurlencode($this->canonical());
    }

    public function equals(self $other): bool {
        return $this->canonical() === $other->canonical();
    }

    public function __toString(): string {
        return $this->canonical();
    }

    /**
     * Liest den Textinhalt des ersten direkten cbc-Kindelements.
     */
    private static function firstValue(DOMElement $root, string $localName): ?string {
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $value = trim($child->textContent);
                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
