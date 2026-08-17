<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebConformanceTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use ERechnungToolkit\Enums\GaebFormat;
use ERechnungToolkit\Parsers\GaebReader;
use ERechnungToolkit\Validators\GaebSchemaValidator;
use Tests\Contracts\BaseTestCase;

/**
 * Conformance gate (MVP-566). Runs against the official BVBS test files and the
 * GAEB sample files, which are not redistributed with this package - they live
 * outside the repository. Where they are absent the test skips instead of
 * failing, so the suite stays green for anyone without the material, while a
 * developer who has it gets the real check.
 *
 * Set `GAEB_REFERENCE_DIR` to point at the directory holding them.
 */
class GaebConformanceTest extends BaseTestCase {
    private string $referenceDir;

    protected function setUp(): void {
        parent::setUp();
        $this->referenceDir = (string) (getenv('GAEB_REFERENCE_DIR') ?: (getenv('HOME') . '/gaeb-referenz'));
    }

    /** @return list<string> */
    private function files(string $pattern): array {
        $found = glob($this->referenceDir . '/' . $pattern, GLOB_BRACE);

        return $found === false ? [] : $found;
    }

    /** @param list<string> $files */
    private function skipWithout(array $files, string $what): void {
        if ($files === []) {
            $this->markTestSkipped("Kein Referenzmaterial für {$what} unter {$this->referenceDir}.");
        }
    }

    /**
     * Jede offizielle Prüf- und Musterdatei muss gegen ihr eigenes Schema
     * validieren - das ist die Messlatte, an der sich der Leser hält.
     */
    public function test_official_files_validate_against_their_schema(): void {
        $files = array_merge(
            $this->files('pruefdateien/extracted/*.{x81,x83,x84,x86,x31,X81,X83,X84,X86,X31}'),
            $this->files('musterdateien/*.{X81,X83,X84,X86,X83Z,X84Z,X86ZE,X86ZR}'),
        );
        $this->skipWithout($files, 'die Schemavalidierung');

        $validator = new GaebSchemaValidator;
        foreach ($files as $file) {
            $xml = (string) file_get_contents($file);
            $this->assertSame([], $validator->validate($xml), basename($file) . ' ist nicht schemavalide.');
        }
    }

    /** Und jede muss sich lesen lassen, mit Positionen darin. */
    public function test_official_files_are_readable(): void {
        $files = array_merge(
            $this->files('musterdateien/*.{X81,X83,X84,X86,X83Z,X84Z,X86ZE,X86ZR}'),
            $this->files('gaeb90/*.{d81,d82,d83,d84,d86}'),
        );
        $this->skipWithout($files, 'das Lesen');

        $reader = new GaebReader;
        foreach ($files as $file) {
            $boq = $reader->read((string) file_get_contents($file), basename($file));
            $this->assertGreaterThan(0, $boq->countItems(), basename($file) . ' liefert keine Positionen.');
        }
    }

    /**
     * Der Abschlusssatz einer GAEB-90-Datei nennt die Positionszahl. Weicht der
     * Leser davon ab, sind Sätze verloren gegangen - genau dafür steht die Zahl
     * in der Datei.
     */
    public function test_gaeb_90_matches_its_own_item_count(): void {
        $files = $this->files('gaeb90/*.{d81,d82,d83,d84,d86}');
        $this->skipWithout($files, 'GAEB 90');

        $reader = new GaebReader;
        $parser = new \ERechnungToolkit\Parsers\Gaeb90Parser;

        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            if ($reader->detect($raw, basename($file)) !== GaebFormat::Gaeb90) {
                continue;
            }
            $content = $reader->decode($raw, GaebFormat::Gaeb90);
            $stated = $parser->statedItemCount($content);
            if ($stated === null) {
                continue;
            }
            $this->assertSame($stated, $parser->parse($content)->countItems(), basename($file) . ': Positionszahl weicht ab.');
        }
    }
}
