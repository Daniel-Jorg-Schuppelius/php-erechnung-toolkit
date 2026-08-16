<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormWorkTime.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

/**
 * DATANORM working time (C-record, indicator ARBA / DATANORM 4 ARBEIT-1):
 * the calculated labour time for an article, the calculation backbone of
 * the electrical trade (ZVEH). Times are normalized to minutes regardless
 * of the transferred unit (work units of 1/100 h, minutes or hours).
 */
final class DatanormWorkTime {
    public const PURPOSE_PRODUCTION = 1;
    public const PURPOSE_INSTALLATION = 2;
    public const PURPOSE_REPAIR = 3;
    public const PURPOSE_DISASSEMBLY = 4;

    public function __construct(
        private readonly string $articleNumber,
        private readonly int $purpose,
        private readonly float $minutes
    ) {}

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    /** One of the PURPOSE_* constants (production/installation/repair/disassembly). */
    public function getPurpose(): int {
        return $this->purpose;
    }

    /** Working time in minutes. */
    public function getMinutes(): float {
        return $this->minutes;
    }
}
