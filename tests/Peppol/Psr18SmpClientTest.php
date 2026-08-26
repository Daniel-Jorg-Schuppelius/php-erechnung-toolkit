<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Psr18SmpClientTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol;

use ERechnungToolkit\Peppol\Http\Psr18SmpClient;
use Exception;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\{ClientExceptionInterface, ClientInterface};
use Psr\Http\Message\{RequestInterface, ResponseInterface};
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * PSR-18-Adapter der SMP-Transportnaht - ohne echten HTTP-Verkehr.
 */
class Psr18SmpClientTest extends BaseTestCase {
    public function test_sends_a_get_request_with_xml_accept_header(): void {
        $factory = new Psr17Factory;
        $client = new class($factory) implements ClientInterface {
            public ?RequestInterface $lastRequest = null;

            public function __construct(private readonly Psr17Factory $factory) {}

            public function sendRequest(RequestInterface $request): ResponseInterface {
                $this->lastRequest = $request;

                return $this->factory->createResponse(200)
                    ->withBody($this->factory->createStream('<ServiceGroup/>'));
            }
        };

        $response = (new Psr18SmpClient($client, $factory, 'workdiary-test'))->get('https://smp.example.org/participant');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<ServiceGroup/>', $response->getBody());
        $this->assertTrue($response->isSuccessful());
        $this->assertInstanceOf(RequestInterface::class, $client->lastRequest);
        $this->assertSame('GET', $client->lastRequest->getMethod());
        $this->assertSame('https://smp.example.org/participant', (string) $client->lastRequest->getUri());
        $this->assertSame('application/xml, text/xml', $client->lastRequest->getHeaderLine('Accept'));
        $this->assertSame('workdiary-test', $client->lastRequest->getHeaderLine('User-Agent'));
    }

    public function test_http_error_status_is_returned_not_thrown(): void {
        $factory = new Psr17Factory;
        $client = new class($factory) implements ClientInterface {
            public function __construct(private readonly Psr17Factory $factory) {}

            public function sendRequest(RequestInterface $request): ResponseInterface {
                return $this->factory->createResponse(404);
            }
        };

        $response = (new Psr18SmpClient($client, $factory))->get('https://smp.example.org/unknown');

        $this->assertTrue($response->isNotFound());
        $this->assertFalse($response->isSuccessful());
    }

    public function test_transport_errors_become_runtime_exceptions(): void {
        $factory = new Psr17Factory;
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface {
                throw new class('DNS-Auflösung fehlgeschlagen') extends Exception implements ClientExceptionInterface {};
            }
        };

        $this->expectException(RuntimeException::class);
        (new Psr18SmpClient($client, $factory))->get('https://smp.invalid/participant');
    }
}
