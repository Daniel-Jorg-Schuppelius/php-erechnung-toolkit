<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXProfile.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Order-X conformance profile (UN/CEFACT Cross Industry Order, FeRD/FNFE-MPE).
 *
 * Order-X is to orders what ZUGFeRD/Factur-X is to invoices: a CII XML document
 * (D20B SCRDMCCBDACIO message) that can be embedded in a PDF/A-3 file. The enum
 * value is the GuidelineSpecifiedDocumentContextParameter URN emitted in the
 * ExchangedDocumentContext.
 *
 * @see https://www.mustangproject.org/order-x/
 */
enum OrderXProfile: string {
    /** Order-X BASIC profile. */
    case BASIC = 'urn:order-x.eu:1p0:basic';

    /** Order-X COMFORT profile. */
    case COMFORT = 'urn:order-x.eu:1p0:comfort';

    /** Order-X EXTENDED profile. */
    case EXTENDED = 'urn:order-x.eu:1p0:extended';

    /**
     * Returns the profile name.
     */
    public function label(): string {
        return match ($this) {
            self::BASIC => 'BASIC',
            self::COMFORT => 'COMFORT',
            self::EXTENDED => 'EXTENDED',
        };
    }

    /**
     * Returns the recommended profile for general B2B orders.
     */
    public static function forB2B(): self {
        return self::COMFORT;
    }
}
