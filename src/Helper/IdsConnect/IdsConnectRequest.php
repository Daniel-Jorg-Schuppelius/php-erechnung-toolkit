<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsConnectRequest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\IdsConnect;

use ERechnungToolkit\Enums\IdsConnectAction;
use InvalidArgumentException;

/**
 * Formularfelder für den Absprung in einen IDS-Connect-Shop. Die Parameter
 * gehen als POST (`multipart/form-data`) an die Shop-Adresse, die der
 * Großhändler bekannt gibt; das Formular baut die Anwendung.
 */
final class IdsConnectRequest {
    /** Länge der Rücksprungadresse laut Spezifikation (STRING 256). */
    public const HOOK_URL_MAX = 256;

    /**
     * @return array<string, string>
     *
     * @throws InvalidArgumentException fehlende Pflichtangaben je Aktion oder zu lange Rücksprungadresse
     */
    public static function formFields(
        IdsConnectAction $action,
        ?string $hookUrl = null,
        ?string $customerNumber = null,
        ?string $userName = null,
        ?string $password = null,
        ?string $cartXml = null,
        ?string $articleNumber = null,
        ?string $version = null,
    ): array {
        if ($action->needsHookUrl() && ($hookUrl === null || $hookUrl === '')) {
            throw new InvalidArgumentException('IDS-Aktion ' . $action->value . ' braucht eine Rücksprungadresse.');
        }
        if ($hookUrl !== null && strlen($hookUrl) > self::HOOK_URL_MAX) {
            throw new InvalidArgumentException('IDS-Rücksprungadresse ist länger als ' . self::HOOK_URL_MAX . ' Zeichen.');
        }
        if ($action === IdsConnectAction::SendCart && ($cartXml === null || $cartXml === '')) {
            throw new InvalidArgumentException('IDS-Aktion WKS braucht einen Warenkorb.');
        }
        if ($action === IdsConnectAction::ArticleDeepLink && ($articleNumber === null || $articleNumber === '')) {
            throw new InvalidArgumentException('IDS-Aktion ADL braucht eine Großhandelsartikelnummer.');
        }

        return array_filter([
            'kndnr' => $customerNumber,
            'name_kunde' => $userName,
            'pw_kunde' => $password,
            'action' => $action->value,
            'hookurl' => $action->needsHookUrl() ? $hookUrl : null,
            'warenkorb' => $action === IdsConnectAction::SendCart ? $cartXml : null,
            'ghnummer' => $action === IdsConnectAction::ArticleDeepLink ? $articleNumber : null,
            'version' => $version,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
