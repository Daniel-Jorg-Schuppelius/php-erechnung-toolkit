<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebMarkupType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * What a markup item applies to (GAEB DA XML 3.3, `tgMarkupType`).
 *
 * A markup item carries no work of its own - it raises the price of others. The
 * three values differ solely in *which* others, and that decides the sum: the
 * same percentage on a different base is a different amount.
 */
enum GaebMarkupType: string {
    /** Positionen, die dasselbe Zuschlagskennzeichen tragen. */
    case IdenticallyMarked = 'IdentAsMark';
    /** Alle Positionen der Gruppe, in der die Zuschlagsposition steht. */
    case AllInCategory = 'AllInCat';
    /** Nur die unter `MarkupSubQty` aufgeführten Teilmengen. */
    case ListedSubQuantities = 'ListInSubQty';

    public function label(): string {
        return match ($this) {
            self::IdenticallyMarked => 'Gleich gekennzeichnete Positionen',
            self::AllInCategory => 'Alle Positionen der Gruppe',
            self::ListedSubQuantities => 'Aufgeführte Teilmengen',
        };
    }

    /**
     * Does the base follow from the document structure alone? Only then can the
     * markup be computed without the additional list - otherwise the sub
     * quantities have to travel with it.
     */
    public function derivesBaseFromStructure(): bool {
        return $this === self::AllInCategory;
    }
}
