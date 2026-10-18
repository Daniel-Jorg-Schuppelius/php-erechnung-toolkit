<?php
/*
 * Created on   : Sat Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UnsupportedFormatException.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Exceptions;

use RuntimeException;

/**
 * Wohlgeformtes XML, dessen Wurzelelement keinem unterstützten
 * E-Rechnungs-Format entspricht (UBL, CII D16B, ZUGFeRD 1.0).
 *
 * Eingangskanäle behandeln den Fall als „keine E-Rechnung“, nicht als Fehler.
 */
final class UnsupportedFormatException extends RuntimeException {
    public static function forRoot(?string $namespace, ?string $localName): self {
        return new self(sprintf(
            'Unknown E-Rechnung format (root <%s> in namespace %s). Expected UBL or CII.',
            $localName ?? '?',
            $namespace ?? '-'
        ));
    }
}
