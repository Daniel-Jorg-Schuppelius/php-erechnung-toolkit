<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormVersion.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DATANORM file format version.
 *
 * The value is the version marker as it appears in the header record:
 * DATANORM 4 carries `04` at fixed position 124-125 of its 128-char header,
 * DATANORM 5 carries `050` as the second semicolon-separated header field.
 */
enum DatanormVersion: string {
    case V4 = '04';
    case V5 = '050';

    public function label(): string {
        return match ($this) {
            self::V4 => 'DATANORM 4',
            self::V5 => 'DATANORM 5',
        };
    }
}
