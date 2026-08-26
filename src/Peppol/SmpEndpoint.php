<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpEndpoint.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DateTimeImmutable;

/**
 * Ein im SMP registrierter Empfangs-Endpunkt (Access Point des Empfängers).
 *
 * Enthält Transportprofil, Zustelladresse, Gültigkeitszeitraum und das
 * AP-Zertifikat (Base64-DER ohne PEM-Rahmen), mit dem die AS4-Nachricht
 * verschlüsselt wird.
 */
final class SmpEndpoint {
    /** Aktuelles Peppol-AS4-Transportprofil. */
    public const TRANSPORT_AS4 = 'peppol-transport-as4-v2_0';

    public function __construct(
        private readonly string $processId,
        private readonly string $processScheme,
        private readonly string $transportProfile,
        private readonly string $url,
        private readonly ?string $certificate = null,
        private readonly ?DateTimeImmutable $activationDate = null,
        private readonly ?DateTimeImmutable $expirationDate = null,
        private readonly ?string $serviceDescription = null,
        private readonly ?string $technicalContactUrl = null,
        private readonly bool $requiresBusinessLevelSignature = false,
    ) {}

    public function getProcessId(): string {
        return $this->processId;
    }

    public function getProcessScheme(): string {
        return $this->processScheme;
    }

    public function getTransportProfile(): string {
        return $this->transportProfile;
    }

    /** Zustelladresse des Empfänger-Access-Points. */
    public function getUrl(): string {
        return $this->url;
    }

    /** AP-Zertifikat als Base64-DER, wie im SMP hinterlegt. */
    public function getCertificate(): ?string {
        return $this->certificate;
    }

    public function getActivationDate(): ?DateTimeImmutable {
        return $this->activationDate;
    }

    public function getExpirationDate(): ?DateTimeImmutable {
        return $this->expirationDate;
    }

    public function getServiceDescription(): ?string {
        return $this->serviceDescription;
    }

    public function getTechnicalContactUrl(): ?string {
        return $this->technicalContactUrl;
    }

    public function requiresBusinessLevelSignature(): bool {
        return $this->requiresBusinessLevelSignature;
    }

    /** Ob der Endpunkt das aktuelle Peppol-AS4-Profil nutzt. */
    public function isAs4(): bool {
        return $this->transportProfile === self::TRANSPORT_AS4;
    }

    /**
     * Ob der Endpunkt zum Stichtag gültig ist (Aktivierung/Ablauf aus dem SMP).
     */
    public function isActiveAt(?DateTimeImmutable $moment = null): bool {
        $moment ??= new DateTimeImmutable;

        if ($this->activationDate !== null && $moment < $this->activationDate) {
            return false;
        }

        return $this->expirationDate === null || $moment <= $this->expirationDate;
    }

    /** Das Zertifikat als PEM-Block. */
    public function getCertificatePem(): ?string {
        if ($this->certificate === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $this->certificate) ?? '';
        if ($normalized === '') {
            return null;
        }

        return "-----BEGIN CERTIFICATE-----\n"
            . trim(chunk_split($normalized, 64, "\n"))
            . "\n-----END CERTIFICATE-----\n";
    }

    /**
     * Eckdaten des AP-Zertifikats (Subject, Aussteller, Gültigkeit, Seriennummer).
     *
     * Benötigt ext-openssl; ohne die Extension oder bei unlesbarem Zertifikat
     * wird null geliefert.
     *
     * @return array{subject: string, issuer: string, serialNumber: string, validFrom: DateTimeImmutable|null, validTo: DateTimeImmutable|null}|null
     */
    public function getCertificateInfo(): ?array {
        $pem = $this->getCertificatePem();
        if ($pem === null || !function_exists('openssl_x509_parse')) {
            return null;
        }

        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            return null;
        }

        return [
            'subject' => is_array($parsed['subject'] ?? null) ? self::distinguishedName($parsed['subject']) : '',
            'issuer' => is_array($parsed['issuer'] ?? null) ? self::distinguishedName($parsed['issuer']) : '',
            'serialNumber' => isset($parsed['serialNumber']) && is_scalar($parsed['serialNumber']) ? (string) $parsed['serialNumber'] : '',
            'validFrom' => self::timestamp($parsed['validFrom_time_t'] ?? null),
            'validTo' => self::timestamp($parsed['validTo_time_t'] ?? null),
        ];
    }

    /**
     * @param array<array-key, mixed> $parts
     */
    private static function distinguishedName(array $parts): string {
        $segments = [];
        foreach ($parts as $key => $value) {
            $flat = is_array($value) ? implode('+', array_map(static fn ($item): string => is_scalar($item) ? (string) $item : '', $value)) : (is_scalar($value) ? (string) $value : '');
            $segments[] = ((string) $key) . '=' . $flat;
        }

        return implode(', ', $segments);
    }

    private static function timestamp(mixed $value): ?DateTimeImmutable {
        if (!is_int($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('U', (string) $value);

        return $date === false ? null : $date;
    }
}
