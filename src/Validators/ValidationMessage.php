<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ValidationMessage.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use ERechnungToolkit\Enums\ValidationSeverity;

/**
 * Einzelne Meldung aus einem Validierungslauf.
 *
 * Entspricht einem `rep:message`-Element des KoSIT-Reports und damit
 * einer ausgelösten EN16931- bzw. XRechnung-Schematron-Regel.
 */
final class ValidationMessage {
    public function __construct(
        private readonly ValidationSeverity $severity,
        private readonly string $code,
        private readonly string $text,
        private readonly ?string $location = null,
        private readonly ?string $stepId = null,
    ) {}

    public function getSeverity(): ValidationSeverity {
        return $this->severity;
    }

    /** Regel-ID, z.B. "BR-DE-1", "EN16931-CII-SR-12". */
    public function getCode(): string {
        return $this->code;
    }

    /** Lesbarer Meldungstext. */
    public function getText(): string {
        return $this->text;
    }

    /** XPath-Position des Verstoßes im Dokument (falls vorhanden). */
    public function getLocation(): ?string {
        return $this->location;
    }

    /** ID des Validierungsschritts (val-xsd, val-sch.1, ...). */
    public function getStepId(): ?string {
        return $this->stepId;
    }

    public function isError(): bool {
        return $this->severity === ValidationSeverity::ERROR;
    }

    public function __toString(): string {
        $code = $this->code !== '' ? "[{$this->code}] " : '';
        return "{$this->severity->label()}: {$code}{$this->text}";
    }
}
