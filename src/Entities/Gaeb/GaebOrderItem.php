<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebOrderItem.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;

/**
 * One line of a GAEB trade document (`OrderItem`).
 *
 * The trade phases do not carry a bill of quantity but an order: what is bought
 * is identified by article number rather than by an ordinal number, and the
 * delivery date belongs to the line, because a lorry load rarely arrives in one
 * go. Weight travels with it - freight is charged by it.
 */
final class GaebOrderItem {
    public function __construct(
        private readonly string $catalogArticleNo,
        private readonly ?string $description = null,
        private readonly ?string $quantity = null,
        private readonly ?string $unit = null,
        private readonly ?Money $price = null,
        private readonly ?string $deliveryDate = null,
        private readonly ?string $weight = null,
        private readonly ?string $weightUnit = null,
        private readonly ?string $supplierArticleNo = null,
        private readonly ?string $customerArticleNo = null,
        private readonly ?string $ean = null,
        private readonly ?string $vatRate = null,
    ) {}

    /** Artikelnummer aus dem Katalog des Lieferanten - die Pflichtkennung. */
    public function getCatalogArticleNo(): string {
        return $this->catalogArticleNo;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    public function getUnit(): ?string {
        return $this->unit;
    }

    public function getPrice(): ?Money {
        return $this->price;
    }

    public function getDeliveryDate(): ?string {
        return $this->deliveryDate;
    }

    public function getWeight(): ?string {
        return $this->weight;
    }

    /** Gewichtseinheit, z. B. `kg` oder `to`. */
    public function getWeightUnit(): ?string {
        return $this->weightUnit;
    }

    public function getSupplierArticleNo(): ?string {
        return $this->supplierArticleNo;
    }

    public function getCustomerArticleNo(): ?string {
        return $this->customerArticleNo;
    }

    public function getEan(): ?string {
        return $this->ean;
    }

    public function getVatRate(): ?string {
        return $this->vatRate;
    }
}
