<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb90Parser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebSection};
use ERechnungToolkit\Enums\GaebItemType;
use InvalidArgumentException;

/**
 * Reader for the GAEB 90 family (`.d81` … `.d86`).
 *
 * A GAEB 90 file is a grid, not a markup language: every record is exactly 80
 * characters, the first two hold the record type and the last six a gapless
 * running number. Fields sit at fixed columns, and the opening record carries
 * the mask that says how the nine digit ordinal number is split into levels.
 *
 * Column layout, verified against the public GAEB test files (33 of 34 priced
 * items reproduce quantity × unit price = total exactly):
 *
 * | Record | Columns | Field |
 * | --- | --- | --- |
 * | `00` | 11-12 / 63-71 / 72-73 | phase, ordinal number mask, edition year |
 * | `11` | 3-11 | ordinal number of a group |
 * | `12` | 3-74 | label of the group |
 * | `21` | 3-11 / 12-14 / 24-34 / 35-38 | ordinal number, item kinds, quantity (8,3), unit |
 * | `23` | 3-11 / 13-22 / 23 / 25-36 | ordinal number, unit price (8,2), tenth of a cent, total (10,2) |
 * | `25` / `26` | 3-74 | short text, long text |
 * | `99` | last columns | number of items - the reader checks itself against it |
 *
 * The result is the same {@see GaebBoq} the DA XML reader produces, so
 * everything downstream stays untouched.
 */
final class Gaeb90Parser {
    private const RECORD_LENGTH = 80;

    /** Quantities carry three decimals, prices two plus a tenth of a cent. */
    private const QUANTITY_SCALE = 3;

    private const PRICE_SCALE = 2;

    /** Prices keep the tenth of a cent, so money is held with four decimals. */
    private const MONEY_SCALE = 4;

    public function parse(string $content, CurrencyCode $currency = CurrencyCode::Euro): GaebBoq {
        $records = $this->records($content);
        if ($records === []) {
            throw new InvalidArgumentException('File is not a valid GAEB 90 document.');
        }

        $opening = $this->firstOfType($records, '00');
        $mask = $opening !== null ? trim(mb_substr($opening, 62, 9)) : '';
        $phaseCode = $opening !== null ? trim(mb_substr($opening, 10, 2)) : null;

        $sections = [];
        $items = [];
        $prices = $this->prices($records, $currency, $mask);
        $labels = [];
        $lastGroup = null;
        $lastItem = null;
        $counters = ['section' => 0, 'item' => 0];
        /** @var array<string, string> $shortTexts */
        $shortTexts = [];
        /** @var array<string, list<string>> $longTexts */
        $longTexts = [];

        foreach ($records as $record) {
            $type = mb_substr($record, 0, 2);
            $body = rtrim(mb_substr($record, 2, 72));

            if ($type === '11') {
                $lastGroup = $this->reference(mb_substr($record, 2, 9), $mask);
                $labels[$lastGroup] = null;

                continue;
            }

            if ($type === '12' && $lastGroup !== null && ($labels[$lastGroup] ?? null) === null) {
                $labels[$lastGroup] = trim($body);

                continue;
            }

            if ($type === '21') {
                $reference = $this->reference(mb_substr($record, 2, 9), $mask);
                $lastItem = $reference;
                $longTexts[$reference] = [];
                $items[$reference] = [
                    'reference' => $reference,
                    'quantity' => $this->decimal(mb_substr($record, 23, 11), self::QUANTITY_SCALE),
                    'unit' => trim(mb_substr($record, 34, 4)) ?: null,
                    'position' => $counters['item']++,
                ];

                continue;
            }

            if ($type === '25' && $lastItem !== null) {
                $shortTexts[$lastItem] = trim($body);

                continue;
            }

            if ($type === '26' && $lastItem !== null) {
                $longTexts[$lastItem][] = rtrim($body);
            }
        }

        foreach (array_keys($labels) as $reference) {
            $sections[] = new GaebSection(
                reference: (string) $reference,
                parentReference: $this->parentOf((string) $reference),
                label: $labels[$reference],
                position: $counters['section']++,
            );
        }

        // Die Angebotsabgabe trägt keine Positionssätze: dort stehen nur
        // Ordnungszahl und Preis, genau wie in der X84.
        if ($items === []) {
            foreach ($prices as $reference => $price) {
                $items[$reference] = [
                    'reference' => (string) $reference,
                    'quantity' => null,
                    'unit' => null,
                    'position' => $counters['item']++,
                ];
            }
        }

        $entities = [];
        foreach ($items as $reference => $item) {
            $long = implode("\n", $longTexts[$reference] ?? []);
            $entities[] = new GaebItem(
                reference: $item['reference'],
                sectionReference: $this->parentOf($item['reference']),
                type: GaebItemType::Standard,
                shortText: $shortTexts[$reference] ?? null,
                longText: trim($long) === '' ? null : $long,
                quantity: $item['quantity'],
                unit: $item['unit'],
                unitPrice: $prices[$reference]['unit'] ?? null,
                totalPrice: $prices[$reference]['total'] ?? null,
                position: $item['position'],
            );
        }

        return new GaebBoq(
            phaseCode: $phaseCode,
            sections: $sections,
            items: $entities,
            currency: $currency,
        );
    }

