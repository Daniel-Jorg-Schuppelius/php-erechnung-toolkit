<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Entities\OrderLine;
use ERechnungToolkit\Enums\{AllowanceChargeReasonCode, UnitCode};
use ERechnungToolkit\Parsers\UglParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UGL 5.0 order parser (round-trip against the generator).
 */
class UglParserTest extends BaseTestCase {
    private UglParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new UglParser;
    }

    public function test_roundtrip_ugl_order(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Müller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->withRequestedDeliveryPeriod(new DateTimeImmutable('2026-07-01'))
            ->addLine('Heizungspumpe', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Kugelhahn', 2, 12.50, UnitCode::PIECE, 'ART-4712')
            ->build();

        $parsed = $this->parser->parse($order->toUgl());

        $this->assertSame('ORD-2026-001', $parsed->getId());
        $this->assertSame('2026-06-25', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertSame('2026-07-01', $parsed->getRequestedDeliveryStartDate()?->format('Y-m-d'));
        $this->assertSame('EUR', $parsed->getCurrency()->value);

        $this->assertCount(2, $parsed->getLines());
        $line = $parsed->getLines()[0];
        $this->assertSame('Heizungspumpe', $line->getItemName());
        $this->assertSame('ART-4711', $line->getSellersItemId());
        $this->assertSame(5.0, $line->getQuantity());
        // UGL kennt nur „ST" → C62/H87 normalisieren auf H87 (Stück).
        $this->assertSame(UnitCode::UNIT_H87, $line->getUnitCode());
        $this->assertSame('120.00', $line->getUnitPrice()->getAmount());
        $this->assertSame('600.00', $line->getNetAmount()->getAmount());

        $second = $parsed->getLines()[1];
        $this->assertSame('Kugelhahn', $second->getItemName());
        $this->assertSame('25.00', $second->getNetAmount()->getAmount()); // 2 * 12.50
    }

    public function test_roundtrip_preserves_umlauts(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-009')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Müller & Söhne GmbH')
            ->withBuyerAddress('Straße 1', '12345', 'Köln')
            ->withSeller('Großhandel', 'DE1')
            ->withSellerAddress('Weg 2', '54321', 'Ort')
            ->addLine('Übergangsstück', 1, 9.90)
            ->build();

        $parsed = $this->parser->parse($order->toUgl());

        $this->assertSame('Müller & Söhne GmbH', $parsed->getBuyer()->getName());
        $this->assertSame('Übergangsstück', $parsed->getLines()[0]->getItemName());
    }

    public function test_roundtrip_delivery_address(): void {
        $order = OrderBuilder::xbestellung('ORD-ADR-2')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Müller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->withDeliveryAddress('Baustelle 7', '20095', 'Hamburg', 'DE', 'Bauvorhaben Hafencity', null, 'Anlieferung Tor 3')
            ->addLine('Heizungspumpe', 1, 120.00, UnitCode::PIECE, 'ART-4711')
            ->build();

        $parsed = $this->parser->parse($order->toUgl());

        $this->assertSame('Bauvorhaben Hafencity', $parsed->getDeliveryName());
        $this->assertSame('Anlieferung Tor 3', $parsed->getDeliveryNote());
        $address = $parsed->getDeliveryAddress();
        $this->assertNotNull($address);
        $this->assertSame('Baustelle 7', $address->getStreetName());
        $this->assertSame('20095', $address->getPostalCode());
        $this->assertSame('Hamburg', $address->getCity());
    }

    public function test_roundtrip_position_text_and_surcharges(): void {
        $order = OrderBuilder::xbestellung('ORD-PZT')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Mueller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->addOrderLine(new OrderLine(
                id: '1', quantity: 2, unitCode: UnitCode::PIECE, netAmount: Money::of('240.00', CurrencyCode::Euro),
                itemName: 'Pumpe', unitPrice: Money::of('120.00', CurrencyCode::Euro), sellersItemId: 'ART-1',
                note: 'Bitte vormontiert liefern'
            ))
            ->addCharge(5.00, 'Mindermengenzuschlag')
            ->build();
        $order->addAllowanceCharge(\ERechnungToolkit\Entities\AllowanceCharge::shipping(Money::of('20.00', CurrencyCode::Euro)));

        $parsed = $this->parser->parse($order->toUgl());

        // Positionstext (POT) landet als Note an der Position.
        $this->assertSame('Bitte vormontiert liefern', $parsed->getLines()[0]->getNote());

        // Zuschläge (POZ) als AllowanceCharges, Fracht mit erkanntem ReasonCode.
        $charges = $parsed->getAllowanceCharges();
        $this->assertCount(2, $charges);
        $byAmount = [];
        foreach ($charges as $c) {
            $byAmount[$c->getAmount()->getAmount()] = $c;
        }
        $this->assertSame('Mindermengenzuschlag', $byAmount['5.00']->getReason());
        $this->assertSame(AllowanceChargeReasonCode::FREIGHT, $byAmount['20.00']->getReasonCode());
        $this->assertTrue($byAmount['20.00']->isCharge());
    }

    public function test_rejects_non_ugl_content(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse("<?xml version=\"1.0\"?>\n<Order/>\n");
    }
}
