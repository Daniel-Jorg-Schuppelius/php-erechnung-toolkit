<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Generators\ERechnungGenerator;
use ERechnungToolkit\Parsers\ERechnungParser;
use DateTimeImmutable;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung Parser.
 */
class ERechnungParserTest extends BaseTestCase {
    private ERechnungParser $parser;
    private ERechnungGenerator $generator;

    protected function setUp(): void {
        parent::setUp();

        $this->parser = new ERechnungParser();
        $this->generator = new ERechnungGenerator();
    }

    public function testParseUblInvoice(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 10, 150.00, 19.0)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $this->assertEquals('INV-2026-001', $parsed->getId());
        $this->assertEquals('2026-01-22', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertEquals(InvoiceType::INVOICE, $parsed->getInvoiceType());
        $this->assertEquals(CurrencyCode::Euro, $parsed->getCurrency());
    }

    public function testParseUblSellerParty(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerContact('Max Mustermann', '+49 30 12345', 'max@muster.de')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Test', 1, 100.00)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $seller = $parsed->getSeller();
        $this->assertEquals('Muster GmbH', $seller->getName());
        $this->assertEquals('DE123456789', $seller->getVatId());
        $this->assertNotNull($seller->getPostalAddress());
        $this->assertEquals('Musterstraße 1', $seller->getPostalAddress()->getStreetName());
        $this->assertEquals('12345', $seller->getPostalAddress()->getPostalCode());
        $this->assertEquals('Berlin', $seller->getPostalAddress()->getCity());
    }

    public function testParseUblBuyerParty(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Test', 1, 100.00)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $buyer = $parsed->getBuyer();
        $this->assertEquals('Kunde AG', $buyer->getName());
        $this->assertEquals('DE987654321', $buyer->getVatId());
        $this->assertNotNull($buyer->getPostalAddress());
        $this->assertEquals('54321', $buyer->getPostalAddress()->getPostalCode());
        $this->assertEquals('München', $buyer->getPostalAddress()->getCity());
    }

    public function testParseUblInvoiceLines(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 10, 150.00, 19.0)
            ->addLine('Software-Lizenz', 1, 499.00, 19.0)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $lines = $parsed->getLines();
        $this->assertCount(2, $lines);

        $this->assertEquals('Beratungsleistung', $lines[0]->getItemName());
        $this->assertEquals(10.0, $lines[0]->getQuantity());
        $this->assertEquals(150.00, $lines[0]->getUnitPrice());
        $this->assertEquals(1500.00, $lines[0]->getNetAmount());
        $this->assertEquals(19.0, $lines[0]->getTaxPercent());

