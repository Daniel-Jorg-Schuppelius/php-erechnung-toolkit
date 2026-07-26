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
use DOMElement;
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
        $root = $dom->documentElement;
        $this->assertNotNull($root);
        $this->assertSame('DespatchAdvice', $root->localName);
        $this->assertSame(self::DA_NS, $root->namespaceURI);
    }

    public function test_emits_identifiers_and_order_reference(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:trns:despatch_advice:3',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cbc:CustomizationID')
        );
        $this->assertSame(
            'urn:fdc:peppol.eu:poacc:bis:despatch_advice:3',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cbc:ProfileID')
        );
        $this->assertSame('351', $this->xpathText($xpath, '/ubl:DespatchAdvice/cbc:DespatchAdviceTypeCode'));
        $this->assertSame('ORD-2026-001', $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:OrderReference/cbc:ID'));
    }

    public function test_emits_supplier_and_customer_parties(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame(
            'Lieferant GmbH',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:DespatchSupplierParty/cac:Party/cac:PartyName/cbc:Name')
        );
        $this->assertSame(
            'Besteller AG',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:DeliveryCustomerParty/cac:Party/cac:PartyName/cbc:Name')
        );
    }

    public function test_emits_shipment_and_delivery_date(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame('1', $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:Shipment/cbc:ID'));
        $this->assertSame(
            '2026-06-30',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:Shipment/cac:Delivery/cbc:ActualDeliveryDate')
        );
    }

    public function test_emits_despatch_lines_with_order_line_reference(): void {
        $xpath = $this->xpath($this->buildXml());

        $this->assertSame(2, $this->xpathCount($xpath, '/ubl:DespatchAdvice/cac:DespatchLine'));

        $qty = $this->xpathNode($xpath, '/ubl:DespatchAdvice/cac:DespatchLine[1]/cbc:DeliveredQuantity');
        $this->assertInstanceOf(DOMElement::class, $qty);
        $this->assertSame('5.00', $qty->textContent);
        $this->assertSame('C62', $qty->getAttribute('unitCode'));

        $this->assertSame(
            '1',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:DespatchLine[1]/cac:OrderLineReference/cbc:LineID')
        );
        $this->assertSame(
            'Bürostuhl',
            $this->xpathText($xpath, '/ubl:DespatchAdvice/cac:DespatchLine[1]/cac:Item/cbc:Name')
        );
    }
}
