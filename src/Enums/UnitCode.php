<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UnitCode.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Unit of Measure Code according to UN/ECE Recommendation 20 (EN 16931).
 * 
 * Common unit codes used in e-invoicing.
 * 
 * @see https://docs.peppol.eu/poacc/billing/3.0/codelist/UNECERec20/
 * @package ERechnungToolkit\Enums
 */
enum UnitCode: string {
    // Count units
    /** One/Piece (Stück) */
    case PIECE = 'C62';

    /** Unit (Einheit) */
    case UNIT = 'EA';

    /** Set (Satz) */
    case SET = 'SET';

    /** Pair (Paar) */
    case PAIR = 'PR';

    /** Dozen (Dutzend) */
    case DOZEN = 'DZN';

    /** Hundred (Hundert) */
    case HUNDRED = 'CEN';

    /** Thousand (Tausend) */
    case THOUSAND = 'MIL';

    // Mass units
    /** Kilogram */
    case KILOGRAM = 'KGM';

    /** Gram */
    case GRAM = 'GRM';

    /** Milligram */
    case MILLIGRAM = 'MGM';

    /** Tonne (Metric ton) */
    case TONNE = 'TNE';

    // Length units
    /** Metre */
    case METRE = 'MTR';

    /** Centimetre */
    case CENTIMETRE = 'CMT';

    /** Millimetre */
    case MILLIMETRE = 'MMT';

    /** Kilometre */
    case KILOMETRE = 'KMT';

    // Area units
    /** Square metre */
    case SQUARE_METRE = 'MTK';

    // Volume units
    /** Litre */
    case LITRE = 'LTR';

    /** Millilitre */
    case MILLILITRE = 'MLT';

    /** Cubic metre */
    case CUBIC_METRE = 'MTQ';

    // Time units
    /** Hour (Stunde) */
    case HOUR = 'HUR';

    /** Day (Tag) */
    case DAY = 'DAY';

    /** Week (Woche) */
    case WEEK = 'WEE';

    /** Month (Monat) */
    case MONTH = 'MON';

    /** Year (Jahr) */
    case YEAR = 'ANN';

    /** Minute */
    case MINUTE = 'MIN';

    /** Second */
    case SECOND = 'SEC';

    // Package units
    /** Package (Paket) */
    case PACKAGE = 'PK';

    /** Box (Karton) */
    case BOX = 'BX';

    /** Pallet (Palette) */
    case PALLET = 'PF';

    /** Container */
    case CONTAINER = 'CH';

    // Service units
    /** Job (Auftrag) */
    case JOB = 'E49';

    /** Lump sum (Pauschale) */
    case LUMP_SUM = 'LS';

    /**
     * Returns the German label.
     */
    public function label(): string {
        return match ($this) {
            self::PIECE => 'Stück',
            self::UNIT => 'Einheit',
            self::SET => 'Satz',
            self::PAIR => 'Paar',
            self::DOZEN => 'Dutzend',
            self::HUNDRED => 'Hundert',
            self::THOUSAND => 'Tausend',
            self::KILOGRAM => 'Kilogramm',
            self::GRAM => 'Gramm',
            self::MILLIGRAM => 'Milligramm',
            self::TONNE => 'Tonne',
            self::METRE => 'Meter',
            self::CENTIMETRE => 'Zentimeter',
            self::MILLIMETRE => 'Millimeter',
            self::KILOMETRE => 'Kilometer',
            self::SQUARE_METRE => 'Quadratmeter',
            self::LITRE => 'Liter',
            self::MILLILITRE => 'Milliliter',
            self::CUBIC_METRE => 'Kubikmeter',
            self::HOUR => 'Stunde',
            self::DAY => 'Tag',
            self::WEEK => 'Woche',
            self::MONTH => 'Monat',
            self::YEAR => 'Jahr',
            self::MINUTE => 'Minute',
            self::SECOND => 'Sekunde',
            self::PACKAGE => 'Paket',
            self::BOX => 'Karton',
            self::PALLET => 'Palette',
            self::CONTAINER => 'Container',
            self::JOB => 'Auftrag',
            self::LUMP_SUM => 'Pauschale',
        };
    }

    /**
     * Returns the abbreviation.
     */
    public function abbreviation(): string {
        return match ($this) {
            self::PIECE, self::UNIT => 'Stk.',
            self::SET => 'Satz',
            self::PAIR => 'Paar',
            self::DOZEN => 'Dtz.',
            self::HUNDRED => 'Hdt.',
            self::THOUSAND => 'Tsd.',
            self::KILOGRAM => 'kg',
            self::GRAM => 'g',
            self::MILLIGRAM => 'mg',
            self::TONNE => 't',
            self::METRE => 'm',
            self::CENTIMETRE => 'cm',
            self::MILLIMETRE => 'mm',
            self::KILOMETRE => 'km',
            self::SQUARE_METRE => 'm²',
            self::LITRE => 'l',
            self::MILLILITRE => 'ml',
            self::CUBIC_METRE => 'm³',
            self::HOUR => 'h',
            self::DAY => 'Tag(e)',
            self::WEEK => 'Wo.',
            self::MONTH => 'Mon.',
            self::YEAR => 'Jahr(e)',
            self::MINUTE => 'min',
            self::SECOND => 's',
            self::PACKAGE => 'Pkt.',
            self::BOX => 'Krt.',
            self::PALLET => 'Pal.',
            self::CONTAINER => 'Cnt.',
            self::JOB => 'Auftrag',
            self::LUMP_SUM => 'pauschal',
        };
    }

    /**
     * Creates from UN/ECE Rec 20 code string.
     */
    public static function fromCode(string $code): ?self {
        return self::tryFrom($code);
    }
}
