<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Gaeb2000Generator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem};
use ERechnungToolkit\Enums\{GaebItemType, GaebPhase};

/**
 * Writer for the GAEB 2000 family, counterpart of {@see \ERechnungToolkit\Parsers\Gaeb2000Parser}.
 *
 * The format is a keyword syntax: objects are framed by `#begin[Name]` and
 * `#end[Name]`, fields written as `[Tag]value[end]`, and nesting carries the
 * structure. Three things have to be restored on the way out:
 *
 * - the ordinal number goes back **unsplit** (`01.01.0001` becomes `01010001`),
 *   while the level widths are declared once in the header as `LVGlied`;
 * - groups nest inside each other, so a group closes only after its children;
 * - long texts return as RTF, because that is what a reader of this family
 *   expects - plain text in that field would be shown with its control words
 *   missing rather than as text.
 *
 * Field names follow what the published sample file uses (`OZ`, `Menge`, `ME`,
 * `Kurztext`, `Langtext`). Prices are written as `EP` and `GB`, the names GAEB
 * 90 uses for them; the reader takes the same ones, so a round trip is stable
 * either way.
 */
final class Gaeb2000Generator {
    /** Level widths of the ordinal number, matching the DA XML default. */
    private const DEFAULT_LEVELS = [2, 2, 4];

    /** @param list<int> $levels widths of the ordinal number levels */
    public function generate(GaebBoq $boq, GaebPhase $phase, array $levels = self::DEFAULT_LEVELS, ?string $date = null): string {
        $out = "#begin[GAEB]\r\n";
        $out .= $this->gaebInfo($date);
        $out .= $this->projectInfo($boq);

        $out .= " #begin[Vergabe]\r\n";
        $out .= $this->field('DP', $phase->value, 2);
        $out .= "  #begin[LV]\r\n";
        $out .= $this->lvInfo($boq, $levels);
        $out .= $this->body($boq, $levels);
        $out .= "  #end[LV]\r\n";
        $out .= " #end[Vergabe]\r\n";
        $out .= "#end[GAEB]\r\n";

        return $out;
    }

    private function gaebInfo(?string $date): string {
        return " #begin[GAEBInfo]\r\n"
            . $this->field('Version', '1.1', 2)
            . $this->field('VersMon', '11', 2)
            . $this->field('VersJahr', '2000', 2)
            . $this->field('Datum', $date ?? '01.01.2026', 2)
            . $this->field('Zeichensatz', 'ANSI', 2)
            . $this->field('ProgSystem', 'php-erechnung-toolkit', 2)
            . " #end[GAEBInfo]\r\n";
    }

    private function projectInfo(GaebBoq $boq): string {
        return " #begin[PrjInfo]\r\n"
            . $this->field('Name', $boq->getProjectName() ?? '', 2)
            . $this->field('Wae', $boq->getCurrency()->value, 2)
            . " #end[PrjInfo]\r\n";
    }

    /** @param list<int> $levels */
    private function lvInfo(GaebBoq $boq, array $levels): string {
        $out = "   #begin[LVInfo]\r\n"
            . $this->field('Name', $boq->getProjectName() ?? '', 4);

        foreach ($levels as $index => $length) {
            $out .= "    #begin[LVGlied]\r\n"
                . $this->field('Typ', $index === count($levels) - 1 ? 'Position' : 'LVStufe', 5)
                . $this->field('Laenge', (string) $length, 5)
                . "    #end[LVGlied]\r\n";
        }

        return $out . "   #end[LVInfo]\r\n";
    }

    /**
     * Groups with their children. A group is written around everything below
     * it, so the nesting of the file mirrors the hierarchy.
     *
     * @param list<int> $levels
     */
    private function body(GaebBoq $boq, array $levels, ?string $parent = null, int $depth = 3): string {
        $out = '';
        $indent = str_repeat(' ', $depth);

        foreach ($boq->getSections() as $section) {
            if ($section->getParentReference() !== $parent) {
                continue;
            }

            $out .= "{$indent}#begin[LVBereich]\r\n"
                . $this->field('OZ', $this->ordinal($section->getReference()), $depth + 1)
                . $this->field('Bez', $section->getLabel() ?? '', $depth + 1)
                . $this->body($boq, $levels, $section->getReference(), $depth + 1)
                . "{$indent}#end[LVBereich]\r\n";
        }

        foreach ($boq->getItems() as $item) {
            if ($item->getSectionReference() !== $parent || $item->getType() === GaebItemType::Note) {
                continue;
            }
            $out .= $this->item($item, $depth);
        }

        return $out;
    }

    private function item(GaebItem $item, int $depth): string {
        $indent = str_repeat(' ', $depth);
        $inner = $depth + 1;

        $out = "{$indent}#begin[Position]\r\n"
            . $this->field('OZ', $this->ordinal($item->getReference()), $inner);

        if ($item->getQuantity() !== null) {
            $out .= $this->field('Menge', $item->getQuantity(), $inner);
        }
        if ($item->getUnit() !== null) {
            $out .= $this->field('ME', $item->getUnit(), $inner);
        }
        if ($item->getUnitPrice() !== null) {
            $out .= $this->field('EP', $item->getUnitPrice()->getAmount(), $inner);
        }
        if ($item->getTotalPrice() !== null) {
            $out .= $this->field('GB', $item->getTotalPrice()->getAmount(), $inner);
        }

        if ($item->getShortText() !== null || $item->getLongText() !== null) {
            $out .= str_repeat(' ', $inner) . "#begin[Beschreibung]\r\n";
            if ($item->getShortText() !== null) {
                $out .= $this->field('Kurztext', $item->getShortText(), $inner + 1);
            }
            if ($item->getLongText() !== null) {
                $out .= $this->rtfField($item->getLongText(), $inner + 1);
            }
            $out .= str_repeat(' ', $inner) . "#end[Beschreibung]\r\n";
        }

        return $out . "{$indent}#end[Position]\r\n";
    }

    /** Ordinal number without its separators - that is how the format carries it. */
    private function ordinal(string $reference): string {
        return str_replace('.', '', $reference);
    }

    private function field(string $tag, string $value, int $depth): string {
        return str_repeat(' ', $depth) . "[{$tag}]{$value}[end]\r\n";
    }

    /**
     * Long text as RTF. Only what the text needs: a header, the paragraphs and
     * the escapes for braces and backslashes.
     */
    private function rtfField(string $text, int $depth): string {
        $escaped = str_replace(['\\', '{', '}'], ['\\\\', '\\{', '\\}'], $text);
        $paragraphs = implode("\\par\r\n", preg_split('/\r\n|\r|\n/', $escaped) ?: [$escaped]);

        return str_repeat(' ', $depth) . '[Langtext]{\rtf1{\fonttbl{\f0\fswiss Arial ;}}' . "\r\n"
            . $paragraphs . "\\par}\r\n"
            . str_repeat(' ', $depth) . "[end]\r\n";
    }
}
