<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : LeitwegId.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Helper\Data\StringHelper;
use InvalidArgumentException;
use Stringable;

/**
 * Leitweg-ID (ICD 0204) nach „Leitweg-ID Format-Spezifikation“ Version 2.0.2
 * vom 28.07.2021 (KoSIT, Betrieb XRechnung):
 * https://xeinkauf.de/app/uploads/2022/11/Leitweg-ID-Formatspezifikation-v2-0-2-1.pdf
 *
 * Aufbau (Kap. 2.1, Tabelle 1): Grobadressierung `-` [Feinadressierung `-`] Prüfziffer.
 * - Grobadressierung (Kap. 2.2): Ziffern aus Kennzahl des Landes bzw. Bundes
 *   (2 Stellen, 01–16 oder 99), Regierungsbezirk (1), Landkreis (2) und
 *   Gemeindeverband/Gemeinde (3, 4 oder 7); ein Element setzt das vorige voraus,
 *   also 2, 3, 5, 8, 9 oder 12 Stellen.
 * - Feinadressierung (Kap. 2.3), optional: bis zu 30 Zeichen A–Z (nicht
 *   case-sensitiv) und 0–9.
 * - Prüfziffer (Kap. 2.4): zwei Ziffern nach ISO/IEC 7064:2003 Mod 97-10 über Grob-
 *   und Feinadressierung ohne Bindestriche, Buchstaben A = 10 bis Z = 35.
 * - Trennzeichen (Kap. 2.5): Bindestrich-Minus U+002D; Gesamtlänge 5 bis 46.
 *
 * Gespeichert wird die normalisierte Form ({@see normalize()}): Großbuchstaben,
 * ohne Leerraum, typografische Striche als Bindestrich.
 */
final class LeitwegId implements Stringable {
    /** ICD der Leitweg-ID in ISO/IEC 6523 (Kap. 1.5). */
    public const ICD = '0204';

    /** Kennzahlen der Länder und des Bundes (Kap. 2.2.1). */
    private const STATE_CODES = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '99'];

    /** Zulässige Längen der Grobadressierung: 2, +1, +2, +3/4/7 (Kap. 2.1, Tabelle 1). */
    private const COARSE_LENGTHS = [2, 3, 5, 8, 9, 12];

    private const FINE_MAX_LENGTH = 30;

    /** Striche, die beim Kopieren aus Textverarbeitung und PDF statt U+002D ankommen. */
    private const DASHES = ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"];

    private function __construct(
        private readonly string $coarseAddress,
        private readonly ?string $fineAddress,
        private readonly string $checkDigits,
    ) {}

    /**
     * @throws InvalidArgumentException wenn der Wert nach der Normalisierung keine gültige Leitweg-ID ist
     */
    public static function of(string $value): self {
        $normalized = self::normalize($value);
        if (preg_match('/^([0-9]+)(?:-([A-Z0-9]+))?-([0-9]{2})$/', $normalized, $parts) !== 1) {
            throw new InvalidArgumentException("Leitweg-ID \"{$value}\" hat nicht die Form Grobadressierung[-Feinadressierung]-Prüfziffer (Ziffern, A–Z, Bindestriche).");
        }
        [, $coarse, $fine, $check] = $parts;

        self::assertCoarseAddress($coarse, $value);
        if (strlen($fine) > self::FINE_MAX_LENGTH) {
            throw new InvalidArgumentException("Leitweg-ID \"{$value}\": Die Feinadressierung hat mehr als " . self::FINE_MAX_LENGTH . ' Zeichen.');
        }

        $expected = self::checkDigitsFor($coarse, $fine);
        if ($check !== $expected) {
            throw new InvalidArgumentException("Leitweg-ID \"{$value}\": Die Prüfziffer {$check} stimmt nicht, erwartet {$expected}.");
        }

        return new self($coarse, $fine !== '' ? $fine : null, $check);
    }

    public static function tryFrom(string $value): ?self {
        try {
            return self::of($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isValid(string $value): bool {
        return self::tryFrom($value) !== null;
    }

    /**
     * Großbuchstaben, ohne Leerraum, typografische Striche als Bindestrich; prüft nichts.
     */
    public static function normalize(string $value): string {
        return strtoupper(str_replace(self::DASHES, '-', StringHelper::removeWhitespace($value, true)));
    }

    /**
     * Prüfziffer zu Grob- und Feinadressierung (Kap. 2.4): 98 minus Rest modulo 97
     * der Ziffernfolge mit angehängtem „00“, zweistellig.
     *
     * @throws InvalidArgumentException bei ungültiger Grob- oder Feinadressierung
     */
    public static function checkDigitsFor(string $coarseAddress, ?string $fineAddress = null): string {
        $coarse = self::normalize($coarseAddress);
        $fine = self::normalize($fineAddress ?? '');
        self::assertCoarseAddress($coarse, $coarseAddress);
        if (preg_match('/^[A-Z0-9]{0,' . self::FINE_MAX_LENGTH . '}$/', $fine) !== 1) {
            throw new InvalidArgumentException("Feinadressierung \"{$fineAddress}\" erlaubt nur bis zu " . self::FINE_MAX_LENGTH . ' Zeichen A–Z und 0–9.');
        }

        return sprintf('%02d', 98 - self::mod97($coarse . $fine . '00'));
    }

    public function getCoarseAddress(): string {
        return $this->coarseAddress;
    }

    public function getFineAddress(): ?string {
        return $this->fineAddress;
    }

    public function getCheckDigits(): string {
        return $this->checkDigits;
    }

    public function toString(): string {
        return implode('-', array_filter([$this->coarseAddress, $this->fineAddress, $this->checkDigits], static fn (?string $part): bool => $part !== null));
    }

    public function __toString(): string {
        return $this->toString();
    }

    private static function assertCoarseAddress(string $coarse, string $value): void {
        if (preg_match('/^[0-9]+$/', $coarse) !== 1 || !in_array(strlen($coarse), self::COARSE_LENGTHS, true)) {
            throw new InvalidArgumentException("Leitweg-ID \"{$value}\": Die Grobadressierung hat " . strlen($coarse) . ' Stellen; zulässig sind 2, 3, 5, 8, 9 oder 12 Ziffern.');
        }
        if (!in_array(substr($coarse, 0, 2), self::STATE_CODES, true)) {
            throw new InvalidArgumentException("Leitweg-ID \"{$value}\": " . substr($coarse, 0, 2) . ' ist keine Kennzahl eines Landes (01–16) oder des Bundes (99).');
        }
    }

    /** Rest modulo 97 nach ISO/IEC 7064 (A = 10 … Z = 35), ziffernweise ohne bcmath; nur [0-9A-Z]. */
    private static function mod97(string $value): int {
        $remainder = 0;
        foreach (str_split($value) as $char) {
            foreach (str_split($char <= '9' ? $char : (string) (ord($char) - 55)) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder;
    }
}
