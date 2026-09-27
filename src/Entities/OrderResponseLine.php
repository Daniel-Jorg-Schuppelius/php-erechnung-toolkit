<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderResponseLine.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Enums\UnitCode;

/**
 * Line of an order response: what the supplier confirms for one order line
 * (quantity, price, delivery date), referenced by the order's line id.
 */
final class OrderResponseLine {
    private UnitCode $unitCode;

    public function __construct(
        private string $lineId,
        private float $quantity,
        UnitCode|string $unitCode,
        private string $itemName = '',
        private ?Money $unitPrice = null,
        private ?string $sellersItemId = null,
        private ?string $buyersItemId = null,
        private ?DateTimeImmutable $deliveryDate = null,
        private ?string $note = null
    ) {
        $this->unitCode = is_string($unitCode)
            ? (UnitCode::tryFrom($unitCode) ?? UnitCode::PIECE)
            : $unitCode;
    }

    public function getLineId(): string {
        return $this->lineId;
    }

    public function getQuantity(): float {
        return $this->quantity;
    }

    public function getUnitCode(): UnitCode {
        return $this->unitCode;
    }

    public function getItemName(): string {
        return $this->itemName;
    }

    public function getUnitPrice(): ?Money {
        return $this->unitPrice;
    }

    public function getSellersItemId(): ?string {
        return $this->sellersItemId;
    }

    public function getBuyersItemId(): ?string {
        return $this->buyersItemId;
    }

    public function getDeliveryDate(): ?DateTimeImmutable {
        return $this->deliveryDate;
    }

    public function getNote(): ?string {
        return $this->note;
    }
}
