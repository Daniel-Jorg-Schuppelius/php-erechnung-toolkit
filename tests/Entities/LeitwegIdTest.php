<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : LeitwegIdTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use ERechnungToolkit\Entities\LeitwegId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\BaseTestCase;

/**
 * Leitweg-ID nach Formatspezifikation 2.0.2; die gültigen Werte stammen aus
 * dem Rechenbeispiel der Spezifikation (Kap. 2.4) und der XRechnung-Testsuite.
 */
class LeitwegIdTest extends BaseTestCase {
    public function test_specification_example_computes_and_accepts_check_digits(): void {
        $this->assertSame('06', LeitwegId::checkDigitsFor('04011000', '1234512345'));

        $leitwegId = LeitwegId::of('04011000-1234512345-06');
        $this->assertSame('04011000', $leitwegId->getCoarseAddress());
        $this->assertSame('1234512345', $leitwegId->getFineAddress());
        $this->assertSame('06', $leitwegId->getCheckDigits());
        $this->assertSame('04011000-1234512345-06', (string) $leitwegId);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIds(): array {
        return [
            'Bremen, Feinadressierung numerisch' => ['04011000-12345-03'],
            'Bund, Feinadressierung mit Buchstaben' => ['991-33333TEST-33'],
            'Bund, drei Stellen' => ['992-90009-96'],
            'Berlin, acht Stellen' => ['11002002-98765-14'],
            'Bund, acht Stellen' => ['99000000-18188-18'],
        ];
    }

    #[DataProvider('validIds')]
    public function test_valid_ids_from_the_test_suite(string $value): void {
        $this->assertTrue(LeitwegId::isValid($value));
        $this->assertSame($value, LeitwegId::of($value)->toString());
    }

    public function test_id_without_fine_address(): void {
        $value = '991-' . LeitwegId::checkDigitsFor('991');

        $leitwegId = LeitwegId::of($value);
        $this->assertNull($leitwegId->getFineAddress());
        $this->assertSame($value, $leitwegId->toString());
    }

    public function test_input_is_normalized(): void {
        $this->assertSame('991-33333TEST-33', LeitwegId::normalize(" 991\u{2013}33333test\u{2011}33\n"));
        $this->assertSame('991-33333TEST-33', LeitwegId::of('991-33333test-33')->toString());
        // Normalisieren prüft nichts.
        $this->assertSame('04011000-12345-67', LeitwegId::normalize('04011000-12345-67'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidIds(): array {
        return [
            'Prüfziffer falsch' => ['04011000-12345-67', 'Prüfziffer 67 stimmt nicht, erwartet 03'],
            'Prüfziffer vertauscht' => ['04011000-1234512345-60', 'Prüfziffer'],
            'Zahlendreher in der Feinadressierung' => ['04011000-1234512354-06', 'Prüfziffer'],
            'Prüfziffer einstellig' => ['04011000-1234512345-6', 'nicht die Form'],
            'Prüfziffer fehlt' => ['04011000-1234512345', 'nicht die Form'],
            'Grobadressierung vier Stellen' => ['0401-12345-03', '4 Stellen'],
            'Grobadressierung dreizehn Stellen' => ['0401100012345-12345-03', '13 Stellen'],
            'Kennzahl kein Land' => ['90000000-03083-72', '90 ist keine Kennzahl'],
            'Feinadressierung leer' => ['04011000--06', 'nicht die Form'],
            'Feinadressierung mit Unterstrich' => ['04011000-1234_5-06', 'nicht die Form'],
            'Feinadressierung zu lang' => ['04011000-' . str_repeat('A', 31) . '-00', 'mehr als 30 Zeichen'],
            'Umlaut' => ['04011000-ÄMT-00', 'nicht die Form'],
            'leer' => ['', 'nicht die Form'],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_are_rejected_with_reason(string $value, string $reason): void {
        $this->assertFalse(LeitwegId::isValid($value));
        $this->assertNull(LeitwegId::tryFrom($value));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        LeitwegId::of($value);
    }

    public function test_check_digits_need_a_valid_coarse_and_fine_address(): void {
        $this->expectException(InvalidArgumentException::class);
        LeitwegId::checkDigitsFor('04011000', 'AMT_1');
    }
}
