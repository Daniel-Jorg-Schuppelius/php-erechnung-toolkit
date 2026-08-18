<?php
/*
 * Created on   : Tue Aug 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A main cost type of the calculation data exchange (GAEB X52).
 *
 * The key is the calculating system's own — GAEB prescribes no catalogue here.
 * `Markup` is the percentage added on top of the direct costs; it belongs to
 * the type, not to the individual approach, because a company surcharges by
 * kind of cost, not by item.
 */
final class GaebCostType {
    public function __construct(
        private readonly string $key,
        private readonly ?string $description = null,
        private readonly ?string $unit = null,
        private readonly ?string $markup = null,
    ) {}

    public function getKey(): string {
        return $this->key;
    }

    public function getDescription(): ?string {
        return $this->description;
    }

    /** Unit of the cost type — hours for labour, kilograms for material. */
    public function getUnit(): ?string {
        return $this->unit;
    }

    /** Surcharge in percent, as a decimal string. */
    public function getMarkup(): ?string {
        return $this->markup;
    }
}
