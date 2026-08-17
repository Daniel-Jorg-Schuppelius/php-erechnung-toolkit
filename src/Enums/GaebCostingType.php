<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostingType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Stage of a cost determination (GAEB DA XML 3.3, `ECType`).
 *
 * DIN 276 knows four stages, each tied to a point in the planning: estimate
 * during preliminary design, calculation as the detailed forecast, the cost
 * quote as the basis for awarding, and the final statement after the audited
 * closing invoice (technical documentation, 10.2).
 *
 * **The mapping is read from the order of the values, not from a legend** -
 * neither the schema nor the documentation spells out which English term means
 * which German stage. The sequence follows the planning progress, which makes
 * this the sound reading; a file from the wild would settle it.
 */
enum GaebCostingType: string {
    case Estimate = 'cost estimate';         // Kostenschätzung
    case Calculation = 'cost determination'; // Kostenberechnung
    case Quote = 'cost planning';            // Kostenanschlag
    case FinalStatement = 'cost estimation'; // Kostenfeststellung
    case Other = 'other';

    public function label(): string {
        return match ($this) {
            self::Estimate => 'Kostenschätzung',
            self::Calculation => 'Kostenberechnung',
            self::Quote => 'Kostenanschlag',
            self::FinalStatement => 'Kostenfeststellung',
            self::Other => 'Sonstige',
        };
    }

    /**
     * Are these the costs actually incurred? Only the final statement rests on
     * audited invoices - everything before it is a forecast, however careful.
     */
    public function isActual(): bool {
        return $this === self::FinalStatement;
    }

    /** Position in the planning, 1 to 4; `other` has none. */
    public function stage(): ?int {
        return match ($this) {
            self::Estimate => 1,
            self::Calculation => 2,
            self::Quote => 3,
            self::FinalStatement => 4,
            self::Other => null,
        };
    }
}
