<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTakeoffTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Parsers\GaebDaXmlParser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the quantity survey (X31, REB-VB 23.003). The column layout was
 * verified against the official BVBS test file; the fixture here follows it.
 */
class GaebTakeoffTest extends BaseTestCase {
    /**
     * Baut eine Aufmaßzeile nach dem festen Raster (Spalte 13 = Kennzeichen).
     *
     * @param list<string> $values
     */
    private function row(string $kind, string $explanation, string $factor, string $formula, array $values, string $address): string {
        $row = str_repeat(' ', 12) . $kind
            . mb_substr(str_pad($explanation, 11), 0, 11)
            . str_pad(mb_substr($factor, 0, 5), 5, ' ', STR_PAD_LEFT)
            . str_pad(mb_substr($formula, 0, 2), 2, ' ', STR_PAD_LEFT)
            . '  ';
        foreach (array_pad($values, 5, '') as $value) {
            $row .= str_pad(mb_substr((string) $value, 0, 7), 7, ' ', STR_PAD_LEFT);
        }
        $row .= ' ' . str_pad($address, 6);

        return $row . str_repeat(' ', max(0, 80 - mb_strlen($row)));
    }

    /** @param list<string> $rows */
    private function file(array $rows): string {
        $items = '';
        foreach ($rows as $row) {
            $items .= '<QDetermItem><QTakeoff Row="' . htmlspecialchars($row, ENT_XML1) . '"/></QDetermItem>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA31/3.3">'
            . '<GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2026-01-01</Date></GAEBInfo>'
            . '<QtyDeterm><DP>31</DP><BoQ ID="B1"><BoQBody><Itemlist>'
            . '<Item ID="I1" RNoPart="0010"><QtyDeterm>' . $items . '</QtyDeterm></Item>'
            . '</Itemlist></BoQBody></BoQ></QtyDeterm></GAEB>';
    }

    public function test_reads_kind_formula_values_and_address(): void {
        $xml = $this->file([
            $this->row(' ', 'Achse 1', '', '05', ['12330', '18550', '4650', '5120'], '0003B0'),
            $this->row(' ', 'Achse 2', '20000', '05', ['6450', '7340'], '0003C0'),
        ]);

        $lines = (new GaebDaXmlParser)->parse($xml)->getItems()[0]->getTakeoffLines();

        $this->assertCount(2, $lines);
        $this->assertSame('Achse 1', $lines[0]->getExplanation());
        $this->assertSame('05', $lines[0]->getFormula());
        $this->assertSame(['12330', '18550', '4650', '5120'], $lines[0]->getValues());
        $this->assertSame('0003B0', $lines[0]->getAddress());
        $this->assertSame('20000', $lines[1]->getFactor());
    }

    /**
     * Umlaute dürfen das Raster nicht verschieben: die Spalten zählen Zeichen,
     * `substr` zählt Bytes - „Aufmaß" verschiebt sonst alles dahinter.
     */
    public function test_umlauts_do_not_shift_the_columns(): void {
        $xml = $this->file([
            $this->row('*', 'Aufmaß am 11 12 2020', '', '', [], '0001B0'),
            $this->row(' ', 'Grabentiefe', '', '04', ['19000', '6500'], '0001C0'),
        ]);

        $lines = (new GaebDaXmlParser)->parse($xml)->getItems()[0]->getTakeoffLines();

        $this->assertTrue($lines[0]->isComment());
        $this->assertStringContainsString('Aufmaß', (string) $lines[0]->getExplanation());
        $this->assertSame('0001B0', $lines[0]->getAddress());
        $this->assertSame('04', $lines[1]->getFormula());
        $this->assertSame(['19000', '6500'], $lines[1]->getValues());
        $this->assertSame('0001C0', $lines[1]->getAddress());
    }

    /** Hilfswerte zählen nicht zur Menge, Kommentare erst recht nicht. */
    public function test_helpers_and_comments_stay_out_of_the_quantity(): void {
        $xml = $this->file([
            $this->row('*', 'Hinweis', '', '', [], '0004B0'),
            $this->row('H', 'Hilfswert', '', '04', ['2120', '2560'], '0004C0'),
            $this->row(' ', 'Graben A', '', '04', ['2050', '2700'], '0004D0'),
            $this->row('Z', 'Teil 1', '', '04', ['3500', '4600'], '0004E0'),
        ]);

        $lines = (new GaebDaXmlParser)->parse($xml)->getItems()[0]->getTakeoffLines();

        $this->assertFalse($lines[0]->countsTowardsQuantity());
        $this->assertTrue($lines[1]->isHelper());
        $this->assertFalse($lines[1]->countsTowardsQuantity());
        $this->assertTrue($lines[2]->countsTowardsQuantity());
        $this->assertTrue($lines[3]->isSubtotal());
    }

    /** Die freie Formel 91 trägt einen Ausdruck, keine Wertfelder. */
    public function test_free_formula_carries_an_expression(): void {
        $row = str_repeat(' ', 12) . ' ' . str_repeat(' ', 16) . '91(4,55 + 5,12) * 2=';
        $row = str_pad($row, 69) . '0008B0';
        $xml = $this->file([$row . str_repeat(' ', max(0, 80 - mb_strlen($row)))]);

        $line = (new GaebDaXmlParser)->parse($xml)->getItems()[0]->getTakeoffLines()[0];

        $this->assertSame('91', $line->getFormula());
        $this->assertSame('(4,55 + 5,12) * 2=', $line->getValues()[0]);
        $this->assertTrue($line->closesResult());
    }
}
