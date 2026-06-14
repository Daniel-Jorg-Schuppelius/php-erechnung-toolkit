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

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Generators\ERechnungGenerator;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for E-Rechnung XML Generator.
 */
class ERechnungGeneratorTest extends BaseTestCase {
    private ERechnungGenerator $generator;
    private Document $testDocument;

    protected function setUp(): void {
        parent::setUp();

        $this->generator = new ERechnungGenerator();

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

    public function testGenerateUblXml(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('Invoice', $xml);
        $this->assertStringContainsString('INV-2026-001', $xml);
    }

    public function testUblXmlIsWellFormed(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument();
        $result = $dom->loadXML($xml);

        $this->assertTrue($result, 'UBL XML should be well-formed');
    }

    public function testUblXmlContainsRequiredElements(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument();
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

    public function testUblXmlInvoiceNumber(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        $idNodes = $xpath->query('/ubl:Invoice/cbc:ID');
        $this->assertEquals(1, $idNodes->length);
        $this->assertEquals('INV-2026-001', $idNodes->item(0)->textContent);
    }

    public function testUblXmlSellerParty(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Muster GmbH', $xml);
        $this->assertStringContainsString('DE123456789', $xml);
        $this->assertStringContainsString('Musterstraße 1', $xml);
        $this->assertStringContainsString('12345', $xml);
        $this->assertStringContainsString('Berlin', $xml);
    }

    public function testUblXmlBuyerParty(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Kunde AG', $xml);
        $this->assertStringContainsString('DE987654321', $xml);
        $this->assertStringContainsString('Kundenweg 2', $xml);
        $this->assertStringContainsString('München', $xml);
    }

    public function testUblXmlInvoiceLines(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('Beratungsleistung', $xml);
        $this->assertStringContainsString('Software-Lizenz', $xml);
        $this->assertStringContainsString('1500.00', $xml); // 10 * 150
        $this->assertStringContainsString('499.00', $xml);
    }

    public function testUblXmlTaxTotal(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        // Tax: 1999 * 0.19 = 379.81
        $this->assertStringContainsString('TaxTotal', $xml);
        $this->assertStringContainsString('TaxSubtotal', $xml);
        $this->assertStringContainsString('S', $xml); // Standard rate category
        $this->assertStringContainsString('19.00', $xml); // Tax percent
    }

    public function testUblXmlMonetaryTotal(): void {
        $xml = $this->generator->generateUbl($this->testDocument);

        $this->assertStringContainsString('LegalMonetaryTotal', $xml);
        $this->assertStringContainsString('LineExtensionAmount', $xml);
        $this->assertStringContainsString('TaxExclusiveAmount', $xml);
        $this->assertStringContainsString('TaxInclusiveAmount', $xml);
        $this->assertStringContainsString('PayableAmount', $xml);
    }

    public function testGenerateCiiXml(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('CrossIndustryInvoice', $xml);
        $this->assertStringContainsString('INV-2026-001', $xml);
    }

    public function testCiiXmlIsWellFormed(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $dom = new DOMDocument();
        $result = $dom->loadXML($xml);

        $this->assertTrue($result, 'CII XML should be well-formed');
    }

    public function testCiiXmlContainsRequiredElements(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

        // Check required elements
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext'));
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument'));
        $this->assertNotEmpty($xpath->query('/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction'));
    }

    public function testCiiXmlSellerParty(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertStringContainsString('SellerTradeParty', $xml);
        $this->assertStringContainsString('Muster GmbH', $xml);
    }

    public function testCiiXmlBuyerParty(): void {
        $xml = $this->generator->generateCii($this->testDocument);

        $this->assertStringContainsString('BuyerTradeParty', $xml);
        $this->assertStringContainsString('Kunde AG', $xml);
    }

    public function testCreditNoteGeneratesUblCreditNote(): void {
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

    public function testXRechnungDocumentToXml(): void {
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

    public function testZugferdDocumentToXml(): void {
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

    public function testDocumentLevelAllowanceInXml(): void {
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

    public function testDocumentLevelChargeInXml(): void {
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

    public function testPaymentMeansInXml(): void {
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

    public function testXRechnungCustomizationIdUsesCurrentUrn(): void {
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

    public function testUblNationalTaxNumberIsEmittedAsPartyTaxSchemeFc(): void {
        $document = ERechnungDocumentBuilder::create('INV-2026-003')
            ->withIssueDate(new DateTimeImmutable('2026-01-22'))
            ->withSeller('Muster GmbH', 'DE123456789', '151/815/08150')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Produkt', 1, 100.00)
            ->build();

        $xml = $this->generator->generateUbl($document);

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        // BT-32 must be a PartyTaxScheme with TaxScheme/ID = FC.
        $fcNodes = $xpath->query(
            "//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='FC']/cbc:CompanyID"
        );
        $this->assertNotNull($fcNodes);
        $this->assertSame(1, $fcNodes->length);
        $this->assertSame('151/815/08150', $fcNodes->item(0)->textContent);

        // It must NOT live in PartyLegalEntity/CompanyID anymore.
        $legalCompanyId = $xpath->query('//cac:PartyLegalEntity/cbc:CompanyID');
        $this->assertSame(0, $legalCompanyId->length);

        // VAT ID (BT-31) stays as PartyTaxScheme with TaxScheme/ID = VAT.
        $vatNodes = $xpath->query(
            "//cac:PartyTaxScheme[cac:TaxScheme/cbc:ID='VAT']/cbc:CompanyID"
        );
        $this->assertSame('DE123456789', $vatNodes->item(0)->textContent);
    }

    public function testCiiNationalTaxNumberIsEmittedWithSchemeFc(): void {
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

    public function testTaxExemptionReasonIsEmitted(): void {
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
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $nodes = $xpath->query($query);
        return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0)->textContent : null;
    }

    public function testRemittanceInformationAndAccountHolderAreEmitted(): void {
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
}
