<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderGeneratorTest.php
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
use ERechnungToolkit\Generators\OpenTransOrderGenerator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the openTRANS 2.1 ORDER generator.
 */
class OpenTransOrderGeneratorTest extends BaseTestCase {
    private const OT_NS = 'http://www.opentrans.org/XMLSchema/2.1';
    private const BMECAT_NS = 'http://www.bmecat.org/bmecat/2005';

    private OpenTransOrderGenerator $generator;
    private Order $order;

    protected function setUp(): void {
        parent::setUp();

        $this->generator = new OpenTransOrderGenerator;
        $this->order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Schreibtisch', 2, 250.00, UnitCode::PIECE, 'ART-4712')
            ->build();
    }

    private function xpath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ot', self::OT_NS);
        $xpath->registerNamespace('bmecat', self::BMECAT_NS);

        return $xpath;
    }

    public function test_generates_well_formed_opentrans_order(): void {
        $xml = $this->generator->generateOrder($this->order);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'openTRANS ORDER XML should be well-formed');
        $root = $dom->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('ORDER', $root->localName);
        $this->assertSame(self::OT_NS, $root->namespaceURI);
        $this->assertSame('2.1', $root->getAttribute('version'));
        $this->assertSame('standard', $root->getAttribute('type'));
    }

    public function test_emits_order_id_currency_and_parties(): void {
        $xpath = $this->xpath($this->generator->generateOrder($this->order));
        $info = '/ot:ORDER/ot:ORDER_HEADER/ot:ORDER_INFO';

        $this->assertSame('ORD-2026-001', $this->xpathText($xpath, "{$info}/ot:ORDER_ID"));
        $this->assertSame('EUR', $this->xpathText($xpath, "{$info}/ot:CURRENCY"));
        $this->assertSame(
            'Stadt Musterstadt',
            $this->xpathText($xpath, "{$info}/ot:PARTIES/ot:PARTY[ot:PARTY_ROLE='buyer']/ot:ADDRESS/bmecat:NAME")
        );
        $this->assertSame(
            'Lieferant GmbH',
            $this->xpathText($xpath, "{$info}/ot:PARTIES/ot:PARTY[ot:PARTY_ROLE='supplier']/ot:ADDRESS/bmecat:NAME")
        );
        $this->assertSame(
            'DE123456789',
            $this->xpathText($xpath, "{$info}/ot:PARTIES/ot:PARTY[ot:PARTY_ROLE='supplier']/ot:ADDRESS/bmecat:VAT_ID")
        );
    }

    public function test_emits_order_items_with_product_and_price(): void {
        $xpath = $this->xpath($this->generator->generateOrder($this->order));

        $this->assertSame(2, $this->xpathCount($xpath, '/ot:ORDER/ot:ORDER_ITEM_LIST/ot:ORDER_ITEM'));

        $first = '/ot:ORDER/ot:ORDER_ITEM_LIST/ot:ORDER_ITEM[1]';
        $this->assertSame('1', $this->xpathText($xpath, "{$first}/ot:LINE_ITEM_ID"));
        $this->assertSame('ART-4711', $this->xpathText($xpath, "{$first}/ot:PRODUCT_ID/bmecat:SUPPLIER_PID"));
        $this->assertSame('Bürostuhl', $this->xpathText($xpath, "{$first}/ot:PRODUCT_ID/bmecat:DESCRIPTION_SHORT"));
        $this->assertSame('5', $this->xpathText($xpath, "{$first}/ot:QUANTITY"));
        $this->assertSame('C62', $this->xpathText($xpath, "{$first}/bmecat:ORDER_UNIT"));
        $this->assertSame('120.00', $this->xpathText($xpath, "{$first}/ot:PRODUCT_PRICE_FIX/bmecat:PRICE_AMOUNT"));
        $this->assertSame('600.00', $this->xpathText($xpath, "{$first}/ot:PRICE_LINE_AMOUNT"));
    }

    public function test_emits_order_summary_totals(): void {
        $xpath = $this->xpath($this->generator->generateOrder($this->order));

        $this->assertSame('2', $this->xpathText($xpath, '/ot:ORDER/ot:ORDER_SUMMARY/ot:TOTAL_ITEM_NUM'));
        // 5*120 + 2*250 = 1100.00
        $this->assertSame('1100.00', $this->xpathText($xpath, '/ot:ORDER/ot:ORDER_SUMMARY/ot:TOTAL_AMOUNT'));
    }
}
