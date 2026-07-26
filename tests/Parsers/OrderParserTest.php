<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\{OrderProfile, UnitCode};
use ERechnungToolkit\Parsers\OrderParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UBL Order parser.
 */
class OrderParserTest extends BaseTestCase {
    private OrderParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new OrderParser;
    }

    public function test_roundtrip_order(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withBuyerEndpoint('04011000-12345-67', '0204')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withContractReference('V-2026-77')
            ->withRequestedDeliveryPeriod(new DateTimeImmutable('2026-07-01'))
            ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Schreibtisch', 2, 250.00, UnitCode::PIECE, 'ART-4712')
            ->build();

        $xml = $order->toUblXml();
        $parsed = $this->parser->parse($xml);

        $this->assertSame('ORD-2026-001', $parsed->getId());
        $this->assertSame('2026-06-25', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertSame(OrderProfile::PEPPOL_ORDER_ONLY, $parsed->getProfile());
        $this->assertSame('Stadt Musterstadt', $parsed->getBuyer()->getName());
        $this->assertSame('Lieferant GmbH', $parsed->getSeller()->getName());
        $this->assertSame('04011000-12345-67', $parsed->getBuyer()->getEndpointId());
        $this->assertSame('0204', $parsed->getBuyer()->getEndpointScheme());
        $this->assertSame('V-2026-77', $parsed->getContractReference());
        $this->assertSame('2026-07-01', $parsed->getRequestedDeliveryStartDate()?->format('Y-m-d'));

        $this->assertCount(2, $parsed->getLines());
        $firstLine = $parsed->getLines()[0];
        $this->assertSame('Bürostuhl', $firstLine->getItemName());
        $this->assertSame(5.0, $firstLine->getQuantity());
        $this->assertSame('120.00', $firstLine->getUnitPrice()->getAmount());
        $this->assertSame('600.00', $firstLine->getNetAmount()->getAmount());
        $this->assertSame('ART-4711', $firstLine->getSellersItemId());
        $this->assertSame(UnitCode::PIECE, $firstLine->getUnitCode());

        $this->assertSame('1100.00', $parsed->getLineExtensionAmount()->getAmount());
    }

    public function test_parse_rejects_non_order_document(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('<?xml version="1.0"?><Foo xmlns="urn:example"/>');
    }

    public function test_parse_rejects_invalid_xml(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('not xml at all');
    }
}
