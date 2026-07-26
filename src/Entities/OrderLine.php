<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderLine.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{TaxCategory, UnitCode};

/**
 * Order Line for a UBL Order (Peppol BIS Order / XBestellung).
 *
 * Represents a single requested item (cac:OrderLine/cac:LineItem). Unlike an
 * invoice line, tax information is optional - an order states the requested
 * quantity and the expected price; the tax breakdown is settled on the invoice.
 */
final class OrderLine {
    public function __construct(
        private string $id,
        private float $quantity,
        private UnitCode|string $unitCode,
        private Money $netAmount,
        private string $itemName,
        private Money $unitPrice,
        private ?string $itemDescription = null,
        private ?string $sellersItemId = null,
        private ?string $buyersItemId = null,
        private ?string $standardItemId = null,
        private ?string $standardItemScheme = null,
        private ?TaxCategory $taxCategory = null,
        private ?float $taxPercent = null,
        private ?string $note = null,
        private ?float $baseQuantity = null,
        private ?bool $partialDeliveryAllowed = null
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

    public function getTaxCategory(): ?TaxCategory {
        return $this->taxCategory;
    }

    public function getTaxPercent(): ?float {
        return $this->taxPercent;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function getBaseQuantity(): ?float {
        return $this->baseQuantity;
    }

    public function getPartialDeliveryAllowed(): ?bool {
        return $this->partialDeliveryAllowed;
    }

    /**
     * Creates a simple order line; the net amount is derived from quantity and
     * unit price.
     */
    public static function create(
        string $id,
        string $itemName,
        float $quantity,
        Money $unitPrice,
        UnitCode $unitCode = UnitCode::PIECE,
        ?string $sellersItemId = null
    ): self {
        return new self(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $unitPrice->times($quantity),
            itemName: $itemName,
            unitPrice: $unitPrice,
            sellersItemId: $sellersItemId
        );
    }
}
