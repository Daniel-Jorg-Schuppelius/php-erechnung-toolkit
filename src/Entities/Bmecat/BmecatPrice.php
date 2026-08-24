<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatPrice.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Bmecat;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;

/**
 * Ein Preiselement eines BMEcat-Artikels (ARTICLE_PRICE in 1.2,
 * PRODUCT_PRICE in 2005).
 *
 * `lowerBound` ist die Mengen-Untergrenze der Staffel (LOWER_BOUND, Standard
 * 1). Ein Element mit Untergrenze ≤ 1 ist der Basispreis, alles darüber eine
 * Mengenstaffel ({@see isScalePrice()}). Der Betrag ist auf 4 Nachkommastellen
 * skaliert (kaufmännisch gerundet); nicht deutbare Beträge sind null.
 */
final class BmecatPrice {
    public function __construct(
        private readonly ?Money $amount,
        private readonly CurrencyCode $currency,
        private readonly ?string $priceType = null,
        private readonly float $lowerBound = 1.0
    ) {}

    /** Betrag (Skala 4), null wenn kein deutbarer PRICE_AMOUNT übertragen wurde. */
    public function getAmount(): ?Money {
        return $this->amount;
    }

    /** Währung des Preiselements (PRICE_CURRENCY, sonst Katalogwährung, sonst EUR). */
    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    /** Preisart aus dem `price_type`-Attribut (z. B. "net_list", "net_customer"). */
    public function getPriceType(): ?string {
        return $this->priceType;
    }

    /** Mengen-Untergrenze der Staffel (LOWER_BOUND, Standard 1). */
    public function getLowerBound(): float {
        return $this->lowerBound;
    }

    /** True für eine echte Mengenstaffel: deutbarer Betrag und Untergrenze > 1. */
    public function isScalePrice(): bool {
        return $this->amount !== null && $this->lowerBound > 1.0;
    }
}
