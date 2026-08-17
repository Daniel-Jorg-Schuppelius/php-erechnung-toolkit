<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCostingTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebCostElement, GaebCosting};
use ERechnungToolkit\Enums\{GaebCostingMethod, GaebCostingType, GaebPhase};
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Parsers\GaebCostingParser;
use Tests\Contracts\BaseTestCase;

/**
 * Kostenermittlung (X50 Baukostenkatalog, X51 Kostenermittlung): Sie beschreibt
 * nicht, was zu tun ist, sondern was es kosten soll - nach Kostengruppen
 * gegliedert und verschachtelt.
 */
class GaebCostingTest extends BaseTestCase {
    private function money(string $value): Money {
        return Money::of($value, CurrencyCode::Euro);
    }

    private function costing(bool $fullNumbers = true): GaebCosting {
        return new GaebCosting(
            name: 'KB-2026',
            elements: [new GaebCostElement(
                description: 'Baukonstruktion',
                unit: 'm2',
                number: '300',
                quantity: '1200.000',
                unitPrice: $this->money('1450.00'),
                unitPriceFrom: $this->money('1300.00'),
                unitPriceAverage: $this->money('1450.00'),
                unitPriceTo: $this->money('1600.00'),
                children: [new GaebCostElement(description: 'Gründung', unit: 'm2', number: '320', unitPrice: $this->money('180.00'))],
            )],
            label: 'Kostenberechnung Neubau',
            type: GaebCostingType::Calculation,
            method: GaebCostingMethod::ByElements,
            date: '2026-08-17',
            fullElementNumbers: $fullNumbers,
        );
    }

    private function roundTrip(GaebCosting $costing): GaebCosting {
        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $costing);

        return (new GaebCostingParser)->parse($xml);
    }

    /** Kopf und Hierarchie überstehen den Weg durch die Datei. */
    public function test_round_trip_keeps_head_and_hierarchy(): void {
        $back = $this->roundTrip($this->costing());

        $this->assertSame('KB-2026', $back->getName());
        $this->assertSame(GaebCostingType::Calculation, $back->getType());
        $this->assertSame(GaebCostingMethod::ByElements, $back->getMethod());

        $element = $back->getElements()[0];
        $this->assertSame('300', $element->getNumber());
        $this->assertSame('Baukonstruktion', $element->getDescription());
        $this->assertCount(1, $element->getChildren());
        $this->assertSame('Gründung', $element->getChildren()[0]->getDescription());
    }

    /** Ein früher Kennwert ist eine Spanne - die bleibt erhalten. */
    public function test_price_range_survives(): void {
        $element = $this->roundTrip($this->costing())->getElements()[0];

        $this->assertTrue($element->hasPriceRange());
        $this->assertSame('1.300,00 €', $element->getUnitPriceFrom()?->format());
        $this->assertSame('1.600,00 €', $element->getUnitPriceTo()?->format());
    }

    /**
     * Die Bauform steht nicht im Kopf, sondern zeigt sich am Elementbezeichner:
     * `EleNo` voll ausgeschrieben, `ElePart` nur die eigene Ebene.
     */
    public function test_shape_is_recognised_from_the_element_number(): void {
        $this->assertTrue($this->roundTrip($this->costing(true))->hasFullElementNumbers());
        $this->assertFalse($this->roundTrip($this->costing(false))->hasFullElementNumbers());
    }

    /** Die vier Stufen der DIN 276 stehen für den Planungsfortschritt. */
    public function test_stages_order_the_planning_progress(): void {
        $this->assertSame(1, GaebCostingType::Estimate->stage());
        $this->assertSame(4, GaebCostingType::FinalStatement->stage());
        $this->assertNull(GaebCostingType::Other->stage());

        // Nur die Kostenfeststellung nennt tatsächlich entstandene Kosten.
        $this->assertTrue(GaebCostingType::FinalStatement->isActual());
        $this->assertFalse(GaebCostingType::Calculation->isActual());
    }
}
