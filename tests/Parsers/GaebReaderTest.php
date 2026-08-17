<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebReaderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};
use ERechnungToolkit\Generators\Gaeb90Generator;
use ERechnungToolkit\Parsers\GaebReader;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the entry point across the families. Fixtures are self-authored.
 */
class GaebReaderTest extends BaseTestCase {
    private GaebReader $reader;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = new GaebReader;
    }

    private function xml(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2026-01-01</Date></GAEBInfo>
  <PrjInfo><NamePrj>Muster</NamePrj><Cur>EUR</Cur></PrjInfo>
  <Award><DP>83</DP><BoQ ID="B1"><BoQInfo><Name>1</Name></BoQInfo><BoQBody><Itemlist>
    <Item ID="I1" RNoPart="0010"><Qty>5.000</Qty><QU>m2</QU></Item>
  </Itemlist></BoQBody></BoQ></Award>
</GAEB>
XML;
    }

    private function keywordFile(): string {
        return "#begin[GAEB]\n #begin[PrjInfo]\n  [Name]Muster[end]\n  [Wae]EUR[end]\n"
            . " #end[PrjInfo]\n #begin[Vergabe]\n  [DP]83[end]\n #end[Vergabe]\n#end[GAEB]\n";
    }

    public function test_reads_every_family_through_one_entry_point(): void {
        $this->assertSame(GaebFormat::DaXml, $this->reader->detect($this->xml()));
        $this->assertSame(1, $this->reader->read($this->xml())->countItems());

        $grid = (new Gaeb90Generator)->generate($this->reader->read($this->xml()), GaebPhase::RequestForBid);
        $this->assertSame(GaebFormat::Gaeb90, $this->reader->detect($grid));
        $this->assertSame(1, $this->reader->read($grid)->countItems());

        $this->assertSame(GaebFormat::Gaeb2000, $this->reader->detect($this->keywordFile()));
        $this->assertSame('83', $this->reader->read($this->keywordFile())->getPhaseCode());
    }

    /**
     * Die Endung entscheidet nicht: die veröffentlichte Beispieldatei
     * `GAEB2000.d83` ist trotz ihres Namens GAEB 2000.
     */
    public function test_content_beats_the_extension(): void {
        $this->assertSame(GaebFormat::Gaeb2000, $this->reader->detect($this->keywordFile(), 'GAEB2000.d83'));
    }

    /** Alte Codepages werden beim Lesen aufgelöst, nicht vom Aufrufer. */
    public function test_decodes_the_code_page_of_the_family(): void {
        $latin = mb_convert_encoding('Baugelände abräumen', 'CP850', 'UTF-8');

        $this->assertSame('Baugelände abräumen', $this->reader->decode($latin, GaebFormat::Gaeb90));
        $this->assertSame('schon UTF-8: äöü', $this->reader->decode('schon UTF-8: äöü', GaebFormat::Gaeb90));
    }

    public function test_unknown_content_is_rejected_with_its_name(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unbekannt\.txt/');
        $this->reader->read("Hallo Welt\n", 'unbekannt.txt');
    }
}
