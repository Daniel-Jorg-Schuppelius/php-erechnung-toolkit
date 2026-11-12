<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungProfile.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * E-Rechnung Profile (ZUGFeRD/Factur-X/XRechnung).
 *
 * Defines the conformance profile of the e-invoice.
 */
enum ERechnungProfile: string {
    /** ZUGFeRD 2.1/2.2 - MINIMUM profile */
    case MINIMUM = 'urn:factur-x.eu:1p0:minimum';

    /** ZUGFeRD 2.1/2.2 - BASIC WL profile */
    case BASIC_WL = 'urn:factur-x.eu:1p0:basicwl';

    /** ZUGFeRD 2.1/2.2 - BASIC profile */
    case BASIC = 'urn:factur-x.eu:1p0:basic';

    /** ZUGFeRD 2.1/2.2 - EN16931 profile (COMFORT) */
    case EN16931 = 'urn:cen.eu:en16931:2017';

    /** ZUGFeRD 2.1/2.2 - EXTENDED profile */
    case EXTENDED = 'urn:factur-x.eu:1p0:extended';

    /** XRechnung 3.0 profile (CIUS, compliant) */
    case XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    /** XRechnung 3.0 Extension: conformant zur compliant-CIUS, Kennung wie im KoSIT-Szenario */
    case XRECHNUNG_EXTENSION = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0';

    /** ZUGFeRD 1.0 (2014) - BASIC profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_BASIC = 'urn:ferd:CrossIndustryDocument:invoice:1p0:basic';

    /** ZUGFeRD 1.0 (2014) - COMFORT profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_COMFORT = 'urn:ferd:CrossIndustryDocument:invoice:1p0:comfort';

    /** ZUGFeRD 1.0 (2014) - EXTENDED profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_EXTENDED = 'urn:ferd:CrossIndustryDocument:invoice:1p0:extended';

    /**
     * Profile from the specification identifier (BT-24: CII
     * GuidelineSpecifiedDocumentContextParameter/ID, UBL CustomizationID).
     *
     * Exact values first, then the keywords of known URNs, e.g. the Factur-X
     * form urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic. A
     * missing or unknown identifier counts as EN 16931. ZUGFeRD 1.0 names are
     * read by the parser itself.
     */
    public static function fromSpecificationIdentifier(?string $identifier): self {
        if ($identifier === null) {
            return self::EN16931;
        }

        $exact = self::tryFrom($identifier);
        if ($exact !== null) {
            return $exact;
        }

        $lower = strtolower($identifier);

        // Most specific first to avoid partial matches.
        if (str_contains($lower, 'xrechnung')) {
            return str_contains($lower, 'extension') || str_contains($identifier, 'conformant')
                ? self::XRECHNUNG_EXTENSION
                : self::XRECHNUNG;
        }
        if (str_contains($lower, 'extended')) {
            return self::EXTENDED;
        }
        if (str_contains($lower, 'basic')) {
            return str_contains($lower, 'wl') ? self::BASIC_WL : self::BASIC;
        }
        if (str_contains($lower, 'minimum')) {
            return self::MINIMUM;
        }

        // KoSIT authority without the keyword: XRechnung 2.x carries
        // "xoev-de:kosit", XRechnung 3.0 "xeinkauf.de:kosit".
        if (str_contains($identifier, 'xoev-de:kosit') || str_contains($identifier, 'xeinkauf.de:kosit')) {
            return self::XRECHNUNG;
        }

        return self::EN16931;
    }

    /**
     * Returns the profile name.
     */
    public function label(): string {
        return match ($this) {
            self::MINIMUM => 'MINIMUM',
            self::BASIC_WL => 'BASIC WL',
            self::BASIC => 'BASIC',
            self::EN16931 => 'EN 16931 (COMFORT)',
            self::EXTENDED => 'EXTENDED',
            self::XRECHNUNG => 'XRechnung',
            self::XRECHNUNG_EXTENSION => 'XRechnung Extension',
            self::ZUGFERD1_BASIC => 'ZUGFeRD 1.0 BASIC',
            self::ZUGFERD1_COMFORT => 'ZUGFeRD 1.0 COMFORT',
            self::ZUGFERD1_EXTENDED => 'ZUGFeRD 1.0 EXTENDED',
        };
    }

    /**
     * ZUGFeRD 1.0 predates EN 16931 and is no e-invoice under § 14 UStG since
     * 2025: the parser still reads it, generators write {@see successor()}.
     */
    public function isLegacy(): bool {
        return in_array($this, [self::ZUGFERD1_BASIC, self::ZUGFERD1_COMFORT, self::ZUGFERD1_EXTENDED], true);
    }

    /**
     * Current profile of the same scope: the ZUGFeRD 2.x counterpart for a
     * legacy profile, otherwise the profile itself.
     */
    public function successor(): self {
        return match ($this) {
            self::ZUGFERD1_BASIC => self::BASIC,
            self::ZUGFERD1_COMFORT => self::EN16931,
            self::ZUGFERD1_EXTENDED => self::EXTENDED,
            default => $this,
        };
    }

    /**
     * Whether the profile carries AdditionalReferencedDocument: supporting
     * documents (BG-24), invoiced object (BT-18) and line object (BT-128).
     * The Factur-X schemas of MINIMUM, BASIC WL and BASIC lack the element.
     */
    public function supportsReferencedDocuments(): bool {
        return in_array($this->successor(), [self::EN16931, self::EXTENDED, self::XRECHNUNG, self::XRECHNUNG_EXTENSION], true);
    }

    /**
     * Whether the profile carries the invoicing period (BG-14); from BASIC WL on.
     */
    public function supportsInvoicingPeriod(): bool {
        return $this->successor() !== self::MINIMUM;
    }

    /**
     * Whether the profile carries invoice lines and their period (BG-26); from BASIC on.
     */
    public function supportsLinePeriod(): bool {
        return !in_array($this->successor(), [self::MINIMUM, self::BASIC_WL], true);
    }

    /**
     * Whether the profile carries the deliver-to party and address (BT-70,
     * BT-71, BG-15); from BASIC WL on.
     */
    public function supportsDeliverTo(): bool {
        return $this->successor() !== self::MINIMUM;
    }

    /**
     * Checks if this profile is XRechnung compliant.
     */
    public function isXRechnung(): bool {
        return $this === self::XRECHNUNG || $this === self::XRECHNUNG_EXTENSION;
    }

    /**
     * Checks if this profile is ZUGFeRD/Factur-X.
     */
    public function isZugferd(): bool {
        return in_array($this, [
            self::MINIMUM,
            self::BASIC_WL,
            self::BASIC,
            self::EN16931,
            self::EXTENDED,
            self::ZUGFERD1_BASIC,
            self::ZUGFERD1_COMFORT,
            self::ZUGFERD1_EXTENDED,
        ], true);
    }

    /**
     * Returns the minimum required profile for public sector invoices in Germany.
     */
    public static function forPublicSector(): self {
        return self::XRECHNUNG;
    }

    /**
     * Returns the recommended profile for B2B invoices.
     */
    public static function forB2B(): self {
        return self::EN16931;
    }
}
