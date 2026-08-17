<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebChangeOrderPhase.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Exchange phase of an addendum (GAEB DA XML 3.3, `tgCOPhase`).
 *
 * An addendum runs through the same three steps as the award itself - request,
 * bid, agreement - but inside a running contract, which is why it carries its
 * own phase beside the one of the file.
 */
enum GaebChangeOrderPhase: string {
    case Call = 'CallChangOrder';      // Nachtragsaufforderung
    case SupplementaryBid = 'SupplBid'; // Nachtragsangebot
    case Agreement = 'SupplAgree';      // Nachtragsvereinbarung

    public function label(): string {
        return match ($this) {
            self::Call => 'Nachtragsaufforderung',
            self::SupplementaryBid => 'Nachtragsangebot',
            self::Agreement => 'Nachtragsvereinbarung',
        };
    }

    /** Does this phase carry prices the other side is bound by? */
    public function carriesPrices(): bool {
        return $this !== self::Call;
    }
}
