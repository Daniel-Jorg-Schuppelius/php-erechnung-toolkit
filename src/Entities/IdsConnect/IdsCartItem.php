<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsCartItem.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\IdsConnect;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{IdsItemCharacter, UnitCode};

/**
 * Position eines IDS-Warenkorbs (`OrderItem`).
 *
 * Preissemantik nach Spezifikation: `OfferPrice` ist der Brutto-/Listenpreis
 * je `PriceBasis` Einheiten, `NetPrice` der Einkaufspreis des Kunden für die
 * **ganze** Anfragemenge (Rabatte und Rohstoffanteile eingerechnet) — daher
 * {@see getNetUnitPrice()} für den Stückpreis.
 */
final class IdsCartItem {
    /** @param list<IdsRawMaterial> $rawMaterials */
    public function __construct(
        private readonly string $articleNumber,
        private readonly float $quantity,
        private readonly string $unit,
        private readonly IdsItemCharacter $itemCharacter = IdsItemCharacter::Normal,
        private readonly ?string $shortText = null,
        private readonly ?string $longText = null,
        private readonly ?string $ean = null,
        private readonly ?Money $offerPrice = null,
        private readonly ?Money $netPrice = null,
        private readonly ?float $priceBasis = null,
        private readonly ?float $vatPercent = null,
        private readonly ?float $surchargePercent = null,
        private readonly ?string $customerPosition = null,
        private readonly ?string $customerSubPosition = null,
        private readonly ?string $supplierPosition = null,
        private readonly ?string $supplierSubPosition = null,
        private readonly ?bool $technicalClarification = null,
        private readonly ?string $notice = null,
        private readonly ?int $errorCode = null,
        private readonly ?string $errorText = null,
        private readonly array $rawMaterials = [],
    ) {}

    /** Großhändlerartikelnummer (`ArtNo`). */
    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    public function getQuantity(): float {
        return $this->quantity;
    }

    /** Mengeneinheit nach IDS-Codeliste (`QU`), z. B. PCE, MTR, KGM. */
    public function getUnit(): string {
        return $this->unit;
    }

    /** Mengeneinheit als UN/ECE-Code; IDS `PCE` ist das Stück (C62). */
    public function getUnitCode(): ?UnitCode {
        return strtoupper($this->unit) === 'PCE' ? UnitCode::PIECE : UnitCode::tryFrom(strtoupper($this->unit));
    }

    public function getItemCharacter(): IdsItemCharacter {
        return $this->itemCharacter;
    }

    public function getShortText(): ?string {
        return $this->shortText;
    }

    public function getLongText(): ?string {
        return $this->longText;
    }

    public function getEan(): ?string {
        return $this->ean;
    }

    /** Brutto-/Listenpreis je {@see getPriceBasis()} Einheiten. */
    public function getOfferPrice(): ?Money {
        return $this->offerPrice;
    }

    /** Einkaufspreis für die ganze Anfragemenge. */
    public function getNetPrice(): ?Money {
        return $this->netPrice;
    }

    /** Einkaufspreis je Mengeneinheit: Nettopreis ÷ Menge, Skala 4. */
    public function getNetUnitPrice(): ?Money {
        if ($this->netPrice === null || $this->quantity <= 0.0) {
            return null;
        }

        return $this->netPrice->withScale(4)->dividedBy($this->quantity);
    }

    public function getPriceBasis(): ?float {
        return $this->priceBasis;
    }

    public function getVatPercent(): ?float {
        return $this->vatPercent;
    }

    /** Prozentualer Zuschlag; Rabatte kommen als negativer Zuschlag. */
    public function getSurchargePercent(): ?float {
        return $this->surchargePercent;
    }

    public function getCustomerPosition(): ?string {
        return $this->customerPosition;
    }

    public function getCustomerSubPosition(): ?string {
        return $this->customerSubPosition;
    }

    public function getSupplierPosition(): ?string {
        return $this->supplierPosition;
    }

    public function getSupplierSubPosition(): ?string {
        return $this->supplierSubPosition;
    }

    public function needsTechnicalClarification(): ?bool {
        return $this->technicalClarification;
    }

    /** Wichtiger Hinweis, der dem Anwender angezeigt werden muss. */
    public function getNotice(): ?string {
        return $this->notice;
    }

    public function getErrorCode(): ?int {
        return $this->errorCode;
    }

    public function getErrorText(): ?string {
        return $this->errorText;
    }

    public function hasError(): bool {
        return $this->errorCode !== null || ($this->errorText !== null && trim($this->errorText) !== '');
    }

    /** @return list<IdsRawMaterial> */
    public function getRawMaterials(): array {
        return $this->rawMaterials;
    }
}
