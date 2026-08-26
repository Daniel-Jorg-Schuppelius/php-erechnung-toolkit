<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Psr18SmpClient.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol\Http;

use ERechnungToolkit\Contracts\SmpHttpClientInterface;
use ERRORToolkit\Traits\ErrorLog;
use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;

/**
 * SMP-Abruf über einen beliebigen PSR-18-Client.
 *
 * Der HTTP-Stack bleibt Sache der Anwendung (Guzzle, Symfony HttpClient,
 * Laravel); das Toolkit kennt nur die PSR-Schnittstellen.
 *
 * Beispiel:
 * ```php
 * $client = new Psr18SmpClient(new GuzzleHttp\Client(), new GuzzleHttp\Psr7\HttpFactory());
 * $lookup = new SmpLookup($client);
 * ```
 */
final class Psr18SmpClient implements SmpHttpClientInterface {
    use ErrorLog;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $userAgent = 'php-erechnung-toolkit',
    ) {}

    public function get(string $url): SmpResponse {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept', 'application/xml, text/xml')
            ->withHeader('User-Agent', $this->userAgent);

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            $this->logError("SMP-Abruf fehlgeschlagen: $url ({$exception->getMessage()})");
            throw new RuntimeException("SMP-Abruf fehlgeschlagen: $url", 0, $exception);
        }

        return new SmpResponse($response->getStatusCode(), (string) $response->getBody());
    }
}
