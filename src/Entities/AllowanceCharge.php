<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AllowanceCharge.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{AllowanceChargeReasonCode, TaxCategory};

/**
 * Allowance/Charge for E-Rechnung (EN 16931).
 *
 * Represents document or line level allowances (discounts) and charges (surcharges).
 * Beträge sind {@see Money}-Value-Objects; Prozentsätze bleiben skalar.
 */
final class AllowanceCharge {
    public function __construct(
        private bool $chargeIndicator,
        private Money $amount,
        private ?AllowanceChargeReasonCode $reasonCode = null,
        private ?string $reason = null,
        private ?Money $baseAmount = null,
        private ?float $percentage = null,
        private ?TaxCategory $taxCategory = null,
        private ?float $taxPercent = null
    ) {}

    /**
     * Returns true if this is a charge (Zuschlag), false if allowance (Rabatt).
     */
    public function isCharge(): bool {
        return $this->chargeIndicator;
    }

    /**
     * Returns true if this is an allowance (Rabatt).
     */
    public function isAllowance(): bool {
        return !$this->chargeIndicator;
    }

    public function getAmount(): Money {
        return $this->amount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->amount->getCurrency();
    }

    public function getReasonCode(): ?AllowanceChargeReasonCode {
        return $this->reasonCode;
    }

    public function getReason(): ?string {
        return $this->reason ?? $this->reasonCode?->label();
    }

    public function getBaseAmount(): ?Money {
        return $this->baseAmount;
    }

    public function getPercentage(): ?float {
        return $this->percentage;
    }

    public function getTaxCategory(): ?TaxCategory {
        return $this->taxCategory;
    }

    public function getTaxPercent(): ?float {
        return $this->taxPercent;
    }

    /**
     * Calculates the tax amount for this allowance/charge.
     * Rabatte mindern die Steuer, deshalb negatives Vorzeichen.
     */
    public function getTaxAmount(): Money {
        if ($this->taxPercent === null) {
            return Money::zero($this->amount->getCurrency());
        }

        $tax = $this->amount->percentage($this->taxPercent);

        return $this->chargeIndicator ? $tax : $tax->negated();
    }

    /**
     * Creates a document-level discount (Rabatt).
     */
    public static function discount(
        Money $amount,
        string $reason = 'Discount',
        ?AllowanceChargeReasonCode $reasonCode = AllowanceChargeReasonCode::DISCOUNT,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        return new self(
            chargeIndicator: false,
            amount: $amount,
            reasonCode: $reasonCode,
            reason: $reason,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }

    /**
     * Creates a percentage-based discount.
     */
    public static function percentageDiscount(
        Money $baseAmount,
        float $percentage,
        string $reason = 'Discount',
        ?AllowanceChargeReasonCode $reasonCode = AllowanceChargeReasonCode::DISCOUNT,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        return new self(
            chargeIndicator: false,
            amount: $baseAmount->percentage($percentage),
            reasonCode: $reasonCode,
            reason: $reason,
            baseAmount: $baseAmount,
            percentage: $percentage,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }

    /**
     * Creates a document-level surcharge (Zuschlag).
     */
    public static function surcharge(
        Money $amount,
        string $reason,
        ?AllowanceChargeReasonCode $reasonCode = null,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        return new self(
            chargeIndicator: true,
            amount: $amount,
            reasonCode: $reasonCode,
            reason: $reason,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }

    /**
     * Creates a shipping/freight charge.
     */
    public static function shipping(
        Money $amount,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        return new self(
            chargeIndicator: true,
            amount: $amount,
            reasonCode: AllowanceChargeReasonCode::FREIGHT,
            reason: 'Versandkosten',
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }

    /**
     * Creates a packing charge.
     */
    public static function packing(
        Money $amount,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        return new self(
            chargeIndicator: true,
            amount: $amount,
            reasonCode: AllowanceChargeReasonCode::PACKING,
            reason: 'Verpackungskosten',
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }
}
