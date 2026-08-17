<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebItem.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{GaebAlternativeBidStatus, GaebChangeOrderStatus, GaebItemType, GaebMarkupType};

/**
 * A single entry of a bill of quantity: a regular item, a markup item or a
 * remark. Amounts are {@see Money} - the unit price carries a tenth of a cent in
 * GAEB, which no float survives. Quantities and percentages stay strings so that
 * the scale of the source file is preserved as written.
 */
final class GaebItem {
    /**
     * @param list<GaebTextComplement> $textComplements
     * @param list<GaebSubDescription> $subDescriptions
     * @param list<Money>              $unitPriceComponents shares of the unit price, in the order of the header labels
     * @param list<GaebCatalogAssignment> $catalogAssignments cost group, work category, building, model identifier
     * @param list<GaebQuantitySplit>  $quantitySplits partial quantities, each with its own assignments
     * @param list<GaebTakeoffLine>    $takeoffLines quantity survey after REB-VB 23.003
     */
    public function __construct(
        private readonly string $reference,
        private readonly ?string $sectionReference = null,
        private readonly GaebItemType $type = GaebItemType::Standard,
        private readonly ?string $shortText = null,
        private readonly ?string $longText = null,
        private readonly ?string $quantity = null,
        private readonly ?string $unit = null,
        private readonly ?Money $unitPrice = null,
        private readonly ?Money $totalPrice = null,
        private readonly ?string $provisionKind = null,
        private readonly ?string $alternativeGroup = null,
        private readonly ?int $alternativeNo = null,
        private readonly ?GaebMarkupType $markupType = null,
        private readonly ?Money $markupBase = null,
        private readonly array $textComplements = [],
        private readonly array $subDescriptions = [],
        private readonly array $unitPriceComponents = [],
        private readonly ?string $changeOrderNo = null,
        private readonly ?GaebChangeOrderStatus $changeOrderStatus = null,
        private readonly bool $notOffered = false,
        private readonly bool $notApplicable = false,
        private readonly bool $quantityToBeDetermined = false,
        private readonly bool $hourlyItem = false,
        private readonly ?string $discountPercent = null,
        private readonly ?string $vatRate = null,
        private readonly ?string $bidderComment = null,
        private readonly ?GaebAlternativeBidStatus $alternativeBidStatus = null,
        private readonly ?string $externalId = null,
        private readonly int $position = 0,
        private readonly array $catalogAssignments = [],
        private readonly array $quantitySplits = [],
        private readonly array $takeoffLines = []
    ) {}

    /** Ordinal number including the index level, e.g. "001.001.0010.A". */
    public function getReference(): string {
        return $this->reference;
    }

    public function getSectionReference(): ?string {
        return $this->sectionReference;
    }

    public function getType(): GaebItemType {
        return $this->type;
    }

    public function getShortText(): ?string {
        return $this->shortText;
    }

    /** Long text; text complements appear as markers, see {@see GaebBoq::complementMarker()}. */
    public function getLongText(): ?string {
        return $this->longText;
    }

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    public function getUnit(): ?string {
        return $this->unit;
    }

    public function getUnitPrice(): ?Money {
        return $this->unitPrice;
    }

    public function getTotalPrice(): ?Money {
        return $this->totalPrice;
    }

    /** `WithTotal` or `WithoutTotal` for a provisional item. */
    public function getProvisionKind(): ?string {
        return $this->provisionKind;
    }

    /** GAEB `ALNGroupNo`: groups a base execution with its alternatives. */
    public function getAlternativeGroup(): ?string {
        return $this->alternativeGroup;
    }

    /** GAEB `ALNSerNo`: 0 marks the base execution. */
    public function getAlternativeNo(): ?int {
        return $this->alternativeNo;
    }

    /** How the markup applies, e.g. `AllInCat`. */
    /**
     * What the markup applies to. Without it a markup item is a percentage
     * without a base - the sum would be a guess.
     */
    public function getMarkupType(): ?GaebMarkupType {
        return $this->markupType;
    }

    /**
     * Sum the markup applies to (`ITMarkup`). Only the types that name their
     * base in the document carry it - for `AllInCat` it follows from the group
     * and is computed instead.
     */
    public function getMarkupBase(): ?Money {
        return $this->markupBase;
    }

