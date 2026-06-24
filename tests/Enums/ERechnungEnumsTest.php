<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungEnumsTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Enums;

use ERechnungToolkit\Enums\{AllowanceChargeReasonCode, ERechnungProfile, InvoiceType, NoteSubjectCode, PaymentMeansCode, TaxCategory, UnitCode};
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung Enums.
 */
class ERechnungEnumsTest extends BaseTestCase {
    // InvoiceType Tests
    public function test_invoice_type_values(): void {
        $this->assertEquals('380', InvoiceType::INVOICE->value);
        $this->assertEquals('381', InvoiceType::CREDIT_NOTE->value);
        $this->assertEquals('384', InvoiceType::CORRECTED_INVOICE->value);
        $this->assertEquals('389', InvoiceType::SELF_BILLED_INVOICE->value);
    }

    public function test_invoice_type_is_credit(): void {
        $this->assertFalse(InvoiceType::INVOICE->isCredit());
        $this->assertTrue(InvoiceType::CREDIT_NOTE->isCredit());
        $this->assertFalse(InvoiceType::CORRECTED_INVOICE->isCredit());
    }

    public function test_invoice_type_label(): void {
        $this->assertEquals('Rechnung', InvoiceType::INVOICE->label());
        $this->assertEquals('Gutschrift', InvoiceType::CREDIT_NOTE->label());
        $this->assertEquals('Korrekturrechnung', InvoiceType::CORRECTED_INVOICE->label());
    }

    public function test_invoice_type_from_code(): void {
        $this->assertEquals(InvoiceType::INVOICE, InvoiceType::fromCode('380'));
        $this->assertEquals(InvoiceType::CREDIT_NOTE, InvoiceType::fromCode('381'));
    }

    public function test_invoice_type_from_code_returns_null_on_invalid(): void {
        $this->assertNull(InvoiceType::fromCode('999'));
    }

    // TaxCategory Tests
    public function test_tax_category_values(): void {
        $this->assertEquals('S', TaxCategory::STANDARD->value);
        $this->assertEquals('Z', TaxCategory::ZERO_RATED->value);
        $this->assertEquals('E', TaxCategory::EXEMPT->value);
        $this->assertEquals('AE', TaxCategory::REVERSE_CHARGE->value);
        $this->assertEquals('K', TaxCategory::INTRA_COMMUNITY->value);
        $this->assertEquals('G', TaxCategory::EXPORT->value);
        $this->assertEquals('O', TaxCategory::OUT_OF_SCOPE->value);
    }

    public function test_tax_category_default_rate(): void {
        $this->assertEquals(19.0, TaxCategory::STANDARD->defaultRate());
        $this->assertEquals(0.0, TaxCategory::ZERO_RATED->defaultRate());
        $this->assertEquals(0.0, TaxCategory::EXEMPT->defaultRate());
        $this->assertEquals(0.0, TaxCategory::REVERSE_CHARGE->defaultRate());
    }

    public function test_tax_category_is_taxable(): void {
        $this->assertTrue(TaxCategory::STANDARD->isTaxable());
        $this->assertFalse(TaxCategory::ZERO_RATED->isTaxable());
        $this->assertFalse(TaxCategory::EXEMPT->isTaxable());
        $this->assertFalse(TaxCategory::REVERSE_CHARGE->isTaxable());
    }

    public function test_tax_category_label(): void {
        $this->assertEquals('Regelsteuersatz', TaxCategory::STANDARD->label());
        $this->assertEquals('Nullsteuersatz', TaxCategory::ZERO_RATED->label());
        $this->assertStringContainsString('Reverse Charge', TaxCategory::REVERSE_CHARGE->label());
    }

    public function test_tax_category_from_code(): void {
        $this->assertEquals(TaxCategory::STANDARD, TaxCategory::fromCode('S'));
        $this->assertEquals(TaxCategory::REVERSE_CHARGE, TaxCategory::fromCode('AE'));
    }

    // PaymentMeansCode Tests
    public function test_payment_means_code_values(): void {
        $this->assertEquals('30', PaymentMeansCode::CREDIT_TRANSFER->value);
        $this->assertEquals('58', PaymentMeansCode::SEPA_CREDIT_TRANSFER->value);
        $this->assertEquals('59', PaymentMeansCode::SEPA_DIRECT_DEBIT->value);
        $this->assertEquals('48', PaymentMeansCode::BANK_CARD->value);
        $this->assertEquals('10', PaymentMeansCode::CASH->value);
    }

    public function test_payment_means_code_is_sepa(): void {
        $this->assertTrue(PaymentMeansCode::SEPA_CREDIT_TRANSFER->isSepa());
        $this->assertTrue(PaymentMeansCode::SEPA_DIRECT_DEBIT->isSepa());
        $this->assertFalse(PaymentMeansCode::CREDIT_TRANSFER->isSepa());
        $this->assertFalse(PaymentMeansCode::CASH->isSepa());
    }

