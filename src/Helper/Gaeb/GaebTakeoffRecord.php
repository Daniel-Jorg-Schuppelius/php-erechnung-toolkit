<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTakeoffRecord.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\Gaeb;

use ERechnungToolkit\Entities\Gaeb\GaebTakeoffLine;

/**
 * Reads and writes the fixed 80 character record of REB-VB 23.003.
 *
 * Both directions live here so the column layout exists exactly once: the X31
 * carries the record inside its `QTakeoff` attribute, the DA11 file writes it
 * as a line of its own, and both must agree down to the single blank.
 *
 * The column layout is the one verified against the BVBS test file:
 *
 * ```
 *  0-11  frei (in DA XML leer, in der DA11-Datei die Satzkennung)
 *    12  Kennzeichen (' ', '*' Kommentar, 'H' Hilfswert, 'Z' Abzug)
 * 13-21  Erläuterung (bei Kommentarzeilen 13-68)
 *    22  Vorzeichen des Faktors
 * 23-28  Faktor
 * 29-30  Formelnummer
 * 31-67  fünf Wertfelder (9 + 4×7), rechtsbündig; Formel 91: Ausdruck ab 31
 *    68  Rechenzeichen des Folgewertes bzw. Abschluss `=`
 * 69-74  Adresse
 * ```
 *
 * Ein Round-Trip ist **wertegleich, nicht byteweise gleich**: Beim Lesen gehen
 * leere Wertfelder verloren, Werte rücken deshalb beim Schreiben nach vorn. Für
 * das Rechenergebnis ist das ohne Belang, die Spalte kann sich aber verschieben.
 */
final class GaebTakeoffRecord {
    private const LENGTH = 80;

    /**
     * Offset und Breite der fünf Wertfelder. Das erste ist breiter; die
     * übrigen tragen das Rechenzeichen an ihrer ersten Stelle (REB-VB 23.003,
     * Abschnitt 2.2.2.2 - Stellen 32-40, 41-47, 48-54, 55-61, 62-68).
     */
    private const VALUE_FIELDS = [[31, 9], [40, 7], [47, 7], [54, 7], [61, 7]];

    /** Stelle 69: letztes Rechenzeichen bzw. Abschluss der Rechnung. */
    private const CLOSING_COLUMN = 68;

    /**
     * Reads one record. Everything before column 13 belongs to the file around
     * it (data type, ordinal number, subtotal index) and is left to the caller.
     */
    public function parse(string $row): GaebTakeoffLine {
        // Zeichen zählen, nicht Bytes: „Aufmaß" verschiebt sonst jede folgende
        // Spalte, weil ein Umlaut in UTF-8 zwei Bytes belegt.
        $row .= str_repeat(' ', max(0, self::LENGTH - mb_strlen($row)));

        $kind = mb_substr($row, 12, 1);
        if ($kind === GaebTakeoffLine::KIND_COMMENT) {
            // Eine Kommentarzeile trägt nur Text - keine Felder.
            return new GaebTakeoffLine(
                kind: $kind,
                explanation: $this->read($row, 13, 56),
                address: $this->read($row, 69, 6),
            );
        }

        $sign = trim(mb_substr($row, 22, 1));
        $factor = $this->read($row, 23, 6);
        $formula = $this->read($row, 29, 2);

        $values = [];
        if ($formula === '91') {
            // Die freie Formel kennt keine Wertfelder: alles hinter ihrer
            // Nummer ist ein Ausdruck, der über mehrere Zeilen laufen darf.
            $expression = rtrim(mb_substr($row, 31, 38));
            if ($expression !== '') {
                $values[] = ltrim($expression);
            }
        } else {
            foreach (self::VALUE_FIELDS as [$offset, $width]) {
                // Das Gleichheitszeichen schließt die Rechnung ab und ist kein
                // Wert - sonst zählt es als zusätzliche Koordinate.
                $value = trim(str_replace('=', '', mb_substr($row, $offset, $width)));
                if ($value !== '') {
                    $values[] = $value;
                }
            }

            // Das letzte Rechenzeichen gehört bereits zum ersten Wert der
            // Folgezeile; als eigener Eintrag bleibt es erhalten.
            $carry = mb_substr($row, self::CLOSING_COLUMN, 1);
            if ($carry === '+' || $carry === '-') {
                $values[] = $carry;
            }
        }

        return new GaebTakeoffLine(
            kind: $kind === '' ? ' ' : $kind,
            explanation: $this->read($row, 13, 9),
            factor: $factor === null ? null : ($sign === '-' ? '-' . $factor : $factor),
            formula: $formula,
            values: $values,
            address: $this->read($row, 69, 6),
            // Ein abschließendes Gleichheitszeichen schließt die Rechnung ab.
            closesResult: str_contains(mb_substr($row, 12, 57), '='),
        );
    }

