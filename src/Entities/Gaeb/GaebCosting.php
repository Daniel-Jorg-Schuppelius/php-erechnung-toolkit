<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCosting.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use ERechnungToolkit\Enums\{GaebCostingMethod, GaebCostingType};

/**
 * A cost determination or building cost catalogue (X50/X51).
 *
 * Unlike a bill of quantity this document does not describe work to be done but
 * what it is expected to cost - ordered by cost groups after DIN 276 rather
 * than by trades. The stage says how firm the figures are, from an estimate
 * during preliminary design to the final statement after the audited invoice.
 *
 * Two shapes exist and differ only in the element number: `.2` writes it out in
 * full on every level (`300`, `310`, `314` - the usual form for DIN 276), `.1`
 * gives only the part of the current level and leaves the joining to the
 * reading program.
 */
final class GaebCosting {
    /**
     * @param list<GaebCostElement> $elements
     */
    public function __construct(
        private readonly string $name,
        private readonly array $elements = [],
        private readonly ?string $label = null,
        private readonly ?GaebCostingType $type = null,
        private readonly ?GaebCostingMethod $method = null,
        private readonly ?string $date = null,
        private readonly bool $fullElementNumbers = true,
    ) {}

    /** Kurzbezeichnung (Pflicht im Kopf). */
    public function getName(): string {
        return $this->name;
    }

    /** @return list<GaebCostElement> */
    public function getElements(): array {
        return $this->elements;
    }

    public function getLabel(): ?string {
        return $this->label;
    }

    public function getType(): ?GaebCostingType {
        return $this->type;
    }

    public function getMethod(): ?GaebCostingMethod {
        return $this->method;
    }

    public function getDate(): ?string {
        return $this->date;
    }

    /**
     * Are the element numbers written out in full (shape `.2`)? That is the
     * usual form for DIN 276, where `314` is meaningful on its own.
     */
    public function hasFullElementNumbers(): bool {
        return $this->fullElementNumbers;
    }
}
