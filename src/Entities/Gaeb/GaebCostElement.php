<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostElement.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;

/**
 * One element of a cost determination (GAEB `CostElement`).
 *
 * Elements nest: a cost group holds sub-groups, those hold building elements.
 * Beside a single unit price an element may carry a **span** - lowest, average
 * and highest - which is what a cost estimate at an early planning stage
 * honestly is: a range, not a figure.
 */
final class GaebCostElement {
    /**
     * @param list<GaebCostElement> $children
     */
    public function __construct(
        private readonly string $description,
        private readonly string $unit,
        private readonly ?string $number = null,
        private readonly ?string $quantity = null,
        private readonly ?Money $unitPrice = null,
        private readonly ?Money $total = null,
        private readonly ?Money $unitPriceFrom = null,
        private readonly ?Money $unitPriceAverage = null,
        private readonly ?Money $unitPriceTo = null,
        private readonly array $children = [],
        private readonly ?string $remark = null,
        /** @var list<GaebCatalogAssignment> */
        private readonly array $catalogAssignments = [],
    ) {}

    public function getDescription(): string {
        return $this->description;
    }

    /** Bezugseinheit, z. B. `m2` BGF - ohne sie ist ein Kennwert wertlos. */
    public function getUnit(): string {
        return $this->unit;
    }

    /**
     * Elementbezeichner. In der Bauform `.2` steht er vollständig (`314`), in
     * der Bauform `.1` nur der Teil der eigenen Ebene.
     */
    public function getNumber(): ?string {
        return $this->number;
    }

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    public function getUnitPrice(): ?Money {
        return $this->unitPrice;
    }

    public function getTotal(): ?Money {
        return $this->total;
    }

    public function getUnitPriceFrom(): ?Money {
        return $this->unitPriceFrom;
    }

    public function getUnitPriceAverage(): ?Money {
        return $this->unitPriceAverage;
    }

    public function getUnitPriceTo(): ?Money {
        return $this->unitPriceTo;
    }

    /** @return list<GaebCostElement> */
    public function getChildren(): array {
        return $this->children;
    }

    public function getRemark(): ?string {
        return $this->remark;
    }

    /** @return list<GaebCatalogAssignment> */
    public function getCatalogAssignments(): array {
        return $this->catalogAssignments;
    }

    /** Does this element give a price span instead of one figure? */
    public function hasPriceRange(): bool {
        return $this->unitPriceFrom !== null || $this->unitPriceTo !== null;
    }
}
