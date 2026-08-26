<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpLookupTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use DateTimeImmutable;
use ERechnungToolkit\Enums\SmlZone;
use ERechnungToolkit\Peppol\Dns\SystemNaptrResolver;
use ERechnungToolkit\Peppol\{DocumentTypeId, ParticipantId, SmpEndpoint, SmpLookup};
use ERechnungToolkit\Peppol\Http\SmpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Contracts\BaseTestCase;
use Tests\Peppol\Doubles\{FakeNaptrResolver, FakeSmpHttpClient};

/**
 * SML-Hashbildung und SMP-Auswertung - ohne Netzzugriff.
 *
 * Die DNS-Namen sind gegen die veröffentlichten Beispiele der Peppol-Spezifikation
 * geprüft (CNAME/MD5 und NAPTR/SHA-256-Base32).
 */
class SmpLookupTest extends BaseTestCase {
    private const RESOURCES = __DIR__ . '/../resources/peppol';

    private const SMP_BASE_URL = 'https://smp.example.org';

    private const PARTICIPANT = '9930:DE123456789';

    /**
     * Referenzwerte aus der Peppol-Dokumentation bzw. den Testvektoren der
     * Peppol-Referenzimplementierung (peppol-commons).
     *
     * @return array<string, array{string, string, string}>
     */
    public static function naptrDnsNameProvider(): array {
        return [
            '0088:123abc' => ['0088:123abc', SmlZone::PRODUCTION->value, 'y7dzfxaf3d4cjz4kcgrxtec6twvcga4ky7zwa5boif6mswd4tdrq'],
            '0088:123ABC (case-insensitiv)' => ['0088:123ABC', SmlZone::PRODUCTION->value, 'y7dzfxaf3d4cjz4kcgrxtec6twvcga4ky7zwa5boif6mswd4tdrq'],
            '0010:5798000000001' => ['0010:5798000000001', SmlZone::PRODUCTION->value, 'xukhfqabqziki3ykvr2fhr4snfa3pf5vpq6k4tonv3lmvsy5arvq'],
            '9999:elonia' => ['9999:elonia', SmlZone::PRODUCTION->value, 'hsh3fmc5cyerdv5j6ln6mmqcn2pp2ucyvczrwueahosobvikb6kq'],
        ];
    }

    #[DataProvider('naptrDnsNameProvider')]
    public function test_naptr_dns_name_matches_the_specification(string $value, string $zone, string $expectedHash): void {
        $this->assertSame(
            $expectedHash . '.iso6523-actorid-upis.' . $zone,
            SmpLookup::dnsName(new ParticipantId($value), SmlZone::PRODUCTION)
        );
    }

    public function test_cname_dns_name_matches_the_specification(): void {
        // Beispiel der Peppol-Dokumentation: 0088:123abc => B-f5e78500450d37de5aabe6648ac3bb70
        $this->assertSame(
            'B-f5e78500450d37de5aabe6648ac3bb70.iso6523-actorid-upis.edelivery.tech.ec.europa.eu',
            SmpLookup::legacyDnsName(new ParticipantId('0088:123abc'))
        );
        $this->assertSame(
            'B-f5e78500450d37de5aabe6648ac3bb70.iso6523-actorid-upis.acc.edelivery.tech.ec.europa.eu',
            SmpLookup::legacyDnsName(new ParticipantId('0088:123ABC'), SmlZone::LEGACY_TEST)
        );
    }

    public function test_zone_may_be_given_as_string_with_trailing_dot(): void {
        $this->assertSame(
            'y7dzfxaf3d4cjz4kcgrxtec6twvcga4ky7zwa5boif6mswd4tdrq.iso6523-actorid-upis.toop.acc.edelivery.tech.ec.europa.eu',
            SmpLookup::dnsName(new ParticipantId('0088:123abc'), 'toop.acc.edelivery.tech.ec.europa.eu.')
        );
    }

    public function test_builds_the_smp_urls(): void {
        $participant = new ParticipantId(self::PARTICIPANT);

        $this->assertSame(
            self::SMP_BASE_URL . '/iso6523-actorid-upis%3A%3A9930%3Ade123456789',
            SmpLookup::serviceGroupUrl($participant, self::SMP_BASE_URL . '/')
        );
        $this->assertStringStartsWith(
            self::SMP_BASE_URL . '/iso6523-actorid-upis%3A%3A9930%3Ade123456789/services/busdox-docid-qns%3A%3A',
            SmpLookup::serviceMetadataUrl($participant, DocumentTypeId::peppolBisBillingInvoice(), self::SMP_BASE_URL)
        );
    }

    public function test_reads_the_supported_document_types_from_the_service_group(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $url = SmpLookup::serviceGroupUrl($participant, self::SMP_BASE_URL);
        $client = new FakeSmpHttpClient([$url => new SmpResponse(200, $this->fixture('smp_service_group.xml'))]);

        $group = (new SmpLookup($client))->fetchServiceGroup($participant, self::SMP_BASE_URL);

        $this->assertSame([$url], $client->requestedUrls);
        $this->assertTrue($group->getParticipantId()->equals($participant));
        $this->assertCount(2, $group->getDocumentTypeIds());
        $this->assertTrue($group->supports(DocumentTypeId::peppolBisBillingInvoice()));
        $this->assertTrue($group->supports(DocumentTypeId::peppolBisBillingCreditNote()));
        $this->assertTrue($group->supportsCustomization(DocumentTypeId::BIS_BILLING_3));
        $this->assertFalse($group->supports(DocumentTypeId::xrechnungInvoice()));
    }

