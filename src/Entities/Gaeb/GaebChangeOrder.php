<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebChangeOrder.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use ERechnungToolkit\Enums\{GaebChangeOrderInitiator, GaebChangeOrderPhase, GaebChangeOrderStatus};

/**
 * Head of an addendum (GAEB DA XML 3.3, `COInfo`).
 *
 * The positions of an addendum carry only its number; everything that describes
 * it - who raised it, why, in which phase and since when it is agreed - belongs
 * to the document. Without this block an addendum is a set of items no one can
 * account for.
 */
final class GaebChangeOrder {
    public function __construct(
        private readonly string $number,
        private readonly ?GaebChangeOrderPhase $phase = null,
        private readonly ?GaebChangeOrderStatus $status = null,
        private readonly ?GaebChangeOrderInitiator $initiator = null,
        private readonly ?string $reason = null,
        private readonly ?string $contractReference = null,
        private readonly ?string $date = null,
    ) {}

    public function getNumber(): string {
        return $this->number;
    }

    public function getPhase(): ?GaebChangeOrderPhase {
        return $this->phase;
    }

    public function getStatus(): ?GaebChangeOrderStatus {
        return $this->status;
    }

    public function getInitiator(): ?GaebChangeOrderInitiator {
        return $this->initiator;
    }

    public function getReason(): ?string {
        return $this->reason;
    }

    /** Bezeichnung des Auftrags-LV, auf das sich der Nachtrag bezieht. */
    public function getContractReference(): ?string {
        return $this->contractReference;
    }

    /** Datum der Beauftragung (ISO). */
    public function getDate(): ?string {
        return $this->date;
    }

    /** Is the main contract, not an addendum? Number 0 stands for it. */
    public function isMainContract(): bool {
        return (int) $this->number === 0;
    }
}
