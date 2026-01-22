<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Parsers\ZugferdPdfParser;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for ZUGFeRD PDF Parser.
 */
class ZugferdPdfParserTest extends BaseTestCase {
    private ZugferdPdfParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new ZugferdPdfParser();
    }

    public function testIsAvailableReturnsBool(): void {
        $result = $this->parser->isAvailable();
        $this->assertIsBool($result);
    }

    public function testParseFileWithPdfToolkit(): void {
        if (!$this->parser->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed or pdfdetach/pdftk not available');
        }

        // Ohne echte ZUGFeRD-PDF testen wir null-Rückgabe
        $tempPdf = $this->createTempPdf();

        try {
            $document = $this->parser->parseFile($tempPdf);
            // Normale PDF ohne ZUGFeRD sollte null zurückgeben
            $this->assertNull($document);
        } finally {
            unlink($tempPdf);
        }
    }

    public function testIsZugferdPdfWithNonZugferdPdf(): void {
        if (!$this->parser->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $tempPdf = $this->createTempPdf();

        try {
            $result = $this->parser->isZugferdPdf($tempPdf);
            $this->assertFalse($result);
        } finally {
            unlink($tempPdf);
        }
    }

    public function testExtractXmlReturnsNullForNonZugferdPdf(): void {
        if (!$this->parser->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $tempPdf = $this->createTempPdf();

        try {
            $xml = $this->parser->extractXml($tempPdf);
            $this->assertNull($xml);
        } finally {
            unlink($tempPdf);
        }
    }

    public function testListAttachmentsReturnsArray(): void {
        if (!$this->parser->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $tempPdf = $this->createTempPdf();

        try {
            $attachments = $this->parser->listAttachments($tempPdf);
            $this->assertIsArray($attachments);
            $this->assertEmpty($attachments, 'Regular PDF should have no attachments');
        } finally {
            unlink($tempPdf);
        }
    }

    public function testParseXmlWithValidCii(): void {
        // Test mit gültigem CII XML (ohne PDF)
        $ciiXml = $this->getSampleCiiXml();

        $document = $this->parser->parseXml($ciiXml);

        $this->assertNotNull($document);
        $this->assertEquals('ZF-TEST-001', $document->getId());
    }

    public function testParseXmlWithInvalidXml(): void {
        $document = $this->parser->parseXml('invalid xml content');
        $this->assertNull($document);
    }

    /**
     * Creates a minimal PDF for testing.
     */
    private function createTempPdf(): string {
        $tempFile = sys_get_temp_dir() . '/test_' . uniqid() . '.pdf';

        // Minimales gültiges PDF
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
        $pdf .= "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n";
        $pdf .= "3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R>>endobj\n";
        $pdf .= "xref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\n";
        $pdf .= "trailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF";

        file_put_contents($tempFile, $pdf);

        return $tempFile;
    }

    /**
     * Returns a sample CII XML for testing.
     */
    private function getSampleCiiXml(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
    <rsm:ExchangedDocumentContext>
        <ram:GuidelineSpecifiedDocumentContextParameter>
            <ram:ID>urn:cen.eu:en16931:2017</ram:ID>
        </ram:GuidelineSpecifiedDocumentContextParameter>
    </rsm:ExchangedDocumentContext>
    <rsm:ExchangedDocument>
        <ram:ID>ZF-TEST-001</ram:ID>
        <ram:TypeCode>380</ram:TypeCode>
        <ram:IssueDateTime>
            <udt:DateTimeString format="102">20260122</udt:DateTimeString>
        </ram:IssueDateTime>
    </rsm:ExchangedDocument>
    <rsm:SupplyChainTradeTransaction>
        <ram:ApplicableHeaderTradeAgreement>
            <ram:SellerTradeParty>
                <ram:Name>Test Seller GmbH</ram:Name>
                <ram:SpecifiedTaxRegistration>
                    <ram:ID schemeID="VA">DE123456789</ram:ID>
                </ram:SpecifiedTaxRegistration>
                <ram:PostalTradeAddress>
                    <ram:LineOne>Teststraße 1</ram:LineOne>
                    <ram:PostcodeCode>12345</ram:PostcodeCode>
                    <ram:CityName>Berlin</ram:CityName>
                    <ram:CountryID>DE</ram:CountryID>
                </ram:PostalTradeAddress>
            </ram:SellerTradeParty>
            <ram:BuyerTradeParty>
                <ram:Name>Test Buyer AG</ram:Name>
                <ram:PostalTradeAddress>
                    <ram:LineOne>Kundenweg 2</ram:LineOne>
                    <ram:PostcodeCode>54321</ram:PostcodeCode>
                    <ram:CityName>München</ram:CityName>
                    <ram:CountryID>DE</ram:CountryID>
                </ram:PostalTradeAddress>
            </ram:BuyerTradeParty>
        </ram:ApplicableHeaderTradeAgreement>
        <ram:ApplicableHeaderTradeDelivery/>
        <ram:ApplicableHeaderTradeSettlement>
            <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
            <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>100.00</ram:LineTotalAmount>
                <ram:TaxBasisTotalAmount>100.00</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="EUR">19.00</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>119.00</ram:GrandTotalAmount>
                <ram:DuePayableAmount>119.00</ram:DuePayableAmount>
            </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        </ram:ApplicableHeaderTradeSettlement>
        <ram:IncludedSupplyChainTradeLineItem>
            <ram:AssociatedDocumentLineDocument>
                <ram:LineID>1</ram:LineID>
            </ram:AssociatedDocumentLineDocument>
            <ram:SpecifiedTradeProduct>
                <ram:Name>Test Artikel</ram:Name>
            </ram:SpecifiedTradeProduct>
            <ram:SpecifiedLineTradeAgreement>
                <ram:NetPriceProductTradePrice>
                    <ram:ChargeAmount>100.00</ram:ChargeAmount>
                </ram:NetPriceProductTradePrice>
            </ram:SpecifiedLineTradeAgreement>
            <ram:SpecifiedLineTradeDelivery>
                <ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity>
            </ram:SpecifiedLineTradeDelivery>
            <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                    <ram:TypeCode>VAT</ram:TypeCode>
                    <ram:CategoryCode>S</ram:CategoryCode>
                    <ram:RateApplicablePercent>19</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
                <ram:SpecifiedTradeSettlementLineMonetarySummation>
                    <ram:LineTotalAmount>100.00</ram:LineTotalAmount>
                </ram:SpecifiedTradeSettlementLineMonetarySummation>
            </ram:SpecifiedLineTradeSettlement>
        </ram:IncludedSupplyChainTradeLineItem>
    </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;
    }
}
