<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb2000Parser.php
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
 * Reader for the GAEB 2000 family (`.p81` … `.p86`).
 *
 * Unlike GAEB 90 this format is not a grid but a keyword syntax: objects open
 * with `#begin[Name]` and close with `#end[Name]`, fields are written as
 * `[Tag]value[end]`. Nesting carries the structure, so groups (`LVBereich`) can
 * contain groups, and the ordinal number of a position arrives unsplit - how it
 * divides into levels is declared once in the header (`LVGlied`).
 *
 * Long texts are RTF. They are reduced to plain text here: the format is a
 * transport detail, and keeping the control words would put markup into a field
 * that everything downstream treats as text.
 */
final class Gaeb2000Parser {
    /** Prices keep a tenth of a cent, like everywhere else in GAEB. */
    private const MONEY_SCALE = 4;

    public function parse(string $content, ?CurrencyCode $currency = null): GaebBoq {
        if (!str_contains($content, '#begin[')) {
            throw new InvalidArgumentException('File is not a valid GAEB 2000 document.');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $levels = $this->levels($content);
        $documentCurrency = $currency ?? $this->currency($content);

        $sections = [];
        $items = [];
        $counters = ['section' => 0, 'item' => 0];

        /** @var list<string> $stack open objects */
        $stack = [];
        /** @var list<string> $groupPath ordinal numbers of the open groups */
        $groupPath = [];
        /** @var array<string, string> $fields fields of the object currently open */
        $fields = [];
        $longText = null;
        $collecting = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // A long text runs across records until its closing [end].
            if ($collecting !== null) {
                if (str_ends_with($trimmed, '[end]')) {
                    $collecting .= "\n" . substr($trimmed, 0, -5);
                    $longText = $this->plainText($collecting);
                    $collecting = null;

                    continue;
                }
                $collecting .= "\n" . $line;

                continue;
            }

            if (str_starts_with($trimmed, '#begin[')) {
                $name = substr($trimmed, 7, strpos($trimmed, ']', 7) - 7);
                $stack[] = $name;
                if ($name === 'Position' || $name === 'LVBereich') {
                    $fields = [];
                    $longText = null;
                }

                continue;
            }

            if (str_starts_with($trimmed, '#end[')) {
                $name = substr($trimmed, 5, strpos($trimmed, ']', 5) - 5);
                array_pop($stack);

                if ($name === 'LVBereich') {
                    $reference = $this->reference($fields['OZ'] ?? '', $levels);
                    array_pop($groupPath);
                    $fields = [];

                    continue;
                }

                if ($name === 'Position') {
                    $reference = $this->reference($fields['OZ'] ?? '', $levels);
                    $items[] = new GaebItem(
                        reference: $reference,
                        sectionReference: $groupPath === [] ? null : $groupPath[count($groupPath) - 1],
                        type: GaebItemType::Standard,
                        shortText: $fields['Kurztext'] ?? $fields['Bez'] ?? null,
                        longText: $longText,
                        quantity: $this->number($fields['Menge'] ?? null),
                        unit: $fields['ME'] ?? null,
                        unitPrice: $this->money($fields['EP'] ?? null, $documentCurrency),
                        totalPrice: $this->money($fields['GB'] ?? null, $documentCurrency),
                        position: $counters['item']++,
                    );
                    $fields = [];
                    $longText = null;
                }

                continue;
            }

            if (!str_starts_with($trimmed, '[')) {
                continue;
            }

            $close = strpos($trimmed, ']');
            if ($close === false) {
                continue;
            }
            $tag = substr($trimmed, 1, $close - 1);
            $value = substr($trimmed, $close + 1);

            if (!str_ends_with($value, '[end]')) {
                // Opens a value that continues on the following records.
                $collecting = $value;

                continue;
            }
            $value = substr($value, 0, -5);

            $fields[$tag] = $value;
            if ($tag === 'Langtext') {
                $longText = $this->plainText($value);
            }

            // A group is complete once its ordinal number and label are read;
            // its children need it on the stack before they arrive.
            $current = $stack === [] ? null : $stack[count($stack) - 1];
            if ($current === 'LVBereich' && $tag === 'Bez' && isset($fields['OZ'])) {
                $reference = $this->reference($fields['OZ'], $levels);
                $sections[] = new GaebSection(
                    reference: $reference,
                    parentReference: $groupPath === [] ? null : $groupPath[count($groupPath) - 1],
                    label: $value,
                    position: $counters['section']++,
                );
                $groupPath[] = $reference;
                $fields = [];
            }
        }

        return new GaebBoq(
            phaseCode: $this->field($content, 'DP'),
            projectName: $this->field($content, 'Name'),
            sections: $sections,
            items: $items,
            currency: $documentCurrency,
        );
    }

    /**
     * Widths of the ordinal number levels, declared once in the header
     * (`LVGlied` with `Laenge`). Without them the number cannot be split.
     *
     * @return list<int>
     */
    private function levels(string $content): array {
        $levels = [];
        if (preg_match_all('/\[Laenge\](\d+)\[end\]/', $content, $matches) >= 1) {
            foreach ($matches[1] as $length) {
                $levels[] = (int) $length;
            }
        }

        return $levels;
    }

    /** @param list<int> $levels */
    private function reference(string $raw, array $levels): string {
        $raw = trim($raw);
        if ($raw === '' || $levels === []) {
            return $raw;
        }

        $parts = [];
        $offset = 0;
        foreach ($levels as $length) {
            if ($offset >= strlen($raw)) {
                break;
            }
            $parts[] = substr($raw, $offset, $length);
            $offset += $length;
        }
        if ($offset < strlen($raw)) {
            $parts[] = substr($raw, $offset);
        }

        return implode('.', array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    private function currency(string $content): CurrencyCode {
        $code = $this->field($content, 'Wae');

        return $code === null ? CurrencyCode::Euro : (CurrencyCode::tryFrom(strtoupper($code)) ?? CurrencyCode::Euro);
    }

    private function field(string $content, string $tag): ?string {
        return preg_match('/\[' . preg_quote($tag, '/') . '\](.*?)\[end\]/s', $content, $matches) === 1
            ? trim($matches[1])
            : null;
    }

    private function number(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : null;
    }

    private function money(?string $value, CurrencyCode $currency): ?Money {
        $number = $this->number($value);

        return $number === null ? null : Money::of($number, $currency, self::MONEY_SCALE);
    }

    /**
     * RTF reduced to what it says. `\par` becomes a line break, control words
     * and groups fall away, escaped characters come back.
     */
    private function plainText(string $rtf): ?string {
        if (!str_contains($rtf, '\\rtf')) {
            $text = trim($rtf);

            return $text === '' ? null : $text;
        }

        $text = preg_replace('/\{\\\\(fonttbl|colortbl|stylesheet|info|\*)[^{}]*(\{[^{}]*\})*[^{}]*\}/', '', $rtf) ?? $rtf;
        $text = str_replace(['\\par', '\\line'], "\n", $text);
        $text = preg_replace_callback(
            "/\\\\'([0-9a-fA-F]{2})/",
            static fn (array $m): string => chr((int) hexdec($m[1])),
            $text
        ) ?? $text;
        $text = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', '', $text) ?? $text;
        $text = str_replace(['{', '}'], '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\n\s*\n+/', "\n", $text) ?? $text);

        return $text === '' ? null : $text;
    }
}
