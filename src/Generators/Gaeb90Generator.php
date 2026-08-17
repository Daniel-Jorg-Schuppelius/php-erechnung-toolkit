<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb90Generator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem};
use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};

/**
 * Writer for the GAEB 90 family, counterpart of {@see \ERechnungToolkit\Parsers\Gaeb90Parser}.
 *
 * Everything about this format is positional: each record is exactly 80
 * characters, columns 75 to 80 hold a running number that must not have gaps,
 * and every field sits where the layout says. Two consequences shape the code -
 * a value that does not fit is cut rather than shifting its neighbours, and the
 * ordinal number is folded back into the mask of the opening record.
 *
 * The bid (`.d84`) carries no item records at all: only ordinal number and
 * price travel back, exactly as in the X84.
 */
final class Gaeb90Generator {
    private const RECORD_LENGTH = 80;

    private const BODY_LENGTH = 74;

    /** Default mask: two levels of two digits, four for the item, one index. */
    public const DEFAULT_MASK = '1122PPPPI';

    private const QUANTITY_SCALE = 3;

    private const PRICE_SCALE = 2;

    public function generate(GaebBoq $boq, GaebPhase $phase, string $mask = self::DEFAULT_MASK): string {
        $bodies = [$this->opening($phase, $mask, $boq->getProjectName() ?? '')];

        if ($phase->carriesTexts()) {
            $bodies = array_merge($bodies, $this->documentBodies($boq, $mask));
        }

        // Preise reisen in jeder preisführenden Phase mit - im Auftrag neben den
        // Texten, in der Angebotsabgabe als einziger Inhalt.
        if ($phase->carriesPrices()) {
            foreach ($boq->getItems() as $item) {
                if ($item->getType() !== GaebItemType::Note) {
                    $bodies[] = $this->priceRecord($item, $mask);
                }
            }
        }

        $bodies[] = $this->closing($this->countBillableItems($boq));

        return $this->assemble($bodies);
    }

    /**
     * Groups, items and their texts.
     *
     * @return list<string>
     */
    private function documentBodies(GaebBoq $boq, string $mask): array {
        $bodies = [];

        foreach ($boq->getSections() as $section) {
            $bodies[] = '11' . $this->ordinal($section->getReference(), $mask) . 'N';
            if ($section->getLabel() !== null) {
                $bodies[] = '12' . $section->getLabel();
            }
        }

        foreach ($boq->getItems() as $item) {
            if ($item->getType() === GaebItemType::Note) {
                continue;
            }

            // Die Positionsarten folgen unmittelbar auf die Ordnungszahl; ein
            // Zeichen zu viel verschiebt Menge und Einheit aus ihren Spalten.
            $bodies[] = '21' . $this->ordinal($item->getReference(), $mask) . 'NNN'
                . str_repeat(' ', 9)
                . $this->fixed($item->getQuantity(), 11, self::QUANTITY_SCALE)
                . str_pad(substr((string) $item->getUnit(), 0, 4), 4);

            if ($item->getShortText() !== null) {
                $bodies[] = '25' . $item->getShortText();
            }
            foreach ($this->longTextLines($item) as $line) {
                $bodies[] = '26' . $line;
            }
        }

        return $bodies;
    }

    /**
     * Price record: ordinal number, unit price with its tenth of a cent, total.
     * A position without a price keeps its record - in GAEB 90 that is how "not
     * offered" travels, and dropping it loses the position for the recipient.
     */
    private function priceRecord(GaebItem $item, string $mask): string {
        $unitPrice = $item->getUnitPrice();
        $total = $item->getTotalPrice();

        return '23' . $this->ordinal($item->getReference(), $mask) . ' '
            . $this->price($unitPrice)
            . ' '
            . $this->fixed($total?->getAmount(), 12, self::PRICE_SCALE);
    }

    /**
     * Unit price across eleven columns: ten for the price itself, one for the
     * tenth of a cent that GAEB 90 keeps separate.
     */
    private function price(?Money $price): string {
        if ($price === null) {
            return str_repeat(' ', 11);
        }

        $amount = $price->getAmount();
        $tenth = ' ';
        if (str_contains($amount, '.')) {
            [$whole, $fraction] = explode('.', $amount, 2);
            $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
            $tenth = $fraction[2] === '0' ? ' ' : $fraction[2];
            $amount = $whole . '.' . substr($fraction, 0, 2);
        }

        return $this->fixed($amount, 10, self::PRICE_SCALE) . $tenth;
    }

    private function opening(GaebPhase $phase, string $mask, string $project): string {
        $body = str_pad('00', 10) . str_pad($phase->value, 2) . 'L' . substr($project, 0, 49);

        return str_pad(substr($body, 0, 62), 62) . $mask . '90';
    }

    /** Closing record; its last columns state how many items the file holds. */
    private function closing(int $items): string {
        return str_pad('99', 68) . str_pad((string) $items, 6, '0', STR_PAD_LEFT);
    }

    private function countBillableItems(GaebBoq $boq): int {
        $count = 0;
        foreach ($boq->getItems() as $item) {
            if ($item->getType() !== GaebItemType::Note) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Long text split into what fits a record.
     *
     * @return list<string>
     */
    private function longTextLines(GaebItem $item): array {
        $text = $item->getLongText();
        if ($text === null) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            foreach (str_split($line, self::BODY_LENGTH - 2) as $chunk) {
                $lines[] = $chunk;
            }
        }

        return $lines;
    }

    /**
     * Ordinal number folded back into the mask: every level gets the width the
     * mask gives it, right aligned, so that reading the file again yields the
     * same number.
     */
    private function ordinal(string $reference, string $mask): string {
        $groups = [];
        $previous = null;
        foreach (str_split($mask) as $marker) {
            if ($marker !== $previous) {
                $groups[] = 0;
                $previous = $marker;
            }
            $groups[count($groups) - 1]++;
        }

        $parts = explode('.', $reference);
        $out = '';
        foreach ($groups as $index => $width) {
            $part = array_key_exists($index, $parts) ? $parts[$index] : '';
            $out .= str_pad(substr($part, 0, $width), $width, ' ', STR_PAD_LEFT);
        }

        return str_pad(substr($out, 0, strlen($mask)), strlen($mask));
    }

    /** Fixed point field: digits only, right aligned, zero padded. */
    private function fixed(?string $value, int $length, int $scale): string {
        if ($value === null || !is_numeric($value)) {
            return str_repeat(' ', $length);
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = str_contains($value, '.') ? explode('.', $value, 2) : [$value, ''];
        $digits = $whole . str_pad(substr($fraction, 0, $scale), $scale, '0');
        $digits = ltrim($digits, '0');

        return str_pad(substr($negative ? '-' . $digits : $digits, -$length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Records to file: body padded to 74 characters, then the running number in
     * columns 75 to 80. The number has no gaps - readers use it to notice loss.
     *
     * @param  list<string>  $bodies
     */
    private function assemble(array $bodies): string {
        $out = '';
        foreach ($bodies as $index => $body) {
            $record = str_pad(substr($body, 0, self::BODY_LENGTH), self::BODY_LENGTH)
                . str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
            $out .= substr($record, 0, self::RECORD_LENGTH) . "\r\n";
        }

        return $out;
    }
}
