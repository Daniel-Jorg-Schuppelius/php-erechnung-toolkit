<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : MonetaryTotal.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;

/**
 * Monetary Total for E-Rechnung (EN 16931).
 * 
 * Represents the monetary totals of the invoice.
 * 
 * @package ERechnungToolkit\Entities
 */
final class MonetaryTotal {
    public function __construct(
        private float $lineExtensionAmount,
        private float $taxExclusiveAmount,
        private float $taxInclusiveAmount,
        private float $payableAmount,
        private CurrencyCode|string $currency,
        private float $allowanceTotalAmount = 0.0,
        private float $chargeTotalAmount = 0.0,
        private float $prepaidAmount = 0.0,
        private float $payableRoundingAmount = 0.0
    ) {
        if (is_string($this->currency)) {
            $this->currency = CurrencyCode::fromSymbol($this->currency) ?? CurrencyCode::from($this->currency);
        }
    }

    /**
     * Sum of all invoice line net amounts.
     */
    public function getLineExtensionAmount(): float {
        return $this->lineExtensionAmount;
    }

    /**
     * Total amount without VAT.
     */
    public function getTaxExclusiveAmount(): float {
        return $this->taxExclusiveAmount;
    }

    /**
     * Total amount including VAT.
     */
    public function getTaxInclusiveAmount(): float {
        return $this->taxInclusiveAmount;
    }

    /**
     * Amount to be paid.
     */
    public function getPayableAmount(): float {
        return $this->payableAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    /**
     * Sum of all document level allowances.
     */
    public function getAllowanceTotalAmount(): float {
        return $this->allowanceTotalAmount;
    }

    /**
     * Sum of all document level charges.
     */
    public function getChargeTotalAmount(): float {
        return $this->chargeTotalAmount;
    }

    /**
     * Amount already paid (prepayments, deposits).
     */
    public function getPrepaidAmount(): float {
        return $this->prepaidAmount;
    }

    /**
     * Rounding amount for the payable amount.
     */
    public function getPayableRoundingAmount(): float {
        return $this->payableRoundingAmount;
    }

    /**
     * Returns the calculated tax amount.
     */
    public function getTaxAmount(): float {
        return round($this->taxInclusiveAmount - $this->taxExclusiveAmount, 2);
    }

    /**
     * Returns the outstanding amount (payable minus prepaid).
     */
    public function getOutstandingAmount(): float {
        return round($this->payableAmount - $this->prepaidAmount, 2);
    }

    /**
     * Calculates totals from invoice lines and document level allowances/charges.
     * 
     * @param InvoiceLine[] $lines
     * @param AllowanceCharge[] $allowanceCharges
     * @param TaxTotal $taxTotal
     */
    public static function calculate(
        array $lines,
        array $allowanceCharges,
        TaxTotal $taxTotal,
        CurrencyCode $currency,
        float $prepaidAmount = 0.0
    ): self {
        // Sum of line net amounts
        $lineExtensionAmount = array_reduce(
            $lines,
            fn(float $sum, InvoiceLine $line) => $sum + $line->getNetAmount(),
            0.0
        );

        // Document level allowances
        $allowanceTotalAmount = array_reduce(
            array_filter($allowanceCharges, fn(AllowanceCharge $ac) => $ac->isAllowance()),
            fn(float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
            0.0
        );

        // Document level charges
        $chargeTotalAmount = array_reduce(
            array_filter($allowanceCharges, fn(AllowanceCharge $ac) => $ac->isCharge()),
            fn(float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
            0.0
        );

        // Tax exclusive = lines - allowances + charges
        $taxExclusiveAmount = round(
            $lineExtensionAmount - $allowanceTotalAmount + $chargeTotalAmount,
            2
        );

        // Tax inclusive = tax exclusive + tax
        $taxInclusiveAmount = round(
            $taxExclusiveAmount + $taxTotal->getTaxAmount(),
            2
        );

        // Payable = tax inclusive - prepaid
        $payableAmount = round($taxInclusiveAmount - $prepaidAmount, 2);

        return new self(
            lineExtensionAmount: round($lineExtensionAmount, 2),
            taxExclusiveAmount: $taxExclusiveAmount,
            taxInclusiveAmount: $taxInclusiveAmount,
            payableAmount: $payableAmount,
            currency: $currency,
            allowanceTotalAmount: round($allowanceTotalAmount, 2),
            chargeTotalAmount: round($chargeTotalAmount, 2),
            prepaidAmount: $prepaidAmount
        );
    }

    /**
     * Creates a simple monetary total.
     */
    public static function simple(
        float $netAmount,
        float $taxAmount,
        CurrencyCode $currency
    ): self {
        $grossAmount = round($netAmount + $taxAmount, 2);

        return new self(
            lineExtensionAmount: $netAmount,
            taxExclusiveAmount: $netAmount,
            taxInclusiveAmount: $grossAmount,
            payableAmount: $grossAmount,
            currency: $currency
        );
    }
}
