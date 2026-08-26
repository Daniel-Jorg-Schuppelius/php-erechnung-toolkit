<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SbdhTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Peppol\{ParticipantId, Sbdh};
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Standard Business Document Header: Umschlag bauen, lesen und zurückgewinnen.
 */
class SbdhTest extends BaseTestCase {
    private string $invoiceXml;

    protected function setUp(): void {
        parent::setUp();

        $this->invoiceXml = ERechnungDocumentBuilder::create('INV-2026-001')
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

    private function sbdh(): Sbdh {
        return Sbdh::forUbl(
            $this->invoiceXml,
            ParticipantId::germanVatId('DE123456789'),
            ParticipantId::leitwegId('04011000-12345-67'),
            'DE',
            null,
            'instance-4711',
            new DateTimeImmutable('2026-08-26T10:00:00+00:00')
        );
    }

    public function test_derives_header_data_from_the_ubl_document(): void {
        $sbdh = $this->sbdh();

        $this->assertSame('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $sbdh->getStandard());
        $this->assertSame('Invoice', $sbdh->getType());
        $this->assertSame('2.1', $sbdh->getTypeVersion());
        // Prozesskennung stammt aus cbc:ProfileID der Rechnung.
        $this->assertSame(Sbdh::PROCESS_BILLING, $sbdh->getProcessId());
        $this->assertSame(ERechnungProfile::XRECHNUNG->value, $sbdh->getDocumentTypeId()->getCustomizationId());
        $this->assertSame('instance-4711', $sbdh->getInstanceIdentifier());
        $this->assertSame('DE', $sbdh->getSenderCountry());
    }

    public function test_envelope_contains_the_peppol_scopes(): void {
        $envelope = $this->sbdh()->envelope($this->invoiceXml);

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($envelope));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('sbd', Sbdh::NS);

        $this->assertSame('1.0', $this->xpathText($xpath, '//sbd:StandardBusinessDocumentHeader/sbd:HeaderVersion'));
        $this->assertSame('9930:DE123456789', $this->xpathText($xpath, '//sbd:Sender/sbd:Identifier'));
        $this->assertSame('iso6523-actorid-upis', $this->xpathText($xpath, '//sbd:Sender/sbd:Identifier/@Authority'));
        $this->assertSame('0204:04011000-12345-67', $this->xpathText($xpath, '//sbd:Receiver/sbd:Identifier'));
        $this->assertSame('2026-08-26T10:00:00+00:00', $this->xpathText($xpath, '//sbd:DocumentIdentification/sbd:CreationDateAndTime'));

        $this->assertSame(3, $this->xpathCount($xpath, '//sbd:BusinessScope/sbd:Scope'));
        $this->assertSame(
            'busdox-docid-qns',
            $this->xpathText($xpath, '//sbd:Scope[sbd:Type="DOCUMENTID"]/sbd:Identifier')
        );
        $this->assertSame(
            'cenbii-procid-ubl',
            $this->xpathText($xpath, '//sbd:Scope[sbd:Type="PROCESSID"]/sbd:Identifier')
        );
        $this->assertSame('DE', $this->xpathText($xpath, '//sbd:Scope[sbd:Type="COUNTRY_C1"]/sbd:InstanceIdentifier'));
        // Die Nutzlast bleibt als eigenes Wurzelelement erhalten.
        $this->assertSame(1, $this->xpathCount($xpath, '/sbd:StandardBusinessDocument/*[local-name()="Invoice"]'));
    }

    public function test_build_parse_roundtrip(): void {
        $original = $this->sbdh();
        $parsed = Sbdh::parse($original->envelope($this->invoiceXml));

        $this->assertTrue($parsed->getSender()->equals($original->getSender()));
        $this->assertTrue($parsed->getReceiver()->equals($original->getReceiver()));
        $this->assertTrue($parsed->getDocumentTypeId()->equals($original->getDocumentTypeId()));
        $this->assertSame($original->getProcessId(), $parsed->getProcessId());
        $this->assertSame($original->getProcessScheme(), $parsed->getProcessScheme());
        $this->assertSame($original->getStandard(), $parsed->getStandard());
        $this->assertSame($original->getType(), $parsed->getType());
        $this->assertSame($original->getTypeVersion(), $parsed->getTypeVersion());
        $this->assertSame($original->getInstanceIdentifier(), $parsed->getInstanceIdentifier());
        $this->assertSame($original->getSenderCountry(), $parsed->getSenderCountry());
        $this->assertSame(
            $original->getCreationDateAndTime()->getTimestamp(),
            $parsed->getCreationDateAndTime()->getTimestamp()
        );
    }

    public function test_payload_is_returned_unchanged_in_substance(): void {
        $payload = Sbdh::payloadOf($this->sbdh()->envelope($this->invoiceXml));

        $expected = new DOMDocument;
        $expected->loadXML($this->invoiceXml);
        $actual = new DOMDocument;
        $actual->loadXML($payload);

        $this->assertSame('Invoice', $actual->documentElement?->localName);
        $this->assertTrue($expected->documentElement?->isEqualNode($actual->documentElement) ?? false);
    }

    public function test_header_can_be_parsed_standalone(): void {
        $parsed = Sbdh::parse($this->sbdh()->toXml());

        $this->assertSame('instance-4711', $parsed->getInstanceIdentifier());
        $this->assertSame('DE', $parsed->getSenderCountry());
    }

    public function test_generates_a_uuid_instance_identifier_by_default(): void {
        $sbdh = Sbdh::forUbl(
            $this->invoiceXml,
            ParticipantId::germanVatId('DE123456789'),
            ParticipantId::leitwegId('04011000-12345-67'),
            'DE'
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $sbdh->getInstanceIdentifier()
        );
    }

    public function test_rejects_an_invalid_country_code(): void {
        $this->expectException(InvalidArgumentException::class);
        Sbdh::forUbl(
            $this->invoiceXml,
            ParticipantId::germanVatId('DE123456789'),
            ParticipantId::leitwegId('04011000-12345-67'),
            'Deutschland'
        );
    }

    public function test_rejects_documents_without_payload(): void {
        $this->expectException(InvalidArgumentException::class);
        Sbdh::payloadOf($this->sbdh()->toXml());
    }
}
