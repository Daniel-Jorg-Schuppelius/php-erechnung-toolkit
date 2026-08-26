<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SmlZone.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * DNS-Zone der Peppol Service Metadata Locator (SML).
 *
 * Peppol migriert die Teilnehmerauflösung von CNAME (MD5-Hash, Zonen der
 * EU-Kommission) auf NAPTR/BDXL (SHA-256-Hash, Zonen unter peppol.org). Beide
 * Verfahren sind während der Migration parallel im Einsatz, deshalb sind die
 * Alt-Zonen weiterhin enthalten.
 *
 * @see https://docs.peppol.eu/edelivery/ Peppol eDelivery Spezifikationen
 */
enum SmlZone: string {
    /** Produktivzone (NAPTR/BDXL). */
    case PRODUCTION = 'participant.sml.prod.tech.peppol.org';

    /** Testzone (NAPTR/BDXL). */
    case TEST = 'participant.sml.test.tech.peppol.org';

    /** Produktivzone des CNAME-Verfahrens (Altbestand). */
    case LEGACY_PRODUCTION = 'edelivery.tech.ec.europa.eu';

    /** Testzone des CNAME-Verfahrens (Altbestand). */
    case LEGACY_TEST = 'acc.edelivery.tech.ec.europa.eu';

    /** Der DNS-Zonenname. */
    public function dnsZone(): string {
        return $this->value;
    }

    /**
     * Ob die Zone über NAPTR-Records aufgelöst wird (SHA-256-Hash statt MD5).
     */
    public function usesNaptr(): bool {
        return $this === self::PRODUCTION || $this === self::TEST;
    }

    /** Ob es sich um eine Produktivzone handelt. */
    public function isProduction(): bool {
        return $this === self::PRODUCTION || $this === self::LEGACY_PRODUCTION;
    }

    public function label(): string {
        return match ($this) {
            self::PRODUCTION => 'Peppol Produktion (NAPTR)',
            self::TEST => 'Peppol Test (NAPTR)',
            self::LEGACY_PRODUCTION => 'Peppol Produktion (CNAME, Altbestand)',
            self::LEGACY_TEST => 'Peppol Test (CNAME, Altbestand)',
        };
    }
}