    /**
     * Number of items the closing record states. It is what a reader checks
     * itself against - a mismatch means records were lost, not merely skipped.
     */
    public function statedItemCount(string $content): ?int {
        $closing = $this->firstOfType($this->records($content), '99');
        if ($closing === null) {
            return null;
        }

        $digits = trim(mb_substr($closing, 68, 6));

        return $digits !== '' && ctype_digit($digits) ? (int) $digits : null;
    }

    /**
     * Records of exactly 80 characters. Shorter lines are padded: writers pad
     * with spaces, and some drop the trailing ones.
     *
     * @return list<string>
     */
    private function records(string $content): array {
        $records = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            // Zeichen zählen, nicht Bytes: ein Umlaut im Text verschiebt sonst
            // jede Spalte dahinter, sobald die Datei als UTF-8 vorliegt.
            $line = mb_substr($line, 0, self::RECORD_LENGTH);
            $records[] = $line . str_repeat(' ', max(0, self::RECORD_LENGTH - mb_strlen($line)));
        }

        return $records;
    }

    /**
     * Prices of the bid records (`23`).
     *
     * @param  list<string>  $records
     * @return array<string, array{unit: ?Money, total: ?Money}>
     */
    private function prices(array $records, CurrencyCode $currency, string $mask): array {
        $prices = [];
        foreach ($records as $record) {
            if (mb_substr($record, 0, 2) !== '23') {
                continue;
            }

            $unit = $this->decimal(mb_substr($record, 12, 10), self::PRICE_SCALE);
            $tenth = trim(mb_substr($record, 22, 1));
            if ($unit !== null && is_numeric($unit) && ctype_digit($tenth)) {
                // The eleventh column holds a tenth of a cent - dropping it
                // changes the price of every item that uses it.
                $unit = bcadd($unit, bcdiv($tenth, '1000', 4), 4);
            }

            $prices[$this->reference(mb_substr($record, 2, 9), $mask)] = [
                'unit' => $unit !== null ? Money::of($unit, $currency, self::MONEY_SCALE) : null,
                'total' => ($total = $this->decimal(mb_substr($record, 24, 12), self::PRICE_SCALE)) !== null
                    ? Money::of($total, $currency, self::MONEY_SCALE)
                    : null,
            ];
        }

        return $prices;
    }

    /** @param list<string> $records */
    private function firstOfType(array $records, string $type): ?string {
        foreach ($records as $record) {
            if (mb_substr($record, 0, 2) === $type) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Ordinal number as the XML side writes it. The mask of the opening record
     * says which columns form which level (`1122PPPPI`: two levels of two
     * digits, four for the item counter, one for the index); without a mask the
     * raw digits are kept so that price records still match their items.
     */
    private function reference(string $raw, string $mask): string {
        $raw = rtrim($raw);
        if ($mask === '' || strlen($mask) < strlen($raw)) {
            return trim($raw);
        }

        $parts = [];
        $current = '';
        $previous = null;
        foreach (str_split($mask) as $index => $marker) {
            $char = substr($raw, $index, 1);
            if ($marker !== $previous && $current !== '') {
                $parts[] = $current;
                $current = '';
            }
            $current .= $char;
            $previous = $marker;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        return implode('.', $parts);
    }

    /** Parent of an ordinal number: everything but its last level. */
    private function parentOf(string $reference): ?string {
        $position = strrpos($reference, '.');

        return $position === false ? null : substr($reference, 0, $position);
    }

    /** Fixed point field: digits only, the last places are decimals. */
    private function decimal(string $raw, int $scale): ?string {
        $raw = trim($raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $raw = str_pad($raw, $scale + 1, '0', STR_PAD_LEFT);
        $whole = substr($raw, 0, -$scale);
        $fraction = substr($raw, -$scale);

        return ltrim($whole, '0') === '' ? '0.' . $fraction : ltrim($whole, '0') . '.' . $fraction;
    }
}
