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

use ERechnungToolkit\Enums\AllowanceChargeReasonCode;
use ERechnungToolkit\Enums\TaxCategory;

/**
 * Allowance/Charge for E-Rechnung (EN 16931).
 * 
 * Represents document or line level allowances (discounts) and charges (surcharges).
 * 
 * @package ERechnungToolkit\Entities
 */
final class AllowanceCharge {
    public function __construct(
        private bool $chargeIndicator,
        private float $amount,
        private ?AllowanceChargeReasonCode $reasonCode = null,
        private ?string $reason = null,
        private ?float $baseAmount = null,
        private ?float $percentage = null,
        private ?TaxCategory $taxCategory = null,
        private ?float $taxPercent = null
    ) {
    }

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

    public function getAmount(): float {
        return $this->amount;
    }

    public function getReasonCode(): ?AllowanceChargeReasonCode {
        return $this->reasonCode;
    }

    public function getReason(): ?string {
        return $this->reason ?? $this->reasonCode?->label();
    }

    public function getBaseAmount(): ?float {
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
     */
    public function getTaxAmount(): float {
        if ($this->taxPercent === null) {
            return 0.0;
        }
        $tax = round($this->amount * $this->taxPercent / 100, 2);
        return $this->chargeIndicator ? $tax : -$tax;
    }

    /**
     * Creates a document-level discount (Rabatt).
     */
    public static function discount(
        float $amount,
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
        float $baseAmount,
        float $percentage,
        string $reason = 'Discount',
        ?AllowanceChargeReasonCode $reasonCode = AllowanceChargeReasonCode::DISCOUNT,
        ?TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?float $taxPercent = 19.0
    ): self {
        $amount = round($baseAmount * $percentage / 100, 2);

        return new self(
            chargeIndicator: false,
            amount: $amount,
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
        float $amount,
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
        float $amount,
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
        float $amount,
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
