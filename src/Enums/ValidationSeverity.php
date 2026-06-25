<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ValidationSeverity.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Schweregrad einer Validierungsmeldung.
 *
 * Spiegelt die `level`-Attribute des KoSIT-Validator-Reports (VARL)
 * bzw. der EN16931/XRechnung-Schematron-Regeln.
 */
enum ValidationSeverity: string {
    /** Verletzung einer verbindlichen Regel - führt zu Ablehnung. */
    case ERROR = 'error';

    /** Hinweis auf eine Regel, die zukünftig verschärft werden kann. */
    case WARNING = 'warning';

    /** Reine Information, ohne Auswirkung auf die Konformität. */
    case INFORMATION = 'information';

    /**
     * Liberale Zuordnung aus dem Report-Level.
     *
     * Unbekannte oder leere Level werden als INFORMATION behandelt,
     * damit ein abweichendes Vokabular die Auswertung nicht bricht.
     */
    public static function fromLevel(?string $level): self {
        return match (strtolower(trim((string) $level))) {
            'error', 'fatal' => self::ERROR,
            'warning', 'warn' => self::WARNING,
            default => self::INFORMATION,
        };
    }

    public function label(): string {
        return match ($this) {
            self::ERROR => 'Fehler',
            self::WARNING => 'Warnung',
            self::INFORMATION => 'Information',
        };
    }
}
