<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormCharset.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper;

use ERechnungToolkit\Enums\DatanormVersion;

/**
 * DATANORM character handling: codepage 850 with a restricted allowed set.
 *
 * Allowed are TAB/CR/LF, 0x20–0x5A, 0x5E–0x7A, the German umlauts and ß; the
 * characters `°`, `²`, `³` only in DATANORM 5. Brackets, braces, pipe, tilde
 * and backslash are outside the table and get deterministic replacements —
 * deliberately no `iconv(…//TRANSLIT)`, whose output depends on LC_CTYPE.
 *
 * The semicolon is the field separator and cannot be escaped; it is replaced
 * with a comma when encoding field content.
 */
final class DatanormCharset {
    private const CODEPAGE = 'CP850';

    /** Deterministic replacements for characters outside the DATANORM table. */
    private const REPLACEMENTS = [
        ';' => ',',
        '[' => '(',
        ']' => ')',
        '{' => '(',
        '}' => ')',
        '|' => '/',
        '~' => '-',
        '\\' => '/',
        "\u{2013}" => '-', // – en dash
        "\u{2014}" => '-', // — em dash
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{201A}" => "'",
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{201E}" => '"',
        "\u{2026}" => '...',
        "\u{20AC}" => 'EUR', // € is not part of the DATANORM table
        "\u{00B4}" => "'",
        "\u{00A0}" => ' ',
    ];

    /** Additional replacements for DATANORM 4, where °/²/³ are not allowed. */
    private const V4_REPLACEMENTS = [
        "\u{00B0}" => ' Grad',
        "\u{00B2}" => '2',
        "\u{00B3}" => '3',
    ];

    private function __construct() {
        // Static helper.
    }

    /** Decodes a single CP850 field value to UTF-8. */
    public static function decode(string $value): string {
        $decoded = @iconv(self::CODEPAGE, 'UTF-8', $value);

        return $decoded !== false ? $decoded : $value;
    }

    /**
     * Encodes a UTF-8 field value to the DATANORM codepage.
     *
     * Applies the deterministic replacement table, then converts to CP850;
     * any remaining unmappable character becomes `?`.
     */
    public static function encode(string $value, DatanormVersion $version = DatanormVersion::V5): string {
        $value = strtr($value, self::REPLACEMENTS);
        if ($version === DatanormVersion::V4) {
            $value = strtr($value, self::V4_REPLACEMENTS);
        }

        $encoded = @iconv('UTF-8', self::CODEPAGE, $value);
        if ($encoded !== false) {
            return $encoded;
        }

        // Fall back to a per-character conversion so unmappable characters
        // degrade to `?` instead of truncating the remainder of the field.
        $result = '';
        $length = mb_strlen($value, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1, 'UTF-8');
            $charEncoded = @iconv('UTF-8', self::CODEPAGE, $char);
            $result .= $charEncoded === false || $charEncoded === '' ? '?' : $charEncoded;
        }

        return $result;
    }
}
