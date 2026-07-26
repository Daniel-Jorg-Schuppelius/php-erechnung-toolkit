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
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\TaxCategory;

/**
 * Tax Total and Tax Subtotal for E-Rechnung (EN 16931).
 *
 * Represents VAT breakdown information for the invoice. Die Währung ergibt sich
 * aus dem Steuerbetrag ({@see Money}) — kein getrenntes Währungsfeld mehr.
 *
 * @param TaxSubtotal[] $subtotals
 */
final class TaxTotal {
    /** @var TaxSubtotal[] */
    private array $subtotals = [];

    public function __construct(
        private Money $taxAmount,
        array $subtotals = []
    ) {
        $this->subtotals = $subtotals;
    }

    public function getTaxAmount(): Money {
        return $this->taxAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->taxAmount->getCurrency();
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
        $this->taxAmount = Money::sum(
            array_map(fn (TaxSubtotal $sub): Money => $sub->getTaxAmount(), $this->subtotals),
            $this->taxAmount->getCurrency()
        );
    }

    /**
     * Creates from subtotals.
     *
     * @param TaxSubtotal[] $subtotals
     */
    public static function fromSubtotals(array $subtotals, CurrencyCode $currency): self {
        $taxAmount = Money::sum(
            array_map(fn (TaxSubtotal $sub): Money => $sub->getTaxAmount(), $subtotals),
            $currency
        );

        return new self($taxAmount, $subtotals);
    }

    /**
     * Creates a simple tax total with a single rate.
     */
    public static function simple(
        Money $taxableAmount,
        float $taxRate,
        TaxCategory $category = TaxCategory::STANDARD
    ): self {
        $taxAmount = $taxableAmount->percentage($taxRate);
        $subtotal = new TaxSubtotal($taxableAmount, $taxAmount, $category, $taxRate);

        return new self($taxAmount, [$subtotal]);
    }
}
