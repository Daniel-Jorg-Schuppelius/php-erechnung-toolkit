<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebWriterTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebCatalogAssignment, GaebItem, GaebQuantitySplit, GaebTextComplement};
use ERechnungToolkit\Enums\{GaebFormat, GaebItemType, GaebPhase};
use ERechnungToolkit\Generators\GaebWriter;
use ERechnungToolkit\Parsers\GaebReader;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for writing across the families. What GAEB 90 cannot carry has to be
 * named, not dropped in silence.
 */
class GaebWriterTest extends BaseTestCase {
    private GaebWriter $writer;

    protected function setUp(): void {
        parent::setUp();
        $this->writer = new GaebWriter;
    }

    private function richBoq(): GaebBoq {
        return new GaebBoq(
            projectName: 'Muster',
            items: [
                new GaebItem(
                    reference: '001.002.003.0010.A',
                    shortText: 'Lange Ordnungszahl',
                    quantity: '1.000',
                    unit: 'St',
                    unitPrice: Money::of('10.00', CurrencyCode::Euro, 4),
                    textComplements: [new GaebTextComplement(mark: '60', body: 'Fabrikat')],
                    unitPriceComponents: [Money::of('6.00', CurrencyCode::Euro, 4), Money::of('4.00', CurrencyCode::Euro, 4)],
                    changeOrderNo: '1',
                    catalogAssignments: [new GaebCatalogAssignment('KG', '310')],
                    quantitySplits: [new GaebQuantitySplit(quantity: '0.500')],
                ),
                new GaebItem(reference: '001.002.H01', type: GaebItemType::Note, longText: 'Hinweis'),
            ],
        );
    }

    /** Der Verlust wird benannt, mit Ursache und Anzahl. */
    public function test_names_what_the_grid_cannot_carry(): void {
        $result = $this->writer->write($this->richBoq(), GaebFormat::Gaeb90, GaebPhase::Award);

        $joined = implode("\n", $result['losses']);
        $this->assertStringContainsString('Ordnungszahlen sind länger', $joined);
        $this->assertStringContainsString('Textergänzungen', $joined);
        $this->assertStringContainsString('Einheitspreise', $joined);
        $this->assertStringContainsString('Katalogzuordnungen', $joined);
        $this->assertStringContainsString('Teilmengen', $joined);
        $this->assertStringContainsString('Nachtragspositionen', $joined);
        $this->assertStringContainsString('Hinweistexte', $joined);
        $this->assertNotSame('', $result['content']);
    }

    /** In die eigene Familie zurück gibt es nichts zu melden. */
    public function test_da_xml_reports_no_loss(): void {
        $result = $this->writer->write($this->richBoq(), GaebFormat::DaXml, GaebPhase::Award);

        $this->assertSame([], $result['losses']);
        $this->assertSame(GaebFormat::DaXml, (new GaebReader)->detect($result['content']));
    }

    /** GAEB 2000 trägt mehr als das Raster, aber nicht die Zusätze von DA XML. */
    public function test_keyword_format_names_its_own_gaps(): void {
        $result = $this->writer->write($this->richBoq(), GaebFormat::Gaeb2000, GaebPhase::Award);

        $joined = implode("\n", $result['losses']);
        $this->assertStringContainsString('Textergänzungen', $joined);
        $this->assertStringContainsString('Katalogzuordnungen', $joined);
        // Die lange Ordnungszahl ist hier kein Problem - anders als im Raster.
        $this->assertStringNotContainsString('Ordnungszahlen sind länger', $joined);
        $this->assertSame(GaebFormat::Gaeb2000, (new GaebReader)->detect($result['content']));
    }

    /** Eine unbekannte Familie lässt sich nicht schreiben. */
    public function test_unknown_target_is_refused(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->writer->write($this->richBoq(), GaebFormat::Unknown, GaebPhase::Award);
    }
}
