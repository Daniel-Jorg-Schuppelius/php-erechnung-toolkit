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

use CommonToolkit\Enums\{CountryCode, CurrencyCode};
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{AllowanceCharge, Document, Party, PostalAddress};
use ERechnungToolkit\Enums\{ERechnungProfile, InvoiceType, PaymentMeansCode, TaxCategory, UnitCode};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung Document Builder.
 */
class ERechnungDocumentBuilderTest extends BaseTestCase {
    public function test_create_basic_invoice(): void {
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
        $this->assertSame('1500.00', $document->getNetAmount()->getAmount());
    }

    public function test_create_x_rechnung_invoice(): void {
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

    public function test_create_zugferd_invoice(): void {
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
        $this->assertSame('1000.00', $document->getNetAmount()->getAmount()); // 500 + 500
    }

    public function test_create_credit_note(): void {
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

    public function test_builder_with_seller_contact(): void {
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

    public function test_builder_with_bank_account(): void {
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

    public function test_builder_with_payment_terms(): void {
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

    public function test_builder_with_discount(): void {
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
        $this->assertSame('900.00', $document->getNetAmount()->getAmount());
    }

    public function test_builder_with_shipping(): void {
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
        $this->assertSame('105.95', $document->getNetAmount()->getAmount());
    }

    public function test_builder_with_service_line(): void {
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
        $this->assertSame('1200.00', $lines[0]->getNetAmount()->getAmount());
    }

    public function test_builder_with_lump_sum_line(): void {
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
        $this->assertSame('5000.00', $lines[0]->getNetAmount()->getAmount());
    }

    public function test_builder_with_notes(): void {
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

    public function test_builder_with_references(): void {
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

    public function test_builder_with_dates(): void {
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
        $this->assertEquals('2026-02-21', $document->getDueDate()?->format('Y-m-d'));
        $this->assertEquals('2026-01-20', $document->getDeliveryDate()?->format('Y-m-d'));
        $this->assertEquals('2026-01-22', $document->getTaxPointDate()?->format('Y-m-d'));
    }

    public function test_builder_throws_exception_without_seller(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Seller name is required');

        ERechnungDocumentBuilder::create('INV-2026-001')
            ->withBuyer('Kunde AG')
            ->build();
    }

    public function test_builder_throws_exception_without_buyer(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Buyer name is required');

        ERechnungDocumentBuilder::create('INV-2026-001')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->build();
    }

    public function test_complete_invoice(): void {
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
        $this->assertSame('2508.95', $document->getNetAmount()->getAmount());

        // Tax: 2508.95 * 0.19 = 476.70 (kaufmännisch gerundet)
        $this->assertSame('476.70', $document->getTaxAmount()->getAmount());

        // Gross: 2508.95 + 476.70 = 2985.65
        $this->assertSame('2985.65', $document->getGrossAmount()->getAmount());
    }

    public function test_builder_with_delivery_address_and_legal_registrations(): void {
        $document = $this->minimalBuilder()
            ->withSellerLegalRegistration('HRB 12345', '0002')
            ->withBuyerLegalRegistration('HRB 98765')
            ->withDeliveryAddress('Havenweg 5', '3011 AA', 'Rotterdam', CountryCode::Netherlands, 'Tor 3', 'Lager Rotterdam')
            ->build();

        $delivery = $document->getDeliveryParty();
        $this->assertNotNull($delivery);
        $this->assertSame('Lager Rotterdam', $delivery->getName());
        $address = $delivery->getPostalAddress();
        $this->assertNotNull($address);
        $this->assertSame('NL', $address->getCountryCode());
        $this->assertSame('Tor 3', $address->getAdditionalStreetName());
        $this->assertSame('HRB 12345', $document->getSeller()->getLegalRegistrationId());
        $this->assertSame('0002', $document->getSeller()->getLegalRegistrationScheme());
        $this->assertSame('HRB 98765', $document->getBuyer()->getLegalRegistrationId());
        $this->assertNull($document->getBuyer()->getLegalRegistrationScheme());
        $this->assertNull($document->getBuyer()->getTaxRegistrationId());
    }

    public function test_builder_with_delivery_party(): void {
        $party = new Party(name: 'Baustelle Nord', postalAddress: new PostalAddress(city: 'Hamburg', postalCode: '20095', country: 'DE'), legalEntityId: '4012345000009', legalEntityScheme: '0088');

        $this->assertSame($party, $this->minimalBuilder()->withDeliveryParty($party)->build()->getDeliveryParty());
    }

    public function test_delivery_address_needs_an_iso_country_code(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BT-80');
        $this->minimalBuilder()->withDeliveryAddress('Havenweg 5', '3011 AA', 'Rotterdam', 'Niederlande');
    }

    /**
     * @return array<string, array{TaxCategory}>
     */
    public static function categoriesWithoutReason(): array {
        return [
            'S' => [TaxCategory::STANDARD],
            'Z' => [TaxCategory::ZERO_RATED],
            'L' => [TaxCategory::IGIC],
            'M' => [TaxCategory::IPSI],
        ];
    }

    #[DataProvider('categoriesWithoutReason')]
    public function test_exemption_reason_is_refused_for_categories_without_one(TaxCategory $category): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("VAT category {$category->value}");
        $this->minimalBuilder()->withTaxExemptionReasonFor($category, 'Grund');
    }

    public function test_exemption_reason_per_category_wins_over_the_common_reason(): void {
        $document = $this->minimalBuilder()
            ->withTaxExemptionReason('Steuerfrei', 'VATEX-EU-132')
            ->withTaxExemptionReasonFor(TaxCategory::REVERSE_CHARGE, 'Steuerschuldnerschaft des Leistungsempfängers', 'VATEX-EU-AE')
            ->addLine('Bauleistung', 1, 100.00, 0.0, null, TaxCategory::REVERSE_CHARGE)
            ->addLine('Heilbehandlung', 1, 50.00, 0.0, null, TaxCategory::EXEMPT)
            ->addLine('Photovoltaik', 1, 80.00, 0.0, null, TaxCategory::ZERO_RATED)
            ->build();

        $reasons = $this->reasonsByCategory($document);
        $this->assertSame(['Steuerschuldnerschaft des Leistungsempfängers', 'VATEX-EU-AE'], $reasons['AE']);
        $this->assertSame(['Steuerfrei', 'VATEX-EU-132'], $reasons['E']);
        // BR-Z-10: früher erhielt auch der Nullsatz den Grund für alle Kategorien.
        $this->assertSame([null, null], $reasons['Z']);
        $this->assertNull($document->getTaxExemptionReasonFor(TaxCategory::ZERO_RATED));
        $this->assertSame('VATEX-EU-AE', $document->getTaxExemptionReasonCodeFor(TaxCategory::REVERSE_CHARGE));
    }

    public function test_exemption_reason_per_category_survives_a_later_allowance(): void {
        $document = $this->minimalBuilder()
            ->withTaxExemptionReasonFor(TaxCategory::OUT_OF_SCOPE, 'Nicht steuerbar', 'VATEX-EU-O')
            ->addLine('Beratung', 2, 100.00, 0.0, UnitCode::HOUR, TaxCategory::OUT_OF_SCOPE)
            ->build();
        $document->addAllowanceCharge(AllowanceCharge::discount(Money::of('10.00', CurrencyCode::Euro), 'Rabatt', taxCategory: TaxCategory::OUT_OF_SCOPE, taxPercent: null));

        $subtotals = $document->getTaxTotal()?->getSubtotals() ?? [];
        $this->assertCount(1, $subtotals, 'Rabatt ohne Satz fällt in denselben Aufriss');
        $this->assertSame('190.00', $subtotals[0]->getTaxableAmount()->getAmount());
        $this->assertSame('VATEX-EU-O', $subtotals[0]->getExemptionReasonCode());
    }

    public function test_not_subject_lines_carry_no_tax_even_with_a_rate(): void {
        $document = $this->minimalBuilder()
            ->addLine('Beratung', 1, 100.00, 19.0, null, TaxCategory::OUT_OF_SCOPE)
            ->addLine('Reisekosten', 1, 50.00, 0.0, null, TaxCategory::OUT_OF_SCOPE)
            ->build();

        $subtotals = $document->getTaxTotal()?->getSubtotals() ?? [];
        $this->assertCount(1, $subtotals);
        $this->assertSame(0.0, $subtotals[0]->getPercent());
        $this->assertSame('0.00', $document->getTaxAmount()->getAmount());
    }

    public function test_leitweg_id_is_normalized_but_not_rejected(): void {
        $document = ERechnungDocumentBuilder::xrechnung('XR-1', ' 991-33333test-33 ')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Bundesamt')
            ->withBuyerLeitwegId("991\u{2013}33333test\u{2013}33")
            ->addLine('Beratung', 1, 100.00)
            ->build();

        $this->assertSame('991-33333TEST-33', $document->getBuyerReference());
        $this->assertSame('991-33333TEST-33', $document->getBuyer()->getEndpointId());
        $this->assertSame('0204', $document->getBuyer()->getEndpointScheme());
        // Bestehende Aufrufer mit falscher Prüfziffer bleiben lauffähig; der Validator warnt.
        $this->assertSame('04011000-12345-67', ERechnungDocumentBuilder::xrechnung('XR-2', '04011000-12345-67')->withSeller('A', 'DE1')->withBuyer('B')->build()->getBuyerReference());
    }

    private function minimalBuilder(): ERechnungDocumentBuilder {
        return ERechnungDocumentBuilder::create('INV-2026-100')
            ->withIssueDate(new DateTimeImmutable('2026-11-12'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Kunde AG');
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    private function reasonsByCategory(Document $document): array {
        $reasons = [];
        foreach ($document->getTaxTotal()?->getSubtotals() ?? [] as $subtotal) {
            $reasons[$subtotal->getCategory()->value] = [$subtotal->getExemptionReason(), $subtotal->getExemptionReasonCode()];
        }

        return $reasons;
    }
}
