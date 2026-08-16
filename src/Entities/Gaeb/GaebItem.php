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

use ERechnungToolkit\Enums\GaebItemType;

/**
 * A single entry of a bill of quantity: a regular item, a markup item or a
 * remark. Numeric values stay strings so that no precision is lost between
 * parsing and writing; the consuming application decides how to store them.
 */
final class GaebItem {
    /**
     * @param list<GaebTextComplement> $textComplements
     * @param list<GaebSubDescription> $subDescriptions
     * @param list<string>             $unitPriceComponents shares of the unit price, in the order of the header labels
     */
    public function __construct(
        private readonly string $reference,
        private readonly ?string $sectionReference = null,
        private readonly GaebItemType $type = GaebItemType::Standard,
        private readonly ?string $shortText = null,
        private readonly ?string $longText = null,
        private readonly ?string $quantity = null,
        private readonly ?string $unit = null,
        private readonly ?string $unitPrice = null,
        private readonly ?string $totalPrice = null,
        private readonly ?string $provisionKind = null,
        private readonly ?string $alternativeGroup = null,
        private readonly ?int $alternativeNo = null,
        private readonly ?string $markupType = null,
        private readonly array $textComplements = [],
        private readonly array $subDescriptions = [],
        private readonly array $unitPriceComponents = [],
        private readonly bool $addendum = false,
        private readonly ?string $externalId = null,
        private readonly int $position = 0
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

    public function getUnitPrice(): ?string {
        return $this->unitPrice;
    }

    public function getTotalPrice(): ?string {
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
    public function getMarkupType(): ?string {
        return $this->markupType;
    }

    /** @return list<GaebTextComplement> */
    public function getTextComplements(): array {
        return $this->textComplements;
    }

    /** @return list<GaebSubDescription> */
    public function getSubDescriptions(): array {
        return $this->subDescriptions;
    }

    /** @return list<string> */
    public function getUnitPriceComponents(): array {
        return $this->unitPriceComponents;
    }

    /** Does the sum of the unit price shares match the unit price? */
    public function unitPriceComponentsAddUp(float $tolerance = 0.005): bool {
        if ($this->unitPriceComponents === [] || $this->unitPrice === null) {
            return true;
        }

        $sum = 0.0;
        foreach ($this->unitPriceComponents as $share) {
            $sum += (float) $share;
        }

        return abs($sum - (float) $this->unitPrice) <= $tolerance;
    }

    public function isAddendum(): bool {
        return $this->addendum;
    }

    public function getExternalId(): ?string {
        return $this->externalId;
    }

    public function getPosition(): int {
        return $this->position;
    }
}
