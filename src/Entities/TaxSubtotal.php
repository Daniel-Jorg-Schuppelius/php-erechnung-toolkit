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

use ERechnungToolkit\Enums\TaxCategory;

/**
 * Tax Subtotal for VAT breakdown (EN 16931).
 * 
 * Represents a single VAT category and rate combination in the tax breakdown.
 * 
 * @package ERechnungToolkit\Entities
 */
final class TaxSubtotal {
    public function __construct(
        private float $taxableAmount,
        private float $taxAmount,
        private TaxCategory $category,
        private float $percent,
        private ?string $exemptionReason = null,
        private ?string $exemptionReasonCode = null
    ) {
    }

    public function getTaxableAmount(): float {
        return $this->taxableAmount;
    }

    public function getTaxAmount(): float {
        return $this->taxAmount;
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
    public static function standard(float $taxableAmount, float $rate = 19.0): self {
        return new self(
            $taxableAmount,
            round($taxableAmount * $rate / 100, 2),
            TaxCategory::STANDARD,
            $rate
        );
    }

    /**
     * Creates a reduced rate subtotal (7% in Germany).
     */
    public static function reduced(float $taxableAmount, float $rate = 7.0): self {
        return new self(
            $taxableAmount,
            round($taxableAmount * $rate / 100, 2),
            TaxCategory::STANDARD,
            $rate
        );
    }

    /**
     * Creates a reverse charge subtotal.
     */
    public static function reverseCharge(float $taxableAmount, string $reason = 'Reverse Charge'): self {
        return new self(
            $taxableAmount,
            0.0,
            TaxCategory::REVERSE_CHARGE,
            0.0,
            $reason,
            'VATEX-EU-AE'
        );
    }

    /**
     * Creates an exempt subtotal.
     */
    public static function exempt(float $taxableAmount, string $reason, ?string $reasonCode = null): self {
        return new self(
            $taxableAmount,
            0.0,
            TaxCategory::EXEMPT,
            0.0,
            $reason,
            $reasonCode
        );
    }
}
