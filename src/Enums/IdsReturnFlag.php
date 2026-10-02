<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsReturnFlag.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Rückgabekennzeichen des IDS-Warenkorbs (`WarenkorbInfo/RueckgabeKZ`): sagt
 * der Handwerkssoftware, ob im Shop zusätzlich bestellt wurde.
 */
enum IdsReturnFlag: string {
    case CartReturn = 'Warenkorbrückgabe';
    case CartReturnWithOrder = 'Warenkorbrückgabe mit Bestellung';

    /** Im Shop wurde bereits bestellt — eine zweite Bestellung wäre doppelt. */
    public function isOrdered(): bool {
        return $this === self::CartReturnWithOrder;
    }
}
