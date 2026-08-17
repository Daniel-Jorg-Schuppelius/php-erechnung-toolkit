<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebSchemaValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use ERechnungToolkit\Entities\Gaeb\GaebParty;
use ERechnungToolkit\Enums\GaebPhase;
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Parsers\GaebDaXmlParser;
use ERechnungToolkit\Validators\GaebSchemaValidator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the bundled GAEB DA XML schemas. Fixtures are self-authored after
 * the published rules — no licensed sample data.
 */
class GaebSchemaValidatorTest extends BaseTestCase {
    private GaebSchemaValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new GaebSchemaValidator;
    }

    private function minimalAward(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo>
    <Version>3.3</Version>
    <VersDate>2021-05</VersDate>
    <Date>2026-01-01</Date>
    <ProgSystem>Fixture</ProgSystem>
  </GAEBInfo>
  <PrjInfo>
    <NamePrj>Prüffall</NamePrj>
    <Cur>EUR</Cur>
  </PrjInfo>
  <Award>
    <DP>83</DP>
    <BoQ ID="BOQ-1">
      <BoQInfo>
        <Name>1</Name>
        <LblBoQ>Leistungsverzeichnis</LblBoQ>
        <Date>2026-01-01</Date>
        <OutlCompl>AllTxt</OutlCompl>
        <BoQBkdn><Type>BoQLevel</Type><Length>3</Length><Num>Yes</Num></BoQBkdn>
        <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
        <NoUPComps>2</NoUPComps>
        <LblUPComp1 Type="Wages">Lohn</LblUPComp1>
        <LblUPComp2 Type="Materials">Stoffe</LblUPComp2>
        <Ctlg>
          <CtlgID>KG</CtlgID>
          <CtlgType>cost group DIN 276 2018-12</CtlgType>
          <CtlgName>Kostengruppen</CtlgName>
        </Ctlg>
        <Ctlg>
          <CtlgID>GEB</CtlgID>
          <CtlgType>locality</CtlgType>
          <CtlgName>Gebäude</CtlgName>
        </Ctlg>
      </BoQInfo>
      <BoQBody>
        <BoQCtgy ID="C1" RNoPart="001">
          <LblTx><p><span>Erdarbeiten</span></p></LblTx>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <UPBkdn>Yes</UPBkdn>
                <Qty>450.000</Qty>
                <QtySplit>
                  <Qty>300.000</Qty>
                  <CtlgAssign><CtlgID>KG</CtlgID><CtlgCode>310</CtlgCode></CtlgAssign>
                  <CtlgAssign><CtlgID>GEB</CtlgID><CtlgCode>H1</CtlgCode></CtlgAssign>
                </QtySplit>
                <QtySplit>
                  <Qty>150.000</Qty>
                  <CtlgAssign><CtlgID>KG</CtlgID><CtlgCode>320</CtlgCode></CtlgAssign>
                </QtySplit>
                <QU>m3</QU>
                <CtlgAssign><CtlgID>KG</CtlgID><CtlgCode>310</CtlgCode></CtlgAssign>
                <Description>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>Boden lösen</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </Description>
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

    public function test_schemas_are_bundled(): void {
        $this->assertTrue($this->validator->isAvailable(), 'GAEB-Schemas fehlen unter data/gaeb/xsd.');

        $available = $this->validator->availableSchemas();
        foreach (['31', '80', '81', '82', '83', '84', '85', '86', '87', '89', '89B', '93', '83Z', '86ZR', '50', '51', '52'] as $phase) {
            $this->assertArrayHasKey($phase, $available, "Schema für Phase {$phase} fehlt.");
            $this->assertArrayHasKey('3.3', $available[$phase] ?? [], "Phase {$phase} liegt nicht in Version 3.3 vor.");
        }

        // Die Typbibliotheken sind keine Dokumentschemas und dürfen nicht auftauchen.
        $this->assertArrayNotHasKey('Lib', $available);
    }

    /**
     * Ältere Linien werden mitgeliefert, damit eingehende Dateien prüfbar sind:
     * 3.2 verhält sich wie 3.3, 3.1 nennt im Namespace weder Phase noch Version
     * und hat zwei Ausgaben, zwischen denen das `VersDate` entscheidet.
     */
    public function test_older_versions_are_available(): void {
        $available = $this->validator->availableSchemas();

        foreach (['81', '83', '84', '86', '83Z', '86ZR'] as $phase) {
            $this->assertArrayHasKey('3.2', $available[$phase] ?? [], "Phase {$phase} fehlt in 3.2.");
        }

        $standard = $this->validator->schemaFile('81', '3.1');
        $bid = $this->validator->schemaFile('84', '3.1');

        $this->assertNotNull($standard);
        $this->assertNotNull($bid);
        $this->assertStringContainsString('_84_3.1_', (string) $bid);
        $this->assertNotSame($standard, $bid);
    }

    public function test_detects_phase_and_version_from_namespace(): void {
        $this->assertSame(
            ['phase' => '83', 'version' => '3.3'],
            $this->validator->detect($this->minimalAward())
        );

        $this->assertNull($this->validator->detect('<foo/>'));
        $this->assertNull($this->validator->detect('kein xml'));
    }

    public function test_x31_uses_the_newer_edition(): void {
        $file = $this->validator->schemaFile('31');

        $this->assertNotNull($file);
        $this->assertStringContainsString('2023-01', (string) $file);
    }

    public function test_validates_a_conforming_award_file(): void {
        $this->assertSame([], $this->validator->validate($this->minimalAward()));
    }

    /**
     * The point of bundling the schemas: what the writer produces has to pass
     * them - in every phase it can write.
     */
    public function test_generated_files_are_schema_valid(): void {
        $boq = (new GaebDaXmlParser)->parse($this->minimalAward());
        $generator = new GaebDaXmlGenerator;

        $lv = $generator->generate($boq, GaebPhase::Lv, 'EUR', '2026-01-01', 'Test');
        $this->assertSame([], $this->validator->validate($lv), 'X81 ist nicht schemavalide.');

        $request = $generator->generate($boq, GaebPhase::RequestForBid, 'EUR', '2026-01-01', 'Test', null, '2026-02-01');
        $this->assertSame([], $this->validator->validate($request), 'X83 ist nicht schemavalide.');

        // Die Angebotsabgabe verlangt den Bieter mit vollständiger Anschrift.
        $bidder = new GaebParty('Muster GmbH', 'Musterweg 1', '12345', 'Musterstadt');
        $bid = $generator->generate($boq, GaebPhase::Bid, 'EUR', '2026-01-01', 'Test', null, null, $bidder);
        $this->assertSame([], $this->validator->validate($bid), 'X84 ist nicht schemavalide.');

        // Preis-Modus: keine Bezeichnungen, keine Einheit, Summe je Gruppe.
        $this->assertStringNotContainsString('<LblTx>', $bid);
        $this->assertStringNotContainsString('<QU>', $bid);
        $this->assertStringContainsString('<Totals>', $bid);
    }

    /**
     * Katalogzuordnung und Mengensplit sind der Mechanismus für Kostengruppe,
     * Gebäude und Modellkennung (Feature 109) — sie müssen den Weg durch Leser
     * und Schreiber unverändert überstehen.
     */
    public function test_catalog_assignments_and_splits_survive(): void {
        $boq = (new GaebDaXmlParser)->parse($this->minimalAward());

        $this->assertCount(2, $boq->getCatalogs());
        $this->assertSame('cost group DIN 276 2018-12', $boq->getCatalogs()[0]->getType());
        $this->assertTrue($boq->getCatalogs()[0]->getCatalogType()?->isCostGroup());

        $item = $boq->getItems()[0];
        $this->assertCount(1, $item->getCatalogAssignments());
        $this->assertSame('310', $item->getCatalogAssignments()[0]->getCode());

        $splits = $item->getQuantitySplits();
        $this->assertCount(2, $splits);
        $this->assertSame('300.000', $splits[0]->getQuantity());
        $this->assertSame(['310', 'H1'], array_map(
            static fn ($assignment): string => $assignment->getCode(),
            $splits[0]->getCatalogAssignments()
        ));

        $again = (new GaebDaXmlGenerator)->generate($boq, GaebPhase::RequestForBid, 'EUR', '2026-01-01', 'Test', null, '2026-02-01');
        $this->assertSame([], $this->validator->validate($again), 'Der Export mit Katalogzuordnungen ist nicht schemavalide.');
        $this->assertCount(2, (new GaebDaXmlParser)->parse($again)->getItems()[0]->getQuantitySplits());
    }

    public function test_reports_schema_violations(): void {
        // Menge ohne Einheit und ein unbekanntes Element: beides muss auffallen.
        $broken = str_replace(
            '<QU>m3</QU>',
            '<Unbekannt>x</Unbekannt>',
            $this->minimalAward()
        );

        $errors = $this->validator->validate($broken);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('Unbekannt', implode("\n", $errors));
    }

    public function test_reports_missing_namespace(): void {
        $errors = $this->validator->validate('<?xml version="1.0"?><GAEB/>');

        $this->assertSame(['Kein GAEB-DA-XML-Namespace am Wurzelelement gefunden.'], $errors);
    }

    public function test_unknown_phase_is_reported_instead_of_throwing(): void {
        $errors = $this->validator->validateAs($this->minimalAward(), '77');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Phase 77', $errors[0]);
    }
}
