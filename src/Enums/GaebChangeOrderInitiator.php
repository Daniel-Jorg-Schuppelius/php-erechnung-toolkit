<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebChangeOrderInitiator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/** Who raised the addendum (GAEB DA XML 3.3, `COInit`). */
enum GaebChangeOrderInitiator: string {
    case Owner = 'Owner';
    case Contractor = 'Contractor';

    public function label(): string {
        return match ($this) {
            self::Owner => 'Auftraggeber',
            self::Contractor => 'Auftragnehmer',
        };
    }
}
