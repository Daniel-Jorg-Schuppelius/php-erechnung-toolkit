<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb90GeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection};
use ERechnungToolkit\Enums\GaebPhase;
use ERechnungToolkit\Generators\Gaeb90Generator;
use ERechnungToolkit\Helper\Gaeb\GaebCalculator;
use ERechnungToolkit\Parsers\Gaeb90Parser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB 90 writer. The layout was verified against the public GAEB
 * test files; here the writer is held against its own reader.
 */
class Gaeb90GeneratorTest extends BaseTestCase {
    private Gaeb90Generator $generator;

    private Gaeb90Parser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new Gaeb90Generator;
        $this->parser = new Gaeb90Parser;
    }

    private function boq(): GaebBoq {
        return new GaebBoq(
            projectName: 'Musterprojekt',
            sections: [new GaebSection(reference: '11.11', label: 'Erdarbeiten')],
            items: [
                new GaebItem(
                    reference: '11.11.10',
                    sectionReference: '11.11',
                    shortText: 'Boden loesen',
                    longText: "Oberboden abtragen\nund seitlich lagern",
                    quantity: '51.300',
                    unit: 'm2',
                    unitPrice: Money::of('4.9530', CurrencyCode::Euro, 4),
                    totalPrice: Money::of('254.09', CurrencyCode::Euro, 4),
                ),
                new GaebItem(
                    reference: '11.11.20',
                    sectionReference: '11.11',
                    shortText: 'Schacht setzen',
                    quantity: '2.000',
                    unit: 'St',
                ),
            ],
        );
    }

    /** Jeder Satz misst exakt 80 Zeichen, die Satznummer läuft lückenlos. */
    public function test_records_keep_the_fixed_grid(): void {
        $file = $this->generator->generate($this->boq(), GaebPhase::RequestForBid);
        $records = array_filter(explode("\r\n", $file));

        $this->assertNotEmpty($records);
        foreach ($records as $index => $record) {
            $this->assertSame(80, strlen($record), "Satz {$index} misst nicht 80 Zeichen.");
            $this->assertSame(
                str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
                substr($record, 74, 6),
                'Satznummer weicht ab.'
            );
        }
    }

    /** Was geschrieben wurde, muss sich unverändert wieder lesen lassen. */
    public function test_round_trip_keeps_structure_and_prices(): void {
        $written = $this->generator->generate($this->boq(), GaebPhase::Award);
        $again = $this->parser->parse($written);

        $this->assertSame('86', $again->getPhaseCode());
        $this->assertCount(1, $again->getSections());
        $this->assertSame('Erdarbeiten', $again->getSections()[0]->getLabel());
        $this->assertSame(2, $again->countItems());

        $first = $again->getItems()[0];
        $this->assertSame('11.11.10', $first->getReference());
        $this->assertSame('51.300', $first->getQuantity());
        $this->assertSame('m2', $first->getUnit());
        $this->assertSame('Boden loesen', $first->getShortText());
        // Der 1/10-Cent überlebt die eigene Spalte im Satz.
        $this->assertSame('4.9530', $first->getUnitPrice()?->getAmount());
        $this->assertSame('254.09', (new GaebCalculator)->documentTotal($again)->getAmount());
    }

    /** Die Angebotsabgabe trägt nur Ordnungszahl und Preis — keine Texte. */
    public function test_bid_carries_prices_without_texts(): void {
        $written = $this->generator->generate($this->boq(), GaebPhase::Bid);

        $this->assertStringNotContainsString("\r\n25", $written);
        $this->assertStringNotContainsString("\r\n21", $written);
        $this->assertStringContainsString("\r\n23", $written);

        $again = $this->parser->parse($written);
        $this->assertSame(2, $again->countItems());
        $this->assertSame('4.9530', $again->getItems()[0]->getUnitPrice()?->getAmount());
    }

    /** Der Abschlusssatz nennt die Positionszahl — daran prüft der Leser die Datei. */
    public function test_closing_record_states_the_item_count(): void {
        $written = $this->generator->generate($this->boq(), GaebPhase::RequestForBid);

        $this->assertSame(2, $this->parser->statedItemCount($written));
        $this->assertSame(2, $this->parser->parse($written)->countItems());
    }
}
