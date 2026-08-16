<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebSection.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A node of the bill of quantity hierarchy (GAEB `BoQCtgy`), for example a lot,
 * trade or title. The reference is the ordinal number built from the RNoPart
 * chain of all ancestors.
 */
final class GaebSection {
    /** @param list<GaebCatalogAssignment> $catalogAssignments */
    public function __construct(
        private readonly string $reference,
        private readonly ?string $parentReference = null,
        private readonly ?string $label = null,
        private readonly int $position = 0,
        private readonly ?GaebTotals $totals = null,
        private readonly ?string $externalId = null,
        private readonly array $catalogAssignments = []
    ) {}

    public function getReference(): string {
        return $this->reference;
    }

    public function getParentReference(): ?string {
        return $this->parentReference;
    }

    public function getLabel(): ?string {
        return $this->label;
    }

    public function getPosition(): int {
        return $this->position;
    }

    /**
     * Identifier of the group as it stood in the file (`xs:ID`, mandatory from
     * 3.3 on). Kept so that a re-export returns the very same identifier - it is
     * what links the group to a model or to another system.
     */
    public function getExternalId(): ?string {
        return $this->externalId;
    }

    /** @return list<GaebCatalogAssignment> */
    public function getCatalogAssignments(): array {
        return $this->catalogAssignments;
    }

    /** Sums of this section, including any discount given on it. */
    public function getTotals(): ?GaebTotals {
        return $this->totals;
    }
}
