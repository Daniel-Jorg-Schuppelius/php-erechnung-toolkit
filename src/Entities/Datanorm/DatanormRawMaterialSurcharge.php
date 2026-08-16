<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormRawMaterialSurcharge.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\DatanormPriceIndicator;

/**
 * DATANORM raw material surcharge (Z-record): copper/aluminium/… surcharges
 * on top of the article price, central to electrical trade catalogs (cables).
 *
 * Two methods exist:
 *  - **international** (DATANORM 5 working flag 2, DATANORM 4 flag 3): a
 *    fixed surcharge/discount (amount or percent) valid while the raw
 *    material day price lies within [fromDayPrice, toDayPrice].
 *  - **german** (DATANORM 5 flag 3, DATANORM 4 flag 4): the surcharge is
 *    calculated from the day quotation (DEL-Notiz for copper): the article
 *    price already contains `includedBasePrice` (× `baseFactor` → per kg);
 *    the difference to the day price is multiplied by the raw material
 *    weight (`weight` × `weightFactor` → per price unit). A day price below
 *    the included base yields no deduction (per specification).
 */
final class DatanormRawMaterialSurcharge {
    public const METHOD_INTERNATIONAL = 'international';
    public const METHOD_GERMAN = 'german';

    public function __construct(
        private readonly string $articleNumber,
        private readonly string $rawMaterial,
        private readonly string $method,
        private readonly ?bool $isDiscount = null,
        private readonly ?bool $isPercent = null,
        private readonly ?Money $amount = null,
        private readonly ?float $percent = null,
        private readonly ?Money $fromDayPrice = null,
        private readonly ?Money $toDayPrice = null,
        private readonly ?Money $includedBasePrice = null,
        private readonly ?float $baseFactor = null,
        private readonly ?float $weight = null,
        private readonly ?float $weightFactor = null,
        private readonly ?DatanormPriceIndicator $priceIndicator = null,
        private readonly int $priceUnitAmount = 1
    ) {}

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    /** Raw material code from the DATANORM table (CU, AL, AG, …). */
    public function getRawMaterial(): string {
        return $this->rawMaterial;
    }

    /** One of the METHOD_* constants. */
    public function getMethod(): string {
        return $this->method;
    }

    public function isDiscount(): ?bool {
        return $this->isDiscount;
    }

    public function isPercent(): ?bool {
        return $this->isPercent;
    }

    /** Absolute surcharge/discount per price unit (international, amount kind). */
    public function getAmount(): ?Money {
        return $this->amount;
    }

    /** Percentage surcharge/discount (international, percent kind). */
    public function getPercent(): ?float {
        return $this->percent;
    }

    public function getFromDayPrice(): ?Money {
        return $this->fromDayPrice;
    }

    public function getToDayPrice(): ?Money {
        return $this->toDayPrice;
    }

    /** Raw material quotation already contained in the article price (german method). */
    public function getIncludedBasePrice(): ?Money {
        return $this->includedBasePrice;
    }

    /** Multiplier converting the included base to a per-kg price (german method). */
    public function getBaseFactor(): ?float {
        return $this->baseFactor;
    }

    /** Raw material weight share (german method), converted via {@see getWeightFactor()}. */
    public function getWeight(): ?float {
        return $this->weight;
    }

    public function getWeightFactor(): ?float {
        return $this->weightFactor;
    }

    public function getPriceIndicator(): ?DatanormPriceIndicator {
        return $this->priceIndicator;
    }

    /** Units the surcharge refers to (resolved, ≥ 1). */
    public function getPriceUnitAmount(): int {
        return $this->priceUnitAmount;
    }

    /** Whether an international surcharge applies at the given day price. */
    public function appliesToDayPrice(Money $dayPrice): bool {
        if ($this->method !== self::METHOD_INTERNATIONAL) {
            return false;
        }
        if ($this->fromDayPrice !== null && $dayPrice->compareTo($this->fromDayPrice->withScale($dayPrice->getScale())) < 0) {
            return false;
        }
        if ($this->toDayPrice !== null && $dayPrice->compareTo($this->toDayPrice->withScale($dayPrice->getScale())) > 0) {
            return false;
        }

        return true;
    }

    /**
     * German method: surcharge per price unit for the given day quotation
     * (per kg, e.g. the copper DEL-Notiz). Returns zero when the day price
     * does not exceed the included base; null when this is not a
     * german-method record or data is incomplete.
     */
    public function germanSurchargePerPriceUnit(Money $dayPricePerKg): ?Money {
        if ($this->method !== self::METHOD_GERMAN
            || $this->includedBasePrice === null
            || $this->baseFactor === null
            || $this->weight === null) {
            return null;
        }

        $includedPerKg = $this->includedBasePrice->withScale(6)->times($this->baseFactor);
        $difference = $dayPricePerKg->withScale(6)->minus($includedPerKg);
        if (!$difference->isPositive()) {
            return Money::zero($dayPricePerKg->getCurrency(), 4);
        }

        $kilograms = $this->weight * ($this->weightFactor ?? 1.0);

        return $difference->times($kilograms)->withScale(4);
    }
}
