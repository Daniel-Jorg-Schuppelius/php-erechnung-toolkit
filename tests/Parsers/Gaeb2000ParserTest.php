<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb2000ParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Parsers\Gaeb2000Parser;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB 2000 reader. The fixture follows the keyword syntax of the
 * published sample file; long texts are RTF there as well.
 */
class Gaeb2000ParserTest extends BaseTestCase {
    private Gaeb2000Parser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new Gaeb2000Parser;
    }

    private function sample(): string {
        return <<<'GAEB'
#begin[GAEB]
 #begin[GAEBInfo]
  [Version]1.1[end]
  [Zeichensatz]ANSI[end]
 #end[GAEBInfo]
 #begin[PrjInfo]
  [Name]Musterprojekt[end]
  [Wae]EUR[end]
 #end[PrjInfo]
 #begin[Vergabe]
  [DP]83[end]
  #begin[LV]
   #begin[LVInfo]
    #begin[LVGlied]
     [Typ]LVStufe[end]
     [Laenge]2[end]
    #end[LVGlied]
    #begin[LVGlied]
     [Typ]LVStufe[end]
     [Laenge]2[end]
    #end[LVGlied]
    #begin[LVGlied]
     [Typ]Position[end]
     [Laenge]4[end]
    #end[LVGlied]
   #end[LVInfo]
   #begin[LVBereich]
    [OZ]01[end]
    [Bez]Beispiele[end]
    #begin[LVBereich]
     [OZ]0101[end]
     [Bez]Erdarbeiten[end]
     #begin[Position]
      [OZ]01010001[end]
      [Menge]150[end]
      [ME]m2[end]
      #begin[Beschreibung]
       [Kurztext]Oberboden abtragen[end]
       [Langtext]{\rtf1{\fonttbl{\f0\fswiss Arial ;}}
Oberboden DIN 18300 abtragen,\par
seitlich lagern.\par}
       [end]
      #end[Beschreibung]
     #end[Position]
    #end[LVBereich]
   #end[LVBereich]
  #end[LV]
 #end[Vergabe]
#end[GAEB]
GAEB;
    }

    /** Verschachtelte Bereiche werden zur Abschnittshierarchie. */
    public function test_reads_nested_groups(): void {
        $boq = $this->parser->parse($this->sample());

        $this->assertSame('83', $boq->getPhaseCode());
        $this->assertSame('Musterprojekt', $boq->getProjectName());
        $this->assertSame('EUR', $boq->getCurrency()->value);

        $sections = $boq->getSections();
        $this->assertCount(2, $sections);
        $this->assertSame('01', $sections[0]->getReference());
        $this->assertNull($sections[0]->getParentReference());
        $this->assertSame('01.01', $sections[1]->getReference());
        $this->assertSame('01', $sections[1]->getParentReference());
    }

    /** Die Ordnungszahl kommt ungeteilt und wird über LVGlied zerlegt. */
    public function test_splits_the_ordinal_number_by_the_declared_levels(): void {
        $item = $this->parser->parse($this->sample())->getItems()[0];

        $this->assertSame('01.01.0001', $item->getReference());
        $this->assertSame('01.01', $item->getSectionReference());
        $this->assertSame('150', $item->getQuantity());
        $this->assertSame('m2', $item->getUnit());
    }

    /** Der Langtext ist RTF und wird zu lesbarem Text reduziert. */
    public function test_rtf_long_text_becomes_plain_text(): void {
        $item = $this->parser->parse($this->sample())->getItems()[0];

        $long = (string) $item->getLongText();
        $this->assertStringContainsString('Oberboden DIN 18300 abtragen', $long);
        $this->assertStringContainsString('seitlich lagern', $long);
        $this->assertStringNotContainsString('\\rtf', $long);
        $this->assertStringNotContainsString('fonttbl', $long);
        $this->assertSame('Oberboden abtragen', $item->getShortText());
    }

    public function test_rejects_content_without_objects(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse("kein GAEB 2000\n");
    }
}
