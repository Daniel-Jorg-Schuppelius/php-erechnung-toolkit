<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormPriceIndicator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DATANORM Preiskennzeichen (price indicator). All prices are B2B net of VAT.
 *
 * DATANORM 4 supports only `ListPrice` and `NetPrice`; the remaining
 * indicators were introduced with DATANORM 5 (`RoutePrice`, `RecommendedPrice`
 * and `OnRequest` only in P-records respectively A-records as documented).
 */
enum DatanormPriceIndicator: int {
    /** Official supplier list price (gross, discountable via discount group). */
    case ListPrice = 1;

    /** Customer purchase price (net). */
    case NetPrice = 2;

    /** Factory price without freight/packaging — not discountable (DATANORM 5). */
    case RoutePrice = 3;

    /** Recommended end-user price without VAT — not discountable (DATANORM 5, P-record only). */
    case RecommendedPrice = 4;

    /** Price on request; a transferred price is a target price (DATANORM 5). */
    case OnRequest = 9;

    /** Whether purchase discounts may be applied to this price type. */
    public function isDiscountable(): bool {
        return $this === self::ListPrice;
    }
}
