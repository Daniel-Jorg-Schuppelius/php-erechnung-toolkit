<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebItemType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Kind of a bill of quantity item, derived from the GAEB item markers.
 *
 * - Standard:    normal item with quantity
 * - Base:        base execution of an alternative group (ALNSerNo = 0)
 * - Alternative: alternative execution (ALNSerNo >= 1)
 * - Optional:    provisional item (Provis)
 * - LumpSum:     lump sum item without quantity based billing
 * - Markup:      markup item (percentage on referenced items, own element)
 * - Note:        remark, a text only entry without quantity (own element)
 */
enum GaebItemType: string {
    case Standard = 'standard';
    case Base = 'base';
    case Alternative = 'alternative';
    case Optional = 'optional';
    case LumpSum = 'lump_sum';
    case Markup = 'markup';
    case Note = 'note';

    /**
     * Is this kind billed regularly by quantity and price? A markup item is
     * not: it carries a percentage on referenced items and has no quantity.
     */
    public function isBillable(): bool {
        return match ($this) {
            self::Note, self::Optional, self::Alternative, self::Markup => false,
            default => true,
        };
    }
}
