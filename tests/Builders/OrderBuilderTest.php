<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderBuilderTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Builders;

use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\OrderProfile;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the fluent OrderBuilder.
 */
class OrderBuilderTest extends BaseTestCase {
    public function test_builds_valid_order(): void {
        $order = OrderBuilder::xbestellung('ORD-1')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->addLine('Ware', 3, 50.00)
            ->build();

        $this->assertTrue($order->isValid());
        $this->assertSame([], $order->validate());
        $this->assertSame('ORD-1', $order->getId());
        $this->assertSame(OrderProfile::XBESTELLUNG, $order->getProfile());
        $this->assertSame(1, $order->countLines());
    }

    public function test_buyer_is_ordering_party_and_seller_is_supplier(): void {
        $order = OrderBuilder::create('ORD-2')
            ->withBuyer('Besteller AG')
            ->withBuyerAddress('Bestellweg 1', '11111', 'Bestellstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->addLine('Ware', 1, 10.00)
            ->build();

        $this->assertSame('Besteller AG', $order->getBuyer()->getName());
        $this->assertSame('Lieferant GmbH', $order->getSeller()->getName());
        $this->assertSame(OrderProfile::PEPPOL_ORDER_ONLY, $order->getProfile());
    }

    public function test_computes_totals_from_lines(): void {
        $order = OrderBuilder::xbestellung('ORD-3')
            ->withBuyer('Besteller AG')
            ->withBuyerAddress('Bestellweg 1', '11111', 'Bestellstadt')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->addLine('A', 2, 100.00)
            ->addLine('B', 1, 50.00)
            ->build();

        $this->assertSame('250.00', $order->getLineExtensionAmount()->getAmount());
        $this->assertSame('250.00', $order->getPayableAmount()->getAmount());
    }

    public function test_build_without_buyer_throws(): void {
        $this->expectException(InvalidArgumentException::class);

        OrderBuilder::xbestellung('ORD-4')
            ->withSeller('Lieferant GmbH', 'DE123456789')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->addLine('Ware', 1, 10.00)
            ->build();
    }
}
