<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ValidationResult.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use ERechnungToolkit\Enums\ValidationSeverity;

/**
 * Ergebnis einer E-Rechnung-Validierung.
 *
 * Bündelt das Konformitätsurteil (`valid`), die Weiterverarbeitungs-
 * empfehlung (`accept`/`reject`) und die einzelnen Regelverstöße aus dem
 * KoSIT-Validator-Report.
 */
final class ValidationResult {
    /**
     * @param ValidationMessage[] $messages
     */
    public function __construct(
        private readonly bool $valid,
        private readonly bool $accepted,
        private readonly array $messages = [],
        private readonly ?string $scenarioName = null,
        private readonly ?string $rawReport = null,
    ) {}

    /**
     * True, wenn das Dokument alle verbindlichen Regeln erfüllt
     * (Schema + Schematron, je nach Szenario).
     */
    public function isValid(): bool {
        return $this->valid;
    }

    /**
     * Empfehlung des Validators zur Weiterverarbeitung (accept).
     * Kann trotz `isValid() === false` true sein, wenn die Konfiguration
     * bestimmte Verstöße toleriert.
     */
    public function isAccepted(): bool {
        return $this->accepted;
    }

    /** Name des angewandten Validierungsszenarios (z.B. "EN16931 XRechnung (UBL Invoice)"). */
    public function getScenarioName(): ?string {
        return $this->scenarioName;
    }

    /** @return ValidationMessage[] */
    public function getMessages(): array {
        return $this->messages;
    }

    /** @return ValidationMessage[] */
    public function getBySeverity(ValidationSeverity $severity): array {
        return array_values(array_filter(
            $this->messages,
            static fn (ValidationMessage $m): bool => $m->getSeverity() === $severity
        ));
    }

    /** @return ValidationMessage[] */
    public function getErrors(): array {
        return $this->getBySeverity(ValidationSeverity::ERROR);
    }

    /** @return ValidationMessage[] */
    public function getWarnings(): array {
        return $this->getBySeverity(ValidationSeverity::WARNING);
    }

    public function hasErrors(): bool {
        return $this->getErrors() !== [];
    }

    /** Roher KoSIT-Report (XML) zur Archivierung/Weiterverarbeitung. */
    public function getRawReport(): ?string {
        return $this->rawReport;
    }
}
