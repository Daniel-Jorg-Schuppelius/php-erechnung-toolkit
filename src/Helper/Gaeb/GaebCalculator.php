<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCalculator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\Gaeb;

use CommonToolkit\Enums\RoundingMode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebTotals};
use ERechnungToolkit\Enums\GaebItemType;

/**
 * Sums of a bill of quantity, following the arithmetic GAEB prescribes.
 *
 * Two rules decide everything here. First, the total of an item is the
 * *rounded* product of quantity and unit price - rounded commercially to the
 * two decimals of the currency, not carried on at full precision. Second, a
 * discount applies to that rounded sum and its result is rounded again. Adding
 * up unrounded values instead produces sums that differ from the ones the other
 * side computes, which is what price checks stumble over.
 *
 * Items the bidder declined (`NotOffered`) and items marked as not applicable
 * carry no money and stay out of every sum. Markup items are a percentage on
 * other items, so they are never summed as quantity times price.
 */
final class GaebCalculator {
    /** Amounts travel with two decimals; only the unit price is finer. */
    private const AMOUNT_SCALE = 2;

    /**
     * Total of one item: quantity times unit price, rounded commercially.
     * Returns null when the item carries no money at all.
     */
    public function itemTotal(GaebItem $item): ?Money {
        if ($item->isNotOffered() || $item->isNotApplicable() || $item->getType() === GaebItemType::Markup) {
            return null;
        }

        $given = $item->getTotalPrice();
        if ($given !== null) {
            return $given->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);
        }

        $unitPrice = $item->getUnitPrice();
        $quantity = $item->getQuantity();
        // Eine nicht lesbare Menge wird nicht geraten: dann gibt es keinen Betrag.
        if ($unitPrice === null || $quantity === null || !is_numeric($quantity)) {
            return null;
        }

        return $unitPrice->times($quantity, RoundingMode::HalfUp)->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Sum of a group: its own items plus the groups below it. Pass null to sum
     * the items that hang directly under the bill of quantity.
     */
    public function sectionTotal(GaebBoq $boq, ?string $reference): Money {
        $sum = Money::zero($boq->getCurrency(), self::AMOUNT_SCALE);

        foreach ($boq->getItems() as $item) {
            if ($item->getSectionReference() !== $reference) {
                continue;
            }
            $total = $this->itemTotal($item);
            if ($total !== null) {
                $sum = $sum->plus($total);
            }
        }

        foreach ($boq->getSections() as $section) {
            if ($section->getParentReference() === $reference) {
                $sum = $sum->plus($this->sectionTotal($boq, $section->getReference()));
            }
        }

        return $sum;
    }

    /**
     * Base a markup item is computed on - the sum of the items it applies to.
     *
     * The type decides which those are (GAEB `MarkupType`). `AllInCat` takes
     * the group the markup item stands in, minus the markup items themselves:
     * a markup on a markup would compound and is not what the form means.
     * The other two types name their base explicitly in the document, so it is
     * taken from there rather than guessed.
     */
    public function markupBase(GaebBoq $boq, GaebItem $item): Money {
        $zero = Money::zero($boq->getCurrency(), self::AMOUNT_SCALE);
        if ($item->getMarkupType()?->derivesBaseFromStructure() !== true) {
            return $item->getMarkupBase() ?? $zero;
        }

        $sum = $zero;
        foreach ($boq->getItems() as $other) {
            if ($other->getSectionReference() !== $item->getSectionReference()) {
                continue;
            }
            if ($other->getType() === GaebItemType::Markup || $other->getType() === GaebItemType::Note) {
                continue;
            }
            $total = $this->itemTotal($other);
            if ($total !== null) {
                $sum = $sum->plus($total);
            }
        }

        return $sum;
    }

    /**
     * Amount a markup item contributes: its rate applied to the base. The rate
     * is a percentage, so a base of 1.000 EUR at 5 makes 50 EUR.
     */
    public function markupAmount(GaebBoq $boq, GaebItem $item): Money {
        $rate = $item->getUnitPrice();
        if ($rate === null) {
            return Money::zero($boq->getCurrency(), self::AMOUNT_SCALE);
        }

        return $this->markupBase($boq, $item)
            ->times($rate->getAmount(), RoundingMode::HalfUp)
            ->dividedBy('100', RoundingMode::HalfUp)
            ->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);
    }

    /**
     * Total of the whole document. Summed over the items directly, not over the
     * groups: a bid file carries prices without any group at all, and a position
     * whose group is missing would silently drop out of a sum built top down.
     */
    public function documentTotal(GaebBoq $boq): Money {
        $sum = Money::zero($boq->getCurrency(), self::AMOUNT_SCALE);

        foreach ($boq->getItems() as $item) {
            $total = $this->itemTotal($item);
            if ($total !== null) {
                $sum = $sum->plus($total);
            }
        }

        return $sum;
    }

    /**
     * Sum after the discount of the given totals. A percentage wins over an
     * absolute amount when both are present, because the percentage is what the
     * bidder declared; the result is rounded commercially.
     */
    public function afterDiscount(Money $total, ?GaebTotals $totals): Money {
        if ($totals === null) {
            return $total;
        }

        $percent = $totals->getDiscountPercent();
        if ($percent !== null && is_numeric($percent)) {
            return $total->minusPercentage($percent, RoundingMode::HalfUp)->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);
        }

        $amount = $totals->getDiscountAmount();
        if ($amount !== null) {
            return $total->minus($amount)->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp);
        }

        return $total;
    }

    /**
     * Does the sum stated in the document match the one computed from the items?
     * GAEB puts the arithmetic of the reading side above the delivered figure
     * (X31 rule), so a mismatch is a finding, never a silent correction.
     */
    public function statedTotalMatches(GaebBoq $boq): ?bool {
        $stated = $boq->getTotals()?->getTotal();
        if ($stated === null) {
            return null;
        }

        return $stated->withScale(self::AMOUNT_SCALE, RoundingMode::HalfUp)
            ->compareTo($this->documentTotal($boq)) === 0;
    }
}
