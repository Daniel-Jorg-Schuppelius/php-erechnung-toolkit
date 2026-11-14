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

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Generators\InvoiceHtmlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests für InvoiceHtmlGenerator.
 */
class InvoiceHtmlGeneratorTest extends TestCase {
    private InvoiceHtmlGenerator $generator;

    protected function setUp(): void {
        $this->generator = new InvoiceHtmlGenerator;
    }

    public function test_generate_returns_valid_html(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_generate_contains_invoice_data(): void {
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

    public function test_generate_contains_invoice_lines(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        // Positionsbeschreibung
        $this->assertStringContainsString('Beratungsleistung', $html);

        // Menge
        $this->assertStringContainsString('10', $html);
    }

    public function test_generate_contains_totals(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        // Währung
        $this->assertStringContainsString('EUR', $html);

        // Summen sollten formatiert sein
        $this->assertMatchesRegularExpression('/\d+,\d{2}/', $html);
    }

    public function test_generate_contains_zugferd_note(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('ZUGFeRD', $html);
        $this->assertStringContainsString('Factur-X', $html);
        $this->assertStringContainsString('EN 16931', $html);
    }

    public function test_generate_with_pretty_print_false(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice, false);

        // Ohne Pretty Print sollte das HTML kompakter sein
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_generate_body_content_returns_partial_html(): void {
        $invoice = $this->createTestInvoice();
        $bodyHtml = $this->generator->generateBodyContent($invoice);

        // Sollte KEINEN DOCTYPE enthalten
        $this->assertStringNotContainsString('<!DOCTYPE', $bodyHtml);
        $this->assertStringNotContainsString('<html', $bodyHtml);

        // Sollte aber den Inhalt enthalten
        $this->assertStringContainsString('INV-2026-001', $bodyHtml);
        $this->assertStringContainsString('Test GmbH', $bodyHtml);
    }

    public function test_generate_contains_style_element(): void {
        $invoice = $this->createTestInvoice();
        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('</style>', $html);
        $this->assertStringContainsString('@page', $html);
    }

    public function test_generate_with_notes(): void {
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

    public function test_generate_with_banking_info(): void {
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

    public function test_delivery_address_without_name_renders_no_empty_heading(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-002')
            ->withIssueDate(new DateTimeImmutable('2026-11-12'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->withDeliveryAddress('Havenweg 5', '3011 AA', 'Rotterdam', 'NL')
            ->addLine('Test', 1, 100.00)
            ->build();

        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('Lieferadresse:', $html);
        $this->assertStringContainsString('Havenweg 5', $html);
        $this->assertStringNotContainsString('<strong></strong>', $html);
    }

    public function test_meta_section_names_each_value_by_its_meaning(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-003')
            ->withIssueDate(new DateTimeImmutable('2026-11-14'))
            ->withDeliveryDate(new DateTimeImmutable('2026-11-10'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 42', '54321', 'Kundenstadt')
            ->withBuyerReference('K-4711')
            ->withBuyerEndpoint('eingang@kunde.example', 'EM')
            ->addLine('Test', 1, 100.00)
            ->build();

        $html = $this->generator->generate($invoice);

        // Die Referenz ist keine Leitweg-ID, die Adresse keine Kundennummer
        $this->assertStringContainsString('Ihre Referenz', $html);
        $this->assertStringNotContainsString('Leitweg-ID', $html);
        $this->assertStringNotContainsString('Kunden-Nr.', $html);
        // "Ihre" USt-IdNr. ist die des Käufers, die eigene steht daneben
        $this->assertMatchesRegularExpression('/Ihre USt-IdNr\..*Unsere USt-IdNr\..*DE987654321.*DE123456789/s', $html);
        // Leistungsdatum (§ 14 Abs. 4 Nr. 6 UStG)
        $this->assertStringContainsString('Leistungsdatum', $html);
        $this->assertStringContainsString('10.11.2026', $html);
    }

    public function test_meta_section_shows_a_leitweg_id_and_a_tax_number(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-004')
            ->withIssueDate(new DateTimeImmutable('2026-11-14'))
            ->withInvoicingPeriod(new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-31'))
            ->withSeller('Klein GmbH', '', '30/123/45678')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withBuyer('Stadt Musterhausen')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterhausen')
            ->withBuyerLeitwegId('04011000-12345-67')
            ->addLine('Test', 1, 100.00)
            ->build();

        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('Leitweg-ID', $html);
        $this->assertStringContainsString('Steuernummer', $html);
        $this->assertStringContainsString('30/123/45678', $html);
        $this->assertStringNotContainsString('Ihre USt-IdNr.', $html);
        $this->assertStringContainsString('Leistungszeitraum', $html);
        $this->assertStringContainsString('01.10.2026 - 31.10.2026', $html);
    }

    public function test_payment_line_without_bic_and_bank_has_no_empty_brackets(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-005')
            ->withIssueDate(new DateTimeImmutable('2026-11-14'))
            ->withSeller('Test GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Musterstadt')
            ->withSellerBankAccount('DE89370400440532013000')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 42', '54321', 'Kundenstadt')
            ->addLine('Test', 1, 100.00)
            ->build();

        $html = $this->generator->generate($invoice);

        $this->assertStringContainsString('IBAN', $html);
        $this->assertStringNotContainsString('BIC', $html);
        $this->assertStringNotContainsString('()', $html);
        // Ohne Kontakt keine leeren Zeilen "Tel: -" und "E-Mail: -"
        $this->assertStringNotContainsString('Tel:', $html);
        $this->assertStringNotContainsString('E-Mail:', $html);
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
