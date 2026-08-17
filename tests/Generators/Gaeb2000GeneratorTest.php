<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb2000GeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection};
use ERechnungToolkit\Enums\GaebPhase;
use ERechnungToolkit\Generators\Gaeb2000Generator;
use ERechnungToolkit\Parsers\Gaeb2000Parser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB 2000 writer, held against its own reader. The layout
 * follows the published sample file.
 */
class Gaeb2000GeneratorTest extends BaseTestCase {
    private Gaeb2000Generator $generator;

    private Gaeb2000Parser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new Gaeb2000Generator;
        $this->parser = new Gaeb2000Parser;
    }

    private function boq(): GaebBoq {
        return new GaebBoq(
            projectName: 'Musterprojekt',
            sections: [
                new GaebSection(reference: '01', label: 'Beispiele'),
                new GaebSection(reference: '01.01', parentReference: '01', label: 'Erdarbeiten'),
            ],
            items: [
                new GaebItem(
                    reference: '01.01.0001',
                    sectionReference: '01.01',
                    shortText: 'Oberboden abtragen',
                    longText: "Oberboden DIN 18300 abtragen,\nseitlich lagern.",
                    quantity: '150',
                    unit: 'm2',
                    unitPrice: Money::of('4.9530', CurrencyCode::Euro, 4),
                ),
            ],
        );
    }

    /** Verschachtelte Bereiche bleiben verschachtelt. */
    public function test_round_trip_keeps_the_hierarchy(): void {
        $again = $this->parser->parse($this->generator->generate($this->boq(), GaebPhase::RequestForBid));

        $this->assertSame('83', $again->getPhaseCode());
        $this->assertSame('Musterprojekt', $again->getProjectName());
        $this->assertCount(2, $again->getSections());
        $this->assertSame('01', $again->getSections()[0]->getReference());
        $this->assertSame('01', $again->getSections()[1]->getParentReference());

        $item = $again->getItems()[0];
        $this->assertSame('01.01.0001', $item->getReference());
        $this->assertSame('01.01', $item->getSectionReference());
        $this->assertSame('150', $item->getQuantity());
        $this->assertSame('m2', $item->getUnit());
        $this->assertSame('4.9530', $item->getUnitPrice()?->getAmount());
    }

    /** Die Ordnungszahl reist ungeteilt und kommt zerlegt zurück. */
    public function test_ordinal_number_travels_unsplit(): void {
        $written = $this->generator->generate($this->boq(), GaebPhase::RequestForBid);

        $this->assertStringContainsString('[OZ]01010001[end]', $written);
        $this->assertStringContainsString('[Laenge]4[end]', $written);
    }

    /** Der Langtext geht als RTF hinaus und kommt als Klartext zurück. */
    public function test_long_text_is_written_as_rtf(): void {
        $written = $this->generator->generate($this->boq(), GaebPhase::RequestForBid);

        $this->assertStringContainsString('{\rtf1', $written);
        $this->assertStringContainsString('\par', $written);

        $long = (string) $this->parser->parse($written)->getItems()[0]->getLongText();
        $this->assertStringContainsString('Oberboden DIN 18300 abtragen', $long);
        $this->assertStringContainsString('seitlich lagern', $long);
        $this->assertStringNotContainsString('rtf1', $long);
    }
}
