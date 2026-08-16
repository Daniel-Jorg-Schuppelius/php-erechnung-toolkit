<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebQuantitySplit.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A partial quantity of an item (GAEB `QtySplit`).
 *
 * The split is what carries the assignments when one item belongs to several
 * cost groups, buildings or model components: the documentation puts the BIM
 * identifier on the split, not on the item. Without it an assignment on a split
 * item is not incomplete but wrong.
 */
final class GaebQuantitySplit {
    /** @param list<GaebCatalogAssignment> $catalogAssignments */
    public function __construct(
        private readonly ?string $quantity = null,
        private readonly ?string $percent = null,
        private readonly array $catalogAssignments = []
    ) {}

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    /** Share in percent, the alternative to an absolute partial quantity. */
    public function getPercent(): ?string {
        return $this->percent;
    }

    /** @return list<GaebCatalogAssignment> */
    public function getCatalogAssignments(): array {
        return $this->catalogAssignments;
    }
}
