<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormProductGroup.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

/**
 * DATANORM S-record: a main product group (`group` is null) or a product
 * group below a main group. The same product group code may occur under
 * different main groups.
 */
final class DatanormProductGroup {
    public function __construct(
        private readonly string $mainGroup,
        private readonly ?string $group,
        private readonly string $label
    ) {}

    public function getMainGroup(): string {
        return $this->mainGroup;
    }

    public function getGroup(): ?string {
        return $this->group;
    }

    public function getLabel(): string {
        return $this->label;
    }

    public function isMainGroup(): bool {
        return $this->group === null || $this->group === '';
    }
}
