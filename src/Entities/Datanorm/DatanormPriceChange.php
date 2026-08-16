<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormPriceChange.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Enums\DatanormPriceIndicator;

/**
 * One article price from a DATPREIS P-record.
 *
 * A DATANORM 4 P-record transports up to three of these per line and no price
 * unit (the unit amount from the article master applies → `priceUnitAmount`
 * is null); DATANORM 5 sends one per line with an explicit price unit.
 * Either a discount group or up to three article-specific discounts may
 * accompany a list price — the discounts then replace the group's values for
 * this article only.
 */
final class DatanormPriceChange {
    /**
     * @param  list<DatanormDiscount>  $discounts
     */
    public function __construct(
        private readonly string $articleNumber,
        private readonly DatanormPriceIndicator $priceIndicator,
        private readonly ?Money $price,
        private readonly ?int $priceUnitAmount = null,
        private readonly ?string $discountGroup = null,
        private readonly array $discounts = [],
        private readonly ?DateTimeImmutable $validFrom = null
    ) {}

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    public function getPriceIndicator(): DatanormPriceIndicator {
        return $this->priceIndicator;
    }

    public function getPrice(): ?Money {
        return $this->price;
    }

    /** Resolved units the price refers to; null = use the article master's unit amount (DATANORM 4). */
    public function getPriceUnitAmount(): ?int {
        return $this->priceUnitAmount;
    }

    public function getDiscountGroup(): ?string {
        return $this->discountGroup;
    }

    /** @return list<DatanormDiscount> article-specific discounts (replace the group) */
    public function getDiscounts(): array {
        return $this->discounts;
    }

    public function getValidFrom(): ?DateTimeImmutable {
        return $this->validFrom;
    }
}
