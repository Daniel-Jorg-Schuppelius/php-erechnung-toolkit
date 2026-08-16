<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormCatalog.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;
use ERechnungToolkit\Enums\{DatanormDataMark, DatanormVersion};

/**
 * The parsed content of one DATANORM file — header (V-record), optional
 * customer control record (K), articles, article deletions/renumberings,
 * product groups, discount groups, price changes, text blocks and graphic
 * references, plus non-fatal parser warnings.
 *
 * Which collections are filled depends on the file type (DATANORM.001,
 * DATANORM.WRG, DATANORM.RAB, DATPREIS.…).
 */
final class DatanormCatalog {
    /** @var list<DatanormArticle> */
    private array $articles = [];

    /** @var list<DatanormArticleChange> */
    private array $articleChanges = [];

    /** @var list<DatanormProductGroup> */
    private array $productGroups = [];

    /** @var array<string, DatanormDiscountGroup> code → group */
    private array $discountGroups = [];

    /** @var list<DatanormPriceChange> */
    private array $priceChanges = [];

    /** @var array<string, DatanormTextBlock> number → block */
    private array $textBlocks = [];

    /** @var list<DatanormGraphicReference> */
    private array $graphicReferences = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?DatanormCustomer $customer = null;
    private ?int $declaredLineCount = null;
    private ?string $footerRemark = null;

    public function __construct(
        private readonly DatanormVersion $version,
        private readonly ?DatanormDataMark $dataMark,
        private readonly ?DateTimeImmutable $creationDate,
        private readonly CurrencyCode $currency,
        private readonly ?string $description = null,
        private readonly ?string $copyright = null,
        private readonly ?string $creatorShortName = null,
        private readonly ?string $creatorName = null,
        private readonly ?string $creatorStreet = null,
        private readonly ?string $creatorCountry = null,
        private readonly ?string $creatorZip = null,
        private readonly ?string $creatorCity = null,
        private readonly ?string $infoText = null
    ) {}

    public function getVersion(): DatanormVersion {
        return $this->version;
    }

    /** DATANORM 5 Datenkennzeichen; null for DATANORM 4 headers. */
    public function getDataMark(): ?DatanormDataMark {
        return $this->dataMark;
    }

    public function getCreationDate(): ?DateTimeImmutable {
        return $this->creationDate;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    public function getCopyright(): ?string {
        return $this->copyright;
    }

    public function getCreatorShortName(): ?string {
        return $this->creatorShortName;
    }

    public function getCreatorName(): ?string {
        return $this->creatorName;
    }

    public function getCreatorStreet(): ?string {
        return $this->creatorStreet;
    }

    public function getCreatorCountry(): ?string {
        return $this->creatorCountry;
    }

    public function getCreatorZip(): ?string {
        return $this->creatorZip;
    }

    public function getCreatorCity(): ?string {
        return $this->creatorCity;
    }

    /** DATANORM 4 free header info text (all three lines joined). */
    public function getInfoText(): ?string {
        return $this->infoText;
    }

    public function setCustomer(?DatanormCustomer $customer): void {
        $this->customer = $customer;
    }

    public function getCustomer(): ?DatanormCustomer {
        return $this->customer;
    }

    public function addArticle(DatanormArticle $article): void {
        $this->articles[] = $article;
    }

    /** @return list<DatanormArticle> */
    public function getArticles(): array {
        return $this->articles;
    }

    public function addArticleChange(DatanormArticleChange $change): void {
        $this->articleChanges[] = $change;
    }

    /** @return list<DatanormArticleChange> */
    public function getArticleChanges(): array {
        return $this->articleChanges;
    }

    public function addProductGroup(DatanormProductGroup $group): void {
        $this->productGroups[] = $group;
    }

    /** @return list<DatanormProductGroup> */
    public function getProductGroups(): array {
        return $this->productGroups;
    }

    /** Resolves a product group label (group first, main group as fallback). */
    public function resolveProductGroupLabel(?string $mainGroup, ?string $group): ?string {
        $fallback = null;
        foreach ($this->productGroups as $candidate) {
            if ($mainGroup !== null && $candidate->getMainGroup() === $mainGroup) {
                if (($candidate->getGroup() ?? '') === ($group ?? '')) {
                    return $candidate->getLabel();
                }
                if ($candidate->isMainGroup()) {
                    $fallback = $candidate->getLabel();
                }
            }
        }

        return $fallback;
    }

    public function addDiscountGroup(DatanormDiscountGroup $group): void {
        $this->discountGroups[$group->getCode()] = $group;
    }

    /** @return array<string, DatanormDiscountGroup> code → group */
    public function getDiscountGroups(): array {
        return $this->discountGroups;
    }

    public function getDiscountGroup(string $code): ?DatanormDiscountGroup {
        return $this->discountGroups[$code] ?? null;
    }

    public function addPriceChange(DatanormPriceChange $priceChange): void {
        $this->priceChanges[] = $priceChange;
    }

    /** @return list<DatanormPriceChange> */
    public function getPriceChanges(): array {
        return $this->priceChanges;
    }

    public function addTextBlock(DatanormTextBlock $block): void {
        // DATANORM 4 keeps separate number ranges for long-text (T) and
        // insert-text (E) blocks, so the usage is part of the key; DATANORM 5
        // shares one range across usages.
        $this->textBlocks[$block->getNumber() . '#' . $block->getUsage()] = $block;
    }

    /** @return array<string, DatanormTextBlock> "number#usage" → block */
    public function getTextBlocks(): array {
        return $this->textBlocks;
    }

    /** Looks a block up by number, optionally restricted to a usage. */
    public function getTextBlock(string $number, ?int $usage = null): ?DatanormTextBlock {
        if ($usage !== null) {
            return $this->textBlocks[$number . '#' . $usage] ?? null;
        }
        foreach ($this->textBlocks as $block) {
            if ($block->getNumber() === $number) {
                return $block;
            }
        }

        return null;
    }

    public function addGraphicReference(DatanormGraphicReference $reference): void {
        $this->graphicReferences[] = $reference;
    }

    /** @return list<DatanormGraphicReference> */
    public function getGraphicReferences(): array {
        return $this->graphicReferences;
    }

    public function addWarning(string $warning): void {
        $this->warnings[] = $warning;
    }

    /** @return list<string> non-fatal parser warnings (unknown records, bad values) */
    public function getWarnings(): array {
        return $this->warnings;
    }

    /** Line count declared by the DATANORM 5 E-record (including V and E). */
    public function getDeclaredLineCount(): ?int {
        return $this->declaredLineCount;
    }

    public function setDeclaredLineCount(?int $count): void {
        $this->declaredLineCount = $count;
    }

    public function getFooterRemark(): ?string {
        return $this->footerRemark;
    }

    public function setFooterRemark(?string $remark): void {
        $this->footerRemark = $remark;
    }
}
