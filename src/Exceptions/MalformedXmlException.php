<?php
/*
 * Created on   : Sun Oct 18 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : MalformedXmlException.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Exceptions;

use LibXMLError;
use RuntimeException;

/**
 * Eingabe, die libxml nicht als XML lädt (Binärdaten, Text, abgeschnittene Datei).
 *
 * Eingangskanäle bekommen auch PDFs oder Text unter XML-Dateinamen — der Parser
 * protokolliert keinen Fehler, der Aufrufer entscheidet über den Schweregrad.
 */
final class MalformedXmlException extends RuntimeException {
    /** @param array<int, LibXMLError> $errors Ergebnis von libxml_get_errors() */
    public static function fromLibxml(array $errors): self {
        $message = 'Failed to parse XML';
        if (isset($errors[0])) {
            $message .= ': ' . trim($errors[0]->message);
        }

        return new self($message);
    }
}
