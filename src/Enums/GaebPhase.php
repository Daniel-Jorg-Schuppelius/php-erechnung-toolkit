<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebPhase.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * GAEB data exchange phase for bill of quantity data (GAEB DA XML 3.3).
 *
 * The value is the DA number as it appears in the file extension and in the
 * `DP` element, e.g. `83` for a request for bid (`.x83`, `.d83`, `.p83`).
 * Phases outside the 80 range (X31 quantity take-off, X50-X52 costing,
 * X93-X99 trade) are not part of this enum.
 */
enum GaebPhase: string {
    case Lv = '81';            // Leistungsbeschreibung
    case Estimate = '82';      // Kostenanschlag
    case RequestForBid = '83'; // Angebotsaufforderung
    case Bid = '84';           // Angebotsabgabe
    case SideBid = '85';       // Nebenangebot
    case Award = '86';         // Auftragserteilung

    public function label(): string {
        return match ($this) {
            self::Lv => 'Leistungsbeschreibung',
            self::Estimate => 'Kostenanschlag',
            self::RequestForBid => 'Angebotsaufforderung',
            self::Bid => 'Angebotsabgabe',
            self::SideBid => 'Nebenangebot',
            self::Award => 'Auftragserteilung',
        };
    }

    /** Does this phase carry binding unit and total prices? */
    public function carriesPrices(): bool {
        return match ($this) {
            self::Lv, self::RequestForBid => false,
            default => true,
        };
    }

    /**
     * Must quantity and unit be present on an item? Bid submission is the only
     * phase where they may be omitted: the bidder returns prices and text
     * complements for an already known bill of quantity (GAEB DA XML 3.3,
     * rules for X80 to X86, object Item, rule 7).
     */
    public function carriesQuantities(): bool {
        return $this !== self::Bid;
    }

    /** Tolerant lookup from a DA code such as "84", "X84" or "x84". */
    public static function fromCode(?string $code): ?self {
        if ($code === null) {
            return null;
        }

        return self::tryFrom(preg_replace('/\D+/', '', $code) ?? '');
    }
}
