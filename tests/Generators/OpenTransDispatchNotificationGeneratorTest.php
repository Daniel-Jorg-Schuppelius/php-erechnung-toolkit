<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransDispatchNotificationGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine, Party};
use ERechnungToolkit\Enums\{DespatchAdviceProfile, UnitCode};
use ERechnungToolkit\Generators\OpenTransDispatchNotificationGenerator;
use ERechnungToolkit\Parsers\OpenTransDispatchNotificationParser;
use Tests\Contracts\BaseTestCase;

/** openTRANS 2.1 DISPATCHNOTIFICATION auf der gemeinsamen DespatchAdvice-Entität. */
class OpenTransDispatchNotificationGeneratorTest extends BaseTestCase {
    public function test_round_trip_maps_onto_the_despatch_advice_entity(): void {
        $advice = new DespatchAdvice(
            id: 'LS-5501',
            issueDate: new DateTimeImmutable('2026-10-04T08:00:00'),
            despatchSupplierParty: new Party('Großhandel AG', vatId: 'DE987654321'),
            deliveryCustomerParty: new Party('Muster Bau GmbH', vatId: 'DE123456789'),
            profile: DespatchAdviceProfile::OPENTRANS_DISPATCHNOTIFICATION,
            orderReference: 'BE-2026-0042',
            shipmentId: 'SEND-9',
            actualDeliveryDate: new DateTimeImmutable('2026-10-05')
        );
        $advice->addLine(new DespatchLine('1', 10, UnitCode::PIECE, 'Kabel NYM-J', orderLineId: '1', sellersItemId: 'KAB-315'));
        $advice->addLine(new DespatchLine('2', 2, 'MTR', 'Rohr', orderLineId: '2', sellersItemId: 'RO-15'));

        $xml = (new OpenTransDispatchNotificationGenerator)->generate($advice);
        $this->assertStringContainsString('<DISPATCHNOTIFICATION', $xml);
        $this->assertStringContainsString('SHIPMENT_PARTIES_REFERENCE', $xml);

        $parsed = (new OpenTransDispatchNotificationParser)->parse($xml);
        $this->assertSame('LS-5501', $parsed->getId());
        $this->assertSame('BE-2026-0042', $parsed->getOrderReference());
        $this->assertSame('SEND-9', $parsed->getShipmentId());
        $this->assertSame('2026-10-05', $parsed->getActualDeliveryDate()?->format('Y-m-d'));
        $this->assertSame(DespatchAdviceProfile::OPENTRANS_DISPATCHNOTIFICATION, $parsed->getProfile());
        $this->assertSame('Großhandel AG', $parsed->getDespatchSupplierParty()->getName());
        $this->assertSame('Muster Bau GmbH', $parsed->getDeliveryCustomerParty()->getName());
        $this->assertCount(2, $parsed->getLines());
        $this->assertSame('2', $parsed->getLines()[1]->getOrderLineId());
        $this->assertSame(2.0, $parsed->getLines()[1]->getDeliveredQuantity());
        $this->assertSame('RO-15', $parsed->getLines()[1]->getSellersItemId());
    }
}
