<?php
/*
 * Created on   : Tue Aug 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostApproach.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * One cost approach of an item (GAEB X52) — what a single kind of cost
 * contributes to this item.
 *
 * The documented conversion is `KW = Qty × Value ÷ Performance`: the
 * performance divides the calculated cost. Where no performance is stated the
 * value stands for itself; dividing by an assumed one would silently change
 * the calculation.
 */
final class GaebCostApproach {
    public function __construct(
        private readonly string $costTypeKey,
        private readonly ?string $quantity = null,
        private readonly ?string $unit = null,
        private readonly ?string $performance = null,
        private readonly ?string $value = null,
    ) {}

    /** Refers to a {@see GaebCostType} by its key. */
    public function getCostTypeKey(): string {
        return $this->costTypeKey;
    }

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    /** Without a unit of its own the cost type's unit applies (schema note). */
    public function getUnit(): ?string {
        return $this->unit;
    }

    public function getPerformance(): ?string {
        return $this->performance;
    }

    public function getValue(): ?string {
        return $this->value;
    }
}
