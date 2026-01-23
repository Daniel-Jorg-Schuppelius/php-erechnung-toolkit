<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungDocumentTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use CommonToolkit\Enums\CountryCode;
use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\AllowanceCharge;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PaymentTerms;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Entities\TaxSubtotal;
use ERechnungToolkit\Entities\TaxTotal;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\NoteSubjectCode;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Enums\UnitCode;
use DateTimeImmutable;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung Document entity.
 */
class ERechnungDocumentTest extends BaseTestCase {
    private Party $seller;
    private Party $buyer;

    protected function setUp(): void {
        parent::setUp();

        $this->seller = new Party(
            name: 'Muster GmbH',
            postalAddress: PostalAddress::german('Musterstraße 1', '12345', 'Berlin'),
            vatId: 'DE123456789',
            taxRegistrationId: '123/456/78901',
            endpointId: 'seller@example.com',
            endpointScheme: 'EM',
            contactName: 'Max Mustermann',
            contactPhone: '+49 30 12345678',
            contactEmail: 'max@muster.de',
            iban: 'DE89370400440532013000',
            bic: 'COBADEFFXXX'
        );

        $this->buyer = new Party(
            name: 'Kunde AG',
            postalAddress: PostalAddress::german('Kundenweg 2', '54321', 'München'),
            vatId: 'DE987654321',
            endpointId: '04011000-12345-67',
            endpointScheme: '0204'
        );
    }

    public function testCreateBasicInvoice(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $this->assertEquals('INV-2026-001', $document->getId());
        $this->assertEquals('2026-01-22', $document->getIssueDate()->format('Y-m-d'));
        $this->assertEquals(InvoiceType::INVOICE, $document->getInvoiceType());
        $this->assertEquals(CurrencyCode::Euro, $document->getCurrency());
        $this->assertEquals(ERechnungProfile::EN16931, $document->getProfile());
    }

    public function testCreateXRechnungInvoice(): void {
        $leitwegId = '04011000-12345-67';

        $document = Document::xrechnung(
            id: 'XR-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer,
            leitwegId: $leitwegId
        );

        $this->assertEquals(ERechnungProfile::XRECHNUNG, $document->getProfile());
        $this->assertEquals($leitwegId, $document->getBuyerReference());
        $this->assertTrue($document->getBuyer()->hasEndpoint());
    }

    public function testCreateCreditNote(): void {
        $document = Document::creditNote(
            id: 'CN-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer,
            precedingInvoiceReference: 'INV-2026-001'
        );

        $this->assertEquals(InvoiceType::CREDIT_NOTE, $document->getInvoiceType());
        $this->assertTrue($document->getInvoiceType()->isCredit());
        $this->assertEquals('INV-2026-001', $document->getPrecedingInvoiceReference());
    }

    public function testAddInvoiceLines(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line1 = InvoiceLine::create('1', 'Beratungsleistung', 10, 150.00, 19.0, UnitCode::HOUR);
        $line2 = InvoiceLine::create('2', 'Software-Lizenz', 1, 499.00, 19.0);

        $document->addLine($line1);
        $document->addLine($line2);

        $this->assertEquals(2, $document->countLines());
        $this->assertEquals(1999.00, $document->getNetAmount()); // 1500 + 499
    }

    public function testTaxCalculation(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 1, 100.00, 19.0);
        $document->addLine($line);

