<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebDaXmlParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Parsers\GaebDaXmlParser;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the GAEB DA XML reader and writer. All fixtures are self-authored
 * after the published rules — no licensed sample data.
 */
class GaebDaXmlParserTest extends BaseTestCase {
    private GaebDaXmlParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new GaebDaXmlParser;
    }

    private function sample(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><ProgSystem>Fixture</ProgSystem></GAEBInfo>
  <PrjInfo><NamePrj>Prüffall</NamePrj><Cur>EUR</Cur></PrjInfo>
  <Award>
    <DP>83</DP>
    <BoQ ID="BOQ-1">
      <BoQInfo>
        <Name>1</Name>
        <NoUPComps>2</NoUPComps>
        <LblUPComp1 Type="Wages">Lohn</LblUPComp1>
        <LblUPComp2 Type="Material">Stoffe</LblUPComp2>
      </BoQInfo>
      <BoQBody>
        <BoQCtgy RNoPart="001">
          <LblTx><p><span>Erdarbeiten</span></p></LblTx>
          <BoQBody>
            <Itemlist>
              <Item RNoPart="0010">
                <UPBkdn>Yes</UPBkdn>
                <Qty>450.000</Qty>
                <QU>m3</QU>
                <UP>20.000</UP>
                <UPComp1>15.000</UPComp1>
                <UPComp2>5.000</UPComp2>
                <Description><CompleteText>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Boden lösen</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
              </Item>
              <Item RNoPart="0010" RNoIndex="A">
                <Provis>WithTotal</Provis>
                <Qty>1.000</Qty>
                <QU>Psch</QU>
                <Description><CompleteText>
                  <ComplTSB>Yes</ComplTSB>
                  <DetailTxt>
                    <Text><p><span>Wasserhaltung mit einer</span></p></Text>
                    <TextComplement MarkLbl="60" Kind="Bidder">
                      <ComplCaption><span>Leistung von</span></ComplCaption>
                      <ComplBody><span>.....</span></ComplBody>
                      <ComplTail><span>kW.</span></ComplTail>
                    </TextComplement>
                  </DetailTxt>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Wasserhaltung</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
              </Item>
              <Item RNoPart="0020">
                <ALNGroupNo>1</ALNGroupNo>
                <ALNSerNo>0</ALNSerNo>
                <Qty>10.000</Qty>
                <QU>m2</QU>
                <Description><CompleteText>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Grundausführung</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
              </Item>
              <Item RNoPart="0030">
                <Qty>1.000</Qty>
                <QU>Stck</QU>
                <Description><CompleteText>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Leitbeschreibung</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
                <SumDescr>Yes</SumDescr>
                <SubDescr><SubDNo>01</SubDNo><Qty>2.000</Qty><QU>Stck</QU></SubDescr>
              </Item>
              <MarkupItem RNoPart="0040">
                <MarkupType>AllInCat</MarkupType>
                <Description><CompleteText>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Zuschlag</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
              </MarkupItem>
              <Remark>
                <Description><CompleteText>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Hinweis ohne Ordnungszahl</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
              </Remark>
            </Itemlist>
          </BoQBody>
        </BoQCtgy>
      </BoQBody>
    </BoQ>
  </Award>
</GAEB>
XML;
    }

    public function test_reads_version_phase_and_project(): void {
        $boq = $this->parser->parse($this->sample());

        $this->assertSame('3.3', $boq->getVersion());
        $this->assertSame(GaebPhase::RequestForBid, $boq->getPhase());
        $this->assertSame('83', $boq->getPhaseCode());
        $this->assertSame('Prüffall', $boq->getProjectName());
        $this->assertSame(1, $boq->countSections());
        $this->assertSame(6, $boq->countItems());
    }

    /** The index level lives in RNoIndex; without it ordinal numbers collide. */
    public function test_builds_ordinal_numbers_including_the_index_level(): void {
        $refs = [];
        foreach ($this->parser->parse($this->sample())->getItems() as $item) {
            $refs[] = $item->getReference();
        }

        $this->assertSame([
            '001.0010',
            '001.0010.A',
            '001.0020',
            '001.0030',
            '001.0040',
            '001.H01',
        ], $refs);
        $this->assertSame($refs, array_unique($refs));
    }

    public function test_reads_item_kinds_and_their_traits(): void {
        $byRef = [];
        foreach ($this->parser->parse($this->sample())->getItems() as $item) {
            $byRef[$item->getReference()] = $item;
        }

        $this->assertSame(GaebItemType::Standard, $byRef['001.0010']->getType());
        $this->assertSame(['15.000', '5.000'], $byRef['001.0010']->getUnitPriceComponents());
        $this->assertTrue($byRef['001.0010']->unitPriceComponentsAddUp());

        $this->assertSame(GaebItemType::Optional, $byRef['001.0010.A']->getType());
        $this->assertSame('WithTotal', $byRef['001.0010.A']->getProvisionKind());

        $this->assertSame(GaebItemType::Base, $byRef['001.0020']->getType());
        $this->assertSame('1', $byRef['001.0020']->getAlternativeGroup());
        $this->assertSame(0, $byRef['001.0020']->getAlternativeNo());

        $this->assertCount(1, $byRef['001.0030']->getSubDescriptions());
        $this->assertSame('2.000', $byRef['001.0030']->getSubDescriptions()[0]->getQuantity());

        $this->assertSame(GaebItemType::Markup, $byRef['001.0040']->getType());
        $this->assertSame('AllInCat', $byRef['001.0040']->getMarkupType());

        $this->assertSame(GaebItemType::Note, $byRef['001.H01']->getType());
    }

    public function test_keeps_text_complements_with_their_marks(): void {
        $byRef = [];
        foreach ($this->parser->parse($this->sample())->getItems() as $item) {
            $byRef[$item->getReference()] = $item;
        }

        $item = $byRef['001.0010.A'];
        $this->assertSame('Wasserhaltung mit einer [[TC:60]]', $item->getLongText());
        $this->assertCount(1, $item->getTextComplements());
        $this->assertSame('60', $item->getTextComplements()[0]->getMark());
        $this->assertTrue($item->getTextComplements()[0]->isBidderComplement());
        $this->assertSame('Leistung von', $item->getTextComplements()[0]->getCaption());
    }

    public function test_detects_mismatching_unit_price_components(): void {
        $xml = str_replace('<UPComp2>5.000</UPComp2>', '<UPComp2>1.000</UPComp2>', $this->sample());
        $byRef = [];
        foreach ($this->parser->parse($xml)->getItems() as $item) {
            $byRef[$item->getReference()] = $item;
        }

        $this->assertFalse($byRef['001.0010']->unitPriceComponentsAddUp());
    }

    /** Writing and reading again must not lose anything. */
    public function test_round_trip_keeps_structure_and_traits(): void {
        $original = $this->parser->parse($this->sample());
        $xml = (new GaebDaXmlGenerator)->generate($original, GaebPhase::Bid, 'EUR', '2026-01-01', 'WorkDiary');

        $this->assertStringContainsString('<MarkupItem', $xml);
        $this->assertStringContainsString('<Remark', $xml);
        $this->assertStringContainsString('RNoIndex="A"', $xml);
        $this->assertStringContainsString('<Provis>WithTotal</Provis>', $xml);
        $this->assertStringContainsString('<ALNSerNo>0</ALNSerNo>', $xml);
        $this->assertStringContainsString('<SumDescr>Yes</SumDescr>', $xml);
        $this->assertStringContainsString('<ComplTSB>Yes</ComplTSB>', $xml);
        $this->assertStringContainsString('<TextComplement MarkLbl="60" Kind="Bidder">', $xml);
        $this->assertStringContainsString('<UPBkdn>Yes</UPBkdn>', $xml);
        $this->assertStringNotContainsString('[[TC:', $xml);
        // GAEBInfo describes the writing program, never the imported one.
        $this->assertStringContainsString('<ProgSystem>WorkDiary</ProgSystem>', $xml);

        $again = $this->parser->parse($xml);
        $this->assertSame($original->countItems(), $again->countItems());
        $this->assertSame($original->countSections(), $again->countSections());

        $refs = [];
        $types = [];
        foreach ($again->getItems() as $item) {
            $refs[] = $item->getReference();
            $types[] = $item->getType()->value;
        }
        $this->assertSame(['001.0010', '001.0010.A', '001.0020', '001.0030', '001.0040', '001.H01'], $refs);
        $this->assertSame(['standard', 'optional', 'base', 'standard', 'markup', 'note'], $types);
        $this->assertCount(2, $again->getUpComponents());
    }

    /** Same input and date produce byte identical output. */
    public function test_generator_is_deterministic(): void {
        $boq = $this->parser->parse($this->sample());
        $generator = new GaebDaXmlGenerator;

        $this->assertSame(
            $generator->generate($boq, GaebPhase::Bid, 'EUR', '2026-01-01'),
            $generator->generate($boq, GaebPhase::Bid, 'EUR', '2026-01-01')
        );
    }

    public function test_rejects_non_gaeb_xml(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse('<root><foo/></root>');
    }

    /** Hardening: a DOCTYPE is rejected instead of expanding entities. */
    public function test_rejects_doctype_to_prevent_xxe(): void {
        $payload = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE GAEB [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3"><Award><BoQ><BoQBody><Itemlist><Item RNoPart="1"><Qty>1</Qty><QU>&xxe;</QU></Item></Itemlist></BoQBody></BoQ></Award></GAEB>
XML;

        $this->expectException(InvalidArgumentException::class);
        $this->parser->parse($payload);
    }
}
