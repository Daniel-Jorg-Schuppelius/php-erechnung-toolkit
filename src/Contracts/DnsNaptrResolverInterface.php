<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DnsNaptrResolverInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts;

/**
 * Auflösung des SMP-Standorts über NAPTR-Records (BDXL).
 *
 * Zweite Naht neben {@see SmpHttpClientInterface}: die DNS-Abfrage bleibt
 * injizierbar, damit {@see \ERechnungToolkit\Peppol\SmpLookup} ohne Netzzugriff
 * testbar ist.
 */
interface DnsNaptrResolverInterface {
    /**
     * Liefert die SMP-Basis-URLs zum DNS-Namen eines Teilnehmers.
     *
     * @return list<string> Leere Liste, wenn der Teilnehmer nicht registriert ist.
     */
    public function resolveSmpUrls(string $dnsName): array;
}
