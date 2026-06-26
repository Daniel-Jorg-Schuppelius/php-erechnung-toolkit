<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXPdfGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Generators\OrderXPdfGenerator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the Order-X hybrid PDF generator.
 */
class OrderXPdfGeneratorTest extends BaseTestCase {
    private OrderXPdfGenerator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new OrderXPdfGenerator;
    }

    public function test_is_available_returns_bool(): void {
        $this->assertIsBool($this->generator->isAvailable());
    }

    public function test_generates_hybrid_pdf(): void {
        if (!$this->generator->isAvailable()) {
            $this->markTestSkipped('PDF Toolkit is not installed');
        }

        $order = OrderBuilder::create('ORD-X-PDF-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Besteller AG', 'DE111111111')
            ->withBuyerAddress('Bestellweg 1', '11111', 'Bestellstadt')
            ->withSeller('Lieferant GmbH', 'DE222222222')
            ->withSellerAddress('Lieferweg 2', '22222', 'Lieferstadt')
            ->addLine('Ware A', 5, 120.00, UnitCode::PIECE, 'ART-1')
            ->build();

        $pdf = $this->generator->generate($order);

        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
