<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{Document, Party, PostalAddress};
use ERechnungToolkit\Enums\{ERechnungProfile, TaxCategory};
use ERechnungToolkit\Generators\ERechnungGenerator;
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

/**
 * Tests for E-Rechnung XML Generator.
 */
class ERechnungGeneratorTest extends BaseTestCase {
    use XRechnungDocuments;

    private ERechnungGenerator $generator;
    private Document $testDocument;

    protected function setUp(): void {
        parent::setUp();

        $this->generator = new ERechnungGenerator;

        $this->testDocument = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withDueDate(new DateTimeImmutable('2026-02-21'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 10, 150.00, 19.0)
            ->addLine('Software-Lizenz', 1, 499.00, 19.0)
            ->withPaymentTermsNet30()
            ->build();
    }

    public function test_generate_ubl_xml(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('Invoice', $xml);
        $this->assertStringContainsString('INV-2026-001', $xml);
    }

    public function test_ubl_xml_is_well_formed(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument;
        $result = $dom->loadXML($xml);

        $this->assertTrue($result, 'UBL XML should be well-formed');
    }

    public function test_ubl_xml_contains_required_elements(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        // Check required elements
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cbc:ID'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cbc:IssueDate'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cbc:InvoiceTypeCode'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cbc:DocumentCurrencyCode'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cac:AccountingSupplierParty'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cac:AccountingCustomerParty'));
        $this->assertNotEmpty($xpath->query('/ubl:Invoice/cac:InvoiceLine'));
    }

    public function test_ubl_xml_invoice_number(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $this->assertSame(1, $this->xpathCount($xpath, '/ubl:Invoice/cbc:ID'));
        $this->assertEquals('INV-2026-001', $this->xpathText($xpath, '/ubl:Invoice/cbc:ID'));
    }

    public function test_ubl_xml_seller_party(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Muster GmbH', $xml);
        $this->assertStringContainsString('DE123456789', $xml);
        $this->assertStringContainsString('Musterstraße 1', $xml);
        $this->assertStringContainsString('12345', $xml);
        $this->assertStringContainsString('Berlin', $xml);
    }

    public function test_ubl_xml_buyer_party(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Kunde AG', $xml);
        $this->assertStringContainsString('DE987654321', $xml);
        $this->assertStringContainsString('Kundenweg 2', $xml);
        $this->assertStringContainsString('München', $xml);
    }

    public function test_ubl_xml_invoice_lines(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Beratungsleistung', $xml);
        $this->assertStringContainsString('Software-Lizenz', $xml);
        $this->assertStringContainsString('1500.00', $xml); // 10 * 150
        $this->assertStringContainsString('499.00', $xml);
    }

    public function test_ubl_xml_tax_total(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        // Tax: 1999 * 0.19 = 379.81
        $this->assertStringContainsString('TaxTotal', $xml);
        $this->assertStringContainsString('TaxSubtotal', $xml);
        $this->assertStringContainsString('S', $xml); // Standard rate category
        $this->assertStringContainsString('19.00', $xml); // Tax percent
    }

    public function test_ubl_xml_monetary_total(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('LegalMonetaryTotal', $xml);
        $this->assertStringContainsString('LineExtensionAmount', $xml);
        $this->assertStringContainsString('TaxExclusiveAmount', $xml);
        $this->assertStringContainsString('TaxInclusiveAmount', $xml);
        $this->assertStringContainsString('PayableAmount', $xml);
    }

    public function test_generate_cii_xml(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
        $this->assertStringContainsString('INV-2026-001', $xml);
    }

    public function test_cii_xml_is_well_formed(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $dom = new DOMDocument;
        $result = $dom->loadXML($xml);

        $this->assertTrue($result, 'CII XML should be well-formed');
    }

    public function test_cii_xml_contains_required_elements(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

        // Check required elements
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext'));
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument'));
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction'));
    }

    public function test_cii_xml_seller_party(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertStringContainsString('SellerTradeParty', $xml);
        $this->assertStringContainsString('Muster GmbH', $xml);
    }

    public function test_cii_xml_buyer_party(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertStringContainsString('BuyerTradeParty', $xml);
        $this->assertStringContainsString('Kunde AG', $xml);
    }

    public function test_credit_note_generates_ubl_credit_note(): void {
        $creditNote = Document::creditNote(
            id: 'CN-2026-001',
            issueDate: new DateTimeImmutable('2026-01-25'),
            seller: new Party(
                name: 'Muster GmbH',
                postalAddress: PostalAddress::german('Musterstraße 1', '12345', 'Berlin'),
                vatId: 'DE123456789'
            ),
            buyer: new Party(
                name: 'Kunde AG',
                postalAddress: PostalAddress::german('Kundenweg 2', '54321', 'München')
            ),
            precedingInvoiceReference: 'INV-2026-001'
        );

        $xml = $this->generator->generateUbl($creditNote);

        $this->assertStringContainsString('CreditNote', $xml);
        $this->assertStringContainsString('381', $xml); // Credit note type code
    }

    public function test_x_rechnung_document_to_xml(): void {
        $xrechnung = ERechnungDocumentBuilder::xrechnung('XR-2026-001', '04011000-12345-67')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withSellerEndpoint('seller@example.com', 'EM')
            ->withBuyer('Öffentliche Verwaltung')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId('04011000-12345-67')
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $xml = $xrechnung->toXml();

        // XRechnung should generate UBL
        $this->assertStringContainsString('Invoice', $xml);
        $this->assertStringContainsString('xrechnung', strtolower($xml));
    }

    public function test_zugferd_document_to_xml(): void {
        $zugferd = ERechnungDocumentBuilder::zugferd('ZF-2026-001', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferantenweg 5', '50667', 'Köln')
            ->withBuyer('Empfänger AG')
            ->withBuyerAddress('Empfängerstraße 10', '60311', 'Frankfurt')
            ->addLine('Ware', 1, 100.00)
            ->build();

        $xml = $zugferd->toXml();

        // ZUGFeRD should generate CII
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
    }

    public function test_document_level_allowance_in_xml(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 10, 100.00)
            ->addDiscount(100.00, 'Treuerabatt')
            ->build();

        $xml = $this->generator->generateUbl($document);

        $this->assertStringContainsString('AllowanceCharge', $xml);
        $this->assertStringContainsString('false', $xml); // ChargeIndicator = false for allowance
        $this->assertStringContainsString('Treuerabatt', $xml);
    }

    public function test_document_level_charge_in_xml(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->addShipping(5.95)
            ->build();

        $xml = $this->generator->generateUbl($document);

        $this->assertStringContainsString('AllowanceCharge', $xml);
        $this->assertStringContainsString('true', $xml); // ChargeIndicator = true for charge
    }

    public function test_payment_means_in_xml(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withSepaCreditTransfer()
            ->addLine('Test', 1, 100.00)
            ->build();

        $xml = $this->generator->generateUbl($document);

        $this->assertStringContainsString('PaymentMeans', $xml);
        $this->assertStringContainsString('58', $xml); // SEPA credit transfer code
        // EN 16931 requires the IBAN without spaces in the XML payload.
        $this->assertStringContainsString('DE89370400440532013000', $xml); // IBAN (normalized)
        $this->assertStringNotContainsString('DE89 3704 0044 0532 0130 00', $xml); // no display formatting
        $this->assertStringContainsString('COBADEFFXXX', $xml); // BIC
    }

    public function test_x_rechnung_customization_id_uses_current_urn(): void {
        $xrechnung = ERechnungDocumentBuilder::xrechnung('XR-2026-002', '04011000-12345-67')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withSellerEndpoint('seller@example.com', 'EM')
            ->withBuyer('Öffentliche Verwaltung')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId('04011000-12345-67')
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $xml = $this->generator->generateUbl($xrechnung);

        $this->assertStringContainsString(
            '<cbc:CustomizationID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</cbc:CustomizationID>',
            $xml
        );
        // The deprecated xoev-de authority must no longer be emitted.
        $this->assertStringNotContainsString('xoev-de:kosit', $xml);
    }

    public function test_ubl_national_tax_number_is_emitted_as_party_tax_scheme_fc(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-003')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789', '151/815/08150')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->build();

        $xml = $this->generator->generateUbl($document);

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        // BT-32 must be a PartyTaxScheme with TaxScheme/ID = FC.
        $fcXpath = "//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='FC']/cbc:CompanyID";
        $this->assertSame(1, $this->xpathCount($xpath, $fcXpath));
        $this->assertSame('151/815/08150', $this->xpathText($xpath, $fcXpath));

        // It must NOT live in PartyLegalEntity/CompanyID anymore.
        $this->assertSame(0, $this->xpathCount($xpath, '//cac:PartyLegalEntity/cbc:CompanyID'));

        // VAT ID (BT-31) stays as PartyTaxScheme with TaxScheme/ID = VAT.
        $this->assertSame(
            'DE123456789',
            $this->xpathText($xpath, "//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID")
        );
    }

    public function test_cii_national_tax_number_is_emitted_with_scheme_fc(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-004')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789', '151/815/08150')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->build();

        $xml = $this->generator->generateCii($document);

        $this->assertStringContainsString('schemeID="FC"', $xml);
        $this->assertStringContainsString('151/815/08150', $xml);
    }

    public function test_tax_exemption_reason_is_emitted(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-005')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Klein GmbH', 'DE123456789')
            ->withSellerAddress('Kleinweg 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Leistung', 1, 100.00, 0.0, null, \ERechnungToolkit\Enums\TaxCategory::EXEMPT)
            ->withTaxExemptionReason('Kleinunternehmer gemäß § 19 UStG', 'VATEX-EU-O')
            ->build();

        $ubl = $this->generator->generateUbl($document);
        $cii = $this->generator->generateCii($document);

        $this->assertSame(
            'Kleinunternehmer gemäß § 19 UStG',
            $this->ublText($ubl, '//cac:TaxCategory/cbc:TaxExemptionReason')
        );
        $this->assertSame(
            'VATEX-EU-O',
            $this->ublText($ubl, '//cac:TaxCategory/cbc:TaxExemptionReasonCode')
        );
        $this->assertStringContainsString('Kleinunternehmer gemäß § 19 UStG', $cii);
        $this->assertStringContainsString('VATEX-EU-O', $cii);
    }

    /**
     * Returns the trimmed text content of the first node matching the XPath
     * in a UBL Invoice document (cbc/cac namespaces pre-registered).
     */
    private function ublText(string $xml, string $query): ?string {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $nodes = $xpath->query($query);
        if ($nodes === false) {
            return null;
        }
        $node = $nodes->item(0);

        return $node instanceof DOMNode ? $node->textContent : null;
    }

    public function test_remittance_information_and_account_holder_are_emitted(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-006')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX', 'Commerzbank', 'Muster GmbH Konto')
            ->withRemittanceInformation('RF18 1234 5678')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->build();

        $ubl = $this->generator->generateUbl($document);
        $cii = $this->generator->generateCii($document);

        // BT-83 remittance information
        $this->assertSame('RF18 1234 5678', $this->ublText($ubl, '//cac:PaymentMeans/cbc:PaymentID'));
        $this->assertStringContainsString('RF18 1234 5678', $cii);
        $this->assertStringContainsString('PaymentReference', $cii);

        // BT-85 account holder name
        $this->assertSame('Muster GmbH Konto', $this->ublText($ubl, '//cac:PayeeFinancialAccount/cbc:Name'));
        $this->assertStringContainsString('Muster GmbH Konto', $cii);
        $this->assertStringContainsString('AccountName', $cii);
    }
    public function test_ubl_line_allowance_carries_no_tax_category_but_document_allowance_does(): void {
        $xml = $this->generator->generateUbl($this->withLineAndDocumentDiscount());

        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $this->assertSame(1, $this->countNodes($xpath, '/ubl:Invoice/cac:InvoiceLine/cac:AllowanceCharge'));
        $this->assertSame(0, $this->countNodes($xpath, '/ubl:Invoice/cac:InvoiceLine/cac:AllowanceCharge/cac:TaxCategory'));
        $this->assertSame(1, $this->countNodes($xpath, '/ubl:Invoice/cac:AllowanceCharge/cac:TaxCategory'));
    }

    private function countNodes(DOMXPath $xpath, string $query): int {
        $nodes = $xpath->query($query);
        $this->assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    private function withLineAndDocumentDiscount(): Document {
        $euro = \CommonToolkit\Enums\CurrencyCode::Euro;
        $line = new \ERechnungToolkit\Entities\InvoiceLine(
            id: '1',
            quantity: 2.0,
            unitCode: \ERechnungToolkit\Enums\UnitCode::PIECE,
            netAmount: \CommonToolkit\ValueObjects\Money::of('180.00', $euro),
            itemName: 'Ware',
            unitPrice: \CommonToolkit\ValueObjects\Money::of('100.00', $euro),
            taxCategory: \ERechnungToolkit\Enums\TaxCategory::STANDARD,
            taxPercent: 19.0,
        );
        $line->addAllowanceCharge(\ERechnungToolkit\Entities\AllowanceCharge::discount(\CommonToolkit\ValueObjects\Money::of('20.00', $euro)));

        return ERechnungDocumentBuilder::create('INV-2026-002')
            ->withIssueDate(new DateTimeImmutable('2026-09-17'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->addInvoiceLine($line)
            ->addDiscount(10.00)
            ->build();
    }

    public function test_deliver_to_address_and_location_are_emitted(): void {
        $document = $this->intraCommunityXRechnung(fullDeliveryParty: true);

        $ubl = $this->xpathFor($this->generator->generateUbl($document));
        $delivery = '/ubl:Invoice/cac:Delivery';
        $this->assertSame(['cbc:ActualDeliveryDate', 'cac:DeliveryLocation', 'cac:DeliveryParty'], $this->childNames($ubl, $delivery));
        $this->assertSame('4012345000009', $ubl->evaluate("string({$delivery}/cac:DeliveryLocation/cbc:ID[@schemeID='0088'])"));
        $this->assertSame('NL', $ubl->evaluate("string({$delivery}/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode)"));
        $this->assertSame('Rotterdam', $ubl->evaluate("string({$delivery}/cac:DeliveryLocation/cac:Address/cbc:CityName)"));
        $this->assertSame('3011 AA', $ubl->evaluate("string({$delivery}/cac:DeliveryLocation/cac:Address/cbc:PostalZone)"));
        $this->assertSame('Zuid-Holland', $ubl->evaluate("string({$delivery}/cac:DeliveryLocation/cac:Address/cbc:CountrySubentity)"));
        $this->assertSame('Lager Rotterdam', $ubl->evaluate("string({$delivery}/cac:DeliveryParty/cac:PartyName/cbc:Name)"));

        $cii = $this->xpathFor($this->generator->generateCii($document));
        $delivery = '//ram:ApplicableHeaderTradeDelivery';
        $this->assertSame(['ram:ShipToTradeParty', 'ram:ActualDeliverySupplyChainEvent'], $this->childNames($cii, $delivery));
        $this->assertSame('4012345000009', $cii->evaluate("string({$delivery}/ram:ShipToTradeParty/ram:GlobalID[@schemeID='0088'])"));
        $this->assertSame('Lager Rotterdam', $cii->evaluate("string({$delivery}/ram:ShipToTradeParty/ram:Name)"));
        $this->assertSame('NL', $cii->evaluate("string({$delivery}/ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID)"));
        $this->assertSame('Zuid-Holland', $cii->evaluate("string({$delivery}/ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountrySubDivisionName)"));
    }

    public function test_deliver_to_address_without_name_leaves_out_the_delivery_party(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-010')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->withDeliveryAddress(null, '75001', 'Paris', 'FR')
            ->addLine('Ware', 1, 100.00)
            ->build();

        $ubl = $this->xpathFor($this->generator->generateUbl($document));
        $this->assertSame(['cac:DeliveryLocation'], $this->childNames($ubl, '/ubl:Invoice/cac:Delivery'));
        $this->assertSame(0.0, $ubl->evaluate('count(//cac:DeliveryLocation/cac:Address/cbc:StreetName)'));
        $this->assertSame('FR', $ubl->evaluate('string(//cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode)'));

        $cii = $this->xpathFor($this->generator->generateCii($document));
        $this->assertSame(['ram:PostalTradeAddress'], $this->childNames($cii, '//ram:ShipToTradeParty'));
    }

    public function test_minimum_profile_leaves_out_the_ship_to_party(): void {
        $document = ERechnungDocumentBuilder::zugferd('ZF-2026-010', ERechnungProfile::MINIMUM)
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->withDeliveryAddress('Havenweg 5', '3011 AA', 'Rotterdam', 'NL')
            ->addLine('Ware', 1, 100.00)
            ->build();

        $this->assertStringNotContainsString('ShipToTradeParty', $this->generator->generateCii($document));
    }

    public function test_not_subject_to_vat_carries_rates_only_in_the_breakdown(): void {
        $document = $this->notSubjectXRechnung();

        $ubl = $this->xpathFor($this->generator->generateUbl($document));
        $this->assertSame(0.0, $ubl->evaluate('count(//cac:ClassifiedTaxCategory/cbc:Percent)'), 'BR-O-05');
        $this->assertSame(0.0, $ubl->evaluate('count(/ubl:Invoice/cac:AllowanceCharge/cac:TaxCategory/cbc:Percent)'), 'BR-O-06, BR-O-07');
        $this->assertSame(2.0, $ubl->evaluate("count(/ubl:Invoice/cac:AllowanceCharge/cac:TaxCategory[cbc:ID='O'])"));
        $this->assertSame('0.00', $ubl->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent)'), 'BR-DE-14');
        $this->assertSame('VATEX-EU-O', $ubl->evaluate('string(//cac:TaxSubtotal/cac:TaxCategory/cbc:TaxExemptionReasonCode)'));

        $cii = $this->xpathFor($this->generator->generateCii($document));
        $this->assertSame(0.0, $cii->evaluate('count(//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent)'));
        $this->assertSame(0.0, $cii->evaluate('count(//ram:CategoryTradeTax/ram:RateApplicablePercent)'));
        $this->assertSame('0.00', $cii->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent)'));
        $this->assertSame('VATEX-EU-O', $cii->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode)'));
    }

    public function test_legal_registration_identifiers_are_emitted(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-011')
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerLegalRegistration('HRB 12345')
            ->withBuyer('Bau GmbH')
            ->withBuyerLegalRegistration('5790000435968', '0088')
            ->addLine('Bauleistung', 1, 100.00, 0.0, null, TaxCategory::REVERSE_CHARGE)
            ->withTaxExemptionReason('Steuerschuldnerschaft des Leistungsempfängers')
            ->build();

        $ubl = $this->xpathFor($this->generator->generateUbl($document));
        $buyer = '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party';
        $this->assertSame('5790000435968', $ubl->evaluate("string({$buyer}/cac:PartyLegalEntity/cbc:CompanyID[@schemeID='0088'])"));
        $this->assertSame(0.0, $ubl->evaluate("count({$buyer}/cac:PartyTaxScheme)"), 'BT-47 ist keine Steuernummer');
        $this->assertSame('HRB 12345', $ubl->evaluate('string(/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID[not(@schemeID)])'));

        $cii = $this->xpathFor($this->generator->generateCii($document));
        $this->assertSame(['ram:Name', 'ram:SpecifiedLegalOrganization'], $this->childNames($cii, '//ram:BuyerTradeParty'));
        $this->assertSame('5790000435968', $cii->evaluate("string(//ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID[@schemeID='0088'])"));
        $this->assertSame('HRB 12345', $cii->evaluate('string(//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID)'));
    }

    public function test_exemption_reason_per_category_skips_zero_and_standard_rate(): void {
        $document = $this->mixedCategoriesXRechnung();

        $ubl = $this->xpathFor($this->generator->generateUbl($document));
        $category = static fn (string $code): string => "//cac:TaxSubtotal/cac:TaxCategory[cbc:ID='{$code}']";
        foreach (['S', 'Z'] as $code) {
            $this->assertSame(0.0, $ubl->evaluate("count({$category($code)}/cbc:TaxExemptionReason | {$category($code)}/cbc:TaxExemptionReasonCode)"), $code);
        }
        $this->assertSame('Steuerfreie Heilbehandlung nach § 4 Nr. 14 UStG', $ubl->evaluate("string({$category('E')}/cbc:TaxExemptionReason)"));
        $this->assertSame('VATEX-EU-132-1C', $ubl->evaluate("string({$category('E')}/cbc:TaxExemptionReasonCode)"));

        $cii = $this->xpathFor($this->generator->generateCii($document));
        $tax = static fn (string $code): string => "//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax[ram:CategoryCode='{$code}']";
        foreach (['S', 'Z'] as $code) {
            $this->assertSame(0.0, $cii->evaluate("count({$tax($code)}/ram:ExemptionReason | {$tax($code)}/ram:ExemptionReasonCode)"), $code);
        }
        $this->assertSame('VATEX-EU-132-1C', $cii->evaluate("string({$tax('E')}/ram:ExemptionReasonCode)"));
    }

    private function xpathFor(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

        return $xpath;
    }

    /**
     * @return list<string>
     */
    private function childNames(DOMXPath $xpath, string $query): array {
        $nodes = $xpath->query("{$query}/*");
        $this->assertNotFalse($nodes, $query);
        $names = [];
        foreach ($nodes as $node) {
            $names[] = $node->nodeName;
        }

        return $names;
    }
}
