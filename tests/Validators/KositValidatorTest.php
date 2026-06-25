<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : KositValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use ERechnungToolkit\Enums\ValidationSeverity;
use ERechnungToolkit\Validators\KositValidator;
use Tests\Contracts\BaseTestCase;
use ZipArchive;

/**
 * Tests für den KoSIT-basierten E-Rechnung-Validator.
 *
 * Der Validator nutzt standardmäßig das gebündelte tools/kosit/validator.jar.
 * Die Tests laufen nur, wenn eine Java-Laufzeit verfügbar ist - sonst werden
 * sie übersprungen.
 */
class KositValidatorTest extends BaseTestCase {
    private const SAMPLE_DIR = __DIR__ . '/../../.samples/E-Rechnung';

    private KositValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new KositValidator;
    }

    public function test_is_available_returns_bool(): void {
        $this->assertIsBool($this->validator->isAvailable());
    }

    public function test_valid_xrechnung_is_accepted(): void {
        $this->requireValidator();

        $result = $this->validator->validateFile(self::SAMPLE_DIR . '/01.01a-INVOICE_ubl.xml');

        $this->assertTrue($result->isValid(), 'Gültige XRechnung sollte valide sein');
        $this->assertTrue($result->isAccepted(), 'Gültige XRechnung sollte zur Annahme empfohlen werden');
        $this->assertStringContainsString('XRechnung', (string) $result->getScenarioName());
        $this->assertSame([], $result->getErrors());
    }

    public function test_invalid_invoice_is_rejected(): void {
        $this->requireValidator();

        $valid = file_get_contents(self::SAMPLE_DIR . '/01.01a-INVOICE_ubl.xml');
        // Pflichtfeld BT-1 (Rechnungsnummer) entfernen -> Schema-/Schematron-Verstoß.
        $broken = preg_replace('{<cbc:ID>.*?</cbc:ID>}s', '', (string) $valid, 1);

        $result = $this->validator->validate((string) $broken);

        $this->assertFalse($result->isValid(), 'Rechnung ohne BT-1 darf nicht valide sein');
        $this->assertFalse($result->isAccepted());
        $this->assertTrue($result->hasErrors());
        foreach ($result->getErrors() as $error) {
            $this->assertSame(ValidationSeverity::ERROR, $error->getSeverity());
            $this->assertNotSame('', $error->getText());
        }
    }

    public function test_raw_report_is_returned(): void {
        $this->requireValidator();

        $result = $this->validator->validateFile(self::SAMPLE_DIR . '/01.01a-INVOICE_ubl.xml');

        $this->assertStringContainsString('report', (string) $result->getRawReport());
    }

    /**
     * Die offiziellen XRechnung-Testsuite-Instanzen (UBL + CII, Standard +
     * Extension) müssen allesamt zur Annahme empfohlen werden. Alle Dateien
     * werden in einem einzigen Validator-Aufruf geprüft (Batch).
     */
    public function test_official_testsuite_instances_are_all_accepted(): void {
        $this->requireValidator();

        $instances = $this->loadTestsuiteInstances();
        if ($instances === []) {
            $this->markTestSkipped('XRechnung-Testsuite nicht verfügbar (ext-zip oder Bundle fehlt).');
        }

        $results = $this->validator->validateFiles($instances);
        $this->assertCount(count($instances), $results);

        $rejected = [];
        foreach ($results as $path => $result) {
            if (!$result->isAccepted()) {
                $codes = array_map(static fn ($e) => $e->getCode(), $result->getErrors());
                $rejected[] = basename($path) . ' (' . implode(', ', $codes) . ')';
            }
        }

        $this->assertSame([], $rejected, 'Abgelehnte Testsuite-Instanzen: ' . implode('; ', $rejected));
    }

    /**
     * Extrahiert die Instanz-Dateien aus dem mitgelieferten Testsuite-Bundle.
     *
     * @return string[]
     */
    private function loadTestsuiteInstances(): array {
        if (!class_exists(ZipArchive::class)) {
            return [];
        }

        $bundles = glob(__DIR__ . '/../../docs/XRechnung/xrechnung-*-testsuite-*.zip') ?: [];
        if ($bundles === []) {
            return [];
        }

        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xrechnung_testsuite';
        $zip = new ZipArchive;
        if ($zip->open($bundles[0]) !== true) {
            return [];
        }
        $zip->extractTo($target);
        $zip->close();

        return glob($target . '/instances/*/*.xml') ?: [];
    }

    private function requireValidator(): void {
        if (!$this->validator->isAvailable()) {
            $this->markTestSkipped('KoSIT-Validator nicht verfügbar (Java-Laufzeit fehlt).');
        }
    }
}
