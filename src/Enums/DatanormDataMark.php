<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormDataMark.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DATANORM 5 header field 3 (Datenkennzeichen): what kind of data the file
 * transports. DATANORM 4 headers carry no data mark; parsers derive it from
 * the record types encountered instead.
 */
enum DatanormDataMark: string {
    case Articles = 'A';
    case ProductGroups = 'S';
    case DiscountGroups = 'R';
    case Sets = 'J';
    case PriceChanges = 'P';
    case Texts = 'T';
    case Oenorm = 'O';
}
