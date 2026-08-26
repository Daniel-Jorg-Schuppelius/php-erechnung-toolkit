<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ParticipantIdTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use ERechnungToolkit\Peppol\ParticipantId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\BaseTestCase;

/**
 * Validierung und Kanonisierung von Peppol-Teilnehmerkennungen.
 */
class ParticipantIdTest extends BaseTestCase {
    public function test_canonical_form_uses_default_scheme(): void {
        $participant = new ParticipantId('9930:DE123456789');

        $this->assertSame('iso6523-actorid-upis', $participant->getScheme());
        $this->assertSame('9930:DE123456789', $participant->getValue());
        $this->assertSame('iso6523-actorid-upis::9930:DE123456789', $participant->canonical());
        $this->assertSame('iso6523-actorid-upis::9930:DE123456789', (string) $participant);
    }

    public function test_parses_canonical_form(): void {
        $participant = ParticipantId::parse('iso6523-actorid-upis::0204:04011000-12345-67');

        $this->assertSame('0204', $participant->getIcd());
        $this->assertSame('04011000-12345-67', $participant->getIdentifier());
        $this->assertSame('DE:LWID (Leitweg-ID)', $participant->getIcdLabel());
        $this->assertTrue($participant->hasKnownIcd());
        $this->assertTrue($participant->isPeppolScheme());
    }

    public function test_parses_value_without_scheme_prefix(): void {
        $this->assertSame(
            'iso6523-actorid-upis::0088:7300010000001',
            ParticipantId::parse('0088:7300010000001')->canonical()
        );
    }

    public function test_factories_build_the_expected_icd(): void {
        $this->assertSame('9930:DE123456789', ParticipantId::germanVatId('de 123 456 789')->getValue());
        $this->assertSame('0204:04011000-12345-67', ParticipantId::leitwegId(' 04011000-12345-67 ')->getValue());
        $this->assertSame('0088:7300010000001', ParticipantId::gln('7300010000001')->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdentifierProvider(): array {
        return [
            'leer' => [''],
            'ohne ICD' => ['DE123456789'],
            'ICD zu kurz' => ['088:123456'],
            'ICD nicht numerisch' => ['abcd:123456'],
            'ohne Kennung' => ['9930:'],
            'zu lang' => ['9930:' . str_repeat('X', 50)],
        ];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_rejects_invalid_identifiers(string $value): void {
        $this->assertFalse(ParticipantId::isValid($value));
        $this->assertNull(ParticipantId::tryParse($value));

        $this->expectException(InvalidArgumentException::class);
        new ParticipantId($value);
    }

    public function test_rejects_invalid_scheme(): void {
        $this->expectException(InvalidArgumentException::class);
        new ParticipantId('9930:DE123456789', 'iso6523 actorid upis');
    }

    public function test_unknown_icd_stays_valid_without_label(): void {
        $participant = new ParticipantId('0999:XYZ');

        $this->assertTrue($participant->hasKnownIcd() === false);
        $this->assertNull($participant->getIcdLabel());
    }

    public function test_comparison_and_url_encoding_are_case_insensitive(): void {
        $upper = new ParticipantId('9930:DE123456789');
        $lower = new ParticipantId('9930:de123456789');

        $this->assertTrue($upper->equals($lower));
        $this->assertSame('iso6523-actorid-upis::9930:de123456789', $upper->lowercased());
        $this->assertSame('iso6523-actorid-upis%3A%3A9930%3Ade123456789', $upper->urlEncoded());
        // Der Originalwert bleibt für die Anzeige erhalten.
        $this->assertSame('9930:DE123456789', $upper->getValue());
    }
}
