<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungDocumentBuilderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Builders;

use CommonToolkit\Enums\CountryCode;
use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Enums\UnitCode;
use DateTimeImmutable;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung Document Builder.
 */
class ERechnungDocumentBuilderTest extends BaseTestCase {
    public function testCreateBasicInvoice(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 10, 150.00)
            ->build();

        $this->assertEquals('INV-2026-001', $document->getId());
        $this->assertEquals('Muster GmbH', $document->getSeller()->getName());
        $this->assertEquals('Kunde AG', $document->getBuyer()->getName());
        $this->assertEquals(1, $document->countLines());
        $this->assertEquals(1500.00, $document->getNetAmount());
    }

    public function testCreateXRechnungInvoice(): void {
        $leitwegId = '04011000-12345-67';

        $document = ERechnungDocumentBuilder::xrechnung('XR-2026-001', $leitwegId)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withSellerEndpoint('seller@example.com', 'EM')
            ->withBuyer('Öffentliche Verwaltung')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId($leitwegId)
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $this->assertEquals(ERechnungProfile::XRECHNUNG, $document->getProfile());
        $this->assertEquals($leitwegId, $document->getBuyerReference());
        $this->assertTrue($document->getBuyer()->hasEndpoint());
    }

    public function testCreateZugferdInvoice(): void {
        $document = ERechnungDocumentBuilder::zugferd('ZF-2026-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferantenweg 5', '50667', 'Köln')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstraße 10', '60311', 'Frankfurt')
            ->addLine('Ware A', 5, 100.00)
            ->addLine('Ware B', 10, 50.00)
            ->build();

        $this->assertEquals(ERechnungProfile::EN16931, $document->getProfile());
        $this->assertEquals(2, $document->countLines());
        $this->assertEquals(1000.00, $document->getNetAmount()); // 500 + 500
    }

    public function testCreateCreditNote(): void {
        $document = ERechnungDocumentBuilder::creditNote('CN-2026-001', 'INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-25'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Rückerstattung', 1, 100.00)
            ->build();

        $this->assertEquals(InvoiceType::CREDIT_NOTE, $document->getInvoiceType());
        $this->assertEquals('INV-2026-001', $document->getPrecedingInvoiceReference());
    }

    public function testBuilderWithSellerContact(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerContact('Max Mustermann', '+49 30 12345678', 'max@muster.de')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertEquals('Max Mustermann', $document->getSeller()->getContactName());
        $this->assertEquals('+49 30 12345678', $document->getSeller()->getContactPhone());
        $this->assertEquals('max@muster.de', $document->getSeller()->getContactEmail());
        $this->assertTrue($document->getSeller()->hasContactInfo());
    }

    public function testBuilderWithBankAccount(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertEquals('DE89 3704 0044 0532 0130 00', $document->getSeller()->getIban());
        $this->assertEquals('COBADEFFXXX', $document->getSeller()->getBic());
        $this->assertEquals('Commerzbank', $document->getSeller()->getBankName());
        $this->assertTrue($document->getSeller()->hasBankingInfo());
    }

    public function testBuilderWithPaymentTerms(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withPaymentTermsSkonto(10, 2.0, 30)
            ->withSepaCreditTransfer()
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertNotNull($document->getPaymentTerms());
        $this->assertEquals(2.0, $document->getPaymentTerms()->getDiscountPercent());
        $this->assertEquals(10, $document->getPaymentTerms()->getDiscountDays());
        $this->assertEquals(PaymentMeansCode::SEPA_CREDIT_TRANSFER, $document->getPaymentMeansCode());
    }

    public function testBuilderWithDiscount(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 10, 100.00)
            ->addDiscount(100.00, '10% Treuerabatt')
            ->build();

        $this->assertCount(1, $document->getAllowanceCharges());
        $this->assertFalse($document->getAllowanceCharges()[0]->isCharge());
        $this->assertEquals(900.00, $document->getNetAmount());
    }

    public function testBuilderWithShipping(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->addShipping(5.95)
            ->build();

        $this->assertCount(1, $document->getAllowanceCharges());
        $this->assertTrue($document->getAllowanceCharges()[0]->isCharge());
        $this->assertEquals(105.95, $document->getNetAmount());
    }

    public function testBuilderWithServiceLine(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addServiceLine('Beratung', 8, 150.00)
            ->build();

        $lines = $document->getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(UnitCode::HOUR, $lines[0]->getUnitCode());
        $this->assertEquals(8.0, $lines[0]->getQuantity());
        $this->assertEquals(1200.00, $lines[0]->getNetAmount());
    }

    public function testBuilderWithLumpSumLine(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLumpSumLine('Projektpauschale', 5000.00)
            ->build();

        $lines = $document->getLines();
        $this->assertCount(1, $lines);
        $this->assertEquals(UnitCode::LUMP_SUM, $lines[0]->getUnitCode());
        $this->assertEquals(1.0, $lines[0]->getQuantity());
        $this->assertEquals(5000.00, $lines[0]->getNetAmount());
    }

    public function testBuilderWithNotes(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addNote('Hinweis 1')
            ->addNote('Hinweis 2')
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertCount(2, $document->getNotes());
    }

    public function testBuilderWithReferences(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withOrderReference('PO-2026-001')
            ->withContractReference('CONTRACT-2026-001')
            ->withProjectReference('PROJECT-001')
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertEquals('PO-2026-001', $document->getOrderReference());
        $this->assertEquals('CONTRACT-2026-001', $document->getContractReference());
        $this->assertEquals('PROJECT-001', $document->getProjectReference());
    }

    public function testBuilderWithDates(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withDueDate(new DateTimeImmutable('2026-02-21'))
            ->withDeliveryDate(new DateTimeImmutable('2026-01-20'))
            ->withTaxPointDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Test', 1, 100.00)
            ->build();

        $this->assertEquals('2026-01-22', $document->getIssueDate()->format('Y-m-d'));
        $this->assertEquals('2026-02-21', $document->getDueDate()->format('Y-m-d'));
        $this->assertEquals('2026-01-20', $document->getDeliveryDate()->format('Y-m-d'));
        $this->assertEquals('2026-01-22', $document->getTaxPointDate()->format('Y-m-d'));
    }

    public function testBuilderThrowsExceptionWithoutSeller(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Seller name is required');

        ERechnungDocumentBuilder::create('INV-2026-001')
            ->withBuyer('Kunde AG')
            ->build();
    }

    public function testBuilderThrowsExceptionWithoutBuyer(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Buyer name is required');

        ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->build();
    }

    public function testCompleteInvoice(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withDueDate(new DateTimeImmutable('2026-02-21'))
            ->withDeliveryDate(new DateTimeImmutable('2026-01-20'))
            ->withInvoiceType(InvoiceType::INVOICE)
            ->withCurrency(CurrencyCode::Euro)
            ->withProfile(ERechnungProfile::EN16931)
            ->withSeller('Muster GmbH', 'DE123456789', '123/456/78901')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin', CountryCode::Germany)
            ->withSellerContact('Max Mustermann', '+49 30 12345678', 'max@muster.de')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München', CountryCode::Germany)
            ->withOrderReference('PO-2026-001')
            ->withPaymentTermsNet30()
            ->withSepaCreditTransfer()
            ->addLine('Beratungsleistung', 10, 150.00, 19.0, UnitCode::HOUR)
            ->addLine('Software-Lizenz', 1, 499.00, 19.0)
            ->addServiceLine('Support', 5, 100.00)
            ->addShipping(9.95)
            ->addNote('Vielen Dank für Ihren Auftrag!')
            ->build();

        $this->assertEquals('INV-2026-001', $document->getId());
        $this->assertTrue($document->isValid());
        $this->assertEquals(3, $document->countLines());

        // Net: 1500 + 499 + 500 + 9.95 = 2508.95
        $this->assertEquals(2508.95, $document->getNetAmount());

        // Tax: 2508.95 * 0.19 = 476.70 (rounded)
        $expectedTax = round(2508.95 * 0.19, 2);
        $this->assertEquals($expectedTax, $document->getTaxAmount());

        // Gross: 2508.95 + 476.70 = 2985.65
        $expectedGross = round(2508.95 + $expectedTax, 2);
        $this->assertEquals($expectedGross, $document->getGrossAmount());
    }
}
