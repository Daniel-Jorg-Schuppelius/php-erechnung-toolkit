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

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{AllowanceCharge, Document, InvoiceLine, Party, PaymentTerms, PostalAddress, TaxSubtotal};
use ERechnungToolkit\Enums\{ERechnungProfile, InvoiceType, NoteSubjectCode, TaxCategory, UnitCode};
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

    public function test_create_basic_invoice(): void {
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

    public function test_create_x_rechnung_invoice(): void {
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

    public function test_create_credit_note(): void {
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

    public function test_add_invoice_lines(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line1 = InvoiceLine::create('1', 'Beratungsleistung', 10, Money::of('150.00', CurrencyCode::Euro), 19.0, UnitCode::HOUR);
        $line2 = InvoiceLine::create('2', 'Software-Lizenz', 1, Money::of('499.00', CurrencyCode::Euro), 19.0);

        $document->addLine($line1);
        $document->addLine($line2);

        $this->assertEquals(2, $document->countLines());
        $this->assertSame('1999.00', $document->getNetAmount()->getAmount()); // 1500 + 499
    }

    public function test_tax_calculation(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 1, Money::of('100.00', CurrencyCode::Euro), 19.0);
        $document->addLine($line);

        $this->assertSame('100.00', $document->getNetAmount()->getAmount());
        $this->assertSame('19.00', $document->getTaxAmount()->getAmount());
        $this->assertSame('119.00', $document->getGrossAmount()->getAmount());
    }

    public function test_mixed_tax_rates(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        // Standard rate 19%
        $line1 = InvoiceLine::create('1', 'Software', 1, Money::of('100.00', CurrencyCode::Euro), 19.0);
        // Reduced rate 7%
        $line2 = InvoiceLine::create('2', 'Buch', 1, Money::of('50.00', CurrencyCode::Euro), 7.0);

        $document->addLine($line1);
        $document->addLine($line2);

        $this->assertSame('150.00', $document->getNetAmount()->getAmount());
        // 19% of 100 = 19, 7% of 50 = 3.50
        $this->assertSame('22.50', $document->getTaxAmount()->getAmount());
        $this->assertSame('172.50', $document->getGrossAmount()->getAmount());

        // Check tax subtotals
        $taxTotal = $document->getTaxTotal();
        $this->assertNotNull($taxTotal);
        $this->assertCount(2, $taxTotal->getSubtotals());
    }

    public function test_document_level_allowances(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 10, Money::of('100.00', CurrencyCode::Euro), 19.0);
        $document->addLine($line);

        // Add 10% discount
        $discount = AllowanceCharge::discount(Money::of('100.00', CurrencyCode::Euro), '10% Rabatt');
        $document->addAllowanceCharge($discount);

        $monetaryTotal = $document->getMonetaryTotal();
        $this->assertNotNull($monetaryTotal);
        $this->assertSame('1000.00', $monetaryTotal->getLineExtensionAmount()->getAmount());
        $this->assertSame('100.00', $monetaryTotal->getAllowanceTotalAmount()->getAmount());
        $this->assertSame('900.00', $document->getNetAmount()->getAmount()); // 1000 - 100
    }

    public function test_document_level_charges(): void {
        $document = Document::create(
            id: 'INV-2026-001',
            issueDate: new DateTimeImmutable('2026-01-22'),
            seller: $this->seller,
            buyer: $this->buyer
        );

        $line = InvoiceLine::create('1', 'Produkt', 1, Money::of('100.00', CurrencyCode::Euro), 19.0);
        $document->addLine($line);

        // Add shipping
        $shipping = AllowanceCharge::shipping(Money::of('5.95', CurrencyCode::Euro));
        $document->addAllowanceCharge($shipping);

        $monetaryTotal = $document->getMonetaryTotal();
        $this->assertNotNull($monetaryTotal);
        $this->assertSame('100.00', $monetaryTotal->getLineExtensionAmount()->getAmount());
        $this->assertSame('5.95', $monetaryTotal->getChargeTotalAmount()->getAmount());
        $this->assertSame('105.95', $document->getNetAmount()->getAmount()); // 100 + 5.95
    }

    public function test_validation(): void {
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
        $line = InvoiceLine::create('1', 'Test', 1, Money::of('100.00', CurrencyCode::Euro));
        $document->addLine($line);

        $errors = $document->validate();
        $this->assertEmpty($errors);
        $this->assertTrue($document->isValid());
    }

    public function test_x_rechnung_validation(): void {
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

        $line = InvoiceLine::create('1', 'Test', 1, Money::of('100.00', CurrencyCode::Euro));
        $document->addLine($line);

        $errors = $document->validate();

        // XRechnung requires buyer reference and endpoints
        $this->assertNotEmpty($errors);
    }

    public function test_notes(): void {
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

    public function test_notes_with_subject_code(): void {
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

    public function test_payment_terms(): void {
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

        $discountedAmount = $terms->calculateDiscountedAmount(Money::of('1000.00', CurrencyCode::Euro));
        $this->assertSame('980.00', $discountedAmount->getAmount());
    }

    public function test_invoice_line_factory_methods(): void {
        $line1 = InvoiceLine::service('1', 'Consulting', 8, Money::of('125.00', CurrencyCode::Euro));
        $this->assertEquals(8.0, $line1->getQuantity());
        $this->assertEquals(UnitCode::HOUR, $line1->getUnitCode());
        $this->assertSame('1000.00', $line1->getNetAmount()->getAmount());

        $line2 = InvoiceLine::lumpSum('2', 'Projektpauschale', Money::of('5000.00', CurrencyCode::Euro));
        $this->assertEquals(1.0, $line2->getQuantity());
        $this->assertEquals(UnitCode::LUMP_SUM, $line2->getUnitCode());
        $this->assertSame('5000.00', $line2->getNetAmount()->getAmount());
    }

    public function test_tax_subtotal_factory_methods(): void {
        $standard = TaxSubtotal::standard(Money::of('1000.00', CurrencyCode::Euro));
        $this->assertEquals(TaxCategory::STANDARD, $standard->getCategory());
        $this->assertEquals(19.0, $standard->getPercent());
        $this->assertSame('190.00', $standard->getTaxAmount()->getAmount());
        $this->assertFalse($standard->isExempt());

        $reduced = TaxSubtotal::reduced(Money::of('1000.00', CurrencyCode::Euro));
        $this->assertEquals(7.0, $reduced->getPercent());
        $this->assertSame('70.00', $reduced->getTaxAmount()->getAmount());

        $reverseCharge = TaxSubtotal::reverseCharge(Money::of('1000.00', CurrencyCode::Euro));
        $this->assertEquals(TaxCategory::REVERSE_CHARGE, $reverseCharge->getCategory());
        $this->assertSame('0.00', $reverseCharge->getTaxAmount()->getAmount());
        $this->assertTrue($reverseCharge->isExempt());
    }
}
