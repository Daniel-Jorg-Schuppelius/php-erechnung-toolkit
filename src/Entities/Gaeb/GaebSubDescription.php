<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebSubDescription.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * Sub description of a leading description (GAEB `SubDescr`). Sub descriptions
 * carry their own quantity but never their own price.
 */
final class GaebSubDescription {
    public function __construct(
        private readonly ?string $no = null,
        private readonly ?string $quantity = null,
        private readonly ?string $unit = null
    ) {}

    public function getNo(): ?string {
        return $this->no;
    }

    public function getQuantity(): ?string {
        return $this->quantity;
    }

    public function getUnit(): ?string {
        return $this->unit;
    }
}
