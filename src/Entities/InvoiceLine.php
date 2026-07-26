<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InvoiceLine.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{TaxCategory, UnitCode};

/**
 * Invoice Line for E-Rechnung (EN 16931).
 *
 * Represents a single line item in the invoice. Beträge sind {@see Money},
 * Mengen und Prozentsätze bleiben skalar.
 */
final class InvoiceLine {
    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    public function __construct(
        private string $id,
        private float $quantity,
        private UnitCode|string $unitCode,
        private Money $netAmount,
        private string $itemName,
        private Money $unitPrice,
        private TaxCategory $taxCategory,
        private float $taxPercent,
        private ?string $itemDescription = null,
        private ?string $sellersItemId = null,
        private ?string $buyersItemId = null,
        private ?string $standardItemId = null,
        private ?string $standardItemScheme = null,
        private ?string $note = null,
        private ?float $baseQuantity = null,
        private ?string $accountingCost = null
    ) {
        if (is_string($this->unitCode)) {
            $this->unitCode = UnitCode::tryFrom($this->unitCode) ?? UnitCode::PIECE;
        }
    }

    public function getId(): string {
        return $this->id;
    }

    public function getQuantity(): float {
        return $this->quantity;
    }

    public function getUnitCode(): UnitCode {
        return $this->unitCode;
    }

    public function getNetAmount(): Money {
        return $this->netAmount;
    }

    public function getCurrency(): CurrencyCode {
        return $this->netAmount->getCurrency();
    }

    public function getItemName(): string {
        return $this->itemName;
    }

    public function getUnitPrice(): Money {
        return $this->unitPrice;
    }

    public function getTaxCategory(): TaxCategory {
        return $this->taxCategory;
    }

    public function getTaxPercent(): float {
        return $this->taxPercent;
    }

    public function getItemDescription(): ?string {
        return $this->itemDescription;
    }

    public function getSellersItemId(): ?string {
        return $this->sellersItemId;
    }

    public function getBuyersItemId(): ?string {
        return $this->buyersItemId;
    }

    public function getStandardItemId(): ?string {
        return $this->standardItemId;
    }

    public function getStandardItemScheme(): ?string {
        return $this->standardItemScheme;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function getBaseQuantity(): ?float {
        return $this->baseQuantity;
    }

    public function getAccountingCost(): ?string {
        return $this->accountingCost;
    }

    /**
     * @return AllowanceCharge[]
     */
    public function getAllowanceCharges(): array {
        return $this->allowanceCharges;
    }

    /**
     * Adds an allowance or charge to this line.
     */
    public function addAllowanceCharge(AllowanceCharge $allowanceCharge): void {
        $this->allowanceCharges[] = $allowanceCharge;
    }

    /**
     * Calculates the gross amount (net + tax).
     */
    public function getGrossAmount(): Money {
        return $this->netAmount->plus($this->getTaxAmount());
    }

    /**
     * Calculates the tax amount for this line.
     */
    public function getTaxAmount(): Money {
        return $this->netAmount->percentage($this->taxPercent);
    }

    /**
     * Returns the total allowances for this line.
     */
    public function getTotalAllowances(): Money {
        return Money::sum(
            array_map(
                fn (AllowanceCharge $ac): Money => $ac->getAmount(),
                array_filter($this->allowanceCharges, fn (AllowanceCharge $ac): bool => $ac->isAllowance())
            ),
            $this->netAmount->getCurrency()
        );
    }

    /**
     * Returns the total charges for this line.
     */
    public function getTotalCharges(): Money {
        return Money::sum(
            array_map(
                fn (AllowanceCharge $ac): Money => $ac->getAmount(),
                array_filter($this->allowanceCharges, fn (AllowanceCharge $ac): bool => $ac->isCharge())
            ),
            $this->netAmount->getCurrency()
        );
    }

    /**
     * Creates a simple invoice line.
     */
    public static function create(
        string $id,
        string $itemName,
        float $quantity,
        Money $unitPrice,
        float $taxPercent = 19.0,
        UnitCode $unitCode = UnitCode::PIECE,
        TaxCategory $taxCategory = TaxCategory::STANDARD
    ): self {
        $netAmount = $unitPrice->times($quantity);

        return new self(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $itemName,
            unitPrice: $unitPrice,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
    }

    /**
     * Creates a service line (hours/days based).
     */
    public static function service(
        string $id,
        string $description,
        float $hours,
        Money $hourlyRate,
        float $taxPercent = 19.0
    ): self {
        return self::create(
            $id,
            $description,
            $hours,
            $hourlyRate,
            $taxPercent,
            UnitCode::HOUR
        );
    }

    /**
     * Creates a lump sum line.
     */
    public static function lumpSum(
        string $id,
        string $description,
        Money $amount,
        float $taxPercent = 19.0
    ): self {
        return self::create(
            $id,
            $description,
            1.0,
            $amount,
            $taxPercent,
            UnitCode::LUMP_SUM
        );
    }
}
