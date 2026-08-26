<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpServiceMetadata.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;

/**
 * Service-Metadaten eines Teilnehmers zu genau einem Dokumenttyp.
 *
 * Ergebnis des SMP-Abrufs `/{participant}/services/{documentType}`: die
 * Endpunkte je Prozess samt Transportprofil, Zustelladresse und Zertifikat.
 */
final class SmpServiceMetadata {
    /**
     * @param list<SmpEndpoint> $endpoints
     */
    public function __construct(
        private readonly ParticipantId $participantId,
        private readonly DocumentTypeId $documentTypeId,
        private readonly array $endpoints,
        private readonly bool $signed = false,
        private readonly ?string $rawXml = null,
    ) {}

    public function getParticipantId(): ParticipantId {
        return $this->participantId;
    }

    public function getDocumentTypeId(): DocumentTypeId {
        return $this->documentTypeId;
    }

    /** @return list<SmpEndpoint> */
    public function getEndpoints(): array {
        return $this->endpoints;
    }

    /** Ob die Antwort als `SignedServiceMetadata` geliefert wurde. */
    public function isSigned(): bool {
        return $this->signed;
    }

    /** Die unveränderte SMP-Antwort zur Archivierung. */
    public function getRawXml(): ?string {
        return $this->rawXml;
    }

    /**
     * Liefert den passenden Endpunkt für Prozess und Transportprofil.
     *
     * Ohne Prozessangabe gilt der erste gültige Endpunkt des Profils.
     */
    public function findEndpoint(
        ?string $processId = null,
        string $transportProfile = SmpEndpoint::TRANSPORT_AS4,
        ?DateTimeImmutable $moment = null,
    ): ?SmpEndpoint {
        foreach ($this->endpoints as $endpoint) {
            if ($endpoint->getTransportProfile() !== $transportProfile) {
                continue;
            }

            if ($processId !== null && $endpoint->getProcessId() !== $processId) {
                continue;
            }

            if (!$endpoint->isActiveAt($moment)) {
                continue;
            }

            return $endpoint;
        }

        return null;
    }

    /**
     * Alle im SMP hinterlegten Prozesskennungen.
     *
     * @return list<string>
     */
    public function getProcessIds(): array {
        $ids = [];
        foreach ($this->endpoints as $endpoint) {
            $ids[$endpoint->getProcessId()] = true;
        }

        return array_keys($ids);
    }
}
