<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TaxCategory.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * VAT Category Code according to UNCL5305 (EN 16931).
 * 
 * Defines the VAT category for invoice lines and totals.
 * 
 * @see https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL5305/
 * @package ERechnungToolkit\Enums
 */
enum TaxCategory: string {
    /** Standard rate (Regelsteuersatz) */
    case STANDARD = 'S';

    /** Zero rated goods (Nullsteuersatz) */
    case ZERO_RATED = 'Z';

    /** Exempt from tax (Steuerbefreit) */
    case EXEMPT = 'E';

    /** Reverse charge (Steuerschuldumkehr) */
    case REVERSE_CHARGE = 'AE';

    /** Intra-Community supply (Innergemeinschaftliche Lieferung) */
    case INTRA_COMMUNITY = 'K';

    /** Export outside the EU (Ausfuhrlieferung) */
    case EXPORT = 'G';

    /** Outside scope of tax (Nicht steuerbar) */
    case OUT_OF_SCOPE = 'O';

    /** Canary Islands general indirect tax (IGIC) */
    case IGIC = 'L';

    /** Tax for production, services and importation (IPSI) */
    case IPSI = 'M';

    /**
     * Returns the German label.
     */
    public function label(): string {
        return match ($this) {
            self::STANDARD => 'Regelsteuersatz',
            self::ZERO_RATED => 'Nullsteuersatz',
            self::EXEMPT => 'Steuerbefreit',
            self::REVERSE_CHARGE => 'Steuerschuldumkehr (Reverse Charge)',
            self::INTRA_COMMUNITY => 'Innergemeinschaftliche Lieferung',
            self::EXPORT => 'Ausfuhrlieferung',
            self::OUT_OF_SCOPE => 'Nicht steuerbar',
            self::IGIC => 'IGIC (Kanarische Inseln)',
            self::IPSI => 'IPSI',
        };
    }

    /**
     * Returns the default German VAT rate for this category.
     */
    public function defaultRate(): float {
        return match ($this) {
            self::STANDARD => 19.0,
            self::ZERO_RATED, self::EXEMPT, self::REVERSE_CHARGE,
            self::INTRA_COMMUNITY, self::EXPORT, self::OUT_OF_SCOPE => 0.0,
            self::IGIC => 7.0,
            self::IPSI => 10.0,
        };
    }

    /**
     * Checks if tax amount should be calculated.
     */
    public function isTaxable(): bool {
        return match ($this) {
            self::STANDARD, self::IGIC, self::IPSI => true,
            default => false,
        };
    }

    /**
     * Creates from UNCL5305 code string.
     */
    public static function fromCode(string $code): ?self {
        return self::tryFrom($code);
    }
}
