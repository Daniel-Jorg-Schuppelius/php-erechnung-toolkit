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

use ERechnungToolkit\Enums\{GaebFormat, GaebPhase};
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Helper\Gaeb\{GaebTakeoffCalculator, GaebTakeoffRecord};
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
 * Set `GAEB_REFERENCE_DIR` to point at the directory holding them; how to get
 * the material is described in `docs/GAEB/referenzmaterial.md`.
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
            $this->markTestSkipped(
                "Kein Referenzmaterial für {$what} unter {$this->referenceDir}. "
                . 'Beschaffung: docs/GAEB/referenzmaterial.md bzw. scripts/fetch-gaeb-reference.sh.'
            );
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
     * Die Aufmaßsätze der Prüfdatei müssen den Schreiber **byteweise**
     * überstehen. Das ist der schärfste Nachweis für die Spaltengrenzen: Jede
     * Verschiebung um ein Zeichen fällt sofort auf, während sie in der Rechnung
     * lange unbemerkt bliebe.
     */
    public function test_quantity_survey_records_survive_byte_for_byte(): void {
        $files = $this->files('pruefdateien/extracted/*.{x31,X31}');
        $this->skipWithout($files, 'die Mengenermittlung');

        $record = new GaebTakeoffRecord;
        foreach ($files as $file) {
            $raw = (string) file_get_contents($file);
            $boq = (new GaebReader)->read($raw, basename($file));

            preg_match_all('/Row="([^"]*)"/', $raw, $matches);
            $expected = array_map(html_entity_decode(...), $matches[1]);

            $index = 0;
            foreach ($boq->getItems() as $item) {
                foreach ($item->getTakeoffLines() as $line) {
                    $this->assertSame(
                        rtrim($expected[$index] ?? '<fehlt>'),
                        rtrim($record->render($line)),
                        basename($file) . ": Satz {$index} weicht ab."
                    );
                    $index++;
                }
            }
            $this->assertSame(count($expected), $index, basename($file) . ': Satzzahl weicht ab.');
        }
    }

    /**
     * Und jede Rechenzeile der Prüfdatei muss auch gerechnet werden. Bliebe
     * eine Formel übrig, wäre die Menge stillschweigend zu klein - der Grund,
     * warum der Rechner Übersprungenes überhaupt meldet.
     */
    public function test_every_formula_of_the_test_file_is_computed(): void {
        $files = $this->files('pruefdateien/extracted/*.{x31,X31}');
        $this->skipWithout($files, 'die Mengenermittlung');

        foreach ($files as $file) {
            $boq = (new GaebReader)->read((string) file_get_contents($file), basename($file));
            foreach ((new GaebTakeoffCalculator)->document($boq) as $reference => $survey) {
                $this->assertSame([], $survey['skipped'], basename($file) . ", Position {$reference}.");
            }
        }
    }

    /**
     * Der X31-Export muss schemavalide sein und dieselben Mengen liefern -
     * sonst wäre die Datei zwar lesbar, aber fachlich eine andere.
     */
    public function test_quantity_survey_export_is_valid_and_lossless(): void {
        $files = $this->files('pruefdateien/extracted/*.{x31,X31}');
        $this->skipWithout($files, 'die Mengenermittlung');

        $calculator = new GaebTakeoffCalculator;
        foreach ($files as $file) {
            $source = (new GaebReader)->read((string) file_get_contents($file), basename($file));
            $xml = (new GaebDaXmlGenerator)->generate($source, GaebPhase::QuantitySurvey);

            $this->assertSame([], (new GaebSchemaValidator)->validate($xml), basename($file) . ': Export nicht schemavalide.');

            $again = (new GaebReader)->read($xml, 'roundtrip.x31');
            $before = $calculator->document($source);
            $after = $calculator->document($again);

            $this->assertSame(array_keys($before), array_keys($after), 'Positionen gingen verloren.');
            foreach ($before as $reference => $survey) {
                $this->assertEqualsWithDelta(
                    $survey['quantity'],
                    $after[$reference]['quantity'],
                    0.0001,
                    "Menge von {$reference} weicht nach dem Schreiben ab."
                );
            }
        }
    }

    /**
     * Round-Trip über **echte Kundendateien** (MVP-635): Jede Datei aus
     * laufenden Verfahren wird gelesen, geschrieben und erneut gelesen — die
     * Positionszahl und jede Ordnungszahl müssen überstehen.
     *
     * Das ist der Nachweis, der mit Musterdateien nicht zu führen ist:
     * Prüfdateien decken die Grenzfälle des Standards ab, Kundendateien die
     * Eigenheiten der Werkzeuge, mit denen tatsächlich gearbeitet wird. Die
     * Dateien liegen außerhalb des Repositorys und werden nirgends
     * mitgeliefert; ohne sie überspringt der Test.
     */
    public function test_real_world_files_survive_a_round_trip(): void {
        $files = $this->files('realdaten/*.{X81,X82,X83,X84,X86,x81,x82,x83,x84,x86}');
        $this->skipWithout($files, 'den Round-Trip über echte Dateien');

        $reader = new GaebReader;
        $generator = new GaebDaXmlGenerator;

        foreach ($files as $file) {
            $name = basename($file);
            $source = $reader->read((string) file_get_contents($file), $name);

            $phase = GaebPhase::fromCode($source->getPhaseCode());
            if ($phase === null || !$phase->isWritableAsDaXml()) {
                continue;
            }

            $xml = $generator->generate($source, $phase);
            $this->assertSame([], (new GaebSchemaValidator)->validate($xml), $name . ': Export nicht schemavalide.');

            $again = $reader->read($xml, $name);
            $this->assertSame($source->countItems(), $again->countItems(), $name . ': Positionszahl weicht ab.');

            // Die Ordnungszahl ist der Schlüssel, über den die Gegenseite
            // zuordnet - geht sie verloren, ist die Datei wertlos, auch wenn
            // die Zahl der Positionen stimmt.
            $before = array_map(static fn ($item): string => $item->getReference(), $source->getItems());
            $after = array_map(static fn ($item): string => $item->getReference(), $again->getItems());
            $this->assertSame($before, $after, $name . ': Ordnungszahlen weichen ab.');
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
