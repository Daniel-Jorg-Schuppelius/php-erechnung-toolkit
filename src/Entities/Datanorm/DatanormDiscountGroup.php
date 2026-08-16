<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormDiscountGroup.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use ERechnungToolkit\Enums\DatanormDiscountKind;

/**
 * DATANORM R-record: a discount group (code, kind, value, label). Articles
 * reference the group by code; the value turns their list price into the net
 * purchase price.
 */
final class DatanormDiscountGroup {
    public function __construct(
        private readonly string $code,
        private readonly DatanormDiscountKind $kind,
        private readonly float $value,
        private readonly ?string $label = null
    ) {}

    public function getCode(): string {
        return $this->code;
    }

    public function getKind(): DatanormDiscountKind {
        return $this->kind;
    }

    /** Percent for Discount/Surcharge (20.0 = 20 %), factor for Factor (1.2). */
    public function getValue(): float {
        return $this->value;
    }

    public function getLabel(): ?string {
        return $this->label;
    }

    public function toDiscount(): DatanormDiscount {
        return new DatanormDiscount($this->kind, $this->value);
    }
}
