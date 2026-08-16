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
    public function __construct(
        private readonly string $reference,
        private readonly ?string $parentReference = null,
        private readonly ?string $label = null,
        private readonly int $position = 0
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
}
