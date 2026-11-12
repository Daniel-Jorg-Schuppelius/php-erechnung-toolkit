<?php
/*
 * Created on   : Thu Nov 12 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : LeitwegIdFailure.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Regel der Leitweg-ID-Formatspezifikation 2.0.2, die ein Wert verletzt
 * ({@see \ERechnungToolkit\Exceptions\InvalidLeitwegIdException::getReason()}).
 * Die Werte sind stabil, damit Aufrufer eigene Texte daran hängen können.
 */
enum LeitwegIdFailure: string {
    /** Nicht Grobadressierung[-Feinadressierung]-Prüfziffer aus Ziffern, A–Z und Bindestrichen. */
    case FORM = 'form';

    /** Grobadressierung nicht 2, 3, 5, 8, 9 oder 12 Ziffern (Kap. 2.1, Tabelle 1). */
    case COARSE_ADDRESS_LENGTH = 'coarse_address_length';

    /** Grobadressierung beginnt nicht mit der Kennzahl eines Landes (01–16) oder des Bundes (99). */
    case STATE_CODE = 'state_code';

    /** Feinadressierung länger als 30 Zeichen oder, bei checkDigitsFor(), mit anderen Zeichen als A–Z und 0–9. */
    case FINE_ADDRESS = 'fine_address';

    /** Prüfziffer stimmt nicht; die Ausnahme nennt gegebene und erwartete. */
    case CHECK_DIGITS = 'check_digits';
}
