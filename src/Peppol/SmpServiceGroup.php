<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpServiceGroup.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

/**
 * Service Group eines Teilnehmers: die im SMP registrierten Dokumenttypen.
 *
 * Ergebnis des SMP-Abrufs `/{participant}`. Damit lässt sich vor dem Versand
 * prüfen, ob der Empfänger den gewünschten Dokumenttyp (z.B. Peppol BIS
 * Billing 3.0 Rechnung) überhaupt empfangen kann.
 */
final class SmpServiceGroup {
    /**
     * @param list<DocumentTypeId> $documentTypeIds
     * @param list<string>         $references      Rohe ServiceMetadataReference-URLs
     */
    public function __construct(
        private readonly ParticipantId $participantId,
        private readonly array $documentTypeIds,
        private readonly array $references = [],
        private readonly ?string $rawXml = null,
    ) {}

    public function getParticipantId(): ParticipantId {
        return $this->participantId;
    }

    /** @return list<DocumentTypeId> */
    public function getDocumentTypeIds(): array {
        return $this->documentTypeIds;
    }

    /** @return list<string> */
    public function getReferences(): array {
        return $this->references;
    }

    /** Die unveränderte SMP-Antwort zur Archivierung. */
    public function getRawXml(): ?string {
        return $this->rawXml;
    }

    /**
     * Ob der Teilnehmer den Dokumenttyp empfangen kann.
     */
    public function supports(DocumentTypeId $documentTypeId): bool {
        foreach ($this->documentTypeIds as $candidate) {
            if ($candidate->equals($documentTypeId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ob der Teilnehmer eine Customization-ID unterstützt (unabhängig von
     * Wurzelelement und Syntaxversion), z.B. Peppol BIS Billing 3.0.
     */
    public function supportsCustomization(string $customizationId): bool {
        foreach ($this->documentTypeIds as $candidate) {
            if ($candidate->getCustomizationId() === $customizationId) {
                return true;
            }
        }

        return false;
    }
}