    private function read(string $row, int $offset, int $length): ?string {
        $value = trim(mb_substr($row, $offset, $length));

        return $value === '' ? null : $value;
    }

    public function render(GaebTakeoffLine $line): string {
        $row = str_repeat(' ', self::LENGTH);
        $row = $this->put($row, 12, $line->getKind());

        if ($line->getKind() === GaebTakeoffLine::KIND_COMMENT) {
            // Die Kommentarzeile trägt nur Text - keine Felder, kein Abschluss.
            $row = $this->put($row, 13, mb_substr((string) $line->getExplanation(), 0, 56));

            return $this->put($row, 69, mb_substr((string) $line->getAddress(), 0, 6));
        }

        $row = $this->put($row, 13, mb_substr((string) $line->getExplanation(), 0, 9));

        $factor = (string) $line->getFactor();
        if ($factor !== '') {
            // Das Vorzeichen steht in einer eigenen Spalte vor dem Betrag.
            $sign = str_starts_with($factor, '-') ? '-' : '';
            $row = $this->put($row, 22, $sign);
            $row = $this->put($row, 23, str_pad(ltrim($factor, '+-'), 6, ' ', STR_PAD_LEFT));
        }

        $formula = (string) $line->getFormula();
        $row = $this->put($row, 29, str_pad($formula, 2, ' ', STR_PAD_LEFT));

        if ($formula === '91') {
            // Die freie Formel kennt keine Wertfelder; ihr Ausdruck trägt das
            // abschließende Gleichheitszeichen bereits in sich.
            $row = $this->put($row, 31, mb_substr($line->getValues()[0] ?? '', 0, 38));

            return $this->put($row, 69, mb_substr((string) $line->getAddress(), 0, 6));
        }

        // Die Koordinatenformeln lassen das breite erste Feld leer und beginnen
        // mit dem zweiten - ihre Werte sind Koordinatenpaare.
        $wide = $formula === '21' || $formula === '22';
        $first = $wide ? 1 : 0;
        $values = $line->getValues();

        // Ein reiner Vorzeichen-Eintrag hinter dem letzten Feld gehört zum
        // ersten Wert der Folgezeile (Rechenansatz, Formel 00).
        $carry = '';
        $last = $values[count($values) - 1] ?? '';
        if (!$wide && ($last === '+' || $last === '-')) {
            $carry = $last;
            array_pop($values);
        }

        $values = array_slice($values, 0, count(self::VALUE_FIELDS) - $first);

        foreach ($values as $index => $value) {
            [$offset, $width] = self::VALUE_FIELDS[$first + $index];
            $row = $this->put($row, $offset, str_pad(mb_substr($value, 0, $width), $width, ' ', STR_PAD_LEFT));
        }

        // Der Abschluss steht unmittelbar hinter dem letzten belegten Wertfeld;
        // sind alle belegt, grenzt er direkt an die Adresse.
        if ($line->closesResult()) {
            $next = $first + count($values);
            $row = $this->put($row, self::VALUE_FIELDS[$next][0] ?? self::CLOSING_COLUMN, '=');
        }
        if ($carry !== '') {
            $row = $this->put($row, self::CLOSING_COLUMN, $carry);
        }

        return $this->put($row, 69, mb_substr((string) $line->getAddress(), 0, 6));
    }

    /** Setzt einen Text an eine feste Spalte, ohne die Satzlänge zu verändern. */
    private function put(string $row, int $offset, string $text): string {
        if ($text === '') {
            return $row;
        }
        $length = mb_strlen($text);

        return mb_substr($row, 0, $offset) . $text . mb_substr($row, $offset + $length);
    }
}
