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

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\SupportingDocument;
use ERechnungToolkit\Enums\AttachmentMimeCode;
use ERechnungToolkit\Generators\ZugferdPdfGenerator;
use ERechnungToolkit\Parsers\ZugferdPdfParser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for ZUGFeRD PDF Generator.
 */
class ZugferdPdfGeneratorTest extends BaseTestCase {
    private ZugferdPdfGenerator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new ZugferdPdfGenerator;
    }

    public function test_is_available_returns_bool(): void {
        $result = $this->generator->isAvailable();
        $this->assertContains($result, [true, false]);
    }

    public function test_generate_with_pdf_toolkit(): void {
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

    public function test_generate_to_file(): void {
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
            $this->assertNotFalse($content);
            $this->assertStringStartsWith('%PDF-', $content);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function test_generate_with_custom_html(): void {
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

    /**
     * Ab php-pdf-toolkit 0.31 liegen die Unterlagen zusätzlich als PDF/A-3-Dateien
     * („Supplement“) neben factur-x.xml.
     */
    public function test_supporting_documents_are_embedded_in_the_pdf(): void {
        if (!$this->generator->isAvailable() || !defined('PDFToolkit\\Writers\\ZugferdWriter::ATTACHMENT_RELATIONSHIPS')) {
            $this->markTestSkipped('php-pdf-toolkit >= 0.31 is not installed');
        }

        $invoice = ERechnungDocumentBuilder::zugferd('RE-2026-0100')
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addSupportingDocument(SupportingDocument::embedded('LN-1', "datum;minuten\n2026-09-02;90\n", AttachmentMimeCode::CSV, 'nachweis-1.csv', 'Leistungsnachweis'))
            ->addLine('Beratung', 1, 100.00)
            ->build();

        $pdf = $this->generator->generate($invoice, '<h1>Rechnung</h1>', ['render_engine' => 'tcpdf']);
        $this->assertNotNull($pdf);
        $this->assertStringContainsString('factur-x.xml', $pdf);
        $this->assertStringContainsString('nachweis-1.csv', $pdf);
        $this->assertStringContainsString('/AFRelationship /Supplement', $pdf);

        $plain = $this->generator->generate($invoice, '<h1>Rechnung</h1>', ['render_engine' => 'tcpdf', 'embed_supporting_documents' => false]);
        $this->assertNotNull($plain);
        $this->assertStringNotContainsString('/AFRelationship /Supplement', $plain);
    }

    /**
     * Eine echte PDF als Leistungsnachweis: Die Engine von TCPDF 7 legt sie roh
     * ab; ohne Neuaufbau der Querverweise (php-pdf-toolkit PDFXrefHelper) war
     * die Rechnung danach unlesbar.
     */
    public function test_real_pdf_supporting_document_keeps_the_invoice_readable(): void {
        if (!$this->generator->isAvailable() || !class_exists('PDFToolkit\\Helper\\PDFXrefHelper')) {
            $this->markTestSkipped('php-pdf-toolkit >= 0.31 is not installed');
        }

        $attachment = $this->tcpdfDocument();
        $invoice = ERechnungDocumentBuilder::zugferd('RE-2026-0101')
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addSupportingDocument(SupportingDocument::embedded('LN-1', $attachment, AttachmentMimeCode::PDF, 'nachweis-1.pdf', 'Leistungsnachweis'))
            ->addLine('Beratung', 1, 100.00)
            ->build();

        $pdf = $this->generator->generate($invoice, '<h1>Rechnung RE-2026-0101</h1>', ['render_engine' => 'tcpdf']);
        $this->assertNotNull($pdf);
        $path = sys_get_temp_dir() . '/zugferd-real-attachment-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($path, $pdf);

        try {
            $this->assertSame(1, \PDFToolkit\Helper\PDFHelper::getPageCount($path));
            if (trim((string) shell_exec('command -v qpdf')) !== '') {
                exec('qpdf --check ' . escapeshellarg($path) . ' 2>&1', $check, $code);
                $this->assertSame(0, $code, implode("\n", $check));
                $this->assertStringNotContainsString('WARNING', implode("\n", $check));
            }

            $parser = new ZugferdPdfParser;
            $this->assertEqualsCanonicalizing(['factur-x.xml', 'nachweis-1.pdf'], $parser->listAttachments($path));
            $this->assertSame($attachment, (new \PDFToolkit\Readers\ZugferdReader)->extractAttachment($path, 'nachweis-1.pdf'));
            $parsed = $parser->parseFile($path);
            $this->assertNotNull($parsed);
            $this->assertSame($attachment, $parsed->getSupportingDocuments()[0]->getContent());
        } finally {
            @unlink($path);
        }
    }

    private function tcpdfDocument(): string {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        foreach ([1, 2] as $page) {
            $pdf->AddPage();
            $pdf->Write(5, "Leistungsnachweis, Seite {$page}");
        }

        return (string) $pdf->Output('', 'S');
    }

    public function test_generate_returns_null_without_pdf_toolkit(): void {
        // Dieser Test prüft das Verhalten wenn PDF-Toolkit nicht verfügbar ist

        $generator = new ZugferdPdfGenerator;

        // Wenn PDF-Toolkit nicht verfügbar, erwarten wir null-Rückgabe
        if (!$generator->isAvailable()) {
            $invoice = ERechnungDocumentBuilder::create('TEST-001')
                ->withIssueDate(new DateTimeImmutable)
                ->withSeller('Test', 'DE123')
                ->withSellerAddress('Test', '12345', 'Berlin')
                ->withBuyer('Buyer')
                ->withBuyerAddress('Test', '54321', 'München')
                ->addLine('Test', 1, 100.00)
                ->build();

            $result = $generator->generate($invoice);
            $this->assertNull($result);
        } else {
            // PDF-Toolkit ist verfügbar, der Null-Rückgabe-Pfad ist hier nicht testbar.
            $this->addToAssertionCount(1);
        }
    }
}
