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

    /** XRechnung 3.0 Extension (conformant) */
    case XRECHNUNG_EXTENSION = 'urn:cen.eu:en16931:2017#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0';

    /** ZUGFeRD 1.0 (2014) - BASIC profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_BASIC = 'urn:ferd:CrossIndustryDocument:invoice:1p0:basic';

    /** ZUGFeRD 1.0 (2014) - COMFORT profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_COMFORT = 'urn:ferd:CrossIndustryDocument:invoice:1p0:comfort';

    /** ZUGFeRD 1.0 (2014) - EXTENDED profile; legacy, read-only, not EN 16931 */
    case ZUGFERD1_EXTENDED = 'urn:ferd:CrossIndustryDocument:invoice:1p0:extended';

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
