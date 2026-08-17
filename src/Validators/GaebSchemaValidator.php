<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebSchemaValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use DOMDocument;
use ERRORToolkit\Traits\ErrorLog;

/**
 * GAEB-DA-XML-Schemavalidierung (XSD) in reinem PHP über libxml.
 *
 * Validiert eine GAEB-Datei gegen das amtliche Schema ihrer Austauschphase. Die
 * Phase und die Version stehen im Namespace des Wurzelelements
 * (`http://www.gaeb.de/GAEB_DA_XML/DA83/3.3`), die passende Schemadatei wird
 * daraus aufgelöst — die Schemas liegen unter `data/gaeb/xsd/` und tragen Phase,
 * Version und Ausgabe im Dateinamen (`GAEB_DA_XML_83_3.3_2021-05.xsd`).
 *
 * Jedes Phasenschema bindet die Typbibliothek über `xs:redefine` mit relativem
 * Pfad ein (`GAEB_DA_XML_Lib_3.3_2021-05.xsd`, für die Kostenphasen zusätzlich
 * `…_Lib5x_…`). Die flache Ablage ist deshalb Voraussetzung: Fehlt die Bibliothek
 * neben dem Phasenschema, lässt sich das Schema nicht laden.
 *
 * Dies ist die formale Prüfebene (Struktur, Datentypen, Elementreihenfolge). Die
 * fachlichen Regeln — Mengenpflicht je Phase, Summe der Einheitspreisanteile,
 * Eindeutigkeit der Ordnungszahl — bleiben Sache des aufrufenden Preflights.
 */
final class GaebSchemaValidator {
    use ErrorLog;

    /** Namespace der GAEB-DA-XML-Dokumente: …/GAEB_DA_XML/DA&lt;Phase&gt;/&lt;Version&gt; */
    private const NAMESPACE_PATTERN = '#^https?://www\.gaeb\.de/GAEB_DA_XML/DA([0-9A-Za-z.]+)/(\d+\.\d+)$#';

    /**
     * Version 3.1 kennt weder Phase noch Version im Namespace - alle Phasen
     * teilen sich `…/GAEB_DA_XML/200407`. Die Phase steht dort nur im Element
     * `DP`, und es gibt genau zwei Schemadateien: eine für die Angebotsabgabe,
     * eine für alle übrigen Phasen.
     */
    private const LEGACY_NAMESPACE = 'http://www.gaeb.de/GAEB_DA_XML/200407';

    private const LEGACY_VERSION = '3.1';

    /** Dateiname: GAEB_DA_XML_&lt;Phase&gt;_&lt;Version&gt;_&lt;Ausgabe&gt;[_Beta].xsd */
    private const FILE_PATTERN = '#^GAEB_DA_XML_(?<phase>\d{2}(?:\.\d)?[A-Z]{0,2})_(?<version>\d+\.\d+)_(?<edition>\d{4}-\d{2})(?<beta>_Beta)?\.xsd$#';

    private readonly string $schemaDir;

    /** @var array<string, array<string, string>>|null Phase ⇒ Version ⇒ Dateipfad */
    private ?array $schemas = null;

    public function __construct(?string $schemaDir = null) {
        $this->schemaDir = $schemaDir ?? self::defaultSchemaDir();
    }

    /**
     * Prüft, ob die gebündelten GAEB-Schemas verfügbar sind.
     */
    public function isAvailable(): bool {
        return is_dir($this->schemaDir) && $this->availableSchemas() !== [];
    }

