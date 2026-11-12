<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DocumentRulesValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use CommonToolkit\Helper\FileSystem\File;
use ERechnungToolkit\Entities\{Document, LeitwegId, Period, SupportingDocument};
use ERechnungToolkit\Enums\{AttachmentMimeCode, ERechnungProfile, ValidationSeverity};
use InvalidArgumentException;

/**
 * Prüft rechnungsbegründende Unterlagen (BG-24), Zeiträume (BG-14, BG-26) und
 * Objektkennungen (BT-18, BT-128) am Datenmodell, bevor XML entsteht, dazu die
 * Leitweg-ID eines Käufers mit Schema 0204 (BT-49, BT-10).
 *
 * Codes folgen dem KoSIT-Regelwerk (BR-52, BR-CL-24, BR-DE-22, BR-29, BR-30,
 * BR-CO-19, BR-CO-20, PEPPOL-EN16931-R110/R111); TK-* sind Prüfungen des
 * Toolkits. BR-DE-22 vergleicht ohne Rücksicht auf Groß-/Kleinschreibung, wie
 * es der Regeltext verlangt — der KoSIT-Validator vergleicht exakt. Die
 * Leitweg-ID prüft der KoSIT-Validator gar nicht; TK-LEITWEG-ID ist daher eine Warnung.
 */
final class DocumentRulesValidator {
    public const SCENARIO = 'EN 16931 Unterlagen, Zeiträume und Objektkennungen (Toolkit)';

    private const MAX_FILENAME_LENGTH = 255;

    public function validate(Document $document): ValidationResult {
        return $this->result([
            ...$this->checkProfile($document),
            ...$this->checkSupportingDocuments($document->getSupportingDocuments(), $document->getProfile()),
            ...$this->checkPeriods($document),
            ...$this->checkLeitwegId($document),
        ]);
    }

    /**
     * Nur die Unterlagenregeln (BR-52, BR-CL-24, BR-DE-22, TK-ATT-*), etwa für
     * Unterlagen, die eine vorhandene Rechnung ergänzen sollen.
     *
     * @param array<int, SupportingDocument> $documents
     */
    public function validateSupportingDocuments(array $documents, ERechnungProfile $profile): ValidationResult {
        return $this->result($this->checkSupportingDocuments($documents, $profile));
    }

    /**
     * @param list<ValidationMessage> $messages
     */
    private function result(array $messages): ValidationResult {
        $valid = array_filter($messages, static fn (ValidationMessage $m): bool => $m->isError()) === [];

        return new ValidationResult($valid, $valid, $messages, self::SCENARIO);
    }

    /**
     * @return list<ValidationMessage>
     */
    private function checkProfile(Document $document): array {
        $profile = $document->getProfile();
        $messages = [];

        $hasLineObjects = array_filter($document->getLines(), static fn ($line): bool => $line->getObjectIdentifier() !== null) !== [];
        if (!$profile->supportsReferencedDocuments()
            && ($document->getSupportingDocuments() !== [] || $document->getInvoicedObjectIdentifier() !== null || $hasLineObjects)) {
            $messages[] = $this->error(
                'TK-ATT-PROFILE',
                "Das Profil {$profile->label()} kennt keine Unterlagen (BG-24) und Objektkennungen (BT-18, BT-128); nötig ist mindestens EN 16931."
            );
        }

        if (!$profile->supportsInvoicingPeriod() && $document->getInvoicingPeriod() !== null) {
            $messages[] = $this->warning('TK-PERIOD-PROFILE', "Das Profil {$profile->label()} kennt keinen Rechnungszeitraum (BG-14); er entfällt in der XML.");
        }

        $hasLinePeriods = array_filter($document->getLines(), static fn ($line): bool => $line->getPeriod() !== null) !== [];
        if (!$profile->supportsLinePeriod() && $hasLinePeriods) {
            $messages[] = $this->warning('TK-PERIOD-PROFILE', "Das Profil {$profile->label()} kennt keinen Positionszeitraum (BG-26); er entfällt in der XML.");
        }

        return $messages;
    }

