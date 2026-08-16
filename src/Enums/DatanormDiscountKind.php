<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormDiscountKind.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DATANORM Rabattkennzeichen (discount kind) as used in R- and P-records.
 *
 * Values are interpreted against a list price: `Discount` subtracts a
 * percentage, `Factor` multiplies, `Surcharge` adds a percentage. Multiple
 * discounts are applied sequentially (10 % + 10 % = 19 %, never added up);
 * rounding happens once at the end of the chain.
 */
enum DatanormDiscountKind: int {
    case Discount = 1;
    case Factor = 2;
    case Surcharge = 3;
}
