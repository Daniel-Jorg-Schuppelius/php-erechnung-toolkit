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
 * 
 * @package ERechnungToolkit\Enums
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

    /** XRechnung profile */
    case XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xoev-de:kosit:standard:xrechnung_3.0';

    /** XRechnung Extension */
    case XRECHNUNG_EXTENSION = 'urn:cen.eu:en16931:2017#conformant#urn:xoev-de:kosit:extension:xrechnung_3.0';

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
