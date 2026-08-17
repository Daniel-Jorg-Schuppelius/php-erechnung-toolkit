<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Da11Test.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebTakeoffLine};
use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};
use ERechnungToolkit\Generators\{Da11Generator, GaebWriter};
use ERechnungToolkit\Helper\Gaeb\GaebTakeoffCalculator;
use ERechnungToolkit\Parsers\{Da11Parser, GaebReader};
use Tests\Contracts\BaseTestCase;

/**
 * Die DA11-Datei nach REB-VB 23.003: Kopfsatz und Rechenansatzzeilen. Ab
 * Stelle 13 ist ihre Zeile die der X31 - beide Formate teilen sich deshalb den
 * Satzleser, und genau das prüfen diese Tests.
 */
class Da11Test extends BaseTestCase {
    private function document(): GaebBoq {
        return new GaebBoq(projectName: 'Bonner Wasserwerk', items: [
            new GaebItem(reference: '01.02.0010', takeoffLines: [
                new GaebTakeoffLine(kind: '*', explanation: 'Aufmaß am 11.12.2020', address: '0001B0'),
                new GaebTakeoffLine(explanation: 'Achse 1', formula: '05', values: ['12330', '18550', '4650', '5120'], address: '0001D0'),
                new GaebTakeoffLine(explanation: 'Tür', factor: '-2000', formula: '04', values: ['2010', '0885'], address: '0001E0'),
            ]),
            new GaebItem(reference: '01.02.0020', takeoffLines: [
                new GaebTakeoffLine(formula: '91', values: ['(4,55 + 5,12) * 2='], address: '0002B0'),
            ]),
        ]);
    }

    /**
     * Der Kopfsatz nennt Verfahren, Ausgabe, Überschrift und OZ-Maske an ihren
     * festen Stellen (11-16, 17-20, 21-71, 72-80).
     */
    public function test_header_record_carries_procedure_and_mask(): void {
        $header = explode("\r\n", (new Da11Generator)->generate($this->document()))[0];

        $this->assertSame(80, mb_strlen($header));
        $this->assertSame('00', mb_substr($header, 0, 2));
        $this->assertSame('23.003', trim(mb_substr($header, 10, 6)));
        $this->assertSame('2009', mb_substr($header, 16, 4));
        $this->assertSame('Bonner Wasserwerk', trim(mb_substr($header, 20, 51)));
        $this->assertSame(Da11Generator::DEFAULT_MASK, mb_substr($header, 71, 9));
    }

    /** Datenart, Ordnungszahl und Satz stehen an ihren Stellen. */
    public function test_line_records_carry_type_and_ordinal(): void {
        $lines = explode("\r\n", (new Da11Generator)->generate($this->document()));

        $this->assertSame('11', mb_substr($lines[1], 0, 2));
        $this->assertSame('01020010', trim(mb_substr($lines[1], 2, 9)));
        // Ab Stelle 13 beginnt der Rechenansatz - hier eine Kommentarzeile.
        $this->assertSame('*', mb_substr($lines[1], 12, 1));
        $this->assertSame('Aufmaß am 11.12.2020', trim(mb_substr($lines[1], 13, 56)));
    }

    /** Geschrieben und wieder gelesen ergibt dieselben Mengen. */
    public function test_round_trip_keeps_the_quantities(): void {
        $source = $this->document();
        $raw = (new Da11Generator)->generate($source);
        $again = (new Da11Parser)->parse($raw);

        $calculator = new GaebTakeoffCalculator;
        $before = $calculator->document($source);
        $after = $calculator->document($again);

        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $reference => $survey) {
            $this->assertEqualsWithDelta($survey['quantity'], $after[$reference]['quantity'], 0.0001, $reference);
        }
    }

    /** Die OZ-Maske zerlegt die Ordnungszahl wieder in ihre Gruppen. */
    public function test_mask_splits_the_ordinal_number(): void {
        $boq = (new Da11Parser)->parse((new Da11Generator)->generate($this->document()));

        $this->assertSame(['01.02.0010', '01.02.0020'], array_map(
            static fn (GaebItem $item): string => $item->getReference(),
            $boq->getItems()
        ));
    }

    /** Der Leser erkennt das Format am Inhalt, nicht an der Endung. */
    public function test_reader_detects_the_format(): void {
        $raw = (new Da11Generator)->generate($this->document());

        $reader = new GaebReader;
        $this->assertSame(GaebFormat::Da11, $reader->detect($raw, 'aufmass.txt'));
        $this->assertCount(2, $reader->read($raw, 'aufmass.d11')->getItems());
    }

    /**
     * Neun Stellen sind das Maximum. Eine längere Ordnungszahl zeigt auf die
     * falsche Position, sobald sie gekürzt wird - das muss auffallen.
     */
    public function test_too_long_ordinal_numbers_are_reported(): void {
        $boq = new GaebBoq(items: [
            new GaebItem(reference: '001.002.0010.1', takeoffLines: [
                new GaebTakeoffLine(formula: '04', values: ['2000', '3000'], address: '0001B0'),
            ]),
        ]);

        $generator = new Da11Generator;
        $generator->generate($boq);

        $this->assertCount(1, $generator->getLosses());
        $this->assertStringContainsString('neun Stellen', $generator->getLosses()[0]);
    }

    /** Über den Writer benennt die DA11, was ein Verzeichnis dort verliert. */
    public function test_writer_names_what_a_bill_of_quantity_loses(): void {
        $result = (new GaebWriter)->write($this->document(), GaebFormat::Da11, GaebPhase::Bid);

        $this->assertStringStartsWith('00', $result['content']);
        $this->assertNotSame([], $result['losses']);
        $this->assertStringContainsString('Mengenermittlung', implode(' ', $result['losses']));
    }
}
