<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsCart.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\IdsConnect;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;
use ERechnungToolkit\Enums\IdsReturnFlag;

/**
 * IDS-Connect-Warenkorb (`Warenkorb`): Kopf (`WarenkorbInfo`, `OrderInfo`),
 * Lieferanten-, Kunden- und Lieferadresse sowie die Positionen.
 * Die Struktur lehnt sich an GAEB XML 3.1 an (Spezifikation ITEK/BVBS).
 */
final class IdsCart {
    /**
     * @param list<IdsCartItem> $items
     * @param list<string> $warnings Hinweise des Parsers, etwa übersprungene Positionen
     */
    public function __construct(
        private readonly array $items,
        private readonly CurrencyCode $currency = CurrencyCode::Euro,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?IdsReturnFlag $returnFlag = null,
        private readonly string $version = '2.5',
        private readonly ?string $inquiryNumber = null,
        private readonly ?string $offerNumber = null,
        private readonly ?string $orderNumber = null,
        private readonly ?string $orderConfirmationNumber = null,
        private readonly ?int $deliveryWeek = null,
        private readonly ?int $deliveryYear = null,
        private readonly ?DateTimeImmutable $deliveryDate = null,
        private readonly ?string $modeOfShipment = null,
        private readonly ?string $additionalText = null,
        private readonly ?string $commission = null,
        private readonly ?string $supplierNumber = null,
        private readonly ?IdsAddress $supplierAddress = null,
        private readonly ?string $customerNumber = null,
        private readonly ?IdsAddress $customerAddress = null,
        private readonly ?IdsAddress $deliveryAddress = null,
        private readonly array $warnings = [],
    ) {}

    /** @return list<IdsCartItem> */
    public function getItems(): array {
        return $this->items;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    /** Nachrichtendatum und -uhrzeit (`WarenkorbInfo/Date` + `Time`). */
    public function getCreatedAt(): ?DateTimeImmutable {
        return $this->createdAt;
    }

    public function getReturnFlag(): ?IdsReturnFlag {
        return $this->returnFlag;
    }

    /** Im Shop wurde bereits bestellt. */
    public function isOrdered(): bool {
        return $this->returnFlag?->isOrdered() ?? false;
    }

    public function getVersion(): string {
        return $this->version;
    }

    /** Anfragenummer der Handwerkssoftware (`InquiryNo`). */
    public function getInquiryNumber(): ?string {
        return $this->inquiryNumber;
    }

    /** Angebotsnummer des Großhandels (`OfferNo`). */
    public function getOfferNumber(): ?string {
        return $this->offerNumber;
    }

    /** Bestellnummer der Handwerkssoftware (`PartNo`). */
    public function getOrderNumber(): ?string {
        return $this->orderNumber;
    }

    /** Auftragsbestätigungsnummer des Großhandels (`OrderConfNo`). */
    public function getOrderConfirmationNumber(): ?string {
        return $this->orderConfirmationNumber;
    }

    public function getDeliveryWeek(): ?int {
        return $this->deliveryWeek;
    }

    public function getDeliveryYear(): ?int {
        return $this->deliveryYear;
    }

    public function getDeliveryDate(): ?DateTimeImmutable {
        return $this->deliveryDate;
    }

    /** Versandart: „Lieferung“ oder „Abholung“. */
    public function getModeOfShipment(): ?string {
        return $this->modeOfShipment;
    }

    public function getAdditionalText(): ?string {
        return $this->additionalText;
    }

    public function getCommission(): ?string {
        return $this->commission;
    }

    /** Lieferantennummer beim Handwerker (`SupplierInfo/IDNo`). */
    public function getSupplierNumber(): ?string {
        return $this->supplierNumber;
    }

    public function getSupplierAddress(): ?IdsAddress {
        return $this->supplierAddress;
    }

    /** Kundennummer des Handwerkers beim Lieferanten (`CustomerInfo/IDNo`). */
    public function getCustomerNumber(): ?string {
        return $this->customerNumber;
    }

    public function getCustomerAddress(): ?IdsAddress {
        return $this->customerAddress;
    }

    public function getDeliveryAddress(): ?IdsAddress {
        return $this->deliveryAddress;
    }

    /** @return list<string> */
    public function getWarnings(): array {
        return $this->warnings;
    }
}
