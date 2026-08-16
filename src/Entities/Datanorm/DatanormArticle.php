<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormArticle.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\{DatanormPriceIndicator, DatanormProcessingFlag};

/**
 * A DATANORM article: the DATANORM 5 A-record superset. In DATANORM 4 the
 * same information is split across the A-record (core) and B-record
 * (matchcode, EAN, packaging, …) — the parser merges both into this entity.
 *
 * `priceUnitAmount` is always the resolved number of units the price refers
 * to (1/10/100/1000/…), never the DATANORM 4 code.
 */
final class DatanormArticle {
    /** @var list<DatanormScalePrice> */
    private array $scalePrices = [];

    /** @var list<DatanormRawMaterialSurcharge> */
    private array $rawMaterialSurcharges = [];

    /** @var list<DatanormWorkTime> */
    private array $workTimes = [];

    /** @var list<string> resolved long/dimension text lines */
    private array $textLines = [];

    private ?string $matchcode = null;
    private ?string $altArticleNumber = null;
    private ?string $altArticleNumberCreator = null;
    private ?string $manufacturerNumber = null;
    private ?string $manufacturerNumberCreator = null;
    private ?string $manufacturerType = null;
    private ?string $ean = null;
    private ?string $graphicNumber = null;
    private int $minPackagingAmount = 1;
    private bool $hasPackagingAmount = false;
    private ?string $cataloguePage = null;
    private int $textFlag = 0;
    private ?string $longTextNumber = null;
    private ?string $costIndicator = null;
    private ?string $stockIndicator = null;
    private ?string $referenceNumber = null;
    private ?string $referenceNumberCreator = null;
    private ?string $vatIndicator = null;
    private ?string $copperWeightIndicator = null;
    private ?string $copperRawPrice = null;
    private ?string $copperWeight = null;

    public function __construct(
        private readonly string $articleNumber,
        private string $shortDescription1 = '',
        private string $shortDescription2 = '',
        private ?string $unit = null,
        private DatanormPriceIndicator $priceIndicator = DatanormPriceIndicator::ListPrice,
        private int $priceUnitAmount = 1,
        private ?Money $price = null,
        private ?string $discountGroup = null,
        private ?string $mainProductGroup = null,
        private ?string $productGroup = null,
        private DatanormProcessingFlag $processingFlag = DatanormProcessingFlag::New
    ) {}

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    public function getShortDescription1(): string {
        return $this->shortDescription1;
    }

    public function getShortDescription2(): string {
        return $this->shortDescription2;
    }

    /** Both short description lines joined (empty lines skipped). */
    public function getName(): string {
        return trim(implode(' ', array_filter([trim($this->shortDescription1), trim($this->shortDescription2)], static fn (string $part): bool => $part !== '')));
    }

    /** Unit of measure — ISO code in DATANORM 5 (PCE, MTR, …), free text in DATANORM 4. */
    public function getUnit(): ?string {
        return $this->unit;
    }

    public function getPriceIndicator(): DatanormPriceIndicator {
        return $this->priceIndicator;
    }

    /** Number of units the transferred price refers to (resolved, ≥ 1). */
    public function getPriceUnitAmount(): int {
        return $this->priceUnitAmount;
    }

    /** The transferred price (list or net depending on the indicator), null if none. */
    public function getPrice(): ?Money {
        return $this->price;
    }

    public function getDiscountGroup(): ?string {
        return $this->discountGroup;
    }

    public function getMainProductGroup(): ?string {
        return $this->mainProductGroup;
    }

    public function getProductGroup(): ?string {
        return $this->productGroup;
    }

    public function getProcessingFlag(): DatanormProcessingFlag {
        return $this->processingFlag;
    }

    public function getMatchcode(): ?string {
        return $this->matchcode;
    }

    public function setMatchcode(?string $matchcode): self {
        $this->matchcode = $matchcode;

        return $this;
    }

    public function getAltArticleNumber(): ?string {
        return $this->altArticleNumber;
    }

    public function setAltArticleNumber(?string $number, ?string $creator = null): self {
        $this->altArticleNumber = $number;
        $this->altArticleNumberCreator = $creator;

        return $this;
    }

    public function getAltArticleNumberCreator(): ?string {
        return $this->altArticleNumberCreator;
    }

    public function getManufacturerNumber(): ?string {
        return $this->manufacturerNumber;
    }

    public function setManufacturerNumber(?string $number, ?string $creator = null): self {
        $this->manufacturerNumber = $number;
        $this->manufacturerNumberCreator = $creator;

        return $this;
    }

    public function getManufacturerNumberCreator(): ?string {
        return $this->manufacturerNumberCreator;
    }

    public function getManufacturerType(): ?string {
        return $this->manufacturerType;
    }

    public function setManufacturerType(?string $type): self {
        $this->manufacturerType = $type;

        return $this;
    }