    /**
     * @param array<int, SupportingDocument> $documents
     * @return list<ValidationMessage>
     */
    private function checkSupportingDocuments(array $documents, ERechnungProfile $profile): array {
        $messages = [];
        $filenames = [];
        $allowed = AttachmentMimeCode::allowedFor($profile);

        foreach ($documents as $index => $supportingDocument) {
            $label = $this->label($supportingDocument, $index);

            if (trim($supportingDocument->getReference()) === '') {
                $messages[] = $this->error('BR-52', "Unterlage {$label}: Jede Unterlage braucht eine Kennung (BT-122).");
            }

            if ($supportingDocument->getExternalUri() !== null) {
                $messages[] = new ValidationMessage(
                    ValidationSeverity::INFORMATION,
                    'TK-ATT-LINK',
                    "Unterlage {$label}: Ein Link (BT-124) ersetzt steuerlich keinen eingebetteten Anhang (BMF 15.10.2025 Rn. 35)."
                );
            }

            if (!$supportingDocument->isEmbedded()) {
                continue;
            }

            if ($supportingDocument->getContent() === '') {
                $messages[] = $this->error('TK-ATT-EMPTY', "Unterlage {$label}: Der eingebettete Anhang ist leer.");
            }

            $mime = $supportingDocument->getMimeCodeEnum();
            if ($supportingDocument->getMimeCode() === null) {
                $messages[] = $this->error('TK-ATT-MIME', "Unterlage {$label}: Der MIME-Code des Anhangs fehlt (BT-125-1).");
            } elseif ($mime === null || !in_array($mime, $allowed, true)) {
                $messages[] = $this->error(
                    'BR-CL-24',
                    "Unterlage {$label}: MIME-Code {$supportingDocument->getMimeCode()} ist nicht zulässig; erlaubt: "
                        . implode(', ', array_map(static fn (AttachmentMimeCode $c): string => $c->value, $allowed)) . '.'
                );
            }

            $messages = [...$messages, ...$this->checkFilename($supportingDocument, $label, $mime, $filenames)];
            $messages = [...$messages, ...$this->checkContent($supportingDocument, $label, $mime)];
        }

        return $messages;
    }

    /**
     * @param array<string, string> $filenames bereits vergebene Namen (Kleinschreibung => Original)
     * @return list<ValidationMessage>
     */
    private function checkFilename(SupportingDocument $document, string $label, ?AttachmentMimeCode $mime, array &$filenames): array {
        $filename = $document->getFilename();
        if ($filename === null || trim($filename) === '') {
            return [$this->error('TK-ATT-FILENAME', "Unterlage {$label}: Der Dateiname des Anhangs fehlt (BT-125-2).")];
        }

        $messages = [];
        if (preg_match('#[/\\\\\x00-\x1F\x7F]#', $filename) === 1 || mb_strlen($filename) > self::MAX_FILENAME_LENGTH) {
            $messages[] = $this->error(
                'TK-ATT-FILENAME',
                "Unterlage {$label}: Der Dateiname \"{$filename}\" enthält Pfadanteile oder Steuerzeichen oder ist länger als " . self::MAX_FILENAME_LENGTH . ' Zeichen.'
            );
        }

        $key = mb_strtolower($filename);
        if (isset($filenames[$key])) {
            $messages[] = $this->error(
                'BR-DE-22',
                "Unterlage {$label}: Der Dateiname \"{$filename}\" kommt schon vor (\"{$filenames[$key]}\"); Dateinamen müssen ohne Rücksicht auf die Schreibweise eindeutig sein."
            );
        } else {
            $filenames[$key] = $filename;
        }

        if ($mime !== null && AttachmentMimeCode::fromExtension(pathinfo($filename, PATHINFO_EXTENSION)) !== $mime) {
            $messages[] = $this->warning('TK-ATT-EXTENSION', "Unterlage {$label}: Die Endung von \"{$filename}\" passt nicht zum MIME-Code {$mime->value}.");
        }

        return $messages;
    }

    /**
     * @return list<ValidationMessage>
     */
    private function checkContent(SupportingDocument $document, string $label, ?AttachmentMimeCode $mime): array {
        $content = $document->getContent();
        if ($mime === null || $content === null || $content === '') {
            return [];
        }

        $detected = File::mimeTypeFromContent($content);
        if ($detected === false || $mime->acceptsDetected($detected)) {
            return [];
        }

        return [$this->warning('TK-ATT-CONTENT', "Unterlage {$label}: Der Inhalt sieht nach {$detected} aus, angegeben ist {$mime->value}.")];
    }

