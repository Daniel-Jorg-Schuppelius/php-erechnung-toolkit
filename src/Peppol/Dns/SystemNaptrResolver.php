<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SystemNaptrResolver.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol\Dns;

use ERechnungToolkit\Contracts\DnsNaptrResolverInterface;
use ERRORToolkit\Traits\ErrorLog;

/**
 * NAPTR-Auflösung über den DNS-Resolver des Systems (dns_get_record).
 *
 * Peppol veröffentlicht den SMP-Standort als NAPTR-Record mit dem Service
 * `Meta:SMP`; die Ziel-URL steht im Ersetzungsteil des regulären Ausdrucks
 * (`!^.*$!https://smp.example.org!`). Ausgewertet wird ausschließlich diese
 * Form der Ersetzung - eine echte Regex-Anwendung sieht BDXL für Peppol nicht vor.
 *
 * Diese Implementierung greift auf das Netz zu; in Tests wird stattdessen ein
 * Fake auf {@see DnsNaptrResolverInterface} gesetzt.
 */
final class SystemNaptrResolver implements DnsNaptrResolverInterface {
    use ErrorLog;

    /** Service-Feld des NAPTR-Records, das den SMP ausweist. */
    public const SMP_SERVICE = 'Meta:SMP';

    public function resolveSmpUrls(string $dnsName): array {
        $records = @dns_get_record($dnsName, DNS_NAPTR);
        if ($records === false || $records === []) {
            $this->logDebug("Keine NAPTR-Records für $dnsName gefunden.");

            return [];
        }

        $candidates = [];
        foreach ($records as $record) {
            $service = isset($record['services']) && is_string($record['services']) ? $record['services'] : '';
            if (strcasecmp($service, self::SMP_SERVICE) !== 0) {
                continue;
            }

            $regex = isset($record['regex']) && is_string($record['regex']) ? $record['regex'] : '';
            $url = self::replacementOf($regex);
            if ($url === null) {
                continue;
            }

            $order = isset($record['order']) && is_int($record['order']) ? $record['order'] : 0;
            $preference = isset($record['pref']) && is_int($record['pref']) ? $record['pref'] : 0;
            $candidates[] = ['order' => $order, 'pref' => $preference, 'url' => $url];
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['order'], $a['pref']] <=> [$b['order'], $b['pref']]);

        return array_map(static fn (array $candidate): string => $candidate['url'], $candidates);
    }

    /**
     * Liest den Ersetzungsteil aus einem NAPTR-Regexp-Feld `!muster!ersetzung!`.
     */
    public static function replacementOf(string $regex): ?string {
        if (strlen($regex) < 3) {
            return null;
        }

        $delimiter = $regex[0];
        $parts = explode($delimiter, $regex);
        // ['', muster, ersetzung, flags]
        if (count($parts) < 3) {
            return null;
        }

        $replacement = trim($parts[2]);

        return $replacement === '' ? null : $replacement;
    }
}
