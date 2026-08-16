<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCalculatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection, GaebTotals};
use ERechnungToolkit\Enums\GaebItemType;
use ERechnungToolkit\Helper\Gaeb\GaebCalculator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB arithmetic. Amounts are checked against hand computed
 * figures, not against the implementation.
 */
class GaebCalculatorTest extends BaseTestCase {
    private GaebCalculator $calculator;

    protected function setUp(): void {
        parent::setUp();
        $this->calculator = new GaebCalculator;
    }

    private function money(string $amount, int $scale = 4): Money {
        return Money::of($amount, CurrencyCode::Euro, $scale);
    }

    private function item(
        string $reference,
        ?string $sectionReference = null,
        ?string $quantity = null,
        ?string $unitPrice = null,
        ?string $totalPrice = null,
        GaebItemType $type = GaebItemType::Standard,
        bool $notOffered = false,
        bool $notApplicable = false
    ): GaebItem {
        return new GaebItem(
            reference: $reference,
            sectionReference: $sectionReference,
            type: $type,
            quantity: $quantity,
            unitPrice: $unitPrice !== null ? $this->money($unitPrice) : null,
            totalPrice: $totalPrice !== null ? $this->money($totalPrice) : null,
            notOffered: $notOffered,
            notApplicable: $notApplicable,
        );
    }

    /** Menge × EP wird kaufmännisch auf zwei Stellen gerundet, nicht abgeschnitten. */
    public function test_item_total_is_the_rounded_product(): void {
        $item = $this->item('001.0010', quantity: '3.000', unitPrice: '10.005');

        $this->assertSame('30.02', $this->calculator->itemTotal($item)?->getAmount());
    }

    /** Der 1/10-Cent des Einheitspreises überlebt bis zur Rundung der Summe. */
    public function test_tenth_of_a_cent_survives_the_multiplication(): void {
        $item = $this->item('001.0020', quantity: '1000.000', unitPrice: '0.0015');

        $this->assertSame('1.50', $this->calculator->itemTotal($item)?->getAmount());
    }

    /** Ein gelieferter Gesamtbetrag gilt, wird aber auf die Währungsstellen gebracht. */
    public function test_given_total_wins_over_the_product(): void {
        $item = $this->item('001.0030', quantity: '2.000', unitPrice: '10.000', totalPrice: '19.99');

        $this->assertSame('19.99', $this->calculator->itemTotal($item)?->getAmount());
    }

    /** Nicht angebotene und entfallene Positionen tragen kein Geld. */
    public function test_declined_and_dropped_items_carry_no_money(): void {
        $declined = $this->item('001.0040', quantity: '1.000', unitPrice: '10.000', notOffered: true);
        $dropped = $this->item('001.0050', quantity: '1.000', unitPrice: '10.000', notApplicable: true);
        $markup = $this->item('001.0060', quantity: '1.000', unitPrice: '10.000', type: GaebItemType::Markup);

        $this->assertNull($this->calculator->itemTotal($declined));
        $this->assertNull($this->calculator->itemTotal($dropped));
        $this->assertNull($this->calculator->itemTotal($markup));
    }

    private function boq(): GaebBoq {
        return new GaebBoq(
            sections: [
                new GaebSection(reference: '001'),
                new GaebSection(reference: '001.001', parentReference: '001'),
            ],
            items: [
                // 3 × 10,005 = 30,02 (gerundet) im Unterabschnitt
                $this->item('001.001.0010', sectionReference: '001.001', quantity: '3.000', unitPrice: '10.005'),
                // 2 × 5,00 = 10,00 direkt im Abschnitt
                $this->item('001.0010', sectionReference: '001', quantity: '2.000', unitPrice: '5.000'),
                // nicht angeboten: bleibt draußen
                $this->item('001.0020', sectionReference: '001', quantity: '9.000', unitPrice: '99.000', notOffered: true),
            ],
        );
    }

    /** Die Gruppensumme enthält die Untergruppen. */
    public function test_section_total_includes_nested_groups(): void {
        $boq = $this->boq();

        $this->assertSame('30.02', $this->calculator->sectionTotal($boq, '001.001')->getAmount());
        $this->assertSame('40.02', $this->calculator->sectionTotal($boq, '001')->getAmount());
        $this->assertSame('40.02', $this->calculator->documentTotal($boq)->getAmount());
    }

    /** Der prozentuale Nachlass wirkt auf die gerundete Summe und wird erneut gerundet. */
    public function test_percentage_discount_is_rounded_again(): void {
        $totals = new GaebTotals(discountPercent: '3.000000');

        $this->assertSame(
            '38.82',
            $this->calculator->afterDiscount($this->money('40.02', 2), $totals)->getAmount()
        );
    }

    /** Ein absoluter Nachlass wird abgezogen, wenn kein Prozentsatz vorliegt. */
    public function test_absolute_discount_applies_without_a_percentage(): void {
        $totals = new GaebTotals(discountAmount: $this->money('0.02', 2));

        $this->assertSame(
            '40.00',
            $this->calculator->afterDiscount($this->money('40.02', 2), $totals)->getAmount()
        );
    }

    /** Die gelieferte Summe wird nachgerechnet — Abweichung ist ein Befund. */
    public function test_stated_total_is_checked_against_the_items(): void {
        $matching = new GaebBoq(
            sections: $this->boq()->getSections(),
            items: $this->boq()->getItems(),
            totals: new GaebTotals(total: $this->money('40.02', 2)),
        );
        $wrong = new GaebBoq(
            sections: $this->boq()->getSections(),
            items: $this->boq()->getItems(),
            totals: new GaebTotals(total: $this->money('41.00', 2)),
        );

        $this->assertTrue($this->calculator->statedTotalMatches($matching));
        $this->assertFalse($this->calculator->statedTotalMatches($wrong));
        $this->assertNull($this->calculator->statedTotalMatches($this->boq()));
    }
}
