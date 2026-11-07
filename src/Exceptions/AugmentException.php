<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AugmentException.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Exceptions;

use ERechnungToolkit\Enums\AugmentFailure;
use RuntimeException;

/**
 * Eine vorhandene E-Rechnung lässt sich nicht verlustfrei um Unterlagen
 * ergänzen; {@see getReason()} nennt den Grund maschinenlesbar.
 */
final class AugmentException extends RuntimeException {
    public function __construct(private readonly AugmentFailure $reason, string $message) {
        parent::__construct($message);
    }

    public function getReason(): AugmentFailure {
        return $this->reason;
    }
}
