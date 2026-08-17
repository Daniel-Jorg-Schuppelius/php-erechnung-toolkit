<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostingMethod.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/** How the costs were arrived at (GAEB DA XML 3.3, `ECMethod`). */
enum GaebCostingMethod: string {
    case ByArea = 'cost by area method';           // Flächenverfahren
    case ByComparison = 'cost by object comparison'; // Objektvergleich
    case ByElements = 'cost by elements';          // Elementverfahren
    case Other = 'other';

    public function label(): string {
        return match ($this) {
            self::ByArea => 'Flächenverfahren',
            self::ByComparison => 'Objektvergleich',
            self::ByElements => 'Elementverfahren',
            self::Other => 'Sonstiges',
        };
    }
}
