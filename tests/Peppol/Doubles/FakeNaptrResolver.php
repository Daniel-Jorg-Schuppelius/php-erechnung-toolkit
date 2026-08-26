<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : FakeNaptrResolver.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Peppol\Doubles;

use ERechnungToolkit\Contracts\DnsNaptrResolverInterface;

/**
 * NAPTR-Auflösung für Tests: liefert hinterlegte SMP-URLs je DNS-Name.
 */
final class FakeNaptrResolver implements DnsNaptrResolverInterface {
    /** @var list<string> */
    public array $lookups = [];

    /**
     * @param array<string, list<string>> $zone DNS-Name => SMP-URLs
     */
    public function __construct(private readonly array $zone = []) {}

    public function resolveSmpUrls(string $dnsName): array {
        $this->lookups[] = $dnsName;

        return $this->zone[$dnsName] ?? [];
    }
}
