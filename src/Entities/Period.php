<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Period.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use DateTimeImmutable;

/**
 * Invoicing period (BG-14: BT-73/BT-74) or invoice line period (BG-26: BT-134/BT-135).
 *
 * Both dates are optional so that parsed documents keep what they carry;
 * {@see \ERechnungToolkit\Validators\DocumentRulesValidator} checks BR-29,
 * BR-30, BR-CO-19 and BR-CO-20. Comparisons use the calendar day only.
 */
final class Period {
    public function __construct(
        private readonly ?DateTimeImmutable $start = null,
        private readonly ?DateTimeImmutable $end = null,
    ) {}

    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self {
        return new self($start, $end);
    }

    public static function day(DateTimeImmutable $day): self {
        return new self($day, $day);
    }

    public function getStart(): ?DateTimeImmutable {
        return $this->start;
    }

    public function getEnd(): ?DateTimeImmutable {
        return $this->end;
    }

    public function isEmpty(): bool {
        return $this->start === null && $this->end === null;
    }

    /**
     * False only when both dates are set and the end lies before the start.
     */
    public function isOrdered(): bool {
        if ($this->start === null || $this->end === null) {
            return true;
        }

        return $this->end->format('Y-m-d') >= $this->start->format('Y-m-d');
    }

    /**
     * Whether every date of $inner lies within this period (open bounds count as unlimited).
     */
    public function contains(self $inner): bool {
        foreach ([$inner->start, $inner->end] as $date) {
            if ($date === null) {
                continue;
            }
            $day = $date->format('Y-m-d');
            if ($this->start !== null && $day < $this->start->format('Y-m-d')) {
                return false;
            }
            if ($this->end !== null && $day > $this->end->format('Y-m-d')) {
                return false;
            }
        }

        return true;
    }
}
