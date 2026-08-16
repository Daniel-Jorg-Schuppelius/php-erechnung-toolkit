<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormPriceCalculator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Datanorm\DatanormDiscount;
use ERechnungToolkit\Enums\{DatanormDiscountKind, DatanormVersion};
use InvalidArgumentException;

/**
 * DATANORM price arithmetic.
 *
 * Implements the rules from the DATANORM specification: discounts are applied
 * **sequentially** (10 % plus 10 % yields 19 %, discounts are never summed),
 * rounding happens once at the end of the chain to the currency's decimals,
 * and the DATANORM 4 price unit is a code (0/1/2/3 = per 1/10/100/1000)
 * while DATANORM 5 transports the unit amount directly.
 */
final class DatanormPriceCalculator {
    /** Internal working scale, rounded to currency decimals only at chain end. */
    private const WORK_SCALE = 6;

    /** DATANORM 4 price unit code → units the price refers to. */
    private const V4_PRICE_UNITS = [0 => 1, 1 => 10, 2 => 100, 3 => 1000];

    private function __construct() {
        // Static helper.
    }

    /**
     * Resolves the transferred price unit field to the number of units the
     * price refers to. DATANORM 4 codes it, DATANORM 5 sends it directly
     * (`0` is treated as "per 1 unit" defensively).
     *
     * @throws InvalidArgumentException on a DATANORM 4 value outside 0-3
     */
    public static function resolvePriceUnitAmount(int $raw, DatanormVersion $version): int {
        if ($version === DatanormVersion::V5) {
            return max(1, $raw);
        }

        return self::V4_PRICE_UNITS[$raw]
            ?? throw new InvalidArgumentException("Invalid DATANORM 4 price unit code: {$raw} (allowed: 0-3)");
    }

    /**
     * Price per single unit: transferred price divided by the (resolved)
     * price unit amount, at scale 4.
     */
    public static function unitPrice(Money $price, int $priceUnitAmount): Money {
        if ($priceUnitAmount <= 1) {
            return $price->withScale(4);
        }

        return $price->withScale(self::WORK_SCALE)->dividedBy($priceUnitAmount)->withScale(4);
    }

    /**
     * Applies a discount chain to a list price — sequentially, with one
     * rounding step at the end.
     *
     * @param  list<DatanormDiscount>  $discounts
     * @param  int|null  $scale  target scale (default: currency decimals)
     */
    public static function netPrice(Money $listPrice, array $discounts, ?int $scale = null): Money {
        $net = $listPrice->withScale(self::WORK_SCALE);
        foreach ($discounts as $discount) {
            $net = match ($discount->getKind()) {
                DatanormDiscountKind::Discount => $net->minusPercentage($discount->getValue()),
                DatanormDiscountKind::Factor => $net->times($discount->getValue()),
                DatanormDiscountKind::Surcharge => $net->plusPercentage($discount->getValue()),
            };
        }

        return $net->withScale($scale ?? $listPrice->getCurrency()->getDefaultFractionDigits());
    }
}
