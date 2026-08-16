<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormScalePrice.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\DatanormPriceIndicator;

/**
 * DATANORM Z-record with working flag "scale prices" (DATANORM 5 flag 1,
 * DATANORM 4 flag 2): a quantity-, distance- or date-dependent scale price,
 * surcharge or discount for an article.
 *
 * A scale *price* (`INDICATOR_SCALE_PRICE`) replaces the price transferred in
 * the A-record once the basis (e.g. order quantity) falls into [from, to].
 */
final class DatanormScalePrice {
    public const INDICATOR_SCALE_PRICE = 1;
    public const INDICATOR_AMOUNT = 2;
    public const INDICATOR_PERCENT = 3;

    public const BASIS_QUANTITY = 1;
    public const BASIS_DISTANCE_KM = 2;
    public const BASIS_DATE = 3;
    public const BASIS_OTHER = 4;

    public function __construct(
        private readonly string $articleNumber,
        private readonly int $indicator,
        private readonly ?Money $amount,
        private readonly ?float $percent,
        private readonly bool $isDiscount,
        private readonly ?DatanormPriceIndicator $priceIndicator = null,
        private readonly int $priceUnitAmount = 1,
        private readonly ?int $basis = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
        private readonly ?string $description = null
    ) {}

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    /** One of the INDICATOR_* constants. */
    public function getIndicator(): int {
        return $this->indicator;
    }

    /** Price or absolute surcharge/discount (INDICATOR_SCALE_PRICE / INDICATOR_AMOUNT). */
    public function getAmount(): ?Money {
        return $this->amount;
    }

    /** Percentage surcharge/discount (INDICATOR_PERCENT). */
    public function getPercent(): ?float {
        return $this->percent;
    }

    /** True for a discount ("-"), false for a surcharge ("+") / plain scale price. */
    public function isDiscount(): bool {
        return $this->isDiscount;
    }

    public function getPriceIndicator(): ?DatanormPriceIndicator {
        return $this->priceIndicator;
    }

    /** Units the amount refers to (already resolved, never a V4 code). */
    public function getPriceUnitAmount(): int {
        return $this->priceUnitAmount;
    }

    /** One of the BASIS_* constants, null if no from/to window is given. */
    public function getBasis(): ?int {
        return $this->basis;
    }

    public function getFrom(): ?string {
        return $this->from;
    }

    public function getTo(): ?string {
        return $this->to;
    }

    public function getDescription(): ?string {
        return $this->description;
    }
}
