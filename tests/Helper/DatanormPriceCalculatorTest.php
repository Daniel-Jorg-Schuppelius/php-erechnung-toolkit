<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormPriceCalculatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Datanorm\DatanormDiscount;
use ERechnungToolkit\Enums\{DatanormDiscountKind, DatanormVersion};
use ERechnungToolkit\Helper\DatanormPriceCalculator;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * DATANORM price arithmetic: coded price units, sequential discounts,
 * rounding only at the end of the chain.
 */
class DatanormPriceCalculatorTest extends BaseTestCase {
    public function test_v4_price_unit_codes_are_decoded(): void {
        self::assertSame(1, DatanormPriceCalculator::resolvePriceUnitAmount(0, DatanormVersion::V4));
        self::assertSame(10, DatanormPriceCalculator::resolvePriceUnitAmount(1, DatanormVersion::V4));
        self::assertSame(100, DatanormPriceCalculator::resolvePriceUnitAmount(2, DatanormVersion::V4));
        self::assertSame(1000, DatanormPriceCalculator::resolvePriceUnitAmount(3, DatanormVersion::V4));
    }

    public function test_v4_price_unit_code_outside_range_throws(): void {
        $this->expectException(InvalidArgumentException::class);
        DatanormPriceCalculator::resolvePriceUnitAmount(100, DatanormVersion::V4);
    }

    public function test_v5_price_unit_is_direct_and_zero_defaults_to_one(): void {
        self::assertSame(6, DatanormPriceCalculator::resolvePriceUnitAmount(6, DatanormVersion::V5));
        self::assertSame(1000, DatanormPriceCalculator::resolvePriceUnitAmount(1000, DatanormVersion::V5));
        self::assertSame(1, DatanormPriceCalculator::resolvePriceUnitAmount(0, DatanormVersion::V5));
    }

    public function test_unit_price_divides_by_price_unit_amount(): void {
        $per100 = Money::ofMinor(18950, CurrencyCode::Euro, 2); // 189,50 je 100

        self::assertSame('1.8950', DatanormPriceCalculator::unitPrice($per100, 100)->getAmount());
        self::assertSame('189.5000', DatanormPriceCalculator::unitPrice($per100, 1)->getAmount());
    }

    public function test_sequential_discounts_are_not_added(): void {
        $list = Money::ofMinor(10000, CurrencyCode::Euro, 2); // 100,00

        $net = DatanormPriceCalculator::netPrice($list, [
            new DatanormDiscount(DatanormDiscountKind::Discount, 10.0),
            new DatanormDiscount(DatanormDiscountKind::Discount, 10.0),
        ]);

        // 10 % + 10 % = 19 % effective, never 20 %.
        self::assertSame('81.00', $net->getAmount());
    }

    public function test_specification_example_chain_rounds_only_at_the_end(): void {
        // Spec example: list 49,95 with 30 % / 10 % / 5 % → 29,90.
        $list = Money::ofMinor(4995, CurrencyCode::Euro, 2);

        $net = DatanormPriceCalculator::netPrice($list, [
            new DatanormDiscount(DatanormDiscountKind::Discount, 30.0),
            new DatanormDiscount(DatanormDiscountKind::Discount, 10.0),
            new DatanormDiscount(DatanormDiscountKind::Discount, 5.0),
        ]);

        self::assertSame('29.90', $net->getAmount());
    }

    public function test_factor_and_surcharge(): void {
        $list = Money::ofMinor(4089, CurrencyCode::Euro, 2); // 40,89

        $factor = DatanormPriceCalculator::netPrice($list, [new DatanormDiscount(DatanormDiscountKind::Factor, 0.9)]);
        self::assertSame('36.80', $factor->getAmount());

        $surcharge = DatanormPriceCalculator::netPrice(
            Money::ofMinor(10000, CurrencyCode::Euro, 2),
            [new DatanormDiscount(DatanormDiscountKind::Surcharge, 12.0)]
        );
        self::assertSame('112.00', $surcharge->getAmount());
    }

    public function test_empty_discount_chain_keeps_the_price(): void {
        $list = Money::ofMinor(4995, CurrencyCode::Euro, 2);

        self::assertSame('49.95', DatanormPriceCalculator::netPrice($list, [])->getAmount());
    }
}
