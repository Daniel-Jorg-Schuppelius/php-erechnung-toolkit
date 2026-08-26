<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InboundDocument.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;

/**
 * Über Peppol empfangenes Dokument samt Umschlag.
 *
 * Ergebnis von {@see \ERechnungToolkit\Contracts\AccessPointClientInterface::receive()}:
 * der vollständige SBDH-Umschlag, aus dem Kopfdaten und fachliche Nutzlast bei
 * Bedarf gelesen werden.
 */
final class InboundDocument {
    private ?Sbdh $sbdh = null;

    public function __construct(
        private readonly string $messageId,
        private readonly string $envelopeXml,
        private readonly DateTimeImmutable $receivedAt = new DateTimeImmutable,
        private readonly ?string $rawMetadata = null,
    ) {}

    /** Nachrichtenkennung des Access Points. */
    public function getMessageId(): string {
        return $this->messageId;
    }

    /** Der unveränderte SBDH-Umschlag (Original für die Aufbewahrung). */
    public function getEnvelopeXml(): string {
        return $this->envelopeXml;
    }

    public function getReceivedAt(): DateTimeImmutable {
        return $this->receivedAt;
    }

    /** Zusätzliche Providerdaten (Rohantwort) zur Archivierung. */
    public function getRawMetadata(): ?string {
        return $this->rawMetadata;
    }

    /** Die Kopfdaten des Umschlags (einmalig geparst). */
    public function getSbdh(): Sbdh {
        return $this->sbdh ??= Sbdh::parse($this->envelopeXml);
    }

    /** Das fachliche Dokument (UBL-XML) ohne Umschlag. */
    public function getPayloadXml(): string {
        return Sbdh::payloadOf($this->envelopeXml);
    }

    public function getSender(): ParticipantId {
        return $this->getSbdh()->getSender();
    }

    public function getReceiver(): ParticipantId {
        return $this->getSbdh()->getReceiver();
    }

    public function getDocumentTypeId(): DocumentTypeId {
        return $this->getSbdh()->getDocumentTypeId();
    }
}
