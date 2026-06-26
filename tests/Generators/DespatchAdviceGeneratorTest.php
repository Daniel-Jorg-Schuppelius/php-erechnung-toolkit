<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ERechnungToolkit\Builders\DespatchAdviceBuilder;
use ERechnungToolkit\Enums\UnitCode;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UBL Despatch Advice generator.
 */
class DespatchAdviceGeneratorTest extends BaseTestCase {
    private const DA_NS = 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private function buildXml(): string {
        return DespatchAdviceBuilder::create('DA-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-26'))
            ->withOrderReference('ORD-2026-001')
            ->withSupplier('Lieferant GmbH', 'DE222222222')
            ->withSupplierAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withCustomer('Besteller AG')
            ->withCustomerAddress('Bestellweg 1', '12345', 'Bestellstadt')
            ->withActualDeliveryDate(new DateTimeImmutable('2026-06-30'))
            ->addLine('Bürostuhl', 5, UnitCode::PIECE, '1', 'ART-4711')
            ->addLine('Schreibtisch', 2, UnitCode::PIECE, '2', 'ART-4712')
            ->build()
            ->toUblXml();
    }

    private function xpath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl', self::DA_NS);
        $xpath->registerNamespace('cac', self::CAC_NS);
        $xpath->registerNamespace('cbc', self::CBC_NS);
        return $xpath;
    }

    public function test_generates_well_formed_despatch_advice(): void {
        $xml = $this->buildXml();
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($xml), 'Despatch Advice XML should be well-formed');
        $this->assertSame('DespatchAdvice', $dom->documentElement->localName);
        $this->assertSame(self::DA_NS, $dom->documentElement->namespaceURI);
    }

    public function test_emits_identifiers_and_order_reference(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:trns:despatch_advice:3',
            $xpath->query('/ubl:DespatchAdvice/cbc:CustomizationID')->item(0)->textContent
        );
        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:bis:despatch_advice:3',
            $xpath->query('/ubl:DespatchAdvice/cbc:ProfileID')->item(0)->textContent
        );
        $this->assertSame('351', $xpath->query('/ubl:DespatchAdvice/cbc:DespatchAdviceTypeCode')->item(0)->textContent);
        $this->assertSame('ORD-2026-001', $xpath->query('/ubl:DespatchAdvice/cac:OrderReference/cbc:ID')->item(0)->textContent);
    }

    public function test_emits_supplier_and_customer_parties(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame(
            'Lieferant GmbH',
            $xpath->query('/ubl:DespatchAdvice/cac:DespatchSupplierParty/cac:Party/cac:PartyName/cbc:Name')->item(0)->textContent
        );
        $this->assertSame(
            'Besteller AG',
            $xpath->query('/ubl:DespatchAdvice/cac:DeliveryCustomerParty/cac:Party/cac:PartyName/cbc:Name')->item(0)->textContent
        );
    }

    public function test_emits_shipment_and_delivery_date(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame('1', $xpath->query('/ubl:DespatchAdvice/cac:Shipment/cbc:ID')->item(0)->textContent);
        $this->assertSame(
            '2026-06-30',
            $xpath->query('/ubl:DespatchAdvice/cac:Shipment/cac:Delivery/cbc:ActualDeliveryDate')->item(0)->textContent
        );
    }

    public function test_emits_despatch_lines_with_order_line_reference(): void {
        $xpath = $this->xpath($this->buildXml());

        $lines = $xpath->query('/ubl:DespatchAdvice/cac:DespatchLine');
        $this->assertSame(2, $lines->length);

        $qty = $xpath->query('/ubl:DespatchAdvice/cac:DespatchLine[1]/cbc:DeliveredQuantity')->item(0);
        $this->assertSame('5.00', $qty->textContent);
        $this->assertSame('C62', $qty->getAttribute('unitCode'));

        $this->assertSame(
            '1',
            $xpath->query('/ubl:DespatchAdvice/cac:DespatchLine[1]/cac:OrderLineReference/cbc:LineID')->item(0)->textContent
        );
        $this->assertSame(
            'Bürostuhl',
            $xpath->query('/ubl:DespatchAdvice/cac:DespatchLine[1]/cac:Item/cbc:Name')->item(0)->textContent
        );
    }
}
