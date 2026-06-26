<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use DateTimeImmutable;
use ERechnungToolkit\Builders\DespatchAdviceBuilder;
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Parsers\DespatchAdviceParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UBL Despatch Advice parser.
 */
class DespatchAdviceParserTest extends BaseTestCase {
    private DespatchAdviceParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new DespatchAdviceParser;
    }

    public function test_roundtrip_despatch_advice(): void {
        $advice = DespatchAdviceBuilder::create('DA-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-26'))
            ->withOrderReference('ORD-2026-001', 'SO-77')
            ->withSupplier('Lieferant GmbH', 'DE222222222')
            ->withSupplierAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withCustomer('Besteller AG')
            ->withCustomerAddress('Bestellweg 1', '12345', 'Bestellstadt')
            ->withActualDeliveryDate(new DateTimeImmutable('2026-06-30'))
            ->withDeliveryAddress('Wareneingang 9', '12345', 'Bestellstadt')
            ->addLine('Bürostuhl', 5, UnitCode::PIECE, '1', 'ART-4711')
            ->addLine('Schreibtisch', 2, UnitCode::PIECE, '2', 'ART-4712')
            ->build();

        $parsed = $this->parser->parse($advice->toUblXml());

        $this->assertSame('DA-2026-001', $parsed->getId());
        $this->assertSame('2026-06-26', $parsed->getIssueDate()->format('Y-m-d'));
        $this->assertSame('ORD-2026-001', $parsed->getOrderReference());
        $this->assertSame('SO-77', $parsed->getSalesOrderId());
        $this->assertSame('Lieferant GmbH', $parsed->getDespatchSupplierParty()->getName());
        $this->assertSame('Besteller AG', $parsed->getDeliveryCustomerParty()->getName());
        $this->assertSame('2026-06-30', $parsed->getActualDeliveryDate()->format('Y-m-d'));
        $this->assertSame('Wareneingang 9', $parsed->getDeliveryAddress()->getStreetName());

        $this->assertCount(2, $parsed->getLines());
        $first = $parsed->getLines()[0];
        $this->assertSame('Bürostuhl', $first->getItemName());
        $this->assertSame(5.0, $first->getDeliveredQuantity());
        $this->assertSame('1', $first->getOrderLineId());
        $this->assertSame('ART-4711', $first->getSellersItemId());
        $this->assertSame(UnitCode::PIECE, $first->getUnitCode());
    }

    public function test_rejects_non_despatch_advice(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse('<?xml version="1.0"?><Foo xmlns="urn:example"/>');
    }
}
