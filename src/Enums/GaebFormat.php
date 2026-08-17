<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebFormat.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * One of the three GAEB families. Award offices still hand out all of them, so
 * a reader has to recognise which one it holds before it can do anything else.
 */
enum GaebFormat: string {
    case Gaeb90 = 'gaeb90';     // .d81 … .d86, fixed 80 character records
    case Gaeb2000 = 'gaeb2000'; // .p81 … .p86, keyword syntax with RTF texts
    case DaXml = 'daxml';       // .x31, .x80 … .x89B, XML against the official schemas
    case Unknown = 'unknown';

    /** File extension prefix of the family (`x`, `p`, `d`). */
    public function extensionPrefix(): ?string {
        return match ($this) {
            self::Gaeb90 => 'd',
            self::Gaeb2000 => 'p',
            self::DaXml => 'x',
            self::Unknown => null,
        };
    }

    /** Can this family be read? GAEB 2000 is read but not yet written. */
    public function isSupported(): bool {
        return $this !== self::Unknown;
    }

    /** Can files of this family be written? */
    public function isWritable(): bool {
        return $this !== self::Unknown;
    }
}
