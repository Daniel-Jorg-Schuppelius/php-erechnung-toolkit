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
use ERechnungToolkit\Enums\LeitwegIdFailure;
use ERechnungToolkit\Exceptions\InvalidLeitwegIdException;
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
     * @return array<string, array{string, string, LeitwegIdFailure}>
     */
    public static function invalidIds(): array {
        return [
            'Prüfziffer falsch' => ['04011000-12345-67', 'Prüfziffer 67 stimmt nicht, erwartet 03', LeitwegIdFailure::CHECK_DIGITS],
            'Prüfziffer vertauscht' => ['04011000-1234512345-60', 'Prüfziffer', LeitwegIdFailure::CHECK_DIGITS],
            'Zahlendreher in der Feinadressierung' => ['04011000-1234512354-06', 'Prüfziffer', LeitwegIdFailure::CHECK_DIGITS],
            'Prüfziffer einstellig' => ['04011000-1234512345-6', 'nicht die Form', LeitwegIdFailure::FORM],
            'Prüfziffer fehlt' => ['04011000-1234512345', 'nicht die Form', LeitwegIdFailure::FORM],
            'Grobadressierung vier Stellen' => ['0401-12345-03', '4 Stellen', LeitwegIdFailure::COARSE_ADDRESS_LENGTH],
            'Grobadressierung dreizehn Stellen' => ['0401100012345-12345-03', '13 Stellen', LeitwegIdFailure::COARSE_ADDRESS_LENGTH],
            'Kennzahl kein Land' => ['90000000-03083-72', '90 ist keine Kennzahl', LeitwegIdFailure::STATE_CODE],
            'Feinadressierung leer' => ['04011000--06', 'nicht die Form', LeitwegIdFailure::FORM],
            'Feinadressierung mit Unterstrich' => ['04011000-1234_5-06', 'nicht die Form', LeitwegIdFailure::FORM],
            'Feinadressierung zu lang' => ['04011000-' . str_repeat('A', 31) . '-00', 'mehr als 30 Zeichen', LeitwegIdFailure::FINE_ADDRESS],
            'Umlaut' => ['04011000-ÄMT-00', 'nicht die Form', LeitwegIdFailure::FORM],
            'leer' => ['', 'nicht die Form', LeitwegIdFailure::FORM],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_are_rejected_with_reason(string $value, string $message, LeitwegIdFailure $failure): void {
        $this->assertFalse(LeitwegId::isValid($value));
        $this->assertNull(LeitwegId::tryFrom($value));

        try {
            LeitwegId::of($value);
            $this->fail("\"{$value}\" wurde angenommen.");
        } catch (InvalidLeitwegIdException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
            $this->assertStringContainsString($message, $e->getMessage());
            $this->assertSame($failure, $e->getReason());
        }
    }

    public function test_wrong_check_digits_name_given_and_expected(): void {
        try {
            LeitwegId::of('04011000-12345-67');
            $this->fail('Falsche Prüfziffer wurde angenommen.');
        } catch (InvalidLeitwegIdException $e) {
            $this->assertSame('Leitweg-ID "04011000-12345-67": Die Prüfziffer 67 stimmt nicht, erwartet 03.', $e->getMessage());
            $this->assertSame('67', $e->getGivenCheckDigits());
            $this->assertSame('03', $e->getExpectedCheckDigits());
        }

        try {
            LeitwegId::of('0401-12345-03');
            $this->fail('Falsche Grobadressierung wurde angenommen.');
        } catch (InvalidLeitwegIdException $e) {
            $this->assertNull($e->getGivenCheckDigits());
            $this->assertNull($e->getExpectedCheckDigits());
        }
    }

    /**
     * @return array<string, array{string, ?string, LeitwegIdFailure}>
     */
    public static function invalidAddresses(): array {
        return [
            'Feinadressierung mit Unterstrich' => ['04011000', 'AMT_1', LeitwegIdFailure::FINE_ADDRESS],
            'Feinadressierung zu lang' => ['04011000', str_repeat('A', 31), LeitwegIdFailure::FINE_ADDRESS],
            'Grobadressierung vier Stellen' => ['0401', null, LeitwegIdFailure::COARSE_ADDRESS_LENGTH],
            'Grobadressierung mit Buchstaben' => ['04A11000', '12345', LeitwegIdFailure::COARSE_ADDRESS_LENGTH],
            'Kennzahl kein Land' => ['90000000', '03083', LeitwegIdFailure::STATE_CODE],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_check_digits_need_a_valid_coarse_and_fine_address(string $coarse, ?string $fine, LeitwegIdFailure $failure): void {
        try {
            LeitwegId::checkDigitsFor($coarse, $fine);
            $this->fail('Ungültige Adressierung wurde angenommen.');
        } catch (InvalidLeitwegIdException $e) {
            $this->assertSame($failure, $e->getReason());
        }
    }

    /**
     * Sieht aus wie eine Leitweg-ID, auch wenn Länge oder Prüfziffer nicht stimmen.
     *
     * @return array<string, array{string}>
     */
    public static function lookAlikes(): array {
        return [
            'gültig' => ['04011000-1234512345-06'],
            'gültig ohne Feinadressierung' => ['991-35'],
            'falsche Prüfziffer' => ['04011000-12345-67'],
            'Grobadressierung vier Stellen' => ['0401-12345-03'],
            'XRechnung-Testsuite, zehn Stellen' => ['1234567890-54321-40'],
            'Feinadressierung zu lang' => ['04011000-' . str_repeat('A', 31) . '-00'],
            'klein, mit Leerraum und Gedankenstrich' => [" 991\u{2013}33333test-33\n"],
        ];
    }

    #[DataProvider('lookAlikes')]
    public function test_look_alikes(string $value): void {
        $this->assertTrue(LeitwegId::looksLike($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherReferences(): array {
        return [
            'leer' => [''],
            'Bestellnummer' => ['PO-4711'],
            'Rechnungsnummer mit Jahr' => ['RE-2026-0001'],
            'Kennzahl 90' => ['90000000-03083-72'],
            'Kennzahl 00' => ['00-123-45'],
            'Kennzahl 17' => ['17000000-12345-67'],
            'Prüfziffer fehlt' => ['04011000-1234512345'],
            'Prüfziffer einstellig' => ['04011000-1234512345-6'],
            'ohne Bindestriche' => ['04011000 12345 06'],
            'Unterstrich' => ['04011000-AB_C-06'],
        ];
    }

    #[DataProvider('otherReferences')]
    public function test_other_references_do_not_look_alike(string $value): void {
        $this->assertFalse(LeitwegId::looksLike($value));
    }
}
