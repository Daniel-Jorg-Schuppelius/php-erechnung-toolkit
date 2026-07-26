<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchLine.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use ERechnungToolkit\Enums\UnitCode;

/**
 * Despatch line for a UBL Despatch Advice (Peppol BIS Despatch Advice).
 *
 * Represents a single delivered item (cac:DespatchLine). The delivered quantity
 * may differ from the ordered quantity; the optional order line reference links
 * back to the originating order (cac:OrderLineReference/cbc:LineID).
 */
final class DespatchLine {
    private UnitCode $unitCode;

    public function __construct(
        private string $id,
        private float $deliveredQuantity,
        UnitCode|string $unitCode,
        private string $itemName,
        private ?string $orderLineId = null,
        private ?string $itemDescription = null,
        private ?string $sellersItemId = null,
        private ?string $buyersItemId = null,
        private ?string $standardItemId = null,
        private ?string $standardItemScheme = null,
        private ?float $backorderQuantity = null,
        private ?string $backorderReason = null,
        private ?string $note = null
    ) {
        $this->unitCode = is_string($unitCode)
            ? (UnitCode::tryFrom($unitCode) ?? UnitCode::PIECE)
            : $unitCode;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getDeliveredQuantity(): float {
        return $this->deliveredQuantity;
    }

    public function getUnitCode(): UnitCode {
        return $this->unitCode;
    }

    public function getItemName(): string {
        return $this->itemName;
    }

    public function getOrderLineId(): ?string {
        return $this->orderLineId;
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

    public function getBackorderQuantity(): ?float {
        return $this->backorderQuantity;
    }

    public function getBackorderReason(): ?string {
        return $this->backorderReason;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    /**
     * Creates a simple despatch line.
     */
    public static function create(
        string $id,
        string $itemName,
        float $deliveredQuantity,
        UnitCode $unitCode = UnitCode::PIECE,
        ?string $orderLineId = null,
        ?string $sellersItemId = null
    ): self {
        return new self(
            id: $id,
            deliveredQuantity: $deliveredQuantity,
            unitCode: $unitCode,
            itemName: $itemName,
            orderLineId: $orderLineId,
            sellersItemId: $sellersItemId
        );
    }
}
