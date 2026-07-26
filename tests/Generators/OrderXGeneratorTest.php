<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\{OrderXProfile, TaxCategory, UnitCode};
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the Order-X (CII) generator.
 */
class OrderXGeneratorTest extends BaseTestCase {
    private const RSM_NS = 'urn:un:unece:uncefact:data:SCRDMCCBDACIOMessageStructure:100';
    private const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:128';

    private function buildOrderXml(): string {
        $order = OrderBuilder::create('ORD-X-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Besteller AG', 'DE111111111')
            ->withBuyerAddress('Bestellweg 1', '11111', 'Bestellstadt')
            ->withSeller('Lieferant GmbH', 'DE222222222')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->addLine('Ware A', 5, 120.00, UnitCode::PIECE, 'ART-1', 19.0, TaxCategory::STANDARD)
            ->build();

        return $order->toOrderXXml();
    }

    private function xpath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', self::RSM_NS);
        $xpath->registerNamespace('ram', self::RAM_NS);
        return $xpath;
    }

    public function test_generates_well_formed_orderx(): void {
        $xml = $this->buildOrderXml();
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Order-X XML should be well-formed');
        $root = $dom->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('SCRDMCCBDACIOMessageStructure', $root->localName);
        $this->assertSame(self::RSM_NS, $root->namespaceURI);
        // ram namespace must be the D20B (128) version, not the invoice D16B (100).
        $this->assertStringContainsString('ReusableAggregateBusinessInformationEntity:128', $xml);
    }

    public function test_emits_context_and_document_header(): void {
        $xpath = $this->xpath($this->buildOrderXml());

        $this->assertSame(
            'A1',
            $this->xpathText($xpath, '//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID')
        );
        $this->assertSame(
            OrderXProfile::COMFORT->value,
            $this->xpathText($xpath, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID')
        );
        $this->assertSame('ORD-X-001', $this->xpathText($xpath, '//rsm:ExchangedDocument/ram:ID'));
        $this->assertSame('220', $this->xpathText($xpath, '//rsm:ExchangedDocument/ram:TypeCode'));
    }

    public function test_emits_parties_seller_first(): void {
        $xpath = $this->xpath($this->buildOrderXml());

        $this->assertSame(
            'Lieferant GmbH',
            $this->xpathText($xpath, '//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name')
        );
        $this->assertSame(
            'Besteller AG',
            $this->xpathText($xpath, '//ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name')
        );
    }

    public function test_emits_line_with_requested_quantity_and_net_price(): void {
        $xpath = $this->xpath($this->buildOrderXml());

        $qty = $this->xpathNode($xpath, '//ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeDelivery/ram:RequestedQuantity');
        $this->assertInstanceOf(DOMElement::class, $qty);
        $this->assertSame('5.00', $qty->textContent);
        $this->assertSame('C62', $qty->getAttribute('unitCode'));

        $this->assertSame(
            '120.00',
            $this->xpathText($xpath, '//ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount')
        );
        $this->assertSame(
            '600.00',
            $this->xpathText($xpath, '//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount')
        );
    }

    public function test_emits_tax_and_monetary_summation(): void {
        $xpath = $this->xpath($this->buildOrderXml());

        // Header VAT breakdown: basis 600, 19% => 114.
        $headerTax = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax';
        $this->assertSame('114.00', $this->xpathText($xpath, "{$headerTax}/ram:CalculatedAmount"));
        $this->assertSame('600.00', $this->xpathText($xpath, "{$headerTax}/ram:BasisAmount"));
        $this->assertSame('19.00', $this->xpathText($xpath, "{$headerTax}/ram:RateApplicablePercent"));

        $sum = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        $this->assertSame('600.00', $this->xpathText($xpath, "{$sum}/ram:LineTotalAmount"));
        $this->assertSame('600.00', $this->xpathText($xpath, "{$sum}/ram:TaxBasisTotalAmount"));
        $this->assertSame('114.00', $this->xpathText($xpath, "{$sum}/ram:TaxTotalAmount"));
        $this->assertSame('714.00', $this->xpathText($xpath, "{$sum}/ram:GrandTotalAmount"));
        $this->assertSame('EUR', $this->xpathText($xpath, '//ram:ApplicableHeaderTradeSettlement/ram:OrderCurrencyCode'));
    }
}
