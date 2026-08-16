<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormProcessingFlag.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DATANORM Verarbeitungskennzeichen (processing flag).
 *
 * `Renumber` (`X`) exists only in DATANORM 4 A-records (the new article number
 * travels in the Kurztext-1 field). DATANORM 5 transports deletions and
 * renumberings via the B-record, where `A` means "change the article number"
 * and `L` means "delete".
 */
enum DatanormProcessingFlag: string {
    case New = 'N';
    case Change = 'A';
    case Delete = 'L';
    case Renumber = 'X';
}
