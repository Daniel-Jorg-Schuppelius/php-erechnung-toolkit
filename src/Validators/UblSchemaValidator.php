<?php
/*
 * Created on   : Fri Jun 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UblSchemaValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use DOMDocument;
use ERRORToolkit\Traits\ErrorLog;

/**
 * UBL 2.1 XML-Schema-Validierung (XSD) in reinem PHP über libxml.
 *
 * Validiert ein UBL-Dokument (Invoice, CreditNote, Order, DespatchAdvice) gegen
 * das offizielle OASIS-UBL-2.1-Maindoc-Schema. Dies ist die erste Prüfebene
 * (Struktur/Datentypen/Elementreihenfolge) und benötigt — anders als die
 * Schematron-Geschäftsregeln des {@see KositValidator} — KEINE Java-Laufzeit.
 *
 * Die Geschäftsregel-Ebene (EN 16931 / XRechnung-CIUS / Peppol-BIS) bleibt dem
 * KoSIT-Validator vorbehalten; dieser Validator stellt sicher, dass das erzeugte
 * XML überhaupt schemavalide ist.
 */
final class UblSchemaValidator {
    use ErrorLog;

    /** @var array<string, string> Wurzel-localName ⇒ Maindoc-XSD */
    private const SCHEMA = [
        'Invoice' => 'UBL-Invoice-2.1.xsd',
        'CreditNote' => 'UBL-CreditNote-2.1.xsd',
        'Order' => 'UBL-Order-2.1.xsd',
        'DespatchAdvice' => 'UBL-DespatchAdvice-2.1.xsd',
    ];

    private readonly string $maindocDir;

    public function __construct(?string $maindocDir = null) {
        $this->maindocDir = $maindocDir ?? self::defaultMaindocDir();
    }

    /**
     * Prüft, ob die gebündelten UBL-Schemas verfügbar sind.
     */
    public function isAvailable(): bool {
        return is_dir($this->maindocDir);
    }

    /**
     * Ob für das Wurzelelement ein UBL-Schema hinterlegt ist.
     */
    public function supports(string $rootLocalName): bool {
        return isset(self::SCHEMA[$rootLocalName]);
    }

    /**
     * Validiert das XML gegen das passende UBL-2.1-Schema.
     *
     * @return list<string> Liste der Schemafehler; leer = valide.
     */
    public function validate(string $xml): array {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            if (!$dom->loadXML($xml)) {
                return $this->collectErrors('XML konnte nicht geladen werden.');
            }

            $root = $dom->documentElement?->localName;
            if ($root === null || !isset(self::SCHEMA[$root])) {
                return ["Kein UBL-Schema für Wurzelelement '" . ($root ?? '') . "'."];
            }

            $xsd = $this->maindocDir . DIRECTORY_SEPARATOR . self::SCHEMA[$root];
            if (!is_file($xsd)) {
                return ["UBL-Schema-Datei nicht gefunden: {$xsd}"];
            }

            libxml_clear_errors();
            if ($dom->schemaValidate($xsd)) {
                return [];
            }

            return $this->collectErrors('Schema-Validierung fehlgeschlagen.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Validiert eine Datei.
     *
     * @return list<string>
     */
    public function validateFile(string $filePath): array {
        if (!is_file($filePath)) {
            return ["Datei nicht gefunden: {$filePath}"];
        }
        $xml = file_get_contents($filePath);
        if ($xml === false) {
            return ["Datei konnte nicht gelesen werden: {$filePath}"];
        }
        return $this->validate($xml);
    }

    public function isValid(string $xml): bool {
        return $this->validate($xml) === [];
    }

    /**
     * @return list<string>
     */
    private function collectErrors(string $fallback): array {
        $errors = [];
        foreach (libxml_get_errors() as $error) {
            $message = trim($error->message);
            if ($message === '') {
                continue;
            }
            $errors[] = $error->line > 0 ? "{$message} (Zeile {$error->line})" : $message;
        }

        return $errors !== [] ? $errors : [$fallback];
    }

    private static function defaultMaindocDir(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'kosit'
            . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'ubl'
            . DIRECTORY_SEPARATOR . '2.1' . DIRECTORY_SEPARATOR . 'xsd'
            . DIRECTORY_SEPARATOR . 'maindoc';
    }
}
