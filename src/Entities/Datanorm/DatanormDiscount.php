<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormDiscount.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use ERechnungToolkit\Enums\DatanormDiscountKind;

/**
 * A single DATANORM discount step: a percentage discount or surcharge, or a
 * multiplication factor. Value semantics: percent for `Discount`/`Surcharge`
 * (20.0 = 20 %), plain factor for `Factor` (0.9 = ×0.9).
 */
final class DatanormDiscount {
    public function __construct(
        private readonly DatanormDiscountKind $kind,
        private readonly float $value
    ) {}

    public function getKind(): DatanormDiscountKind {
        return $this->kind;
    }

    public function getValue(): float {
        return $this->value;
    }
}
