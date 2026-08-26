<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PeppolTransportStatus.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Technischer Zustellstatus einer Peppol-Übertragung.
 *
 * Der Status beschreibt ausschließlich den Transport (AS4-Receipt des
 * Access Points), nicht die fachliche Annahme durch den Empfänger. Eine
 * fachliche Antwort kommt als Message Level Response (MLR) zurück.
 */
enum PeppolTransportStatus: string {
    /** Vom Access Point angenommen, Zustellung läuft. */
    case PENDING = 'pending';

    /** Zustellung mit Transport-Receipt bestätigt. */
    case DELIVERED = 'delivered';

    /** Zustellung endgültig fehlgeschlagen. */
    case FAILED = 'failed';

    /** Vom Empfänger-Access-Point abgelehnt (z.B. Dokumenttyp nicht unterstützt). */
    case REJECTED = 'rejected';

    public function isFinal(): bool {
        return $this !== self::PENDING;
    }

    public function isSuccess(): bool {
        return $this === self::DELIVERED;
    }

    public function label(): string {
        return match ($this) {
            self::PENDING => 'in Zustellung',
            self::DELIVERED => 'zugestellt',
            self::FAILED => 'fehlgeschlagen',
            self::REJECTED => 'abgelehnt',
        };
    }
}
