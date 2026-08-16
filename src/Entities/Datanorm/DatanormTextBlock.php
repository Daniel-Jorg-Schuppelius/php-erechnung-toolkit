<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormTextBlock.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

/**
 * DATANORM text block (T-record; DATANORM 4 insert-text blocks arrive as
 * E-records and share this entity with `USAGE_INSERT`).
 *
 * Insert-text blocks contain `$`-placeholders that are filled per article from
 * the dimension record (D-record).
 */
final class DatanormTextBlock {
    public const USAGE_UNBOUND = 0;
    public const USAGE_LONGTEXT = 1;
    public const USAGE_INSERT = 2;

    /** @param array<int, string> $lines line number → text */
    public function __construct(
        private readonly string $number,
        private readonly int $usage,
        private array $lines = []
    ) {}

    public function getNumber(): string {
        return $this->number;
    }

    public function getUsage(): int {
        return $this->usage;
    }

    public function addLine(int $lineNumber, string $text): void {
        $this->lines[$lineNumber] = $text;
    }

    /** @return array<int, string> line number → text, sorted by line number */
    public function getLines(): array {
        $lines = $this->lines;
        ksort($lines);

        return $lines;
    }

    /** All lines joined with newlines, trailing whitespace per line removed. */
    public function getText(): string {
        return rtrim(implode("\n", array_map(rtrim(...), $this->getLines())));
    }

    /**
     * Fills the `$`-placeholders of an insert-text block with the given
     * inserts (already split, in order). Placeholder runs shorter than the
     * insert truncate it; longer runs are padded with blanks; surplus
     * placeholders collapse to spaces, surplus inserts are ignored.
     *
     * @param  list<string>  $inserts
     */
    public function fillInserts(string $line, array $inserts): string {
        $index = 0;

        return (string) preg_replace_callback('/\$+/', function (array $match) use (&$index, $inserts): string {
            $width = strlen($match[0]);
            $insert = $inserts[$index] ?? '';
            $index++;

            return str_pad(substr($insert, 0, $width), $width);
        }, $line);
    }
}