    public function test_payment_means_code_is_direct_debit(): void {
        $this->assertTrue(PaymentMeansCode::SEPA_DIRECT_DEBIT->isDirectDebit());
        $this->assertTrue(PaymentMeansCode::DIRECT_DEBIT->isDirectDebit());
        $this->assertFalse(PaymentMeansCode::SEPA_CREDIT_TRANSFER->isDirectDebit());
    }

    public function test_payment_means_code_label(): void {
        $this->assertEquals('Überweisung', PaymentMeansCode::CREDIT_TRANSFER->label());
        $this->assertEquals('SEPA-Überweisung', PaymentMeansCode::SEPA_CREDIT_TRANSFER->label());
        $this->assertEquals('SEPA-Lastschrift', PaymentMeansCode::SEPA_DIRECT_DEBIT->label());
    }

    // ERechnungProfile Tests
    public function test_e_rechnung_profile_urns(): void {
        $this->assertStringContainsString('en16931', ERechnungProfile::EN16931->value);
        $this->assertStringContainsString('xrechnung', strtolower(ERechnungProfile::XRECHNUNG->value));
    }

    public function test_x_rechnung_profile_uses_current_kosit_authority(): void {
        $this->assertEquals(
            'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0',
            ERechnungProfile::XRECHNUNG->value
        );
        $this->assertEquals(
            'urn:cen.eu:en16931:2017#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0',
            ERechnungProfile::XRECHNUNG_EXTENSION->value
        );
        $this->assertStringNotContainsString('xoev-de', ERechnungProfile::XRECHNUNG->value);
        $this->assertStringNotContainsString('xoev-de', ERechnungProfile::XRECHNUNG_EXTENSION->value);
    }

    public function test_e_rechnung_profile_is_x_rechnung(): void {
        $this->assertTrue(ERechnungProfile::XRECHNUNG->isXRechnung());
        $this->assertFalse(ERechnungProfile::EN16931->isXRechnung());
    }

    public function test_e_rechnung_profile_is_zugferd(): void {
        $this->assertTrue(ERechnungProfile::MINIMUM->isZugferd());
        $this->assertTrue(ERechnungProfile::BASIC->isZugferd());
        $this->assertTrue(ERechnungProfile::EN16931->isZugferd());
        $this->assertTrue(ERechnungProfile::EXTENDED->isZugferd());
        $this->assertFalse(ERechnungProfile::XRECHNUNG->isZugferd());
    }

    public function test_e_rechnung_profile_label(): void {
        $this->assertStringContainsString('EN 16931', ERechnungProfile::EN16931->label());
        $this->assertEquals('XRechnung', ERechnungProfile::XRECHNUNG->label());
    }

    public function test_e_rechnung_profile_for_public_sector(): void {
        $this->assertEquals(ERechnungProfile::XRECHNUNG, ERechnungProfile::forPublicSector());
    }

    public function test_e_rechnung_profile_for_b2_b(): void {
        $this->assertEquals(ERechnungProfile::EN16931, ERechnungProfile::forB2B());
    }

    // AllowanceChargeReasonCode Tests
    public function test_allowance_charge_reason_code_allowances(): void {
        $this->assertEquals('95', AllowanceChargeReasonCode::DISCOUNT->value);
        $this->assertEquals('64', AllowanceChargeReasonCode::SPECIAL_AGREEMENT->value);
    }

    public function test_allowance_charge_reason_code_charges(): void {
        $this->assertEquals('FC', AllowanceChargeReasonCode::FREIGHT->value);
        $this->assertEquals('PC', AllowanceChargeReasonCode::PACKING->value);
        $this->assertEquals('INS', AllowanceChargeReasonCode::INSURANCE->value);
    }

    public function test_allowance_charge_reason_code_is_allowance(): void {
        $this->assertTrue(AllowanceChargeReasonCode::DISCOUNT->isAllowance());
        $this->assertFalse(AllowanceChargeReasonCode::FREIGHT->isAllowance());
    }

    public function test_allowance_charge_reason_code_is_charge(): void {
        $this->assertTrue(AllowanceChargeReasonCode::FREIGHT->isCharge());
        $this->assertTrue(AllowanceChargeReasonCode::PACKING->isCharge());
        $this->assertFalse(AllowanceChargeReasonCode::DISCOUNT->isCharge());
    }

