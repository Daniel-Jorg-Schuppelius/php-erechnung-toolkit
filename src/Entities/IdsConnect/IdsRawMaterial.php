<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsRawMaterial.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\IdsConnect;

use CommonToolkit\ValueObjects\Money;

/**
 * Rohstoffanteil einer IDS-Position (`Rohstoffanteil`), etwa Kupfer im Kabel.
 * Die Notierungen gelten je 100 kg (DEL-Notierung); `basisNotierung` ist die
 * im Preis einkalkulierte, `notierungAktuell` die bei der Berechnung gültige.
 */
final class IdsRawMaterial {
    public function __construct(
        private readonly ?string $material = null,
        private readonly ?float $weightValue = null,
        private readonly ?string $weightUnit = null,
        private readonly ?float $baseValue = null,
        private readonly ?string $baseUnit = null,
        private readonly ?Money $baseQuotation = null,
        private readonly ?Money $currentQuotation = null,
    ) {}

    /** Rohstoffcode (z. B. CU, AL, PB). */
    public function getMaterial(): ?string {
        return $this->material;
    }

    public function getWeightValue(): ?float {
        return $this->weightValue;
    }

    public function getWeightUnit(): ?string {
        return $this->weightUnit;
    }

    public function getBaseValue(): ?float {
        return $this->baseValue;
    }

    public function getBaseUnit(): ?string {
        return $this->baseUnit;
    }

    public function getBaseQuotation(): ?Money {
        return $this->baseQuotation;
    }

    public function getCurrentQuotation(): ?Money {
        return $this->currentQuotation;
    }
}