        $this->assertEquals('Software-Lizenz', $lines[1]->getItemName());
        $this->assertEquals(1.0, $lines[1]->getQuantity());
        $this->assertEquals(499.00, $lines[1]->getNetAmount());
    }

    public function testParseUblTaxTotal(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00, 19.0)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $taxTotal = $parsed->getTaxTotal();
        $this->assertNotNull($taxTotal);
        $this->assertEquals(19.00, $taxTotal->getTaxAmount());

        $subtotals = $taxTotal->getSubtotals();
        $this->assertCount(1, $subtotals);
        $this->assertEquals(100.00, $subtotals[0]->getTaxableAmount());
        $this->assertEquals(19.0, $subtotals[0]->getPercent());
    }

    public function testParseUblMonetaryTotal(): void {
        $original = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00, 19.0)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $total = $parsed->getMonetaryTotal();
        $this->assertNotNull($total);
        $this->assertEquals(100.00, $total->getLineExtensionAmount());
        $this->assertEquals(100.00, $total->getTaxExclusiveAmount());
        $this->assertEquals(119.00, $total->getTaxInclusiveAmount());
        $this->assertEquals(119.00, $total->getPayableAmount());
    }

    public function testParseCiiInvoice(): void {
        $original = ERechnungDocumentBuilder::zugferd('ZF-2026-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferantenweg 5', '50667', 'Köln')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstraße 10', '60311', 'Frankfurt')
            ->addLine('Ware A', 5, 100.00, 19.0)
            ->build();

        $xml = $this->generator->generateCii($original);
        $parsed = $this->parser->parse($xml);

        $this->assertEquals('ZF-2026-001', $parsed->getId());
        $this->assertEquals('2026-01-22', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertEquals(InvoiceType::INVOICE, $parsed->getInvoiceType());
    }

    public function testParseCiiSellerParty(): void {
        $original = ERechnungDocumentBuilder::zugferd('ZF-2026-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferantenweg 5', '50667', 'Köln')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstraße 10', '60311', 'Frankfurt')
            ->addLine('Test', 1, 100.00)
            ->build();

        $xml = $this->generator->generateCii($original);
        $parsed = $this->parser->parse($xml);

        $seller = $parsed->getSeller();
        $this->assertEquals('Lieferant GmbH', $seller->getName());
        $this->assertEquals('DE123456789', $seller->getVatId());
    }

    public function testParseCiiInvoiceLines(): void {
        $original = ERechnungDocumentBuilder::zugferd('ZF-2026-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferantenweg 5', '50667', 'Köln')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstraße 10', '60311', 'Frankfurt')
            ->addLine('Ware A', 5, 100.00, 19.0)
            ->addLine('Ware B', 10, 50.00, 19.0)
            ->build();

        $xml = $this->generator->generateCii($original);
        $parsed = $this->parser->parse($xml);

        $lines = $parsed->getLines();
        $this->assertCount(2, $lines);

        $this->assertEquals('Ware A', $lines[0]->getItemName());
        $this->assertEquals(5.0, $lines[0]->getQuantity());
        $this->assertEquals(100.00, $lines[0]->getUnitPrice());
        $this->assertEquals(500.00, $lines[0]->getNetAmount());
    }

    public function testRoundtripUbl(): void {
        $original = ERechnungDocumentBuilder::create('RT-UBL-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withDueDate(new DateTimeImmutable('2026-02-21'))
            ->withSeller('Roundtrip Seller GmbH', 'DE111111111')
            ->withSellerAddress('Sellerstraße 1', '10115', 'Berlin')
            ->withBuyer('Roundtrip Buyer AG', 'DE222222222')
            ->withBuyerAddress('Buyerweg 2', '80333', 'München')
            ->withOrderReference('ORDER-001')
            ->addLine('Produkt A', 5, 100.00, 19.0)
            ->addLine('Produkt B', 3, 200.00, 19.0)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        // Compare essential fields
        $this->assertEquals($original->getId(), $parsed->getId());
        $this->assertEquals(
            $original->getIssueDate()->format('Y-m-d'),
            $parsed->getIssueDate()->format('Y-m-d')
        );
        $this->assertEquals($original->getInvoiceType(), $parsed->getInvoiceType());
        $this->assertEquals($original->getCurrency(), $parsed->getCurrency());
        $this->assertEquals($original->countLines(), $parsed->countLines());
        $this->assertEquals($original->getOrderReference(), $parsed->getOrderReference());

        // Compare seller
        $this->assertEquals(
            $original->getSeller()->getName(),
            $parsed->getSeller()->getName()
        );
        $this->assertEquals(
            $original->getSeller()->getVatId(),
            $parsed->getSeller()->getVatId()
        );

        // Compare buyer
        $this->assertEquals(
            $original->getBuyer()->getName(),
            $parsed->getBuyer()->getName()
        );

        // Compare totals
        $this->assertEquals(
            $original->getMonetaryTotal()->getLineExtensionAmount(),
            $parsed->getMonetaryTotal()->getLineExtensionAmount()
        );
        $this->assertEquals(
            $original->getTaxTotal()->getTaxAmount(),
            $parsed->getTaxTotal()->getTaxAmount()
        );
    }

    public function testRoundtripCii(): void {
        $original = ERechnungDocumentBuilder::zugferd('RT-CII-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('CII Seller GmbH', 'DE333333333')
            ->withSellerAddress('CII-Straße 1', '50667', 'Köln')
            ->withBuyer('CII Buyer AG')
            ->withBuyerAddress('CII-Weg 2', '60311', 'Frankfurt')
            ->addLine('CII Produkt', 2, 250.00, 19.0)
            ->build();

        $xml = $this->generator->generateCii($original);
        $parsed = $this->parser->parse($xml);

        $this->assertEquals($original->getId(), $parsed->getId());
        $this->assertEquals(
            $original->getIssueDate()->format('Y-m-d'),
            $parsed->getIssueDate()->format('Y-m-d')
        );
        $this->assertEquals(
            $original->getSeller()->getName(),
            $parsed->getSeller()->getName()
        );
        $this->assertEquals($original->countLines(), $parsed->countLines());
    }

    public function testParseXRechnungProfile(): void {
        $original = ERechnungDocumentBuilder::xrechnung('XR-PARSE-001', '04011000-12345-67')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('XRechnung Seller', 'DE444444444')
            ->withSellerAddress('XR-Straße 1', '10115', 'Berlin')
            ->withSellerEndpoint('seller@example.com', 'EM')
            ->withBuyer('Öffentliche Stelle')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId('04011000-12345-67')
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $xml = $this->generator->generateUbl($original);
        $parsed = $this->parser->parse($xml);

        $this->assertEquals(ERechnungProfile::XRECHNUNG, $parsed->getProfile());
    }

    public function testParseInvalidXmlThrowsException(): void {
        $this->expectException(\Exception::class);

        $this->parser->parse('This is not valid XML');
    }

    public function testParseUnknownFormatThrowsException(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown E-Rechnung format');

        $xml = '<?xml version="1.0"?><UnknownRoot xmlns="http://unknown.namespace"/>';
        $this->parser->parse($xml);
    }

    /**
     * Test parsing real XRechnung sample file.
     */
    public function testParseRealXRechnungSample(): void {
        $samplePath = __DIR__ . '/../../../.samples/E-Rechnung/01.01a-INVOICE_ubl.xml';

        if (!file_exists($samplePath)) {
            $this->markTestSkipped('XRechnung sample file not found');
        }

        $doc = $this->parser->parseFile($samplePath);

        // Document metadata
        $this->assertEquals('123456XX', $doc->getId());
        $this->assertEquals('2016-04-04', $doc->getIssueDate()->format('Y-m-d'));
        $this->assertEquals(InvoiceType::INVOICE, $doc->getInvoiceType());
        $this->assertEquals(ERechnungProfile::XRECHNUNG, $doc->getProfile());
        $this->assertEquals(CurrencyCode::Euro, $doc->getCurrency());

        // Buyer reference (Leitweg-ID)
        $this->assertEquals('04011000-12345-03', $doc->getBuyerReference());

        // Seller
        $seller = $doc->getSeller();
        $this->assertEquals('[Seller trading name]', $seller->getName());
        $this->assertEquals('DE 123456789', $seller->getVatId());
        $this->assertNotNull($seller->getPostalAddress());
        $this->assertEquals('[Seller address line 1]', $seller->getPostalAddress()->getStreetName());
        $this->assertEquals('12345', $seller->getPostalAddress()->getPostalCode());
        $this->assertEquals('[Seller city]', $seller->getPostalAddress()->getCity());
        $this->assertEquals('seller@email.de', $seller->getEndpointId());

        // Buyer
        $buyer = $doc->getBuyer();
        $this->assertEquals('[Buyer name]', $buyer->getName());
        $this->assertNotNull($buyer->getPostalAddress());
        $this->assertEquals('[Buyer address line 1]', $buyer->getPostalAddress()->getStreetName());
        $this->assertEquals('12345', $buyer->getPostalAddress()->getPostalCode());
        $this->assertEquals('[Buyer city]', $buyer->getPostalAddress()->getCity());

        // Invoice lines
        $this->assertEquals(2, $doc->countLines());
        $lines = $doc->getLines();

        // Line 1: Zeitschrift
        $this->assertEquals('Zeitschrift [...]', $lines[0]->getItemName());
        $this->assertEquals(1, $lines[0]->getQuantity());
        $this->assertEquals(288.79, $lines[0]->getUnitPrice());
        $this->assertEquals(288.79, $lines[0]->getNetAmount());
        $this->assertEquals(7.0, $lines[0]->getTaxPercent());

        // Line 2: Porto
        $this->assertEquals('Porto + Versandkosten', $lines[1]->getItemName());
        $this->assertEquals(1, $lines[1]->getQuantity());
        $this->assertEquals(26.07, $lines[1]->getUnitPrice());
        $this->assertEquals(26.07, $lines[1]->getNetAmount());
        $this->assertEquals(7.0, $lines[1]->getTaxPercent());

        // Tax total
        $taxTotal = $doc->getTaxTotal();
        $this->assertNotNull($taxTotal);
        $this->assertEquals(22.04, $taxTotal->getTaxAmount());

        // Monetary total
        $monetaryTotal = $doc->getMonetaryTotal();
        $this->assertNotNull($monetaryTotal);
        $this->assertEquals(314.86, $monetaryTotal->getLineExtensionAmount());
        $this->assertEquals(336.9, $monetaryTotal->getPayableAmount());
    }
}
