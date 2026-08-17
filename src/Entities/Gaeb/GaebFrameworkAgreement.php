<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebFrameworkAgreement.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * Framework agreement head (Zeitvertrag, GAEB `MastAgrInfo`).
 *
 * A framework agreement prices a catalogue of services for a period rather than
 * a single project: the bidder offers a percentage on or off the listed prices,
 * and single orders are called off against it later. Label, description and the
 * period are mandatory - without them the agreement has no scope.
 */
final class GaebFrameworkAgreement {
    public function __construct(
        private readonly string $label,
        private readonly string $description,
        private readonly string $begin,
        private readonly string $end,
        /** Auf-/Abgebot des Bieters in Prozent; 0 heißt „zu den Listenpreisen". */
        private readonly string $bidUpDown = '0',
        private readonly ?string $number = null,
        private readonly ?string $minimumValue = null,
        private readonly ?string $minimumAward = null,
    ) {}

    public function getLabel(): string {
        return $this->label;
    }

    public function getDescription(): string {
        return $this->description;
    }

    /** Beginn der Laufzeit (ISO). */
    public function getBegin(): string {
        return $this->begin;
    }

    /** Ende der Laufzeit (ISO). */
    public function getEnd(): string {
        return $this->end;
    }

    public function getBidUpDown(): string {
        return $this->bidUpDown;
    }

    public function getNumber(): ?string {
        return $this->number;
    }

    /** Mindestauftragswert je Einzelauftrag. */
    public function getMinimumValue(): ?string {
        return $this->minimumValue;
    }

    /** Mindestvergabesumme über die Laufzeit. */
    public function getMinimumAward(): ?string {
        return $this->minimumAward;
    }
}