    public function test_reads_endpoint_and_certificate_from_the_service_metadata(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $documentTypeId = DocumentTypeId::peppolBisBillingInvoice();
        $url = SmpLookup::serviceMetadataUrl($participant, $documentTypeId, self::SMP_BASE_URL);
        $lookup = new SmpLookup(new FakeSmpHttpClient([
            $url => new SmpResponse(200, $this->fixture('smp_signed_service_metadata.xml')),
        ]));

        $metadata = $lookup->fetchServiceMetadata($participant, $documentTypeId, self::SMP_BASE_URL);

        $this->assertTrue($metadata->isSigned());
        $this->assertTrue($metadata->getDocumentTypeId()->equals($documentTypeId));
        $this->assertCount(2, $metadata->getEndpoints());
        $this->assertSame(['urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'], $metadata->getProcessIds());

        $endpoint = $metadata->findEndpoint(null, SmpEndpoint::TRANSPORT_AS4, new DateTimeImmutable('2026-08-26T10:00:00+00:00'));
        $this->assertInstanceOf(SmpEndpoint::class, $endpoint);
        $this->assertTrue($endpoint->isAs4());
        $this->assertSame('https://ap.example.org/as4', $endpoint->getUrl());
        $this->assertSame('cenbii-procid-ubl', $endpoint->getProcessScheme());
        $this->assertFalse($endpoint->requiresBusinessLevelSignature());
        $this->assertSame('mailto:support@ap.example.org', $endpoint->getTechnicalContactUrl());
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $endpoint->getCertificatePem());

        $certificate = $endpoint->getCertificateInfo();
        $this->assertIsArray($certificate);
        $this->assertStringContainsString('CN=APP_1000000123', $certificate['subject']);
        $this->assertInstanceOf(DateTimeImmutable::class, $certificate['validTo']);
    }

    public function test_endpoint_outside_its_validity_period_is_skipped(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $documentTypeId = DocumentTypeId::peppolBisBillingInvoice();
        $url = SmpLookup::serviceMetadataUrl($participant, $documentTypeId, self::SMP_BASE_URL);
        $lookup = new SmpLookup(new FakeSmpHttpClient([
            $url => new SmpResponse(200, $this->fixture('smp_signed_service_metadata.xml')),
        ]));

        $endpoint = $lookup->resolveEndpoint(
            $participant,
            $documentTypeId,
            self::SMP_BASE_URL,
            null,
            SmpEndpoint::TRANSPORT_AS4,
            new DateTimeImmutable('2025-01-01T00:00:00+00:00')
        );

        $this->assertNull($endpoint);
    }

    public function test_unregistered_participant_is_reported_without_exception(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $lookup = new SmpLookup(new FakeSmpHttpClient);

        $this->assertFalse($lookup->isRegistered($participant, self::SMP_BASE_URL));

        $this->expectException(RuntimeException::class);
        $lookup->fetchServiceGroup($participant, self::SMP_BASE_URL);
    }

    public function test_resolves_the_smp_base_url_via_naptr(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $dnsName = SmpLookup::dnsName($participant, SmlZone::TEST);
        $resolver = new FakeNaptrResolver([$dnsName => ['https://smp.example.org/']]);

        $lookup = new SmpLookup(new FakeSmpHttpClient, $resolver);

        $this->assertSame(self::SMP_BASE_URL, $lookup->resolveSmpBaseUrl($participant, SmlZone::TEST));
        $this->assertSame([$dnsName], $resolver->lookups);
        $this->assertNull($lookup->resolveSmpBaseUrl(new ParticipantId('9930:DE999999999'), SmlZone::TEST));
    }

    public function test_legacy_zone_uses_the_dns_name_as_smp_host(): void {
        $participant = new ParticipantId(self::PARTICIPANT);
        $lookup = new SmpLookup(new FakeSmpHttpClient);

        $this->assertSame(
            'http://' . SmpLookup::legacyDnsName($participant),
            $lookup->resolveSmpBaseUrl($participant, SmlZone::LEGACY_PRODUCTION)
        );
    }

    public function test_naptr_zone_without_resolver_fails_fast(): void {
        $lookup = new SmpLookup(new FakeSmpHttpClient);

        $this->expectException(RuntimeException::class);
        $lookup->resolveSmpBaseUrl(new ParticipantId(self::PARTICIPANT));
    }

    public function test_naptr_regexp_replacement_is_extracted(): void {
        $this->assertSame('https://smp.example.org', SystemNaptrResolver::replacementOf('!^.*$!https://smp.example.org!'));
        $this->assertNull(SystemNaptrResolver::replacementOf('!^.*$!'));
        $this->assertNull(SystemNaptrResolver::replacementOf(''));
    }

    private function fixture(string $name): string {
        $content = file_get_contents(self::RESOURCES . '/' . $name);
        $this->assertIsString($content);

        return $content;
    }
}
