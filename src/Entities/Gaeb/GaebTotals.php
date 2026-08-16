<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTotals.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;

/**
 * Sums of a bill of quantity or one of its sections (GAEB `Totals`).
 *
 * When a discount is given on a section sum, the sum after discount has to be
 * transported as well (GAEB DA XML 3.3, discounts) - which is why both live
 * here side by side instead of being recalculated on read.
 */
final class GaebTotals {
    public function __construct(
        private readonly ?Money $total = null,
        private readonly ?string $discountPercent = null,
        private readonly ?Money $discountAmount = null,
        private readonly ?Money $totalAfterDiscount = null,
        private readonly ?string $vatRate = null,
        private readonly ?Money $totalNet = null,
        private readonly ?Money $vatAmount = null,
        private readonly ?Money $totalGross = null
    ) {}

    public function getTotal(): ?Money {
        return $this->total;
    }

    public function getDiscountPercent(): ?string {
        return $this->discountPercent;
    }

    public function getDiscountAmount(): ?Money {
        return $this->discountAmount;
    }

    /** Mandatory whenever a discount percentage or amount exists. */
    public function getTotalAfterDiscount(): ?Money {
        return $this->totalAfterDiscount;
    }

    public function getVatRate(): ?string {
        return $this->vatRate;
    }

    public function getTotalNet(): ?Money {
        return $this->totalNet;
    }

    public function getVatAmount(): ?Money {
        return $this->vatAmount;
    }

    public function getTotalGross(): ?Money {
        return $this->totalGross;
    }

    public function hasDiscount(): bool {
        return $this->discountPercent !== null || $this->discountAmount !== null;
    }

    public function isEmpty(): bool {
        return $this->total === null && $this->discountPercent === null && $this->discountAmount === null
            && $this->totalAfterDiscount === null && $this->vatRate === null && $this->totalNet === null
            && $this->vatAmount === null && $this->totalGross === null;
    }
}
