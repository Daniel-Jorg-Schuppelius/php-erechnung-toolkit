<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebFormatDetectorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};
use ERechnungToolkit\Helper\Gaeb\GaebFormatDetector;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the family detection. The GAEB 90 sample follows the published
 * record layout (80 characters, running number in columns 75 to 80).
 */
class GaebFormatDetectorTest extends BaseTestCase {
    private GaebFormatDetector $detector;

    protected function setUp(): void {
        parent::setUp();
        $this->detector = new GaebFormatDetector;
    }

    private function gaeb90(): string {
        $lines = [
            '00        83L                                                                1122PPPPI90',
            '01Musterdatei                             030701                                        ',
            '11001                                                                                   ',
        ];

        $out = '';
        foreach ($lines as $i => $line) {
            $out .= substr($line, 0, 74) . str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT) . "\n";
        }

        return $out;
    }

    public function test_recognises_da_xml_and_its_phase(): void {
        $xml = '<?xml version="1.0"?><GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA84/3.3"></GAEB>';
        $result = $this->detector->detect($xml, 'angebot.x84');

        $this->assertSame(GaebFormat::DaXml, $result['format']);
        $this->assertSame(GaebPhase::Bid, $result['phase']);
        $this->assertTrue($result['format']->isSupported());
    }

    public function test_recognises_gaeb_2000_by_its_markers(): void {
        $content = "[Zeichensatz]ANSI[end]\n#begin[LV]\n[Bezeichnung]Muster[end]\n#end[LV]\n";

        $this->assertSame(GaebFormat::Gaeb2000, $this->detector->format($content));
    }

    /** GAEB 90 wird am Satzraster erkannt, nicht an der Endung. */
    public function test_recognises_gaeb_90_by_the_record_grid(): void {
        $result = $this->detector->detect($this->gaeb90(), 'lv.d83');

        $this->assertSame(GaebFormat::Gaeb90, $result['format']);
        $this->assertSame('83', $result['phaseCode']);
        // Alle drei Familien werden gelesen und geschrieben; nur eine nicht
        // erkannte Datei bleibt außen vor.
        $this->assertTrue($result['format']->isSupported());
        $this->assertTrue($result['format']->isWritable());
        $this->assertTrue(GaebFormat::Gaeb2000->isWritable());
        $this->assertFalse(GaebFormat::Unknown->isSupported());
        $this->assertFalse(GaebFormat::Unknown->isWritable());
    }

    /** Fehlt die Kennung im Inhalt, hilft die Endung — aber nur als letzter Ausweg. */
    public function test_extension_only_fills_the_phase(): void {
        $xml = '<?xml version="1.0"?><GAEB></GAEB>';
        $result = $this->detector->detect($xml, 'unterlage.x89B');

        $this->assertSame(GaebFormat::DaXml, $result['format']);
        $this->assertSame(GaebPhase::InvoiceAttachment, $result['phase']);
    }

    public function test_unknown_content_stays_unknown(): void {
        $this->assertSame(GaebFormat::Unknown, $this->detector->format("Hallo Welt\nkeine GAEB-Datei\n"));
    }
}
