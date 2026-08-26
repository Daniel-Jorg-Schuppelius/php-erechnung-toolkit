<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : FakeSmpHttpClient.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol\Doubles;

use ERechnungToolkit\Contracts\SmpHttpClientInterface;
use ERechnungToolkit\Peppol\Http\SmpResponse;

/**
 * SMP-Client für Tests: liefert hinterlegte Antworten, ohne das Netz zu berühren.
 */
final class FakeSmpHttpClient implements SmpHttpClientInterface {
    /** @var list<string> */
    public array $requestedUrls = [];

    /**
     * @param array<string, SmpResponse> $responses URL => Antwort
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly SmpResponse $fallback = new SmpResponse(404, ''),
    ) {}

    public function get(string $url): SmpResponse {
        $this->requestedUrls[] = $url;

        return $this->responses[$url] ?? $this->fallback;
    }
}
