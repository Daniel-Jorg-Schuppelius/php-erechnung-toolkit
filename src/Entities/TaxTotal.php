<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TaxTotal.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Enums\TaxCategory;

/**
 * Tax Total and Tax Subtotal for E-Rechnung (EN 16931).
 * 
 * Represents VAT breakdown information for the invoice.
 * 
 * @package ERechnungToolkit\Entities
 */
final class TaxTotal {
    /** @var TaxSubtotal[] */
    private array $subtotals = [];

    public function __construct(
        private float $taxAmount,
        private CurrencyCode|string $currency,
        array $subtotals = []
    ) {
        if (is_string($this->currency)) {
            $this->currency = CurrencyCode::fromSymbol($this->currency) ?? CurrencyCode::from($this->currency);
        }
        $this->subtotals = $subtotals;
    }

    public function getTaxAmount(): float {
        return $this->taxAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    /**
     * @return TaxSubtotal[]
     */
    public function getSubtotals(): array {
        return $this->subtotals;
    }

    public function addSubtotal(TaxSubtotal $subtotal): void {
        $this->subtotals[] = $subtotal;
        $this->recalculateTaxAmount();
    }

    /**
     * Recalculates the total tax amount from subtotals.
     */
    private function recalculateTaxAmount(): void {
        $this->taxAmount = array_reduce(
            $this->subtotals,
            fn(float $sum, TaxSubtotal $sub) => $sum + $sub->getTaxAmount(),
            0.0
        );
    }

    /**
     * Creates from subtotals.
     * 
     * @param TaxSubtotal[] $subtotals
     */
    public static function fromSubtotals(array $subtotals, CurrencyCode $currency): self {
        $taxAmount = array_reduce(
            $subtotals,
            fn(float $sum, TaxSubtotal $sub) => $sum + $sub->getTaxAmount(),
            0.0
        );

        return new self($taxAmount, $currency, $subtotals);
    }

    /**
     * Creates a simple tax total with a single rate.
     */
    public static function simple(
        float $taxableAmount,
        float $taxRate,
        CurrencyCode $currency,
        TaxCategory $category = TaxCategory::STANDARD
    ): self {
        $taxAmount = round($taxableAmount * $taxRate / 100, 2);
        $subtotal = new TaxSubtotal($taxableAmount, $taxAmount, $category, $taxRate);

        return new self($taxAmount, $currency, [$subtotal]);
    }
}
