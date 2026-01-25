<?php
/*
 * Created on   : Sat Jan 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InvoiceHtmlGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Generators\InvoiceHtmlGenerator;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

/**
 * Tests für InvoiceHtmlGenerator.
 */
class InvoiceHtmlGeneratorTest extends TestCase {
    private InvoiceHtmlGenerator $generator;

    protected function setUp(): void {
        $this->generator = new InvoiceHtmlGenerator();
    }

    public function testGenerateReturnsValidHtml(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testGenerateContainsInvoiceData(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        // Rechnungsnummer
        $this->assertStringContainsString('INV-2026-001', $html);

        // Verkäufer
        $this->assertStringContainsString('Test GmbH', $html);

        // Käufer
        $this->assertStringContainsString('Kunde AG', $html);

        // Rechnungstyp
        $this->assertStringContainsString('Rechnung', $html);
    }

    public function testGenerateContainsInvoiceLines(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        // Positionsbeschreibung
        $this->assertStringContainsString('Beratungsleistung', $html);

        // Menge
        $this->assertStringContainsString('10', $html);
    }

    public function testGenerateContainsTotals(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        // Währung
        $this->assertStringContainsString('EUR', $html);

        // Summen sollten formatiert sein
        $this->assertMatchesRegularExpression('/\d+,\d{2}/', $html);
    }

    public function testGenerateContainsZugferdNote(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('ZUGFeRD', $html);
        $this->assertStringContainsString('Factur-X', $html);
        $this->assertStringContainsString('EN 16931', $html);
    }

    public function testGenerateWithPrettyPrintFalse(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice, false);

        // Ohne Pretty Print sollte das HTML kompakter sein
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function testGenerateBodyContentReturnsPartialHtml(): void {
        $invoice = $this->createTestInvoice();
        $bodyHtml = $this->generator->generateBodyContent($invoice);

        // Sollte KEINEN DOCTYPE enthalten
        $this->assertStringNotContainsString('<!DOCTYPE', $bodyHtml);
        $this->assertStringNotContainsString('<html', $bodyHtml);

        // Sollte aber den Inhalt enthalten
        $this->assertStringContainsString('INV-2026-001', $bodyHtml);
        $this->assertStringContainsString('Test GmbH', $bodyHtml);
    }

    public function testGenerateContainsStyleElement(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('</style>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function testGenerateWithNotes(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-25'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 42', '54321', 'Kundenstadt')
            ->addLine('Test', 1, 100.00)
            ->addNote('Dies ist ein Testhinweis')
            ->build();

        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('Hinweise', $html);
        $this->assertStringContainsString('Dies ist ein Testhinweis', $html);
    }

    public function testGenerateWithBankingInfo(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-25'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 42', '54321', 'Kundenstadt')
            ->addLine('Test', 1, 100.00)
            ->build();

        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('IBAN', $html);
        $this->assertStringContainsString('DE89', $html); // IBAN Anfang (kann formatiert sein)
        $this->assertStringContainsString('COBADEFFXXX', $html);
    }

    private function createTestInvoice(): \ERechnungToolkit\Entities\Document {
        return ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-25'))
            ->withDueDate(new DateTimeImmutable('2026-02-25'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withSellerContact('Max Mustermann', '+49 123 456789', 'max@test.de')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 42', '54321', 'Kundenstadt')
            ->addLine('Beratungsleistung', 10, 150.00)
            ->build();
    }
}
