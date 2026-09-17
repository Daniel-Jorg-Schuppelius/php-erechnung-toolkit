<?php
/*
 * Created on   : Thu Sep 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CiiSchemaValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Generators\ERechnungGenerator;
use ERechnungToolkit\Validators\CiiSchemaValidator;
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

/**
 * XSD-Schema-Validierung des CII-Zweigs gegen das gebündelte UN/CEFACT-CII-
 * D16B-Schema (reine libxml-Prüfung, kein Java). Die Testdokumente tragen jede
 * optionale Angabe, deren Reihenfolge das Schema festlegt — genau dort lag der
 * Generator vor v0.14 falsch (Partei, Steuer, Produkt).
 */
class CiiSchemaValidatorTest extends BaseTestCase {
    use XRechnungDocuments;

    private CiiSchemaValidator $validator;

    private ERechnungGenerator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new CiiSchemaValidator;
        $this->generator = new ERechnungGenerator;
    }

    public function test_bundled_schema_is_available(): void {
        $this->assertTrue($this->validator->isAvailable());
        $this->assertTrue($this->validator->supports('CrossIndustryInvoice'));
        $this->assertFalse($this->validator->supports('Invoice'));
        $this->assertFalse($this->validator->supports('CrossIndustryInvoice', 'urn:example'));
        $this->assertFalse((new CiiSchemaValidator('/does/not/exist'))->isAvailable());
    }

    public function test_xrechnung_with_all_party_and_line_details_is_schema_valid(): void {
        $this->assertSame([], $this->validator->validate($this->generator->generateCii($this->fullXRechnung())));
    }

    public function test_credit_note_with_preceding_invoice_is_schema_valid(): void {
        $xml = $this->generator->generateCii($this->fullXRechnung(creditNote: true));

        $this->assertSame([], $this->validator->validate($xml));
        $this->assertSame('381', $this->value($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode'));
        $this->assertSame('XR-2026-0815', $this->value($xml, '//ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument/ram:IssuerAssignedID'));
    }

    public function test_exempt_invoice_with_reason_code_is_schema_valid(): void {
        $document = ERechnungDocumentBuilder::zugferd('ZF-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-09-17'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withTaxExemptionReason('Steuerfreie Ausfuhrlieferung', 'VATEX-EU-G')
            ->addLine('Maschine', 1, 5000.00, 0.0, null, TaxCategory::EXPORT)
            ->build();

        $this->assertSame([], $this->validator->validate($this->generator->generateCii($document)));
    }

    public function test_xrechnung_context_names_the_business_process(): void {
        $xml = $this->generator->generateCii($this->fullXRechnung());

        $this->assertSame(
            'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0',
            $this->value($xml, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID')
        );
    }

    public function test_zugferd_context_stays_without_business_process(): void {
        $document = ERechnungDocumentBuilder::zugferd('ZF-2026-002')
            ->withIssueDate(new DateTimeImmutable('2026-09-17'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->addLine('Beratung', 1, 100.00)
            ->build();
        $xml = $this->generator->generateCii($document);

        $this->assertSame([], $this->validator->validate($xml));
        $this->assertStringNotContainsString('BusinessProcessSpecifiedDocumentContextParameter', $xml);
    }

    public function test_seller_identifier_is_written_before_the_name(): void {
        $xml = $this->generator->generateCii($this->smallBusinessXRechnung());

        $this->assertSame([], $this->validator->validate($xml));
        $this->assertSame('201/987/65432', $this->value($xml, '//ram:SellerTradeParty/ram:ID'));
        $this->assertSame('201/987/65432', $this->value($xml, "//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']"));
        $this->assertNull($this->value($xml, "//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']"));
    }

    public function test_wrong_element_order_is_reported(): void {
        $xml = $this->generator->generateCii($this->fullXRechnung());
        // Name vor ID in der Verkäuferpartei — der Fehler aus v0.13.
        $broken = preg_replace(
            '#(<ram:SellerTradeParty>\s*)(<ram:ID[^>]*>[^<]*</ram:ID>\s*)?(<ram:Name>[^<]*</ram:Name>)#',
            '$1$3<ram:ID>X-1</ram:ID>',
            $xml,
            1
        );

        $this->assertNotSame($xml, $broken);
        $this->assertNotSame([], $this->validator->validate((string) $broken));
    }

    public function test_non_cii_documents_are_reported(): void {
        $errors = $this->validator->validate($this->generator->generateUbl($this->fullXRechnung()));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Kein CII-Dokument', $errors[0]);
        $this->assertNotSame([], $this->validator->validate('kein xml'));
        $this->assertStringContainsString('Datei nicht gefunden', $this->validator->validateFile('/does/not/exist.xml')[0]);
    }

    private function value(string $xml, string $query): ?string {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rsm', CiiSchemaValidator::NAMESPACE);
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $nodes = $xpath->query($query);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof \DOMNode ? $node->textContent : null;
    }
}
