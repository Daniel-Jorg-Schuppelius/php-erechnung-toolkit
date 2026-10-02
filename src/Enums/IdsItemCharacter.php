<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsItemCharacter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Positionskennzeichen einer IDS-Warenkorbposition (`ItemChara`).
 */
enum IdsItemCharacter: string {
    case Normal = 'normal';
    case Alternate = 'alternate';
    /** Bedarfsposition. */
    case Provisional = 'provis';
}
