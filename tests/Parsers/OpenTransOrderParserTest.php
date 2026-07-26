<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Parsers\OpenTransOrderParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the openTRANS 2.1 ORDER parser (round-trip against the generator).
 */
class OpenTransOrderParserTest extends BaseTestCase {
    private OpenTransOrderParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new OpenTransOrderParser;
    }

    public function test_roundtrip_opentrans_order(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Schreibtisch', 2, 250.00, UnitCode::PIECE, 'ART-4712')
            ->build();

        $parsed = $this->parser->parse($order->toOpenTransXml());

        $this->assertSame('ORD-2026-001', $parsed->getId());
        $this->assertSame('2026-06-25', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertSame('EUR', $parsed->getCurrency()->value);
        $this->assertSame('Stadt Musterstadt', $parsed->getBuyer()->getName());
        $this->assertSame('Lieferant GmbH', $parsed->getSeller()->getName());
        $this->assertSame('DE123456789', $parsed->getSeller()->getVatId());

        $this->assertCount(2, $parsed->getLines());
        $line = $parsed->getLines()[0];
        $this->assertSame('Bürostuhl', $line->getItemName());
        $this->assertSame('ART-4711', $line->getSellersItemId());
        $this->assertSame(5.0, $line->getQuantity());
        $this->assertSame(UnitCode::PIECE, $line->getUnitCode());
        $this->assertSame('120.00', $line->getUnitPrice()->getAmount());
        $this->assertSame('600.00', $line->getNetAmount()->getAmount());

        // Anticipated total reconstructs from the parsed lines.
        $this->assertSame('1100.00', $parsed->getPayableAmount()->getAmount());
    }

    public function test_parses_address_and_seller_order_id(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-009')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Käufer AG')
            ->withBuyerAddress('Kaufstr. 3', '10115', 'Berlin')
            ->withSeller('Lieferant GmbH', 'DE999999999')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withSalesOrderId('SUP-77')
            ->addLine('Ware', 1, 10.00)
            ->build();

        $parsed = $this->parser->parse($order->toOpenTransXml());

        $this->assertSame('SUP-77', $parsed->getSalesOrderId());
        $address = $parsed->getBuyer()->getPostalAddress();
        $this->assertNotNull($address);
        $this->assertSame('Kaufstr. 3', $address->getStreetName());
        $this->assertSame('10115', $address->getPostalCode());
        $this->assertSame('Berlin', $address->getCity());
    }

    public function test_rejects_non_opentrans_document(): void {
        $ublOrder = OrderBuilder::xbestellung('ORD-X')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('A')
            ->withBuyerAddress('S 1', '12345', 'X')
            ->withSeller('B', 'DE1')
            ->withSellerAddress('S 2', '54321', 'Y')
            ->addLine('Ware', 1, 10.00)
            ->build()
            ->toUblXml();

        $this->expectException(RuntimeException::class);
        $this->parser->parse($ublOrder);
    }
}
