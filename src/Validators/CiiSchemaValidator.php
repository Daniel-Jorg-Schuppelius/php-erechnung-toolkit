<?php
/*
 * Created on   : Thu Sep 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : CiiSchemaValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use DOMDocument;

/**
 * UN/CEFACT-CII-D16B-Schema-Validierung (XSD) in reinem PHP über libxml.
 *
 * Gegenstück zum {@see UblSchemaValidator} für den CII-Zweig: prüft eine
 * `rsm:CrossIndustryInvoice` (XRechnung-CII, ZUGFeRD/Factur-X) gegen das
 * gebündelte CII-D16B-Schema der KoSIT-Konfiguration. Das ist die erste
 * Prüfebene (Struktur, Datentypen, Elementreihenfolge) und braucht KEINE
 * Java-Laufzeit; EN 16931, XRechnung-CIUS und Peppol-Regeln bleiben dem
 * {@see KositValidator} vorbehalten.
 *
 * Das D16B-Schema ist die Obermenge der Factur-X-Profilschemas: Ein hier
 * valides Dokument kann für ein enges Profil (MINIMUM, BASIC WL) trotzdem
 * unzulässige Elemente enthalten.
 */
final class CiiSchemaValidator {
    public const NAMESPACE = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

    private const ROOT = 'CrossIndustryInvoice';

    private const SCHEMA = 'CrossIndustryInvoice_100pD16B.xsd';

    private readonly string $schemaDir;

    public function __construct(?string $schemaDir = null) {
        $this->schemaDir = $schemaDir ?? self::defaultSchemaDir();
    }

    /**
     * Prüft, ob das gebündelte CII-Schema verfügbar ist.
     */
    public function isAvailable(): bool {
        return is_file($this->schemaDir . DIRECTORY_SEPARATOR . self::SCHEMA);
    }

    /**
     * Ob Wurzelelement und Namensraum ein CII-Dokument beschreiben.
     */
    public function supports(string $rootLocalName, ?string $namespace = self::NAMESPACE): bool {
        return $rootLocalName === self::ROOT && $namespace === self::NAMESPACE;
    }

    /**
     * Validiert das XML gegen das CII-D16B-Schema.
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
            $namespace = $dom->documentElement?->namespaceURI;
            if ($root === null || !$this->supports($root, $namespace)) {
                return ["Kein CII-Dokument: Wurzelelement '" . ($root ?? '') . "' im Namensraum '" . ($namespace ?? '') . "'."];
            }

            $xsd = $this->schemaDir . DIRECTORY_SEPARATOR . self::SCHEMA;
            if (!is_file($xsd)) {
                return ["CII-Schema-Datei nicht gefunden: {$xsd}"];
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

    private static function defaultSchemaDir(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'kosit'
            . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'cii'
            . DIRECTORY_SEPARATOR . '16b' . DIRECTORY_SEPARATOR . 'xsd';
    }
}
