<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : TransportReceipt.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;
use ERechnungToolkit\Enums\PeppolTransportStatus;

/**
 * Zustellnachweis einer Peppol-Übertragung.
 *
 * Bildet die Antwort des Access Points auf einen Versand ab (AS4-Receipt bzw.
 * die Statusmeldung des Providers). Der Nachweis ist rein technisch: er belegt
 * die Übernahme durch den Empfänger-Access-Point, nicht die fachliche Annahme
 * der Rechnung.
 */
final class TransportReceipt {
    public function __construct(
        private readonly string $messageId,
        private readonly PeppolTransportStatus $status,
        private readonly DateTimeImmutable $timestamp,
        private readonly ?string $instanceIdentifier = null,
        private readonly ?string $receiverAccessPoint = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorMessage = null,
        private readonly ?string $rawReceipt = null,
    ) {}

    /**
     * Erfolgreicher Zustellnachweis.
     */
    public static function delivered(
        string $messageId,
        ?DateTimeImmutable $timestamp = null,
        ?string $instanceIdentifier = null,
        ?string $receiverAccessPoint = null,
        ?string $rawReceipt = null,
    ): self {
        return new self(
            $messageId,
            PeppolTransportStatus::DELIVERED,
            $timestamp ?? new DateTimeImmutable,
            $instanceIdentifier,
            $receiverAccessPoint,
            null,
            null,
            $rawReceipt
        );
    }

    /**
     * Fehlgeschlagene oder abgelehnte Zustellung.
     */
    public static function failed(
        string $messageId,
        string $errorMessage,
        ?string $errorCode = null,
        PeppolTransportStatus $status = PeppolTransportStatus::FAILED,
        ?DateTimeImmutable $timestamp = null,
        ?string $rawReceipt = null,
    ): self {
        return new self(
            $messageId,
            $status,
            $timestamp ?? new DateTimeImmutable,
            null,
            null,
            $errorCode,
            $errorMessage,
            $rawReceipt
        );
    }

    /** Nachrichtenkennung des Access Points (AS4 MessageId). */
    public function getMessageId(): string {
        return $this->messageId;
    }

    public function getStatus(): PeppolTransportStatus {
        return $this->status;
    }

    public function getTimestamp(): DateTimeImmutable {
        return $this->timestamp;
    }

    /** InstanceIdentifier des zugehörigen SBDH-Umschlags. */
    public function getInstanceIdentifier(): ?string {
        return $this->instanceIdentifier;
    }

    /** Zustelladresse des Empfänger-Access-Points (C3). */
    public function getReceiverAccessPoint(): ?string {
        return $this->receiverAccessPoint;
    }

    public function getErrorCode(): ?string {
        return $this->errorCode;
    }

    public function getErrorMessage(): ?string {
        return $this->errorMessage;
    }

    /** Unveränderte Providerantwort (AS4-Receipt/JSON) zur Archivierung. */
    public function getRawReceipt(): ?string {
        return $this->rawReceipt;
    }

    public function isSuccess(): bool {
        return $this->status->isSuccess();
    }

    public function isFinal(): bool {
        return $this->status->isFinal();
    }
}
