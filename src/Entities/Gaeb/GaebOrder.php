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

use ERechnungToolkit\Enums\GaebPhase;

/**
 * A GAEB trade document (X93 price inquiry through X97 order confirmation).
 *
 * The trade phases run alongside the award, not inside it: a contractor asks a
 * merchant for prices (X93), receives an offer (X94), orders (X96) and gets a
 * confirmation (X97). The document therefore has no bill of quantity but an
 * order with its own lines - and both sides identify themselves by their tax
 * and commercial register numbers, which trade law requires.
 *
 * Each phase names its own document number in `OrderInfo` (Lib 3.3,
 * tgOrderInfo): `InquiryNo` (X93), `OfferNo` (X94), `PartNo` (X96) and
 * `OrderConfNo` (X97); a reply repeats the numbers it refers to.
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
        private readonly ?string $orderNo = null,
        private readonly ?GaebPhase $phase = null,
        private readonly ?string $date = null,
    ) {}

    /**
     * Liefertermin des Auftrags (ISO). Ohne ihn ist eine Bestellung unbestimmt;
     * ein gelesener Beleg ohne `DeliveryDate` liefert eine leere Zeichenkette.
     */
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

    /** Bestellnummer (`PartNo`, vergeben in X96, in der X97 wiederholt). */
    public function getOrderNo(): ?string {
        return $this->orderNo;
    }

    /** Phase des gelesenen Belegs; beim Schreiben gilt die Phase des Generators. */
    public function getPhase(): ?GaebPhase {
        return $this->phase;
    }

    /** Belegdatum (`GAEBInfo/Date`) des gelesenen Belegs; beim Schreiben gilt `$date` des Generators. */
    public function getDate(): ?string {
        return $this->date;
    }

    public function getDeliveryAddressNote(): ?string {
        return $this->deliveryAddressNote;
    }

    public function getVatRate(): ?string {
        return $this->vatRate;
    }
}
