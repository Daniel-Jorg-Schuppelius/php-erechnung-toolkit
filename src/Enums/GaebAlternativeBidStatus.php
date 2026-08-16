<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebAlternativeBidStatus.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * How an item of a side bid relates to the main bid (GAEB `AlterBidStatus`).
 *
 * A missing value is to be read as "offered identically" (GAEB DA XML 3.3,
 * side bid). Only new and modified items carry their full description; the
 * others transport prices and text complements only.
 */
enum GaebAlternativeBidStatus: string {
    case Identical = 'Identical';
    case NotRequired = 'N/A';
    case Modified = 'Modified';
    case New = 'New';

    public function label(): string {
        return match ($this) {
            self::Identical => 'Position identisch angeboten',
            self::NotRequired => 'Position nicht erforderlich',
            self::Modified => 'Position geändert angeboten',
            self::New => 'Position neu angeboten',
        };
    }

    /** Does this status require the complete description to be transported? */
    public function carriesFullDescription(): bool {
        return $this === self::New || $this === self::Modified;
    }
}
