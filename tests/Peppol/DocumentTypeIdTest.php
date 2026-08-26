<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DocumentTypeIdTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Peppol\DocumentTypeId;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Zerlegung und Aufbau von Peppol-Dokumenttypkennungen.
 */
class DocumentTypeIdTest extends BaseTestCase {
    private const BIS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice'
        . '##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

    public function test_splits_all_parts(): void {
        $documentTypeId = new DocumentTypeId(self::BIS_INVOICE);

        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $documentTypeId->getRootNamespace());
        $this->assertSame('Invoice', $documentTypeId->getLocalName());
        $this->assertSame(DocumentTypeId::BIS_BILLING_3, $documentTypeId->getCustomizationId());
        $this->assertSame('2.1', $documentTypeId->getVersion());
        $this->assertSame('busdox-docid-qns', $documentTypeId->getScheme());
        $this->assertFalse($documentTypeId->isWildcard());
        $this->assertSame('busdox-docid-qns::' . self::BIS_INVOICE, $documentTypeId->canonical());
    }

    public function test_parses_canonical_form_with_and_without_scheme(): void {
        $withScheme = DocumentTypeId::parse('busdox-docid-qns::' . self::BIS_INVOICE);
        $withoutScheme = DocumentTypeId::parse(self::BIS_INVOICE);

        $this->assertTrue($withScheme->equals($withoutScheme));
        $this->assertTrue(DocumentTypeId::parse('peppol-doctype-wildcard::' . self::BIS_INVOICE)->isWildcard());
    }

    public function test_factories_match_the_official_identifiers(): void {
        $this->assertSame('busdox-docid-qns::' . self::BIS_INVOICE, DocumentTypeId::peppolBisBillingInvoice()->canonical());
        $this->assertSame('CreditNote', DocumentTypeId::peppolBisBillingCreditNote()->getLocalName());
        $this->assertSame(ERechnungProfile::XRECHNUNG->value, DocumentTypeId::xrechnungInvoice()->getCustomizationId());
    }

    public function test_derives_the_identifier_from_a_generated_invoice(): void {
        $invoice = ERechnungDocumentBuilder::create('INV-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-08-26'))
            ->withProfile(ERechnungProfile::XRECHNUNG)
            ->withSeller('Muster GmbH', 'DE123456789')
            ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
            ->withBuyer('Kunde AG', 'DE987654321')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addLine('Beratungsleistung', 1, 100.00, 19.0)
            ->build();

        $documentTypeId = DocumentTypeId::fromUbl($invoice->toUblXml());

        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $documentTypeId->getRootNamespace());
        $this->assertSame('Invoice', $documentTypeId->getLocalName());
        $this->assertSame(ERechnungProfile::XRECHNUNG->value, $documentTypeId->getCustomizationId());
        $this->assertSame('2.1', $documentTypeId->getVersion());
    }

    public function test_url_encoding_escapes_the_separators(): void {
        $encoded = DocumentTypeId::peppolBisBillingInvoice()->urlEncoded();

        $this->assertStringContainsString('%3A%3A', $encoded);
        $this->assertStringContainsString('%23%23', $encoded);
        $this->assertStringNotContainsString(' ', $encoded);
    }

    public function test_rejects_incomplete_identifiers(): void {
        $this->expectException(InvalidArgumentException::class);
        new DocumentTypeId('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice');
    }

    public function test_rejects_ubl_without_customization_id(): void {
        $this->expectException(InvalidArgumentException::class);
        DocumentTypeId::fromUbl('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>');
    }
}
