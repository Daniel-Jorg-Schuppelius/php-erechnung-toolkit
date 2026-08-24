<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatVersion.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * BMEcat-Formatversion.
 *
 * Der Wert entspricht dem `version`-Attribut des BMECAT-Wurzelelements.
 * BMEcat 2005 wird zusätzlich am Dokument-Namespace
 * (`http://www.bmecat.org/bmecat/2005…`) erkannt — dort heißen die
 * Artikelelemente PRODUCT/SUPPLIER_PID statt ARTICLE/SUPPLIER_AID.
 */
enum BmecatVersion: string {
    case V12 = '1.2';
    case V2005 = '2005';

    public function label(): string {
        return match ($this) {
            self::V12 => 'BMEcat 1.2',
            self::V2005 => 'BMEcat 2005',
        };
    }
}
