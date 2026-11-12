<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InvalidLeitwegIdException.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Exceptions;

use ERechnungToolkit\Enums\LeitwegIdFailure;
use InvalidArgumentException;

/**
 * Keine gültige Leitweg-ID ({@see \ERechnungToolkit\Entities\LeitwegId}).
 * Die Meldung ist deutsch; {@see getReason()} nennt die verletzte Regel
 * maschinenlesbar, damit Aufrufer sie in ihrer Sprache melden können.
 * Erbt von InvalidArgumentException, damit bestehende catch-Blöcke greifen.
 */
final class InvalidLeitwegIdException extends InvalidArgumentException {
    public function __construct(
        private readonly LeitwegIdFailure $reason,
        string $message,
        private readonly ?string $givenCheckDigits = null,
        private readonly ?string $expectedCheckDigits = null,
    ) {
        parent::__construct($message);
    }

    public function getReason(): LeitwegIdFailure {
        return $this->reason;
    }

    /** Nur bei {@see LeitwegIdFailure::CHECK_DIGITS}. */
    public function getGivenCheckDigits(): ?string {
        return $this->givenCheckDigits;
    }

    /** Nur bei {@see LeitwegIdFailure::CHECK_DIGITS}. */
    public function getExpectedCheckDigits(): ?string {
        return $this->expectedCheckDigits;
    }
}
