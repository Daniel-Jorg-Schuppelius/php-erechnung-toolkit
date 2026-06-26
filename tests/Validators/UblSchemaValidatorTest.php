<?php
/*
 * Created on   : Fri Jun 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UblSchemaValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use DateTimeImmutable;
use ERechnungToolkit\Builders\{DespatchAdviceBuilder, ERechnungDocumentBuilder, OrderBuilder};
use ERechnungToolkit\Enums\{TaxCategory, UnitCode};
use ERechnungToolkit\Validators\UblSchemaValidator;
use Tests\Contracts\BaseTestCase;

/**
 * XSD-Schema-Validierung der erzeugten UBL-Dokumente gegen die gebündelten
 * offiziellen OASIS-UBL-2.1-Schemas (reine libxml-Prüfung, kein Java).
 */
class UblSchemaValidatorTest extends BaseTestCase {
    private UblSchemaValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new UblSchemaValidator;
    }

    public function test_bundled_schemas_are_available(): void {
        $this->assertTrue($this->validator->isAvailable());
        $this->assertTrue($this->validator->supports('Order'));
        $this->assertTrue($this->validator->supports('DespatchAdvice'));
        $this->assertTrue($this->validator->supports('Invoice'));
        $this->assertFalse($this->validator->supports('Foo'));
    }

    public function test_generated_xbestellung_order_is_schema_valid(): void {
        $order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-26'))
            ->withBuyer('Stadt Musterstadt', 'DE111111111')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withBuyerEndpoint('04011000-12345-67', '0204')
            ->withSeller('Lieferant GmbH', 'DE222222222')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withSellerEndpoint('DE222222222', '9930')
            ->withRequestedDeliveryPeriod(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-15'))
            ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-1', 19.0, TaxCategory::STANDARD)
            ->addDiscount(50.00, 'Mengenrabatt')
            ->build();

        $this->assertSame([], $this->validator->validate($order->toUblXml()));
    }

    public function test_generated_despatch_advice_is_schema_valid(): void {
        $advice = DespatchAdviceBuilder::create('DA-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-26'))
            ->withOrderReference('ORD-2026-001', 'SO-77')
            ->withSupplier('Lieferant GmbH', 'DE222222222')
            ->withSupplierAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withCustomer('Stadt Musterstadt')
            ->withCustomerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withActualDeliveryDate(new DateTimeImmutable('2026-07-01'))
            ->withDeliveryAddress('Wareneingang 9', '12345', 'Musterstadt')
            ->addLine('Bürostuhl', 5, UnitCode::PIECE, '1', 'ART-1')
            ->build();

        $this->assertSame([], $this->validator->validate($advice->toUblXml()));
    }

    public function test_generated_xrechnung_invoice_is_schema_valid(): void {
        $invoice = ERechnungDocumentBuilder::xrechnung('INV-2026-001', '04011000-12345-67')
            ->withIssueDate(new DateTimeImmutable('2026-06-26'))
            ->withSeller('Lieferant GmbH', 'DE222222222')
            ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
            ->withSellerEndpoint('a@b.de', 'EM')
            ->withBuyer('Stadt Musterstadt')
            ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
            ->withBuyerLeitwegId('04011000-12345-67')
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $this->assertSame([], $this->validator->validate($invoice->toUblXml()));
    }

    public function test_invalid_order_xml_reports_errors(): void {
        // Order mit einem unzulässigen Kindelement an Stelle der Pflichtstruktur.
        $invalid = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Order xmlns="urn:oasis:names:specification:ubl:schema:xsd:Order-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cbc:NotAValidElement>x</cbc:NotAValidElement></Order>';

        $errors = $this->validator->validate($invalid);
        $this->assertNotEmpty($errors);
    }

    public function test_unsupported_root_is_reported(): void {
        $errors = $this->validator->validate('<?xml version="1.0"?><Foo xmlns="urn:example"/>');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Foo', $errors[0]);
    }
}
