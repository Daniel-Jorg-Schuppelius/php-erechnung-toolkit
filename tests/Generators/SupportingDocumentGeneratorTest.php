<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{Document, SupportingDocument};
use ERechnungToolkit\Enums\{AttachmentMimeCode, ERechnungProfile};
use ERechnungToolkit\Generators\ERechnungGenerator;
use ERechnungToolkit\Validators\{CiiSchemaValidator, UblSchemaValidator};
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

/**
 * Unterlagen (BG-24), Zeiträume (BG-14, BG-26) und Objektkennungen (BT-18,
 * BT-128) an der Stelle, die UBL 2.1 und CII D16B vorschreiben.
 */
class SupportingDocumentGeneratorTest extends BaseTestCase {
    use XRechnungDocuments;

    private ERechnungGenerator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new ERechnungGenerator;
    }

    public function test_ubl_carries_periods_references_and_attachments(): void {
        $xml = $this->generator->generateUbl($this->supportingDocumentXRechnung());
        $x = $this->ublXPath($xml);

        $this->assertSame('2026-09-01', $x->evaluate('string(/inv:Invoice/cac:InvoicePeriod/cbc:StartDate)'));
        $this->assertSame('2026-09-30', $x->evaluate('string(/inv:Invoice/cac:InvoicePeriod/cbc:EndDate)'));

        $this->assertSame('SLA-2026-01', $x->evaluate("string(/inv:Invoice/cac:AdditionalDocumentReference[cbc:DocumentTypeCode='130']/cbc:ID)"));
        $this->assertSame('AAJ', $x->evaluate("string(/inv:Invoice/cac:AdditionalDocumentReference[cbc:DocumentTypeCode='130']/cbc:ID/@schemeID)"));
        $this->assertSame(3.0, $x->evaluate('count(/inv:Invoice/cac:AdditionalDocumentReference[not(cbc:DocumentTypeCode)])'));

        $pdf = "/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='LN-1']";
        $this->assertSame('Leistungsnachweis September 2026', $x->evaluate("string({$pdf}/cbc:DocumentDescription)"));
        $this->assertSame('application/pdf', $x->evaluate("string({$pdf}/cac:Attachment/cbc:EmbeddedDocumentBinaryObject/@mimeCode)"));
        $this->assertSame('nachweis-1.pdf', $x->evaluate("string({$pdf}/cac:Attachment/cbc:EmbeddedDocumentBinaryObject/@filename)"));
        $this->assertSame($this->samplePdf(), base64_decode((string) $x->evaluate("string({$pdf}/cac:Attachment/cbc:EmbeddedDocumentBinaryObject)"), true));
        $this->assertSame(
            'https://rechnung.example.org/anlage/123',
            $x->evaluate("string(/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='LN-3']/cac:Attachment/cac:ExternalReference/cbc:URI)")
        );

        $line = "/inv:Invoice/cac:InvoiceLine[cbc:ID='1']";
        $this->assertSame('2026-09-02', $x->evaluate("string({$line}/cac:InvoicePeriod/cbc:StartDate)"));
        $this->assertSame('2026-09-09', $x->evaluate("string({$line}/cac:InvoicePeriod/cbc:EndDate)"));
        $this->assertSame('TICKET-4711', $x->evaluate("string({$line}/cac:DocumentReference[cbc:DocumentTypeCode='130']/cbc:ID)"));

        $this->assertSame([], (new UblSchemaValidator)->validate($xml));
    }

    public function test_ubl_credit_note_with_attachments_is_schema_valid(): void {
        $xml = $this->generator->generateUbl($this->supportingDocumentXRechnung(creditNote: true));

        $this->assertStringContainsString('<cac:CreditNoteLine>', $xml);
        $this->assertSame([], (new UblSchemaValidator)->validate($xml));
    }

    public function test_cii_carries_periods_references_and_attachments(): void {
        $xml = $this->generator->generateCii($this->supportingDocumentXRechnung());
        $x = $this->ciiXPath($xml);

        $agreement = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';
        $settlement = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';

        $this->assertSame('20260901', $x->evaluate("string({$settlement}/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString)"));
        $this->assertSame('20260930', $x->evaluate("string({$settlement}/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString)"));
        $this->assertSame(1.0, $x->evaluate("count({$settlement}/ram:BillingSpecifiedPeriod/following-sibling::ram:SpecifiedTradeAllowanceCharge)"), 'BG-14 must precede the allowances');

        $this->assertSame(3.0, $x->evaluate("count({$agreement}/ram:AdditionalReferencedDocument[ram:TypeCode='916'])"));
        $pdf = "{$agreement}/ram:AdditionalReferencedDocument[ram:IssuerAssignedID='LN-1']";
        $this->assertSame('Leistungsnachweis September 2026', $x->evaluate("string({$pdf}/ram:Name)"));
        $this->assertSame('nachweis-1.pdf', $x->evaluate("string({$pdf}/ram:AttachmentBinaryObject/@filename)"));
        $this->assertSame($this->samplePdf(), base64_decode((string) $x->evaluate("string({$pdf}/ram:AttachmentBinaryObject)"), true));
        $this->assertSame('https://rechnung.example.org/anlage/123', $x->evaluate("string({$agreement}/ram:AdditionalReferencedDocument[ram:IssuerAssignedID='LN-3']/ram:URIID)"));
        $this->assertSame('AAJ', $x->evaluate("string({$agreement}/ram:AdditionalReferencedDocument[ram:TypeCode='130']/ram:ReferenceTypeCode)"));

        $line = "/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem[ram:AssociatedDocumentLineDocument/ram:LineID='1']/ram:SpecifiedLineTradeSettlement";
        $this->assertSame('20260902', $x->evaluate("string({$line}/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString)"));
        $this->assertSame('TICKET-4711', $x->evaluate("string({$line}/ram:AdditionalReferencedDocument[ram:TypeCode='130']/ram:IssuerAssignedID)"));

        $this->assertSame([], (new CiiSchemaValidator)->validate($xml));
    }

    public function test_basic_profile_keeps_periods_and_drops_referenced_documents(): void {
        $document = $this->simpleDocument(ERechnungProfile::BASIC);
        $x = $this->ciiXPath($this->generator->generateCii($document));

        $this->assertSame(0.0, $x->evaluate('count(//ram:AdditionalReferencedDocument)'));
        $this->assertSame(1.0, $x->evaluate('count(//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod)'));
    }

    public function test_minimum_profile_drops_the_invoicing_period(): void {
        $x = $this->ciiXPath($this->generator->generateCii($this->simpleDocument(ERechnungProfile::MINIMUM)));

        $this->assertSame(0.0, $x->evaluate('count(//ram:BillingSpecifiedPeriod)'));
    }

    public function test_document_without_new_fields_is_unchanged(): void {
        $xml = $this->generator->generateUbl($this->fullXRechnung());

        $this->assertStringNotContainsString('cac:InvoicePeriod', $xml);
        $this->assertStringNotContainsString('cac:AdditionalDocumentReference', $xml);
    }

    private function simpleDocument(ERechnungProfile $profile): Document {
        return ERechnungDocumentBuilder::zugferd('RE-1', $profile)
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withInvoicingPeriod(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'))
            ->addSupportingDocument(SupportingDocument::embedded('LN-1', $this->samplePdf(), AttachmentMimeCode::PDF, 'nachweis-1.pdf'))
            ->addLine('Beratung', 1, 100.00)
            ->build();
    }

    private function ublXPath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $x = new DOMXPath($dom);
        $x->registerNamespace('inv', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
        $x->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

        return $x;
    }

    private function ciiXPath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $x = new DOMXPath($dom);
        $x->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $x->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $x->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        return $x;
    }
}
