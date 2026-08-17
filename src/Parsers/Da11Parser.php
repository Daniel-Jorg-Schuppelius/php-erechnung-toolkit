<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Da11Parser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebTakeoffLine};
use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};
use ERechnungToolkit\Helper\Gaeb\GaebTakeoffRecord;

/**
 * Reads a DA11 file - the quantity survey of the GAEB 90 world, and the direct
 * ancestor of the X31.
 *
 * The file is a flat list of 80 character records without any structure around
 * them. Each one begins with its data type, the ordinal number it belongs to
 * and the subtotal index; from position 13 onwards it is byte for byte the same
 * record the X31 carries inside its `QTakeoff` attribute (REB-VB 23.003,
 * section 2.2.2.2), which is why the column reading is shared.
 *
 * ```
 *  1- 2  Datenart (00 Kopfsatz, 11 Rechenansatzzeile)
 *  3-11  Ordnungszahl
 *    12  Zwischensummen-Index (V)
 * 13-80  Rechenansatz - identisch zur X31
 * ```
 *
 * The header record (DA 00) names the procedure, its edition and the mask that
 * defines how the ordinal number is built. Without the mask the structure of
 * the 1979 edition applies.
 */
final class Da11Parser {
    private const TYPE_HEADER = '00';
    private const TYPE_LINE = '11';

    /** Position und Länge der Felder des Kopfsatzes (0-basiert). */
    private const HEADER_PROCEDURE = [10, 6];
    private const HEADER_TITLE = [20, 51];
    private const HEADER_MASK = [71, 9];

    private readonly GaebTakeoffRecord $record;

    public function __construct(?GaebTakeoffRecord $record = null) {
        $this->record = $record ?? new GaebTakeoffRecord;
    }

    public function parse(string $raw): GaebBoq {
        $projectName = null;
        $mask = null;

        /** @var array<string, list<GaebTakeoffLine>> $byReference */
        $byReference = [];
        /** @var list<string> $order ordinal numbers in file order */
        $order = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            // Zeichen zählen, nicht Bytes - ein Umlaut in der Überschrift würde
            // sonst jede folgende Spalte verschieben.
            $row = $line . str_repeat(' ', max(0, 80 - mb_strlen($line)));
            $type = mb_substr($row, 0, 2);

            if ($type === self::TYPE_HEADER) {
                $projectName = $this->field($row, self::HEADER_TITLE);
                $mask = $this->field($row, self::HEADER_MASK);

                continue;
            }
            if ($type !== self::TYPE_LINE) {
                continue;
            }

            $reference = $this->field($row, [2, 9]);
            if ($reference === null) {
                continue;
            }
            if (!isset($byReference[$reference])) {
                $byReference[$reference] = [];
                $order[] = $reference;
            }
            $byReference[$reference][] = $this->record->parse($row);
        }

        $items = [];
        foreach ($order as $reference) {
            $items[] = new GaebItem(
                reference: $this->formatReference($reference, $mask),
                type: GaebItemType::Standard,
                takeoffLines: $byReference[$reference],
            );
        }

        return new GaebBoq(
            projectName: $projectName,
            phaseCode: GaebPhase::QuantitySurvey->value,
            items: $items,
        );
    }

    /** Does this look like a DA11 file? */
    public function looksLikeDa11(string $raw): bool {
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $type = mb_substr($line, 0, 2);
            if ($type === self::TYPE_HEADER) {
                // Der Kopfsatz nennt die Verfahrensbeschreibung - das ist der
                // eindeutige Nachweis, keine Vermutung über Zeilenlängen.
                return str_starts_with((string) $this->field($line . str_repeat(' ', 80), self::HEADER_PROCEDURE), '23.003');
            }

            return $type === self::TYPE_LINE;
        }

        return false;
    }

    /**
     * Splits the ordinal number along the mask, so `0010020010` becomes the
     * dotted form the rest of the toolkit uses. Without a mask the number stays
     * as written - guessing a structure would invent groups that are not there.
     */
    private function formatReference(string $reference, ?string $mask): string {
        if ($mask === null || $mask === '') {
            return $reference;
        }

        $parts = [];
        $current = '';
        $previous = null;
        $padded = $reference . str_repeat(' ', max(0, mb_strlen($mask) - mb_strlen($reference)));

        for ($i = 0; $i < mb_strlen($mask); $i++) {
            $placeholder = mb_substr($mask, $i, 1);
            if ($placeholder === '0') {
                continue;
            }
            if ($previous !== null && $placeholder !== $previous) {
                $parts[] = $current;
                $current = '';
            }
            $current .= mb_substr($padded, $i, 1);
            $previous = $placeholder;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        $parts = array_values(array_filter(array_map(trim(...), $parts), static fn (string $p): bool => $p !== ''));

        return $parts === [] ? $reference : implode('.', $parts);
    }

    /** @param array{int, int} $field offset and length */
    private function field(string $row, array $field): ?string {
        $value = trim(mb_substr($row, $field[0], $field[1]));

        return $value === '' ? null : $value;
    }
}
