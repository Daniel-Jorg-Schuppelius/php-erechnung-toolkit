<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderResponseGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{OrderResponse, OrderResponseLine, Party, PostalAddress};
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Generators\OpenTransOrderResponseGenerator;
use ERechnungToolkit\Parsers\OpenTransOrderResponseParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/** openTRANS 2.1 ORDERRESPONSE: Generator und Parser im Rundlauf. */
class OpenTransOrderResponseGeneratorTest extends BaseTestCase {
    private function response(): OrderResponse {
        $response = new OrderResponse(
            orderId: 'BE-2026-0042',
            responseDate: new DateTimeImmutable('2026-09-28T10:00:00'),
            buyer: new Party('Muster Bau GmbH', new PostalAddress(streetName: 'Hauptstraße 1', postalCode: '50667', city: 'Köln', country: 'DE'), vatId: 'DE123456789'),
            seller: new Party('Großhandel AG', vatId: 'DE987654321', contactName: 'Frau Berg', contactEmail: 'vertrieb@example.org'),
            currency: CurrencyCode::Euro,
            supplierOrderId: 'AB-7788',
            deliveryDate: new DateTimeImmutable('2026-10-05'),
            note: 'Teillieferung möglich'
        );
        $response->addLine(new OrderResponseLine('1', 10, UnitCode::PIECE, 'Kabel NYM-J', Money::of('2.50', CurrencyCode::Euro), sellersItemId: 'KAB-315'));
        $response->addLine(new OrderResponseLine('2', 4.5, 'MTR', 'Rohr', null, sellersItemId: 'RO-15', deliveryDate: new DateTimeImmutable('2026-10-12'), note: 'Nachlieferung'));

        return $response;
    }

    public function test_round_trip_keeps_order_reference_lines_and_delivery_dates(): void {
        $xml = (new OpenTransOrderResponseGenerator)->generate($this->response());
        $this->assertStringContainsString('<ORDERRESPONSE', $xml);
        $this->assertStringContainsString('ORDER_PARTIES_REFERENCE', $xml);

        $parsed = (new OpenTransOrderResponseParser)->parse($xml);
        $this->assertSame('BE-2026-0042', $parsed->getOrderId());
        $this->assertSame('AB-7788', $parsed->getSupplierOrderId());
        $this->assertSame('Großhandel AG', $parsed->getSeller()->getName());
        $this->assertSame('Köln', $parsed->getBuyer()->getPostalAddress()?->getCity());
        $this->assertSame('Teillieferung möglich', $parsed->getNote());
        $this->assertCount(2, $parsed->getLines());

        [$first, $second] = $parsed->getLines();
        $this->assertSame('1', $first->getLineId());
        $this->assertSame(10.0, $first->getQuantity());
        $this->assertSame('2.50', $first->getUnitPrice()?->getAmount());
        $this->assertSame('KAB-315', $first->getSellersItemId());
        $this->assertSame('2026-10-05', $first->getDeliveryDate()?->format('Y-m-d'));
        $this->assertSame(4.5, $second->getQuantity());
        $this->assertSame(UnitCode::METRE, $second->getUnitCode());
        $this->assertNull($second->getUnitPrice());
        $this->assertSame('2026-10-12', $second->getDeliveryDate()?->format('Y-m-d'));
        $this->assertSame('Nachlieferung', $second->getNote());
    }

    public function test_rejects_other_documents(): void {
        $this->expectException(RuntimeException::class);
        (new OpenTransOrderResponseParser)->parse('<ORDER xmlns="http://www.opentrans.org/XMLSchema/2.1"/>');
    }
}
