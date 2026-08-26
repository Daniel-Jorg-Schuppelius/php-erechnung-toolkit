<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmpHttpClientInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts;

use ERechnungToolkit\Peppol\Http\SmpResponse;
use RuntimeException;

/**
 * Transport-Naht für SMP-Abrufe.
 *
 * Das Toolkit bringt keinen eigenen HTTP-Stack mit: {@see \ERechnungToolkit\Peppol\SmpLookup}
 * bekommt seinen Client injiziert. Für PSR-18-Clients (Guzzle, Symfony
 * HttpClient, Laravel) genügt {@see \ERechnungToolkit\Peppol\Http\Psr18SmpClient};
 * Tests setzen ein einfaches Fake ein.
 */
interface SmpHttpClientInterface {
    /**
     * Führt einen GET-Abruf aus.
     *
     * Implementierungen folgen Weiterleitungen und werfen ausschließlich bei
     * Transportfehlern; HTTP-Fehlerstatus werden als {@see SmpResponse} gemeldet.
     *
     * @throws RuntimeException bei Transportfehlern (DNS, TLS, Timeout).
     */
    public function get(string $url): SmpResponse;
}
