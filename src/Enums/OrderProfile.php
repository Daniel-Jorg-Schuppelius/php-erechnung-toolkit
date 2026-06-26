<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderProfile.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Order conformance profile (Peppol BIS Order only / XBestellung).
 *
 * XBestellung v1.0 is a German CIUS on top of the Peppol BIS "Order only" 3
 * profile and shares the Peppol Order transaction (T01). Both profiles emit the
 * same cbc:CustomizationID and cbc:ProfileID in the current revision; the enum
 * keeps them distinct so the validator scenario and intent are explicit.
 *
 * The enum value is the standard identifier; the actual document URNs are
 * returned by {@see self::customizationId()} and {@see self::profileId()} so the
 * exact values can be adjusted in one place without touching the generator.
 */
enum OrderProfile: string {
    /** Peppol BIS Order only 3 (international). */
    case PEPPOL_ORDER_ONLY = 'urn:fdc:peppol.eu:poacc:bis:order_only:3';

    /** XBestellung v1.0 (German CIUS on Peppol BIS Order only). */
    case XBESTELLUNG = 'urn:xoev-de:kosit:standard:xbestellung.bis-order-only_1.0';

    /**
     * Returns the cbc:CustomizationID emitted in the UBL Order.
     *
     * Both profiles use the Peppol Order transaction (T01) customization.
     */
    public function customizationId(): string {
        return 'urn:fdc:peppol.eu:poacc:trns:order:3';
    }

    /**
     * Returns the cbc:ProfileID emitted in the UBL Order.
     */
    public function profileId(): string {
        return 'urn:fdc:peppol.eu:poacc:bis:order_only:3';
    }

    /**
     * Returns the profile name.
     */
    public function label(): string {
        return match ($this) {
            self::PEPPOL_ORDER_ONLY => 'Peppol BIS Order only',
            self::XBESTELLUNG => 'XBestellung',
        };
    }

    /**
     * Checks if this profile is the German XBestellung CIUS.
     */
    public function isXBestellung(): bool {
        return $this === self::XBESTELLUNG;
    }

    /**
     * Returns the recommended profile for German public sector orders.
     */
    public static function forPublicSector(): self {
        return self::XBESTELLUNG;
    }
}