    /** @return list<GaebTextComplement> */
    public function getTextComplements(): array {
        return $this->textComplements;
    }

    /** @return list<GaebSubDescription> */
    public function getSubDescriptions(): array {
        return $this->subDescriptions;
    }

    /**
     * Catalogue assignments of the item (`CtlgAssign`): cost group after
     * DIN 276, work category, building, cost unit, model identifier.
     *
     * @return list<GaebCatalogAssignment>
     */
    public function getCatalogAssignments(): array {
        return $this->catalogAssignments;
    }

    /**
     * Partial quantities. Where they exist, the assignments of the split beat
     * those of the item - one item can belong to several cost groups.
     *
     * @return list<GaebQuantitySplit>
     */
    public function getQuantitySplits(): array {
        return $this->quantitySplits;
    }

    /**
     * Quantity survey of the item (X31). The lines are the calculation behind
     * the quantity, not a copy of it.
     *
     * @return list<GaebTakeoffLine>
     */
    public function getTakeoffLines(): array {
        return $this->takeoffLines;
    }

    /** @return list<Money> */
    public function getUnitPriceComponents(): array {
        return $this->unitPriceComponents;
    }

    /**
     * Does the sum of the unit price shares match the unit price? Summed as
     * money, not as float: the shares are what the bidder is held to.
     *
     * @param string $tolerance absolute deviation still accepted, in the currency of the price
     */
    public function unitPriceComponentsAddUp(string $tolerance = '0.005'): bool {
        if ($this->unitPriceComponents === [] || $this->unitPrice === null) {
            return true;
        }

        $sum = Money::zero($this->unitPrice->getCurrency(), $this->unitPrice->getScale());
        foreach ($this->unitPriceComponents as $share) {
            $sum = $sum->plus($share);
        }

        $limit = Money::of($tolerance, $this->unitPrice->getCurrency(), $this->unitPrice->getScale());

        return $sum->minus($this->unitPrice)->abs()->compareTo($limit) <= 0;
    }

    /**
     * Addendum number (GAEB `CONo`). It is mandatory on addendum items, so its
     * presence is what marks an item as one - there is no separate flag.
     */
    public function getChangeOrderNo(): ?string {
        return $this->changeOrderNo;
    }

    /** Status of this addendum item; it outranks the status of the addendum. */
    public function getChangeOrderStatus(): ?GaebChangeOrderStatus {
        return $this->changeOrderStatus;
    }

    public function isAddendum(): bool {
        return $this->changeOrderNo !== null;
    }

    /**
     * Did the bidder decline this item (GAEB `NotOffered`)? A declined item
     * carries no unit price at all - which is a different statement from a
     * price of 0.00, and that one is transported (GAEB 3.3, unit prices).
     */
    public function isNotOffered(): bool {
        return $this->notOffered;
    }

    /** Item dropped out (GAEB `NotAppl`). */
    public function isNotApplicable(): bool {
        return $this->notApplicable;
    }

    /** Free quantity: the client asks the bidder to fill it in (GAEB `QtyTBD`). */
    public function hasFreeQuantity(): bool {
        return $this->quantityToBeDetermined;
    }

    /** Hourly work item (GAEB `HourIt`). */
    public function isHourlyItem(): bool {
        return $this->hourlyItem;
    }

    /** Discount on this item in percent (GAEB `DiscountPcnt`). */
    public function getDiscountPercent(): ?string {
        return $this->discountPercent;
    }

    /** VAT rate of the item in percent. */
    public function getVatRate(): ?string {
        return $this->vatRate;
    }

    /** Bidder comment; only allowed when the client enabled it in phase 83. */
    public function getBidderComment(): ?string {
        return $this->bidderComment;
    }

    public function getAlternativeBidStatus(): ?GaebAlternativeBidStatus {
        return $this->alternativeBidStatus;
    }

    /**
     * Is a unit price expected here? Declined items and dropped items carry
     * none - this is the rule ava-sign checks when reading a bid back in.
     */
    public function expectsUnitPrice(): bool {
        return !$this->notOffered && !$this->notApplicable && $this->type->isBillable();
    }

    public function getExternalId(): ?string {
        return $this->externalId;
    }

    public function getPosition(): int {
        return $this->position;
    }
}