    /**
     * Austauschphase und Version aus dem Namespace des Wurzelelements.
     *
     * @return array{phase: string, version: string}|null
     */
    public function detect(string $xml): ?array {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            if (!$dom->loadXML($xml)) {
                return null;
            }

            $namespace = $dom->documentElement?->namespaceURI;
            if ($namespace === null) {
                return null;
            }

            if (preg_match(self::NAMESPACE_PATTERN, $namespace, $matches) === 1) {
                return ['phase' => $matches[1], 'version' => $matches[2]];
            }

            if ($namespace === self::LEGACY_NAMESPACE) {
                $phase = $this->legacyPhase($dom->saveXML() ?: '');

                return $phase === null ? null : ['phase' => $phase, 'version' => self::LEGACY_VERSION];
            }

            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Ob für die Phase (optional: eine bestimmte Version) ein Schema hinterlegt ist.
     */
    public function supports(string $phase, ?string $version = null): bool {
        return $this->schemaFile($phase, $version) !== null;
    }

    /**
     * Validiert das XML gegen das Schema seiner eigenen Austauschphase.
     *
     * @return list<string> Liste der Schemafehler; leer = valide.
     */
    public function validate(string $xml): array {
        $detected = $this->detect($xml);
        if ($detected === null) {
            return ['Kein GAEB-DA-XML-Namespace am Wurzelelement gefunden.'];
        }

        return $this->validateAs($xml, $detected['phase'], $detected['version']);
    }

    /**
     * Validiert gegen eine ausdrücklich gewählte Phase. Nötig bei Phasen, die
     * sich einen Namespace teilen (X50/X50.1/X50.2, X51/X51.1/X51.2) — dort
     * benennt der Namespace nur die Familie, nicht die Ausprägung.
     *
     * @return list<string>
     */
    public function validateAs(string $xml, string $phase, ?string $version = null): array {
        $xsd = $version === self::LEGACY_VERSION
            ? $this->legacySchemaFile($phase, $this->documentEdition($xml))
            : $this->schemaFile($phase, $version);
        if ($xsd === null) {
            return [\sprintf(
                'Kein GAEB-Schema für Phase %s%s hinterlegt.',
                $phase,
                $version !== null ? " in Version {$version}" : ''
            )];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            if (!$dom->loadXML($xml)) {
                return $this->collectErrors('XML konnte nicht geladen werden.');
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
     * Pfad der Schemadatei; ohne Version gewinnt die höchste vorhandene.
     */
    public function schemaFile(string $phase, ?string $version = null): ?string {
        if ($version === self::LEGACY_VERSION) {
            return $this->legacySchemaFile($phase, null);
        }

        $available = $this->availableSchemas();
        if (!isset($available[$phase])) {
            return null;
        }

        if ($version !== null) {
            return $available[$phase][$version] ?? null;
        }

        $versions = array_keys($available[$phase]);
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));
        $newest = end($versions);

        return $newest === false ? null : $available[$phase][$newest];
    }

    /**
     * Alle hinterlegten Schemas. Gibt es eine Phase in mehreren Ausgaben
     * (X31: 2023-01 neben 2021-05), gewinnt die jüngste.
     *
     * @return array<string, array<string, string>> Phase ⇒ Version ⇒ Dateipfad
     */
    public function availableSchemas(): array {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $files = glob($this->schemaDir . DIRECTORY_SEPARATOR . 'GAEB_DA_XML_*.xsd');
        if ($files === false) {
            return $this->schemas = [];
        }

        $found = [];
        $editions = [];
        foreach ($files as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path), $matches) !== 1) {
                continue;
            }
            // 3.4 ist Beta und bleibt bis zur Freigabe außen vor.
            if (($matches['beta'] ?? '') !== '') {
                continue;
            }

            $phase = $matches['phase'];
            $version = $matches['version'];
            $edition = $matches['edition'];

            if (isset($editions[$phase][$version]) && $editions[$phase][$version] >= $edition) {
                continue;
            }

            $editions[$phase][$version] = $edition;
            $found[$phase][$version] = $path;
        }

        ksort($found);

        return $this->schemas = $found;
    }

    /** Phase einer 3.1-Datei: sie steht dort ausschließlich im Element `DP`. */
    private function legacyPhase(string $xml): ?string {
        return preg_match('#<DP>\s*([0-9A-Za-z]+)\s*</DP>#', $xml, $matches) === 1 ? $matches[1] : null;
    }

    /** Ausgabe des Dokuments (`VersDate`); sie entscheidet in 3.1 über das Schema. */
    private function documentEdition(string $xml): ?string {
        return preg_match('#<VersDate>\s*(\d{4}-\d{2})\s*</VersDate>#', $xml, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * In 3.1 deckt eine Datei alle Phasen ab, die Angebotsabgabe hat eine
     * eigene - und es gibt zwei Ausgaben mit demselben Namespace. Gewählt wird
     * die jüngste Ausgabe, die nicht neuer ist als das Dokument: eine Datei von
     * 2007 gegen das Schema von 2009 zu prüfen meldet sonst nur, dass sie alt ist.
     */
    private function legacySchemaFile(string $phase, ?string $edition = null): ?string {
        $prefix = $phase === '84' ? 'GAEB_DA_XML_84_3.1_' : 'GAEB_DA_XML_3.1_';
        $files = glob($this->schemaDir . DIRECTORY_SEPARATOR . $prefix . '*.xsd') ?: [];
        if ($files === []) {
            return null;
        }

        $byEdition = [];
        foreach ($files as $file) {
            if (preg_match('#_(\d{4}-\d{2})\.xsd$#', basename($file), $matches) === 1) {
                $byEdition[$matches[1]] = $file;
            }
        }
        if ($byEdition === []) {
            return $files[0];
        }

        ksort($byEdition);
        $chosen = null;
        foreach ($byEdition as $candidate => $file) {
            if ($edition === null || $candidate <= $edition) {
                $chosen = $file;
            }
        }

        return $chosen ?? reset($byEdition);
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
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'gaeb' . DIRECTORY_SEPARATOR . 'xsd';
    }
}
