<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AccessPointClientInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts;

use ERechnungToolkit\Peppol\{InboundDocument, TransportReceipt};
use RuntimeException;

/**
 * Anbindung an einen zertifizierten Peppol Access Point (Provider).
 *
 * Das Toolkit betreibt **keinen** eigenen AS4-Access-Point: PKI, Zertifizierung
 * und Betrieb bleiben beim Provider. Es liefert die fachneutralen Bausteine
 * (Kennungen, SBDH-Umschlag, SML/SMP-Auflösung, BIS-Prüfung) und diese
 * Schnittstelle als Naht; die konkrete Provider-Implementierung entsteht in der
 * Anwendung (Zugangsdaten, Endpunkte, Fehlerbehandlung).
 *
 * Erwartetes Zusammenspiel:
 * 1. Dokument erzeugen und mit {@see \ERechnungToolkit\Peppol\BisValidator} prüfen;
 * 2. Empfänger über {@see \ERechnungToolkit\Peppol\SmpLookup} auflösen;
 * 3. {@see \ERechnungToolkit\Peppol\Sbdh::envelope()} bauen und hier senden;
 * 4. Eingang über {@see receive()} abholen und mit {@see acknowledge()} quittieren.
 */
interface AccessPointClientInterface {
    /**
     * Prüft, ob Zugangsdaten und Erreichbarkeit des Providers vorliegen.
     */
    public function isAvailable(): bool;

    /**
     * Übergibt einen SBDH-Umschlag zur Zustellung.
     *
     * @param string $sbdhEnvelopeXml Umschlag aus {@see \ERechnungToolkit\Peppol\Sbdh::envelope()}.
     *
     * @throws RuntimeException bei Transport- oder Authentifizierungsfehlern.
     */
    public function send(string $sbdhEnvelopeXml): TransportReceipt;

    /**
     * Holt noch nicht quittierte Eingänge ab.
     *
     * @param int $limit Höchstzahl der Dokumente je Abruf.
     *
     * @return list<InboundDocument>
     *
     * @throws RuntimeException bei Transport- oder Authentifizierungsfehlern.
     */
    public function receive(int $limit = 50): array;

    /**
     * Quittiert ein abgeholtes Dokument, damit es nicht erneut geliefert wird.
     *
     * @return bool true, wenn der Provider die Quittung angenommen hat.
     */
    public function acknowledge(string $messageId): bool;
}
