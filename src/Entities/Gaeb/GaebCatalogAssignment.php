<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCatalogAssignment.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * One catalogue assignment (GAEB `CtlgAssign`).
 *
 * This is the mechanism GAEB uses for every piece of side information a line
 * can carry: cost group after DIN 276, work category, building, cost unit and
 * the global identifiers that link an item to a BIM model. It sits on items,
 * groups and quantity splits alike.
 */
final class GaebCatalogAssignment {
    public function __construct(
        private readonly string $catalogId,
        private readonly string $code,
        private readonly ?string $quantity = null
    ) {}

    /** Id of the catalogue this assignment points to (`CtlgID`). */
    public function getCatalogId(): string {
        return $this->catalogId;
    }

    /** Key inside the catalogue, e.g. the cost group `310` or a GUID. */
    public function getCode(): string {
        return $this->code;
    }

    /** Share of the item that falls to this assignment, if the catalogue allows one. */
    public function getQuantity(): ?string {
        return $this->quantity;
    }
}