    /**
     * @return list<ValidationMessage>
     */
    private function checkPeriods(Document $document): array {
        $messages = [];
        $invoicingPeriod = $document->getInvoicingPeriod();

        if ($invoicingPeriod !== null) {
            if ($invoicingPeriod->isEmpty()) {
                $messages[] = $this->error('BR-CO-19', 'Der Rechnungszeitraum (BG-14) braucht ein Start- oder Enddatum (BT-73/BT-74).');
            } elseif (!$invoicingPeriod->isOrdered()) {
                $messages[] = $this->error('BR-29', 'Das Ende des Rechnungszeitraums (BT-74) liegt vor dem Beginn (BT-73).');
            }
        }

        foreach ($document->getLines() as $line) {
            $period = $line->getPeriod();
            if ($period === null) {
                continue;
            }
            $lineLabel = "Position {$line->getId()}";
            if ($period->isEmpty()) {
                $messages[] = $this->error('BR-CO-20', "{$lineLabel}: Der Positionszeitraum (BG-26) braucht ein Start- oder Enddatum (BT-134/BT-135).");
                continue;
            }
            if (!$period->isOrdered()) {
                $messages[] = $this->error('BR-30', "{$lineLabel}: Das Ende des Positionszeitraums (BT-135) liegt vor dem Beginn (BT-134).");
            }
            if ($invoicingPeriod !== null && !$invoicingPeriod->isEmpty()) {
                $messages = [...$messages, ...$this->checkWithin($invoicingPeriod, $period, $lineLabel)];
            }
        }

        return $messages;
    }

    /**
     * Elektronische Adresse (BT-49) und Käuferreferenz (BT-10) eines Käufers mit
     * Schema 0204 nach der Leitweg-ID-Formatspezifikation 2.0.2 ({@see LeitwegId}).
     *
     * @return list<ValidationMessage>
     */
    private function checkLeitwegId(Document $document): array {
        $buyer = $document->getBuyer();
        if ($buyer->getEndpointScheme() !== LeitwegId::ICD) {
            return [];
        }

        $values = [];
        foreach (['BT-49' => $buyer->getEndpointId(), 'BT-10' => $document->getBuyerReference()] as $term => $value) {
            if ($value !== null && $value !== '') {
                $values[$value][] = $term;
            }
        }

        $messages = [];
        foreach ($values as $value => $terms) {
            try {
                LeitwegId::of((string) $value);
            } catch (InvalidArgumentException $e) {
                $messages[] = $this->warning('TK-LEITWEG-ID', 'Keine gültige Leitweg-ID (' . implode(', ', $terms) . '): ' . $e->getMessage());
            }
        }

        return $messages;
    }

    /**
     * @return list<ValidationMessage>
     */
    private function checkWithin(Period $outer, Period $inner, string $lineLabel): array {
        $messages = [];
        if ($inner->getStart() !== null && !$outer->contains(new Period($inner->getStart()))) {
            $messages[] = $this->error('PEPPOL-EN16931-R110', "{$lineLabel}: Der Beginn des Positionszeitraums liegt außerhalb des Rechnungszeitraums.");
        }
        if ($inner->getEnd() !== null && !$outer->contains(new Period(null, $inner->getEnd()))) {
            $messages[] = $this->error('PEPPOL-EN16931-R111', "{$lineLabel}: Das Ende des Positionszeitraums liegt außerhalb des Rechnungszeitraums.");
        }

        return $messages;
    }

    private function label(SupportingDocument $document, int $index): string {
        $reference = trim($document->getReference());

        return $reference !== '' ? "\"{$reference}\"" : '#' . ($index + 1);
    }

    private function error(string $code, string $text): ValidationMessage {
        return new ValidationMessage(ValidationSeverity::ERROR, $code, $text);
    }

    private function warning(string $code, string $text): ValidationMessage {
        return new ValidationMessage(ValidationSeverity::WARNING, $code, $text);
    }
}