    /** EAN / GTIN. */
    public function getEan(): ?string {
        return $this->ean;
    }

    public function setEan(?string $ean): self {
        $this->ean = $ean;

        return $this;
    }

    /** Reference to G-records (DATANORM 5) or a picture file name (DATANORM 4 B-record). */
    public function getGraphicNumber(): ?string {
        return $this->graphicNumber;
    }

    public function setGraphicNumber(?string $graphicNumber): self {
        $this->graphicNumber = $graphicNumber;

        return $this;
    }

    /** Smallest delivery unit in units of measure (Mindestverpackungsmenge, default 1). */
    public function getMinPackagingAmount(): int {
        return $this->minPackagingAmount;
    }

    /**
     * Whether the packaging amount was explicitly transferred. Change records
     * omit unchanged fields, and an absent field is indistinguishable from the
     * default `1` by value — consumers applying delta semantics must only
     * touch stored packaging data when this returns true.
     */
    public function hasPackagingAmount(): bool {
        return $this->hasPackagingAmount;
    }

    public function setMinPackagingAmount(int $amount): self {
        $this->minPackagingAmount = max(1, $amount);
        $this->hasPackagingAmount = true;

        return $this;
    }

    public function getCataloguePage(): ?string {
        return $this->cataloguePage;
    }

    public function setCataloguePage(?string $page): self {
        $this->cataloguePage = $page;

        return $this;
    }

    /** Text flag 0-6: how short descriptions, long text and dimension text combine. */
    public function getTextFlag(): int {
        return $this->textFlag;
    }

    public function setTextFlag(int $flag): self {
        $this->textFlag = $flag;

        return $this;
    }

    public function getLongTextNumber(): ?string {
        return $this->longTextNumber;
    }

    public function setLongTextNumber(?string $number): self {
        $this->longTextNumber = $number;

        return $this;
    }

    public function getCostIndicator(): ?string {
        return $this->costIndicator;
    }

    public function setCostIndicator(?string $indicator): self {
        $this->costIndicator = $indicator;

        return $this;
    }

    public function getStockIndicator(): ?string {
        return $this->stockIndicator;
    }

    public function setStockIndicator(?string $indicator): self {
        $this->stockIndicator = $indicator;

        return $this;
    }

    public function getReferenceNumber(): ?string {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(?string $number, ?string $creator = null): self {
        $this->referenceNumber = $number;
        $this->referenceNumberCreator = $creator;

        return $this;
    }

    public function getReferenceNumberCreator(): ?string {
        return $this->referenceNumberCreator;
    }

    /** VAT indicator (DATANORM 5): empty/1 normal, 2 increased, 3 reduced. */
    public function getVatIndicator(): ?string {
        return $this->vatIndicator;
    }

    public function setVatIndicator(?string $indicator): self {
        $this->vatIndicator = $indicator;

        return $this;
    }

    public function getCopperWeightIndicator(): ?string {
        return $this->copperWeightIndicator;
    }

    public function getCopperRawPrice(): ?string {
        return $this->copperRawPrice;
    }

    public function getCopperWeight(): ?string {
        return $this->copperWeight;
    }

    public function setCopper(?string $weightIndicator, ?string $rawPrice, ?string $weight): self {
        $this->copperWeightIndicator = $weightIndicator;
        $this->copperRawPrice = $rawPrice;
        $this->copperWeight = $weight;

        return $this;
    }

    public function addScalePrice(DatanormScalePrice $scalePrice): void {
        $this->scalePrices[] = $scalePrice;
    }

    /** @return list<DatanormScalePrice> */
    public function getScalePrices(): array {
        return $this->scalePrices;
    }

    public function addRawMaterialSurcharge(DatanormRawMaterialSurcharge $surcharge): void {
        $this->rawMaterialSurcharges[] = $surcharge;
    }

    /** @return list<DatanormRawMaterialSurcharge> copper/… surcharges (Z-records) */
    public function getRawMaterialSurcharges(): array {
        return $this->rawMaterialSurcharges;
    }

    public function addWorkTime(DatanormWorkTime $workTime): void {
        $this->workTimes[] = $workTime;
    }

    /** @return list<DatanormWorkTime> labour times (C-ARBA records), in minutes */
    public function getWorkTimes(): array {
        return $this->workTimes;
    }

    public function addTextLine(string $line): void {
        $this->textLines[] = $line;
    }

    /** @return list<string> resolved long/dimension text lines in file order */
    public function getTextLines(): array {
        return $this->textLines;
    }

    /** Resolved long/dimension text as one string, null when no text exists. */
    public function getLongText(): ?string {
        if ($this->textLines === []) {
            return null;
        }
        $text = rtrim(implode("\n", array_map(rtrim(...), $this->textLines)));

        return $text !== '' ? $text : null;
    }
}
