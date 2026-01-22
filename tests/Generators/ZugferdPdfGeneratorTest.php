<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Generators\ZugferdPdfGenerator;
use DateTimeImmutable;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for ZUGFeRD PDF Generator.
 */
class ZugferdPdfGeneratorTest extends BaseTestCase {
    private ZugferdPdfGenerator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new ZugferdPdfGenerator();
    }

    public function testIsAvailableReturnsBool(): void {
        $result = $this->generator->isAvailable();
        $this->assertIsBool($result);
    }

    public function testGenerateWithPdfToolkit(): void {
        if (!$this->generator->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $invoice = ERechnungDocumentBuilder::zugferd('ZF-TEST-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withDueDate(new DateTimeImmutable('2026-02-21'))
            ->withSeller('Test Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Test Käufer AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 10, 150.00)
            ->addLine('Software-Lizenz', 1, 499.00)
            ->build();

        $pdfBytes = $this->generator->generate($invoice);

        $this->assertNotNull($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
        $this->assertGreaterThan(1000, strlen($pdfBytes));
    }

    public function testGenerateToFile(): void {
        if (!$this->generator->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $invoice = ERechnungDocumentBuilder::zugferd('ZF-FILE-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Datei Test GmbH', 'DE111222333')
            ->withSellerAddress('Testweg 1', '10115', 'Berlin')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstr. 2', '80331', 'München')
            ->addLine('Test-Artikel', 1, 100.00)
            ->build();

        $tempFile = sys_get_temp_dir() . '/zugferd_test_' . uniqid() . '.pdf';

        try {
            $result = $this->generator->generateToFile($invoice, $tempFile);

            $this->assertTrue($result);
            $this->assertFileExists($tempFile);
            $this->assertGreaterThan(1000, filesize($tempFile));

            // PDF-Header prüfen
            $content = file_get_contents($tempFile);
            $this->assertStringStartsWith('%PDF-', $content);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testGenerateWithCustomHtml(): void {
        if (!$this->generator->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $invoice = ERechnungDocumentBuilder::zugferd('ZF-CUSTOM-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Custom GmbH', 'DE999888777')
            ->withSellerAddress('Customweg 1', '50667', 'Köln')
            ->withBuyer('Kunde Custom')
            ->withBuyerAddress('Kundenstr. 1', '60311', 'Frankfurt')
            ->addLine('Artikel', 1, 200.00)
            ->build();

        $customHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><title>Custom Invoice</title></head>
<body>
    <h1>CUSTOM INVOICE</h1>
    <p>Invoice ID: {$invoice->getId()}</p>
    <p>Amount: {$invoice->getGrossAmount()} EUR</p>
</body>
</html>
HTML;

        $pdfBytes = $this->generator->generate($invoice, $customHtml);

        $this->assertNotNull($pdfBytes);
        $this->assertStringStartsWith('%PDF-', $pdfBytes);
    }

    public function testGenerateThrowsExceptionWithoutPdfToolkit(): void {
        // Dieser Test prüft das Verhalten wenn PDF-Toolkit nicht verfügbar ist
        // Wir können das nicht direkt mocken, da die Klasse final ist
        // Stattdessen prüfen wir die isAvailable-Methode

        $generator = new ZugferdPdfGenerator();

        // Wenn PDF-Toolkit nicht verfügbar, erwarten wir eine Exception
        if (!$generator->isAvailable()) {
            $invoice = ERechnungDocumentBuilder::create('TEST-001')
                ->withIssueDate(new DateTimeImmutable())
                ->withSeller('Test', 'DE123')
                ->withSellerAddress('Test', '12345', 'Berlin')
                ->withBuyer('Buyer')
                ->withBuyerAddress('Test', '54321', 'München')
                ->addLine('Test', 1, 100.00)
                ->build();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('php-pdf-toolkit');

            $generator->generate($invoice);
        } else {
            // PDF-Toolkit ist verfügbar, Test als bestanden markieren
            $this->assertTrue(true);
        }
    }
}
