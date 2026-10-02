<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsConnectAction.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Aktionscodes der IDS-Connect-Schnittstelle (Parameter `action`).
 */
enum IdsConnectAction: string {
    /** Warenkorb empfangen: Handwerkssoftware übernimmt den Warenkorb aus dem Shop. */
    case ReceiveCart = 'WKE';
    /** Warenkorb senden: Handwerkssoftware übergibt einen Warenkorb an den Shop. */
    case SendCart = 'WKS';
    /** Artikeldeeplink: Artikelseite zur Großhandelsartikelnummer öffnen. */
    case ArticleDeepLink = 'ADL';

    /** Die Aktion verlangt eine Rücksprungadresse (`hookurl`). */
    public function needsHookUrl(): bool {
        return $this !== self::ArticleDeepLink;
    }
}
