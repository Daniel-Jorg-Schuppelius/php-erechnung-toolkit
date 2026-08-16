<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebChangeOrderStatus.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Status of an addendum (GAEB `COStatus`, schema type tgCOStatus).
 *
 * The status travels with the item and outranks the status of the addendum as a
 * whole (GAEB DA XML 3.3, information on the addendum).
 */
enum GaebChangeOrderStatus: string {
    case Recognised = 'Recog';        // erkannt
    case Filed = 'Filed';             // angemeldet
    case Offered = 'Offered';         // angeboten
    case Withdrawn = 'Withdrawn';     // zurückgezogen
    case Rejected = 'Rejected';       // abgelehnt
    case ObjectedToRejection = 'ObjToRecj'; // Widerspruch zur Ablehnung
    case FormallyAcknowledged = 'FormAckn'; // sachlich anerkannt
    case Approved = 'Approved';       // genehmigt

    public function label(): string {
        return match ($this) {
            self::Recognised => 'erkannt',
            self::Filed => 'angemeldet',
            self::Offered => 'angeboten',
            self::Withdrawn => 'zurückgezogen',
            self::Rejected => 'abgelehnt',
            self::ObjectedToRejection => 'Widerspruch zur Ablehnung',
            self::FormallyAcknowledged => 'sachlich anerkannt',
            self::Approved => 'genehmigt',
        };
    }

    /** Is the addendum settled, i.e. neither pending nor negotiable any more? */
    public function isFinal(): bool {
        return match ($this) {
            self::Withdrawn, self::Rejected, self::Approved => true,
            default => false,
        };
    }
}
