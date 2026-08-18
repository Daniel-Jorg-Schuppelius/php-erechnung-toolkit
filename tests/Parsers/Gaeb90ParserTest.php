<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb90ParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Helper\Gaeb\GaebCalculator;
use ERechnungToolkit\Parsers\Gaeb90Parser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB 90 reader. The fixtures follow the published record layout
 * (80 characters, running number in columns 75 to 80) and are self-authored -
 * the column positions were verified against the public GAEB test files.
 */
class Gaeb90ParserTest extends BaseTestCase {
    private Gaeb90Parser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new Gaeb90Parser;
    }

    /** @param list<string> $bodies */
    private function file(array $bodies): string {
        $out = '';
        foreach ($bodies as $i => $body) {
            $out .= str_pad(substr($body, 0, 74), 74) . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT) . "\r\n";
        }

        return $out;
    }

    /** Eröffnungssatz: Phase in Spalte 11-12, OZ-Maske ab Spalte 63. */
    private function opening(string $phase): string {
        return str_pad('00', 10) . $phase . str_pad('Musterprojekt', 50) . '1122PPPPI90';
    }

    private function award(): string {
        return $this->file([
            $this->opening('83'),
            '1111       N',
            '12Erdarbeiten',
            '211111  10 NNN         00000051300m2  ',
            '25Boden loesen',
            '26   Oberboden abtragen und seitlich lagern',
            '211111  20 NNN         00000002000St  ',
            '25Schacht setzen',
            '99                                                                   00002',
        ]);
    }

    /** Gruppen, Positionen, Mengen und Texte kommen aus dem festen Raster. */
    public function test_reads_groups_items_and_texts(): void {
        $boq = $this->parser->parse($this->award());

        $this->assertSame('83', $boq->getPhaseCode());
        $this->assertCount(1, $boq->getSections());
        $this->assertSame('Erdarbeiten', $boq->getSections()[0]->getLabel());

        $items = $boq->getItems();
        $this->assertCount(2, $items);
        $this->assertSame('11.11.10', $items[0]->getReference());
        $this->assertSame('51.300', $items[0]->getQuantity());
        $this->assertSame('m2', $items[0]->getUnit());
        $this->assertSame('Boden loesen', $items[0]->getShortText());
        $this->assertStringContainsString('Oberboden abtragen', (string) $items[0]->getLongText());
    }

    /** Der Abschlusssatz nennt die Positionszahl — daran prüft sich der Leser. */
    public function test_closing_record_states_the_item_count(): void {
        $this->assertSame(2, $this->parser->statedItemCount($this->award()));
        $this->assertSame(2, $this->parser->parse($this->award())->countItems());
    }

    /**
     * Die Angebotsabgabe trägt keine Positionssätze, nur Ordnungszahl und Preis
     * — und der Einheitspreis führt eine elfte Stelle für den 1/10-Cent.
     */
    public function test_bid_file_carries_prices_only(): void {
        $bid = $this->file([
            $this->opening('84'),
            '231111  10  00000004953 000000254089',
            '99                                                                   00001',
        ]);

        $boq = $this->parser->parse($bid);
        $items = $boq->getItems();

        $this->assertCount(1, $items);
        $this->assertSame('11.11.10', $items[0]->getReference());
        $this->assertSame('4.9530', $items[0]->getUnitPrice()?->getAmount());
        $this->assertSame('2540.8900', $items[0]->getTotalPrice()?->getAmount());
        $this->assertSame('2540.89', (new GaebCalculator)->documentTotal($boq)->getAmount());
    }

    /**
     * Der Bereich 70–89 ist in GAEB 90 herstellerfrei; manche Systeme legen
     * dort Kostengruppen ab. Geraten wird nichts — aber wer eine Datei
     * einliest, soll erfahren, dass sie etwas trägt, das hier niemand deutet.
     */
    public function test_vendor_record_types_are_reported_instead_of_swallowed(): void {
        $content = $this->file([
            $this->opening('83'),
            '211111  10 NNN         00000051300m2  ',
            '25Boden loesen',
            // Herstellerfreier Satz - Inhalt unbekannt, Existenz meldenswert.
            '751111  10 KG310',
            '99                                                                   00001',
        ]);

        $unknown = $this->parser->unknownRecordTypes($content);
        $this->assertSame(['75' => 1], $unknown);
        $this->assertSame(['75' => 1], $this->parser->vendorRecordTypes($content));

        // Und der Rest der Datei wird davon nicht gestört.
        $boq = $this->parser->parse($content);
        $this->assertSame(1, $boq->countItems());
    }

    /** Bekannte Satzarten tauchen in der Meldung nicht auf. */
    public function test_known_record_types_are_not_reported(): void {
        $this->assertSame([], $this->parser->unknownRecordTypes($this->award()));
    }

    public function test_rejects_content_without_records(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->parse("   \n\n");
    }

    /**
     * Zeilenart 24 eröffnet eine Unterbeschreibung mit eigener Nummer, Menge
     * und Einheit. Ihr Kurztext gehört ihr - er überschrieb bislang den der
     * Position, sodass „Einfachfenster Kiefer" als „Schlußbeschichtung"
     * ankam.
     */
    public function test_sub_descriptions_keep_their_own_text(): void {
        $grid = implode("\r\n", [
            $this->pad('00   83') . '1122PPPPI',
            $this->pad('11001'),
            $this->pad('12Fenster'),
            $this->pad('21001 0010 NNL    X    00000017000St'),
            $this->pad('25Einfachfenster Kiefer'),
            $this->pad('26   Langtext der Position'),
            $this->pad('2401 00000025000St    18505326001011211'),
            $this->pad('25Erneuerungsbeschichtung'),
            $this->pad('26   Langtext der Unterbeschreibung'),
            $this->pad('99'),
        ]) . "\r\n";

        $item = (new Gaeb90Parser)->parse($grid)->getItems()[0];

        // Der Text der Position bleibt ihrer.
        $this->assertSame('Einfachfenster Kiefer', $item->getShortText());
        // Die Einrückung der Langtextzeilen bleibt erhalten - sie ist Teil des
        // Textbilds, das die Vergabestelle so gesetzt hat.
        $this->assertStringContainsString('Langtext der Position', (string) $item->getLongText());
        $this->assertStringNotContainsString('Unterbeschreibung', (string) $item->getLongText());

        $this->assertCount(1, $item->getSubDescriptions());
        $sub = $item->getSubDescriptions()[0];
        $this->assertSame('01', $sub->getNo());
        $this->assertSame('25.000', $sub->getQuantity());
        $this->assertSame('St', $sub->getUnit());
    }

    /** Füllt einen Satz auf die feste Breite auf. */
    private function pad(string $record): string {
        return $record . str_repeat(' ', max(0, 80 - mb_strlen($record)));
    }
}