    // UnitCode Tests
    public function test_unit_code_values(): void {
        $this->assertEquals('C62', UnitCode::PIECE->value);
        $this->assertEquals('H87', UnitCode::UNIT_H87->value);
        $this->assertEquals(UnitCode::UNIT_H87, UnitCode::fromCode('H87'));
        $this->assertEquals('HUR', UnitCode::HOUR->value);
        $this->assertEquals('DAY', UnitCode::DAY->value);
        $this->assertEquals('KGM', UnitCode::KILOGRAM->value);
        $this->assertEquals('MTR', UnitCode::METRE->value);
        $this->assertEquals('LTR', UnitCode::LITRE->value);
        $this->assertEquals('LS', UnitCode::LUMP_SUM->value);
    }

    public function test_unit_code_abbreviation(): void {
        $abbreviation = UnitCode::PIECE->abbreviation();
        $this->assertNotEmpty($abbreviation);

        $hourAbbr = UnitCode::HOUR->abbreviation();
        $this->assertNotEmpty($hourAbbr);
    }

    public function test_unit_code_label(): void {
        $this->assertEquals('Stück', UnitCode::PIECE->label());
        $this->assertEquals('Stunde', UnitCode::HOUR->label());
        $this->assertEquals('Pauschale', UnitCode::LUMP_SUM->label());
    }

    // NoteSubjectCode Tests
    public function test_note_subject_code_values(): void {
        $this->assertEquals('ADU', NoteSubjectCode::ADU->value);
        $this->assertEquals('REG', NoteSubjectCode::REG->value);
        $this->assertEquals('AAI', NoteSubjectCode::AAI->value);
        $this->assertEquals('SUR', NoteSubjectCode::SUR->value);
    }

    public function test_note_subject_code_label(): void {
        $this->assertEquals('Allgemeine Informationen', NoteSubjectCode::ADU->getLabel());
        $this->assertEquals('Regulatorische Hinweise', NoteSubjectCode::REG->getLabel());
        $this->assertEquals('Zahlungsinformationen', NoteSubjectCode::AAI->getLabel());
    }

    public function test_note_subject_code_prefix(): void {
        $this->assertEquals('#ADU#', NoteSubjectCode::ADU->getPrefix());
        $this->assertEquals('#REG#', NoteSubjectCode::REG->getPrefix());
        $this->assertEquals('#TAX#', NoteSubjectCode::TAX->getPrefix());
    }

    public function test_note_subject_code_format_note(): void {
        $note = 'Dies ist eine Testnotiz';
        $formatted = NoteSubjectCode::formatNote($note, NoteSubjectCode::ADU);
        $this->assertEquals('#ADU#Dies ist eine Testnotiz', $formatted);
    }

    public function test_note_subject_code_parse_note(): void {
        // Mit Subject Code
        $parsed = NoteSubjectCode::parseNote('#ADU#General information');
        $this->assertSame(NoteSubjectCode::ADU, $parsed['code']);
        $this->assertEquals('General information', $parsed['text']);

        // Ohne Subject Code
        $parsed = NoteSubjectCode::parseNote('Plain note without code');
        $this->assertNull($parsed['code']);
        $this->assertEquals('Plain note without code', $parsed['text']);
    }

    public function test_note_subject_code_from_prefix(): void {
        $code = NoteSubjectCode::fromPrefix('#ADU#');
        $this->assertSame(NoteSubjectCode::ADU, $code);

        $code = NoteSubjectCode::fromPrefix('#REG#');
        $this->assertSame(NoteSubjectCode::REG, $code);

        $code = NoteSubjectCode::fromPrefix('#UNKNOWN#');
        $this->assertNull($code);
    }

    public function test_note_subject_code_helper_methods(): void {
        $general = NoteSubjectCode::forGeneralInfo();
        $this->assertSame(NoteSubjectCode::ADU, $general);

        $payment = NoteSubjectCode::forPaymentInfo();
        $this->assertSame(NoteSubjectCode::AAI, $payment);

        $paymentTerms = NoteSubjectCode::forPaymentTerms();
        $this->assertSame(NoteSubjectCode::AAB, $paymentTerms);

        $legal = NoteSubjectCode::forLegalInfo();
        $this->assertSame(NoteSubjectCode::ABL, $legal);

        $regulatory = NoteSubjectCode::forRegulatory();
        $this->assertSame(NoteSubjectCode::REG, $regulatory);

        $tax = NoteSubjectCode::forTax();
        $this->assertSame(NoteSubjectCode::TXD, $tax);

        $customs = NoteSubjectCode::forCustoms();
        $this->assertSame(NoteSubjectCode::CUS, $customs);

        $terms = NoteSubjectCode::forTermsAndConditions();
        $this->assertSame(NoteSubjectCode::AAR, $terms);

        $delivery = NoteSubjectCode::forDeliveryConditions();
        $this->assertSame(NoteSubjectCode::AAN, $delivery);
    }
}