        $this->assertEquals(100.00, $document->getNetAmount());
        $this->assertEquals(19.00, $document->getTaxAmount());
        $this->assertEquals(119.00, $document->getGrossAmount());
    }

    public function testMixedTaxRates(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        // Standard rate 19%
        $line1 = InvoiceLine::create('1', 'Software', 1, 100.00, 19.0);
        // Reduced rate 7%
        $line2 = InvoiceLine::create('2', 'Buch', 1, 50.00, 7.0);

        $document->addLine($line1);
        $document->addLine($line2);

        $this->assertEquals(150.00, $document->getNetAmount());
        // 19% of 100 = 19, 7% of 50 = 3.50
        $this->assertEquals(22.50, $document->getTaxAmount());
        $this->assertEquals(172.50, $document->getGrossAmount());

        // Check tax subtotals
        $taxTotal = $document->getTaxTotal();
        $this->assertNotNull($taxTotal);
        $this->assertCount(2, $taxTotal->getSubtotals());
    }

    public function testDocumentLevelAllowances(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 10, 100.00, 19.0);
        $document->addLine($line);

        // Add 10% discount
        $discount = AllowanceCharge::discount(100.00, '10% Rabatt');
        $document->addAllowanceCharge($discount);

        $this->assertEquals(1000.00, $document->getMonetaryTotal()->getLineExtensionAmount());
        $this->assertEquals(100.00, $document->getMonetaryTotal()->getAllowanceTotalAmount());
        $this->assertEquals(900.00, $document->getNetAmount()); // 1000 - 100
    }

    public function testDocumentLevelCharges(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 1, 100.00, 19.0);
        $document->addLine($line);

        // Add shipping
        $shipping = AllowanceCharge::shipping(5.95);
        $document->addAllowanceCharge($shipping);

        $this->assertEquals(100.00, $document->getMonetaryTotal()->getLineExtensionAmount());
        $this->assertEquals(5.95, $document->getMonetaryTotal()->getChargeTotalAmount());
        $this->assertEquals(105.95, $document->getNetAmount()); // 100 + 5.95
    }

    public function testValidation(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        // Without lines, should have validation errors
        $errors = $document->validate();
        $this->assertNotEmpty($errors);
        $this->assertContains('BG-25: At least one invoice line is required', $errors);

        // Add a line
        $line = InvoiceLine::create('1', 'Test', 1, 100.00);
        $document->addLine($line);

        $errors = $document->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($document->isValid());
    }

    public function testXRechnungValidation(): void {
        $document = new Document(
            id: 'XR-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            invoiceType: InvoiceType::INVOICE,
            seller: $this->seller,
            buyer: new Party(
                name: 'Kunde ohne Endpoint',
                postalAddress: PostalAddress::german('Test', '12345', 'Stadt')
            ),
            currency: CurrencyCode::Euro,
            profile: ERechnungProfile::XRECHNUNG
        );

        $line = InvoiceLine::create('1', 'Test', 1, 100.00);
        $document->addLine($line);

        $errors = $document->validate();

        // XRechnung requires buyer reference and endpoints
        $this->assertNotEmpty($errors);
    }

    public function testNotes(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $document->addNote('Erste Bemerkung');
        $document->addNote('Zweite Bemerkung');

        $notes = $document->getNotes();
        $this->assertCount(2, $notes);
        $this->assertEquals('Erste Bemerkung', $notes[0]);
        $this->assertEquals('Zweite Bemerkung', $notes[1]);
    }

    public function testNotesWithSubjectCode(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        // Note ohne Subject Code
        $document->addNote('Einfache Bemerkung');
        // Note mit Subject Code
        $document->addNote('Rechtlicher Hinweis', NoteSubjectCode::REG);
        $document->addNote('Zahlungshinweis', NoteSubjectCode::forPaymentInfo());

        // getNotes() gibt formatierte Strings zurück
        $notes = $document->getNotes();
        $this->assertCount(3, $notes);
        $this->assertEquals('Einfache Bemerkung', $notes[0]);
        $this->assertEquals('#REG#Rechtlicher Hinweis', $notes[1]);
        $this->assertEquals('#AAI#Zahlungshinweis', $notes[2]);

        // getNotesStructured() gibt strukturierte Daten zurück
        $structured = $document->getNotesStructured();
        $this->assertCount(3, $structured);

        $this->assertNull($structured[0]['code']);
        $this->assertEquals('Einfache Bemerkung', $structured[0]['text']);

        $this->assertSame(NoteSubjectCode::REG, $structured[1]['code']);
        $this->assertEquals('Rechtlicher Hinweis', $structured[1]['text']);

        $this->assertSame(NoteSubjectCode::AAI, $structured[2]['code']);
        $this->assertEquals('Zahlungshinweis', $structured[2]['text']);

        // getNotesBySubjectCode() filtert nach Code
        $regNotes = $document->getNotesBySubjectCode(NoteSubjectCode::REG);
        $this->assertCount(1, $regNotes);
        $this->assertEquals('Rechtlicher Hinweis', $regNotes[0]);
    }

    public function testPaymentTerms(): void {
        $terms = PaymentTerms::withSkonto(10, 2.0, 30);

        $this->assertEquals(10, $terms->getDiscountDays());
        $this->assertEquals(2.0, $terms->getDiscountPercent());
        $this->assertEquals(30, $terms->getNetPaymentDays());

        $invoiceDate = new DateTimeImmutable('2026-01-22');
        $dueDate = $terms->calculateDueDate($invoiceDate);
        $this->assertEquals('2026-02-21', $dueDate->format('Y-m-d'));

        $discountDeadline = $terms->calculateDiscountDeadline($invoiceDate);
        $this->assertNotNull($discountDeadline);
        $this->assertEquals('2026-02-01', $discountDeadline->format('Y-m-d'));

        $discountedAmount = $terms->calculateDiscountedAmount(1000.00);
        $this->assertEquals(980.00, $discountedAmount);
    }

    public function testInvoiceLineFactoryMethods(): void {
        $line1 = InvoiceLine::service('1', 'Consulting', 8, 125.00);
        $this->assertEquals(8.0, $line1->getQuantity());
        $this->assertEquals(UnitCode::HOUR, $line1->getUnitCode());
        $this->assertEquals(1000.00, $line1->getNetAmount());

        $line2 = InvoiceLine::lumpSum('2', 'Projektpauschale', 5000.00);
        $this->assertEquals(1.0, $line2->getQuantity());
        $this->assertEquals(UnitCode::LUMP_SUM, $line2->getUnitCode());
        $this->assertEquals(5000.00, $line2->getNetAmount());
    }

    public function testTaxSubtotalFactoryMethods(): void {
        $standard = TaxSubtotal::standard(1000.00);
        $this->assertEquals(TaxCategory::STANDARD, $standard->getCategory());
        $this->assertEquals(19.0, $standard->getPercent());
        $this->assertEquals(190.00, $standard->getTaxAmount());
        $this->assertFalse($standard->isExempt());

        $reduced = TaxSubtotal::reduced(1000.00);
        $this->assertEquals(7.0, $reduced->getPercent());
        $this->assertEquals(70.00, $reduced->getTaxAmount());

        $reverseCharge = TaxSubtotal::reverseCharge(1000.00);
        $this->assertEquals(TaxCategory::REVERSE_CHARGE, $reverseCharge->getCategory());
        $this->assertEquals(0.0, $reverseCharge->getTaxAmount());
        $this->assertTrue($reverseCharge->isExempt());
    }
}
