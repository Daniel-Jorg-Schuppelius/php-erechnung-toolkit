<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebUpComponent.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * One share of a broken down unit price as defined by the client in the bill of
 * quantity header (GAEB `LblUPComp1` to `LblUPComp6`). Up to six shares are
 * allowed; their categories match form 223 of the German VHB (wages, material,
 * equipment, other).
 */
final class GaebUpComponent {
    public function __construct(
        private readonly int $no,
        private readonly ?string $label = null,
        private readonly ?string $category = null
    ) {}

    /** Position 1-6 of the share; matches the `UPComp<n>` element on an item. */
    public function getNo(): int {
        return $this->no;
    }

    public function getLabel(): ?string {
        return $this->label;
    }

    /** Category as transported in the `Type` attribute, e.g. `Wages`. */
    public function getCategory(): ?string {
        return $this->category;
    }
}
