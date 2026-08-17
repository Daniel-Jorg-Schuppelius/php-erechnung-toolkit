<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebOrder.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A GAEB trade document (X93 price inquiry through X97 order confirmation).
 *
 * The trade phases run alongside the award, not inside it: a contractor asks a
 * merchant for prices (X93), receives an offer (X94), orders (X96) and gets a
 * confirmation (X97). The document therefore has no bill of quantity but an
 * order with its own lines - and both sides identify themselves by their tax
 * and commercial register numbers, which trade law requires.
 */
final class GaebOrder {
    /**
     * @param list<GaebOrderItem> $items
     */
    public function __construct(
        private readonly string $deliveryDate,
        private readonly array $items = [],
        private readonly ?GaebParty $supplier = null,
        private readonly ?string $supplierTaxNo = null,
        private readonly ?string $supplierRegisterNo = null,
        private readonly ?GaebParty $customer = null,
        private readonly ?string $inquiryNo = null,
        private readonly ?string $offerNo = null,
        private readonly ?string $orderConfirmationNo = null,
        private readonly ?string $deliveryAddressNote = null,
        private readonly ?string $vatRate = null,
    ) {}

    /** Liefertermin des Auftrags (ISO). Ohne ihn ist eine Bestellung unbestimmt. */
    public function getDeliveryDate(): string {
        return $this->deliveryDate;
    }

    /** @return list<GaebOrderItem> */
    public function getItems(): array {
        return $this->items;
    }

    public function getSupplier(): ?GaebParty {
        return $this->supplier;
    }

    /** Steuernummer des Lieferanten - im Handel Pflichtangabe. */
    public function getSupplierTaxNo(): ?string {
        return $this->supplierTaxNo;
    }

    /** Handelsregisternummer des Lieferanten. */
    public function getSupplierRegisterNo(): ?string {
        return $this->supplierRegisterNo;
    }

    public function getCustomer(): ?GaebParty {
        return $this->customer;
    }

    public function getInquiryNo(): ?string {
        return $this->inquiryNo;
    }

    public function getOfferNo(): ?string {
        return $this->offerNo;
    }

    public function getOrderConfirmationNo(): ?string {
        return $this->orderConfirmationNo;
    }

    public function getDeliveryAddressNote(): ?string {
        return $this->deliveryAddressNote;
    }

    public function getVatRate(): ?string {
        return $this->vatRate;
    }
}
