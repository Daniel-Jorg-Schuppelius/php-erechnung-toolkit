<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpResponse.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol\Http;

/**
 * Antwort eines SMP-Abrufs (Statuscode + Rumpf).
 *
 * Bewusst minimal: die Transport-Naht zum SMP braucht nur den Statuscode und
 * das XML. So bleibt {@see \ERechnungToolkit\Contracts\SmpHttpClientInterface}
 * ohne PSR-7-Bindung testbar.
 */
final class SmpResponse {
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
    ) {}

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function isSuccessful(): bool {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** Der Teilnehmer bzw. Dokumenttyp ist im SMP nicht registriert. */
    public function isNotFound(): bool {
        return $this->statusCode === 404;
    }
}
