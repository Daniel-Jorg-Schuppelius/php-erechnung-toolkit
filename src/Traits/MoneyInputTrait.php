<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : MoneyInputTrait.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Traits;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;

/**
 * Betragseingabe an der Builder-Grenze.
 *
 * Entities des Toolkits verlangen {@see Money}. Die fluenten Builder dürfen
 * zusätzlich Dezimal-Strings und Floats annehmen, damit Aufrufer die Währung
 * nicht an jeder Position wiederholen — sie steht ohnehin am Belegkopf.
 * Strings gehen präzisionswahrend in Money, Floats sind der dokumentierte
 * (unpräzise) Ausweg.
 */
trait MoneyInputTrait {
    /**
     * Belegwährung des Builders.
     */
    abstract protected function documentCurrency(): CurrencyCode;

    protected function toMoney(Money|string|float|int $value): Money {
        if ($value instanceof Money) {
            return $value;
        }

        $currency = $this->documentCurrency();

        return is_float($value)
            ? Money::ofFloat($value, $currency)
            : Money::of((string) $value, $currency);
    }
}
