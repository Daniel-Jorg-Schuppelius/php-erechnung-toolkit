<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\{TaxCategory, UnitCode};
use ERechnungToolkit\Parsers\OrderXParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the Order-X (CII) parser.
 */
class OrderXParserTest extends BaseTestCase {
    private OrderXParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new OrderXParser;
    }

    public function test_parses_official_mustang_sample(): void {
        $order = $this->parser->parseFile(__DIR__ . '/../resources/orderx_comfort_sample.xml');

        $this->assertSame('PO123456789', $order->getId());
        $this->assertSame('EUR', $order->getCurrency()->value);
        $this->assertSame('SELLER_NAME', $order->getSeller()->getName());
        $this->assertSame('BUYER_NAME', $order->getBuyer()->getName());
        $this->assertSame('BUYER_REF_BU123', $order->getBuyerReference());
        $this->assertCount(3, $order->getLines());

        $firstLine = $order->getLines()[0];
        $this->assertSame('Product Name', $firstLine->getItemName());
        $this->assertSame(6.0, $firstLine->getQuantity());
        $this->assertSame('10.00', $firstLine->getUnitPrice()->getAmount());
        $this->assertSame('60.00', $firstLine->getNetAmount()->getAmount());
    }

    public function test_roundtrip_orderx(): void {
        $order = OrderBuilder::create('ORD-X-RT')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Besteller AG', 'DE111111111')
            ->withBuyerAddress('Bestellweg 1', '11111', 'Bestellstadt')
            ->withSeller('Lieferant GmbH', 'DE222222222')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->withContractReference('V-2026-77')
            ->addLine('Ware A', 5, 120.00, UnitCode::PIECE, 'ART-1', 19.0, TaxCategory::STANDARD)
            ->addLine('Ware B', 2, 250.00, UnitCode::PIECE, 'ART-2', 19.0, TaxCategory::STANDARD)
            ->build();

        $parsed = $this->parser->parse($order->toOrderXXml());

        $this->assertSame('ORD-X-RT', $parsed->getId());
        $this->assertSame('2026-06-25', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertSame('Lieferant GmbH', $parsed->getSeller()->getName());
        $this->assertSame('Besteller AG', $parsed->getBuyer()->getName());
        $this->assertSame('DE222222222', $parsed->getSeller()->getVatId());
        $this->assertSame('V-2026-77', $parsed->getContractReference());

        $this->assertCount(2, $parsed->getLines());
        $first = $parsed->getLines()[0];
        $this->assertSame('Ware A', $first->getItemName());
        $this->assertSame(5.0, $first->getQuantity());
        $this->assertSame('120.00', $first->getUnitPrice()->getAmount());
        $this->assertSame('600.00', $first->getNetAmount()->getAmount());
        $this->assertSame('ART-1', $first->getSellersItemId());
        $this->assertSame(TaxCategory::STANDARD, $first->getTaxCategory());
        $this->assertSame(19.0, $first->getTaxPercent());

        $this->assertSame('1100.00', $parsed->getLineExtensionAmount()->getAmount());
    }

    public function test_rejects_non_orderx_document(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('<?xml version="1.0"?><Foo xmlns="urn:example"/>');
    }
}
