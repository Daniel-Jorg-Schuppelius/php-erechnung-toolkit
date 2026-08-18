<?php
/*
 * Created on   : Tue Aug 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCalculationDataTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Enums\GaebPhase;
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Parsers\GaebDaXmlParser;
use Tests\Contracts\BaseTestCase;

/**
 * Kalkulationsdaten X52 (Feature 109, MVP-647).
 *
 * Die **Kostenarten stehen im Kopf**, die **Kostenansätze an der Position** —
 * ein Betrieb schlägt nach Kostenart zu, nicht je Position. Die dokumentierte
 * Umrechnung lautet `KW = Menge × Wert ÷ Leistung`; ohne Leistung steht der
 * Wert für sich, denn durch eine angenommene Leistung zu teilen veränderte die
 * Kalkulation still.
 */
class GaebCalculationDataTest extends BaseTestCase {
    private function xml(): string {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA52/3.3">
          <GAEBInfo><Version>3.3</Version><Date>2026-08-18</Date><ProgSystem>Test</ProgSystem></GAEBInfo>
          <PrjInfo><NamePrj>Neubau Kita</NamePrj><Cur>EUR</Cur></PrjInfo>
          <Award>
            <DP>52</DP>
            <Cur>EUR</Cur>
            <BoQ ID="BOQ-1">
              <BoQInfo>
                <Name>1</Name>
                <BoQBkdn><Type>BoQLevel</Type><Length>3</Length><Num>Yes</Num></BoQBkdn>
                <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
                <CostType Key="LO"><CostDescription><p><span>Lohn</span></p></CostDescription><CostTypeUnit>h</CostTypeUnit><Markup>12.500000</Markup></CostType>
                <CostType Key="MA"><CostDescription><p><span>Material</span></p></CostDescription><CostTypeUnit>kg</CostTypeUnit></CostType>
              </BoQInfo>
              <BoQBody>
                <BoQCtgy RNoPart="001" ID="C-001">
                  <LblTx><p><span>Erdarbeiten</span></p></LblTx>
                  <BoQBody>
                    <Itemlist>
                      <Item RNoPart="0010" ID="I-1">
                        <Qty>100.000</Qty>
                        <QU>m3</QU>
                        <Description><CompleteText><OutlineText><OutlTxt><TextOutlTxt><p><span>Boden loesen</span></p></TextOutlTxt></OutlTxt></OutlineText></CompleteText></Description>
                        <CostApproach Key="LO">
                          <CostApproachQty>2.500</CostApproachQty>
                          <CostApproachQU>h</CostApproachQU>
                          <Performance>1.000</Performance>
                          <Value>48.000</Value>
                        </CostApproach>
                        <CostApproach Key="MA">
                          <CostApproachQty>15.000</CostApproachQty>
                          <Value>1.200</Value>
                        </CostApproach>
                      </Item>
                    </Itemlist>
                  </BoQBody>
                </BoQCtgy>
              </BoQBody>
            </BoQ>
          </Award>
        </GAEB>
        XML;
    }

    /** Kostenarten im Kopf, Ansätze an der Position — mit Zuschlag und Einheit. */
    public function test_reads_cost_types_and_approaches(): void {
        $boq = (new GaebDaXmlParser)->parse($this->xml());

        $types = $boq->getCostTypes();
        $this->assertCount(2, $types);
        $this->assertSame('LO', $types[0]->getKey());
        $this->assertSame('h', $types[0]->getUnit());
        $this->assertSame('12.500000', $types[0]->getMarkup());
        // Ohne Zuschlag bleibt das Feld leer statt auf 0 gesetzt.
        $this->assertNull($types[1]->getMarkup());

        $items = $boq->getItems();
        $approaches = $items[0]->getCostApproaches();
        $this->assertCount(2, $approaches);
        $this->assertSame('LO', $approaches[0]->getCostTypeKey());
        $this->assertSame('2.500', $approaches[0]->getQuantity());
        $this->assertSame('48.000', $approaches[0]->getValue());
        // Ohne eigene Einheit gilt die der Kostenart — sie wird nicht kopiert.
        $this->assertNull($approaches[1]->getUnit());
    }

    /** Der Round-Trip hält Kostenarten und Ansätze fest. */
    public function test_calculation_data_survives_a_round_trip(): void {
        $source = (new GaebDaXmlParser)->parse($this->xml());
        $xml = (new GaebDaXmlGenerator)->generate($source, GaebPhase::CalculationData);

        $this->assertStringContainsString('<CostType Key="LO">', $xml);
        $this->assertStringContainsString('<CostApproach Key="MA">', $xml);

        $again = (new GaebDaXmlParser)->parse($xml);
        $this->assertCount(2, $again->getCostTypes());
        $this->assertSame('12.5', $again->getCostTypes()[0]->getMarkup());

        $approaches = $again->getItems()[0]->getCostApproaches();
        $this->assertCount(2, $approaches);
        $this->assertSame('LO', $approaches[0]->getCostTypeKey());
        $this->assertSame('48', $approaches[0]->getValue());
        $this->assertNull($approaches[1]->getUnit());
    }

    /** In anderen Phasen tauchen die Kalkulationsdaten nicht auf. */
    public function test_other_phases_do_not_carry_calculation_data(): void {
        $source = (new GaebDaXmlParser)->parse($this->xml());
        $xml = (new GaebDaXmlGenerator)->generate($source, GaebPhase::Lv);

        $this->assertStringNotContainsString('CostApproach', $xml);
        $this->assertStringNotContainsString('CostType', $xml);
    }
}
