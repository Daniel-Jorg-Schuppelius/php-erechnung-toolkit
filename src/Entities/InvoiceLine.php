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

use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Enums\UnitCode;

/**
 * Invoice Line for E-Rechnung (EN 16931).
 * 
 * Represents a single line item in the invoice.
 * 
 * @package ERechnungToolkit\Entities
 */
final class InvoiceLine {
    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    public function __construct(
        private string $id,
        private float $quantity,
        private UnitCode|string $unitCode,
        private float $netAmount,
        private string $itemName,
        private float $unitPrice,
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

    public function getNetAmount(): float {
        return $this->netAmount;
    }

    public function getItemName(): string {
        return $this->itemName;
    }

    public function getUnitPrice(): float {
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
    public function getGrossAmount(): float {
        return round($this->netAmount * (1 + $this->taxPercent / 100), 2);
    }

    /**
     * Calculates the tax amount for this line.
     */
    public function getTaxAmount(): float {
        return round($this->netAmount * $this->taxPercent / 100, 2);
    }

    /**
     * Returns the total allowances for this line.
     */
    public function getTotalAllowances(): float {
        return array_reduce(
            array_filter($this->allowanceCharges, fn(AllowanceCharge $ac) => !$ac->isCharge()),
            fn(float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
            0.0
        );
    }

    /**
     * Returns the total charges for this line.
     */
    public function getTotalCharges(): float {
        return array_reduce(
            array_filter($this->allowanceCharges, fn(AllowanceCharge $ac) => $ac->isCharge()),
            fn(float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
            0.0
        );
    }

    /**
     * Creates a simple invoice line.
     */
    public static function create(
        string $id,
        string $itemName,
        float $quantity,
        float $unitPrice,
        float $taxPercent = 19.0,
        UnitCode $unitCode = UnitCode::PIECE,
        TaxCategory $taxCategory = TaxCategory::STANDARD
    ): self {
        $netAmount = round($quantity * $unitPrice, 2);

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
        float $hourlyRate,
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
        float $amount,
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
