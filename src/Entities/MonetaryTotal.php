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
use CommonToolkit\ValueObjects\Money;

/**
 * Monetary Total for E-Rechnung (EN 16931).
 *
 * Represents the monetary totals of the invoice. Alle Beträge sind {@see Money};
 * die Belegwährung ergibt sich aus dem Zahlbetrag — kein getrenntes Feld mehr.
 */
final class MonetaryTotal {
    private readonly Money $allowanceTotalAmount;

    private readonly Money $chargeTotalAmount;

    private readonly Money $prepaidAmount;

    private readonly Money $payableRoundingAmount;

    public function __construct(
        private Money $lineExtensionAmount,
        private Money $taxExclusiveAmount,
        private Money $taxInclusiveAmount,
        private Money $payableAmount,
        ?Money $allowanceTotalAmount = null,
        ?Money $chargeTotalAmount = null,
        ?Money $prepaidAmount = null,
        ?Money $payableRoundingAmount = null
    ) {
        $zero = Money::zero($payableAmount->getCurrency());
        $this->allowanceTotalAmount = $allowanceTotalAmount ?? $zero;
        $this->chargeTotalAmount = $chargeTotalAmount ?? $zero;
        $this->prepaidAmount = $prepaidAmount ?? $zero;
        $this->payableRoundingAmount = $payableRoundingAmount ?? $zero;
    }

    /**
     * Sum of all invoice line net amounts.
     */
    public function getLineExtensionAmount(): Money {
        return $this->lineExtensionAmount;
    }

    /**
     * Total amount without VAT.
     */
    public function getTaxExclusiveAmount(): Money {
        return $this->taxExclusiveAmount;
    }

    /**
     * Total amount including VAT.
     */
    public function getTaxInclusiveAmount(): Money {
        return $this->taxInclusiveAmount;
    }

    /**
     * Amount to be paid.
     */
    public function getPayableAmount(): Money {
        return $this->payableAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->payableAmount->getCurrency();
    }

    /**
     * Sum of all document level allowances.
     */
    public function getAllowanceTotalAmount(): Money {
        return $this->allowanceTotalAmount;
    }

    /**
     * Sum of all document level charges.
     */
    public function getChargeTotalAmount(): Money {
        return $this->chargeTotalAmount;
    }

    /**
     * Amount already paid (prepayments, deposits).
     */
    public function getPrepaidAmount(): Money {
        return $this->prepaidAmount;
    }

    /**
     * Rounding amount for the payable amount.
     */
    public function getPayableRoundingAmount(): Money {
        return $this->payableRoundingAmount;
    }

    /**
     * Returns the calculated tax amount.
     */
    public function getTaxAmount(): Money {
        return $this->taxInclusiveAmount->minus($this->taxExclusiveAmount);
    }

    /**
     * Returns the outstanding amount (payable minus prepaid).
     */
    public function getOutstandingAmount(): Money {
        return $this->payableAmount->minus($this->prepaidAmount);
    }

    /**
     * Calculates totals from invoice lines and document level allowances/charges.
     *
     * @param InvoiceLine[] $lines
     * @param AllowanceCharge[] $allowanceCharges
     */
    public static function calculate(
        array $lines,
        array $allowanceCharges,
        TaxTotal $taxTotal,
        CurrencyCode $currency,
        ?Money $prepaidAmount = null
    ): self {
        $prepaidAmount ??= Money::zero($currency);

        // Sum of line net amounts
        $lineExtensionAmount = Money::sum(
            array_map(fn (InvoiceLine $line): Money => $line->getNetAmount(), $lines),
            $currency
        );

        // Document level allowances
        $allowanceTotalAmount = Money::sum(
            array_map(
                fn (AllowanceCharge $ac): Money => $ac->getAmount(),
                array_filter($allowanceCharges, fn (AllowanceCharge $ac): bool => $ac->isAllowance())
            ),
            $currency
        );

        // Document level charges
        $chargeTotalAmount = Money::sum(
            array_map(
                fn (AllowanceCharge $ac): Money => $ac->getAmount(),
                array_filter($allowanceCharges, fn (AllowanceCharge $ac): bool => $ac->isCharge())
            ),
            $currency
        );

        // Tax exclusive = lines - allowances + charges
        $taxExclusiveAmount = $lineExtensionAmount->minus($allowanceTotalAmount)->plus($chargeTotalAmount);

        // Tax inclusive = tax exclusive + tax
        $taxInclusiveAmount = $taxExclusiveAmount->plus($taxTotal->getTaxAmount());

        return new self(
            lineExtensionAmount: $lineExtensionAmount,
            taxExclusiveAmount: $taxExclusiveAmount,
            taxInclusiveAmount: $taxInclusiveAmount,
            payableAmount: $taxInclusiveAmount->minus($prepaidAmount),
            allowanceTotalAmount: $allowanceTotalAmount,
            chargeTotalAmount: $chargeTotalAmount,
            prepaidAmount: $prepaidAmount
        );
    }

    /**
     * Creates a simple monetary total.
     */
    public static function simple(Money $netAmount, Money $taxAmount): self {
        $grossAmount = $netAmount->plus($taxAmount);

        return new self(
            lineExtensionAmount: $netAmount,
            taxExclusiveAmount: $netAmount,
            taxInclusiveAmount: $grossAmount,
            payableAmount: $grossAmount
        );
    }
}
