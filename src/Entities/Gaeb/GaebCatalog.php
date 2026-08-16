<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCatalog.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use ERechnungToolkit\Enums\GaebCatalogType;

/**
 * A catalogue declared in the header of a bill of quantity (GAEB `Ctlg`).
 *
 * Assignments on items, groups and quantity splits point here by id. The type
 * carries the edition, so a cost group is never ambiguous.
 */
final class GaebCatalog {
    public function __construct(
        private readonly string $id,
        private readonly ?string $type = null,
        private readonly ?string $name = null,
        private readonly ?string $assignType = null
    ) {}

    public function getId(): string {
        return $this->id;
    }

    /** Raw type as written in the file; unknown values stay as they are. */
    public function getType(): ?string {
        return $this->type;
    }

    /** Type as an enum, null when the file uses a value outside the schema list. */
    public function getCatalogType(): ?GaebCatalogType {
        return $this->type === null ? null : GaebCatalogType::tryFrom($this->type);
    }

    public function getName(): ?string {
        return $this->name;
    }

    /** How a share is expressed (`Pct`, `Abs`, `PctAbs`). */
    public function getAssignType(): ?string {
        return $this->assignType;
    }
}
