<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ValidatorInterface.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Contracts;

use ERechnungToolkit\Validators\ValidationResult;

/**
 * Validiert E-Rechnungen (UBL/CII) gegen EN16931- und XRechnung-Regeln.
 */
interface ValidatorInterface {
    /**
     * Prüft, ob die für die Validierung nötigen Abhängigkeiten verfügbar sind
     * (z.B. Java-Laufzeit, Validator-Jar, Konfiguration).
     */
    public function isAvailable(): bool;

    /**
     * Validiert ein E-Rechnung-XML.
     *
     * @param string $xml UBL- oder CII-XML der Rechnung
     */
    public function validate(string $xml): ValidationResult;

    /**
     * Validiert eine E-Rechnung-XML-Datei.
     */
    public function validateFile(string $filePath): ValidationResult;
}
