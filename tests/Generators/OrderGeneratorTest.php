<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Entities\Order;
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Generators\OrderGenerator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UBL Order generator (Peppol BIS Order / XBestellung).
 */
class OrderGeneratorTest extends BaseTestCase {
    private const ORDER_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Order-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private OrderGenerator $generator;
    private Order $order;

    protected function setUp(): void {
        parent::setUp();

        $this->generator = new OrderGenerator;
        $this->order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withBuyerEndpoint('04011000-12345-67', '0204')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withSellerEndpoint('DE123456789', '9930')
            ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Schreibtisch', 2, 250.00, UnitCode::PIECE, 'ART-4712')
            ->build();
    }

    private function xpath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', self::ORDER_NS);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);
        return $xpath;
    }

    public function test_generates_well_formed_order_xml(): void {
        $xml = $this->generator->generateUbl($this->order);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Order XML should be well-formed');
        $this->assertSame('Order', $dom->documentElement->localName);
        $this->assertSame(self::ORDER_NS, $dom->documentElement->namespaceURI);
    }

    public function test_emits_customization_and_profile_id(): void {
        $xml = $this->generator->generateUbl($this->order);
        $xpath = $this->xpath($xml);

        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:trns:order:3',
            $xpath->query('/ubl:Order/cbc:CustomizationID')->item(0)->textContent
        );
        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:bis:order_only:3',
            $xpath->query('/ubl:Order/cbc:ProfileID')->item(0)->textContent
        );
        $this->assertSame('220', $xpath->query('/ubl:Order/cbc:OrderTypeCode')->item(0)->textContent);
    }

    public function test_emits_buyer_and_seller_parties(): void {
        $xml = $this->generator->generateUbl($this->order);
        $xpath = $this->xpath($xml);

        $this->assertSame(
            'Stadt Musterstadt',
            $xpath->query('/ubl:Order/cac:BuyerCustomerParty/cac:Party/cac:PartyName/cbc:Name')->item(0)->textContent
        );
        $this->assertSame(
            'Lieferant GmbH',
            $xpath->query('/ubl:Order/cac:SellerSupplierParty/cac:Party/cac:PartyName/cbc:Name')->item(0)->textContent
        );
        // Buyer endpoint (Leitweg-ID) with scheme.
        $endpoint = $xpath->query('/ubl:Order/cac:BuyerCustomerParty/cac:Party/cbc:EndpointID')->item(0);
        $this->assertSame('04011000-12345-67', $endpoint->textContent);
        $this->assertSame('0204', $endpoint->getAttribute('schemeID'));
    }

    public function test_emits_order_lines_with_quantity_and_price(): void {
        $xml = $this->generator->generateUbl($this->order);
        $xpath = $this->xpath($xml);

        $lines = $xpath->query('/ubl:Order/cac:OrderLine/cac:LineItem');
        $this->assertSame(2, $lines->length);

        $firstQty = $xpath->query('/ubl:Order/cac:OrderLine[1]/cac:LineItem/cbc:Quantity')->item(0);
        $this->assertSame('5.00', $firstQty->textContent);
        $this->assertSame('C62', $firstQty->getAttribute('unitCode'));

        $this->assertSame(
            '600.00',
            $xpath->query('/ubl:Order/cac:OrderLine[1]/cac:LineItem/cbc:LineExtensionAmount')->item(0)->textContent
        );
        $this->assertSame(
            '120.00',
            $xpath->query('/ubl:Order/cac:OrderLine[1]/cac:LineItem/cac:Price/cbc:PriceAmount')->item(0)->textContent
        );
        $this->assertSame(
            'Bürostuhl',
            $xpath->query('/ubl:Order/cac:OrderLine[1]/cac:LineItem/cac:Item/cbc:Name')->item(0)->textContent
        );
        $this->assertSame(
            'ART-4711',
            $xpath->query('/ubl:Order/cac:OrderLine[1]/cac:LineItem/cac:Item/cac:SellersItemIdentification/cbc:ID')->item(0)->textContent
        );
    }

    public function test_emits_anticipated_monetary_total(): void {
        $xml = $this->generator->generateUbl($this->order);
        $xpath = $this->xpath($xml);

        // 5*120 + 2*250 = 1100.00
        $this->assertSame(
            '1100.00',
            $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:LineExtensionAmount')->item(0)->textContent
        );
        $this->assertSame(
            '1100.00',
            $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:PayableAmount')->item(0)->textContent
        );
    }

    public function test_anticipated_total_accounts_for_allowances_and_charges(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-002')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->addLine('Ware', 10, 100.00)
            ->addDiscount(50.00, 'Mengenrabatt')
            ->addCharge(20.00, 'Versand')
            ->build();

        $xpath = $this->xpath($this->generator->generateUbl($order));

        // 1000 - 50 + 20 = 970
        $this->assertSame('1000.00', $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:LineExtensionAmount')->item(0)->textContent);
        $this->assertSame('50.00', $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:AllowanceTotalAmount')->item(0)->textContent);
        $this->assertSame('20.00', $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:ChargeTotalAmount')->item(0)->textContent);
        $this->assertSame('970.00', $xpath->query('/ubl:Order/cac:AnticipatedMonetaryTotal/cbc:PayableAmount')->item(0)->textContent);
    }

    public function test_emits_requested_delivery_period(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-003')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withRequestedDeliveryPeriod(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-15'))
            ->addLine('Ware', 1, 100.00)
            ->build();

        $xpath = $this->xpath($this->generator->generateUbl($order));

        $this->assertSame(
            '2026-07-01',
            $xpath->query('/ubl:Order/cac:Delivery/cac:RequestedDeliveryPeriod/cbc:StartDate')->item(0)->textContent
        );
        $this->assertSame(
            '2026-07-15',
            $xpath->query('/ubl:Order/cac:Delivery/cac:RequestedDeliveryPeriod/cbc:EndDate')->item(0)->textContent
        );
    }
}
