<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BisValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Peppol\{BisValidator, ParticipantId, Sbdh};
use ERechnungToolkit\Validators\{ValidationMessage, ValidationResult};
use Tests\Contracts\BaseTestCase;

/**
 * Kernregeln von Peppol BIS Billing 3.0 (Teilmenge ohne Schematron).
 */
class BisValidatorTest extends BaseTestCase {
    private BisValidator $validator;

    protected function setUp(): void {
        parent::setUp();

        $this->validator = new BisValidator;
    }

    private function invoiceXml(): string {
        return ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-08-26'))
            ->withProfile(ERechnungProfile::XRECHNUNG)
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerEndpoint('DE123456789', '9930')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withBuyerEndpoint('04011000-12345-67', '0204')
            ->withBuyerReference('04011000-12345-67')
            ->addLine('Beratungsleistung', 10, 150.00, 19.0)
            ->build()
            ->toUblXml();
    }

    /**
     * @return list<string>
     */
    private function codes(ValidationResult $result): array {
        return array_values(array_map(static fn (ValidationMessage $message): string => $message->getCode(), $result->getMessages()));
    }

    public function test_generated_invoice_passes_the_core_rules(): void {
        $result = $this->validator->validate($this->invoiceXml());

        $this->assertSame([], $this->codes($result), 'Unerwartete Regelverstöße');
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isAccepted());
        $this->assertSame(BisValidator::SCENARIO, $result->getScenarioName());
        $this->assertTrue($this->validator->isAvailable());
    }

    public function test_missing_buyer_reference_and_order_reference_is_reported(): void {
        $xml = ERechnungDocumentBuilder::create('INV-2026-002')
            ->withIssueDate(new DateTimeImmutable('2026-08-26'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withSellerEndpoint('DE123456789', '9930')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withBuyerEndpoint('04011000-12345-67', '0204')
            ->addLine('Beratungsleistung', 1, 100.00, 19.0)
            ->build()
            ->toUblXml();

        $result = $this->validator->validate($xml);

        $this->assertContains('PEPPOL-EN16931-R003', $this->codes($result));
        $this->assertFalse($result->isValid());
    }

    public function test_missing_electronic_addresses_are_reported(): void {
        $xml = ERechnungDocumentBuilder::create('INV-2026-003')
            ->withIssueDate(new DateTimeImmutable('2026-08-26'))
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->withBuyerReference('04011000-12345-67')
            ->addLine('Beratungsleistung', 1, 100.00, 19.0)
            ->build()
            ->toUblXml();

        $codes = $this->codes($this->validator->validate($xml));

        $this->assertContains('PEPPOL-EN16931-R020', $codes);
        $this->assertContains('PEPPOL-EN16931-R010', $codes);
    }

    public function test_missing_mandatory_header_fields_are_reported(): void {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
                 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2">
          <cbc:ID>INV-2026-004</cbc:ID>
        </Invoice>
        XML;

        $codes = $this->codes($this->validator->validate($xml));

        foreach (['BR-01', 'BR-03', 'BR-04', 'BR-05', 'BR-06', 'BR-07', 'BR-08', 'BR-09', 'BR-10', 'BR-11', 'BR-12', 'BR-13', 'BR-14', 'BR-15', 'BR-16'] as $code) {
            $this->assertContains($code, $codes, "Regel $code wurde nicht gemeldet");
        }
        $this->assertContains('PEPPOL-EN16931-R001', $codes);
        $this->assertNotContains('BR-02', $codes);
    }

    public function test_wrong_customization_prefix_is_reported(): void {
        $xml = str_replace(
            '<cbc:CustomizationID>' . ERechnungProfile::XRECHNUNG->value . '</cbc:CustomizationID>',
            '<cbc:CustomizationID>urn:example:eigenes-profil</cbc:CustomizationID>',
            $this->invoiceXml()
        );

        $this->assertContains('PEPPOL-EN16931-R004', $this->codes($this->validator->validate($xml)));
    }

    public function test_endpoint_without_scheme_identifier_is_reported(): void {
        $xml = str_replace(' schemeID="9930"', '', $this->invoiceXml());

        $this->assertContains('BR-62', $this->codes($this->validator->validate($xml)));
    }

    public function test_inconsistent_totals_are_reported(): void {
        // Der Generator schreibt die Namespace-Deklaration an jedes Element,
        // daher wird gezielt der Betrag im TaxInclusiveAmount ersetzt.
        $xml = preg_replace(
            '#(<cbc:TaxInclusiveAmount[^>]*>)1785\.00(</cbc:TaxInclusiveAmount>)#',
            '${1}1700.00${2}',
            $this->invoiceXml()
        );
        $this->assertIsString($xml);

        $this->assertContains('BR-CO-15', $this->codes($this->validator->validate($xml)));
    }

    public function test_non_ubl_input_is_rejected_with_a_single_message(): void {
        $result = $this->validator->validate('<html><body>keine Rechnung</body></html>');

        $this->assertFalse($result->isValid());
        $this->assertSame(['TK-PEPPOL-ROOT'], $this->codes($result));

        $broken = $this->validator->validate('<Invoice');
        $this->assertSame(['TK-PEPPOL-XML'], $this->codes($broken));
    }

    public function test_envelope_validation_checks_country_and_document_type(): void {
        $invoice = $this->invoiceXml();
        $sbdh = Sbdh::forUbl(
            $invoice,
            ParticipantId::germanVatId('DE123456789'),
            ParticipantId::leitwegId('04011000-12345-67'),
            'DE'
        );

        $this->assertTrue($this->validator->validateEnvelope($sbdh->envelope($invoice))->isValid());

        $withoutCountry = Sbdh::forUbl(
            $invoice,
            ParticipantId::germanVatId('DE123456789'),
            ParticipantId::leitwegId('04011000-12345-67')
        );

        $codes = $this->codes($this->validator->validateEnvelope($withoutCountry->envelope($invoice)));
        $this->assertContains('TK-PEPPOL-SBDH-C1', $codes);
    }

    public function test_envelope_with_mismatching_document_type_is_reported(): void {
        $invoice = $this->invoiceXml();
        $envelope = str_replace(
            ERechnungProfile::XRECHNUNG->value . '::2.1',
            'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1',
            Sbdh::forUbl(
                $invoice,
                ParticipantId::germanVatId('DE123456789'),
                ParticipantId::leitwegId('04011000-12345-67'),
                'DE'
            )->envelope($invoice)
        );

        $this->assertContains('TK-PEPPOL-SBDH-DOCID', $this->codes($this->validator->validateEnvelope($envelope)));
    }
}
