<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TaxSubtotal.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\TaxCategory;

/**
 * Tax Subtotal for VAT breakdown (EN 16931).
 *
 * Represents a single VAT category and rate combination in the tax breakdown.
 * Amounts are {@see Money} value objects — the VAT breakdown is the place where
 * float rounding used to break BR-CO-14/BR-S-09 (sum of subtotals vs. total).
 */
final class TaxSubtotal {
    public function __construct(
        private Money $taxableAmount,
        private Money $taxAmount,
        private TaxCategory $category,
        private float $percent,
        private ?string $exemptionReason = null,
        private ?string $exemptionReasonCode = null
    ) {}

    public function getTaxableAmount(): Money {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): Money {
        return $this->taxAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->taxableAmount->getCurrency();
    }

    public function getCategory(): TaxCategory {
        return $this->category;
    }

    public function getPercent(): float {
        return $this->percent;
    }

    public function getExemptionReason(): ?string {
        return $this->exemptionReason;
    }

    public function getExemptionReasonCode(): ?string {
        return $this->exemptionReasonCode;
    }

    /**
     * Returns true if this is a zero-rate or exempt category.
     */
    public function isExempt(): bool {
        return !$this->category->isTaxable() || $this->percent === 0.0;
    }

    /**
     * Creates a standard rate subtotal.
     */
    public static function standard(Money $taxableAmount, float $rate = 19.0): self {
        return new self(
            $taxableAmount,
            $taxableAmount->percentage($rate),
            TaxCategory::STANDARD,
            $rate
        );
    }

    /**
     * Creates a reduced rate subtotal (7% in Germany).
     */
    public static function reduced(Money $taxableAmount, float $rate = 7.0): self {
        return new self(
            $taxableAmount,
            $taxableAmount->percentage($rate),
            TaxCategory::STANDARD,
            $rate
        );
    }

    /**
     * Creates a reverse charge subtotal.
     */
    public static function reverseCharge(Money $taxableAmount, string $reason = 'Reverse Charge'): self {
        return new self(
            $taxableAmount,
            Money::zero($taxableAmount->getCurrency()),
            TaxCategory::REVERSE_CHARGE,
            0.0,
            $reason,
            'VATEX-EU-AE'
        );
    }

    /**
     * Creates an exempt subtotal.
     */
    public static function exempt(Money $taxableAmount, string $reason, ?string $reasonCode = null): self {
        return new self(
            $taxableAmount,
            Money::zero($taxableAmount->getCurrency()),
            TaxCategory::EXEMPT,
            0.0,
            $reason,
            $reasonCode
        );
    }
}
