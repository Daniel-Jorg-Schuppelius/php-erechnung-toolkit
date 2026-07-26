<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : KositValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Validators;

use CommonToolkit\Helper\FileSystem\{File, Folder};
use CommonToolkit\Helper\Java;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Contracts\ValidatorInterface;
use ERechnungToolkit\Enums\ValidationSeverity;
use ERechnungToolkit\Helper\KositCommandHelper;
use ERRORToolkit\Traits\ErrorLog;
use RuntimeException;

/**
 * E-Rechnung-Validierung über den KoSIT-Validator (extern, Java).
 *
 * Ruft den offiziellen KoSIT-Validator als Prozess auf und prüft UBL- bzw.
 * CII-Rechnungen gegen XML-Schema, EN16931-Schematron und XRechnung-CIUS.
 * Der erzeugte VARL-Report wird in ein {@see ValidationResult} überführt.
 *
 * Die Schematron-Regeln liegen als XSLT 2.0 vor und sind in reinem PHP
 * (libxslt = XSLT 1.0) nicht ausführbar - daher der externe Java-Prozess.
 *
 * Voraussetzungen:
 * - Java-Laufzeit; Verfügbarkeit via {@see KositCommandHelper::isJavaAvailable()}
 *   (Toolkit-PATH-Auflösung), Aufruf via {@see Java::execute()} (java -jar)
 * - Validator-Jar; konfiguriert als `javaExecutables`-Eintrag in
 *   config/erechnung_executables.json (Default: tools/kosit/validator.jar,
 *   eigenes Jar dort eintragbar), override via $jarPath oder KOSIT_VALIDATOR_JAR
 * - Validator-Konfiguration unter data/kosit/ (mitgeliefert)
 *
 * Beispiel:
 * ```php
 * $validator = new KositValidator(); // nutzt das gebündelte Jar + Java aus dem PATH
 * if ($validator->isAvailable()) {
 *     $result = $validator->validate($ublXml);
 *     if (!$result->isValid()) {
 *         foreach ($result->getErrors() as $error) {
 *             echo $error . PHP_EOL;
 *         }
 *     }
 * }
 * ```
 */
final class KositValidator implements ValidatorInterface {
    use ErrorLog;

    private readonly ?string $jarPath;
    private readonly string $repositoryDir;
    private readonly string $scenariosFile;

    public function __construct(
        ?string $jarPath = null,
        ?string $repositoryDir = null,
        ?string $scenariosFile = null,
    ) {
        $this->jarPath = $jarPath
            ?? self::envOrNull('KOSIT_VALIDATOR_JAR')
            ?? KositCommandHelper::configuredJarPath()
            ?? self::defaultJarPath();
        $this->repositoryDir = $repositoryDir ?? self::defaultRepositoryDir();
        $this->scenariosFile = $scenariosFile ?? $this->repositoryDir . DIRECTORY_SEPARATOR . 'scenarios.xml';
    }

    /**
     * Prüft, ob Java-Laufzeit, Validator-Jar und Szenarien verfügbar sind.
     */
    public function isAvailable(): bool {
        if ($this->jarPath === null || !File::exists($this->jarPath)) {
            return false;
        }
        if (!File::exists($this->scenariosFile)) {
            return false;
        }
        return KositCommandHelper::isJavaAvailable();
    }

    public function validate(string $xml): ValidationResult {
        $workDir = $this->createWorkDir();
        $inputFile = $workDir . DIRECTORY_SEPARATOR . 'invoice.xml';

        try {
            File::write($inputFile, $xml);
            return $this->run($inputFile, $workDir);
        } finally {
            Folder::delete($workDir, true);
        }
    }

    public function validateFile(string $filePath): ValidationResult {
        if (!File::exists($filePath)) {
            self::logErrorAndThrow(RuntimeException::class, "Datei nicht gefunden: $filePath");
        }

        $workDir = $this->createWorkDir();
        try {
            return $this->run($filePath, $workDir);
        } finally {
            Folder::delete($workDir, true);
        }
    }

    /**
     * Validiert mehrere XML-Dateien in EINEM Validator-Aufruf (eine JVM).
     *
     * Deutlich schneller als wiederholtes {@see self::validateFile()}, da der
     * teure JVM-Start nur einmal anfällt. Die Eingaben werden eindeutig benannt
     * zwischengespeichert, damit gleiche Dateinamen aus verschiedenen Ordnern
     * nicht kollidieren.
     *
     * @param string[] $filePaths
     * @return array<string, ValidationResult> ergebnis je Eingabepfad (Reihenfolge erhalten)
     */
    public function validateFiles(array $filePaths): array {
        if ($filePaths === []) {
            return [];
        }
        if (!$this->isAvailable()) {
            self::logErrorAndThrow(RuntimeException::class, 'KoSIT-Validator nicht verfügbar.');
        }

        $workDir = $this->createWorkDir();
        $outputDir = $workDir . DIRECTORY_SEPARATOR . 'reports';
        Folder::create($outputDir, 0755, true);

        try {
            $staged = [];
            $index = 0;
            foreach ($filePaths as $original) {
                if (!File::exists($original)) {
                    self::logErrorAndThrow(RuntimeException::class, "Datei nicht gefunden: $original");
                }
                $stem = 'input_' . $index;
                File::write($workDir . DIRECTORY_SEPARATOR . $stem . '.xml', File::read($original));
                $staged[$stem] = $original;
                $index++;
            }

            $inputs = array_map(
                static fn (string $stem): string => $workDir . DIRECTORY_SEPARATOR . $stem . '.xml',
                array_keys($staged)
            );
            Java::execute(
                (string) $this->jarPath,
                ['-r', $this->repositoryDir, '-s', $this->scenariosFile, '-o', $outputDir, ...$inputs]
            );

            $results = [];
            foreach ($staged as $stem => $original) {
                $reportFile = $outputDir . DIRECTORY_SEPARATOR . $stem . '-report.xml';
                if (!File::exists($reportFile)) {
                    self::logErrorAndThrow(RuntimeException::class, "Kein Report für: $original");
                }
                $results[$original] = $this->parseReport(File::read($reportFile));
            }
            return $results;
        } finally {
            Folder::delete($workDir, true);
        }
    }

    /**
     * Führt den Validator aus und parst den erzeugten Report.
     */
    private function run(string $inputFile, string $outputDir): ValidationResult {
        if (!$this->isAvailable()) {
            self::logErrorAndThrow(
                RuntimeException::class,
                'KoSIT-Validator nicht verfügbar. Benötigt Java, das Validator-Jar ' .
                    '(gebündelt oder KOSIT_VALIDATOR_JAR) und die Konfiguration unter data/kosit/.'
            );
        }

        // Argumente aus der javaExecutables-Konfiguration (Platzhalter ersetzt),
        // mit Fallback auf die Standard-KoSIT-Argumente.
        $arguments = KositCommandHelper::validatorArguments([
            '[REPOSITORY]' => $this->repositoryDir,
            '[SCENARIOS]' => $this->scenariosFile,
            '[OUTPUT_DIR]' => $outputDir,
            '[INPUT]' => $inputFile,
        ]);
        if ($arguments === []) {
            $arguments = ['-r', $this->repositoryDir, '-s', $this->scenariosFile, '-o', $outputDir, $inputFile];
        }

        // Aufruf über die Java-Runtime des Common-Toolkit (java -jar ...).
        // Der Exit-Code wird bewusst nicht ausgewertet: der Validator liefert für
        // abgelehnte (aber korrekt verarbeitete) Dokumente einen Exit-Code != 0.
        // Maßgeblich ist allein der erzeugte Report.
        $output = Java::execute((string) $this->jarPath, $arguments);

        $reportFile = $outputDir . DIRECTORY_SEPARATOR
            . pathinfo($inputFile, PATHINFO_FILENAME) . '-report.xml';

        if (!File::exists($reportFile)) {
            self::logErrorAndThrow(
                RuntimeException::class,
                'KoSIT-Validator hat keinen Report erzeugt: ' . $output
            );
        }

        return $this->parseReport(File::read($reportFile));
    }

    /**
     * Überführt einen VARL-Report (KoSIT) in ein ValidationResult.
     */
    private function parseReport(string $reportXml): ValidationResult {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            if (!$dom->loadXML($reportXml)) {
                self::logErrorAndThrow(RuntimeException::class, 'KoSIT-Report konnte nicht geparst werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);

        $reportNodes = $xpath->query('/*[local-name()="report"]');
        $reportNode = $reportNodes !== false ? $reportNodes->item(0) : null;
        $valid = $reportNode instanceof DOMElement && $reportNode->getAttribute('valid') === 'true';

        $acceptNodes = $xpath->query('//*[local-name()="assessment"]/*[local-name()="accept"]');
        $accepted = $acceptNodes !== false && $acceptNodes->length > 0;

        $scenarioNodes = $xpath->query(
            '//*[local-name()="scenarioMatched"]//*[local-name()="scenario"]/*[local-name()="name"]'
        );
        $scenarioNode = $scenarioNodes !== false ? $scenarioNodes->item(0) : null;
        $scenarioName = $scenarioNode instanceof DOMNode ? trim($scenarioNode->textContent) : null;
        if ($scenarioName === '') {
            $scenarioName = null;
        }

        $messages = [];
        foreach ($xpath->query('//*[local-name()="message"]') ?: [] as $messageNode) {
            if (!$messageNode instanceof DOMElement) {
                continue;
            }
            $stepNode = $messageNode->parentNode;
            $stepId = $stepNode instanceof DOMElement && $stepNode->localName === 'validationStepResult'
                ? ($stepNode->getAttribute('id') ?: null)
                : null;

            $location = $messageNode->getAttribute('xpathLocation') ?: null;

            $messages[] = new ValidationMessage(
                ValidationSeverity::fromLevel($messageNode->getAttribute('level')),
                $messageNode->getAttribute('code'),
                trim($messageNode->textContent),
                $location,
                $stepId,
            );
        }

        return new ValidationResult($valid, $accepted, $messages, $scenarioName, $reportXml);
    }

    private function createWorkDir(): string {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erechnung_kosit_' . uniqid('', true);
        Folder::create($dir, 0755, true);
        return $dir;
    }

    private static function defaultRepositoryDir(): string {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'kosit';
    }

    private static function defaultJarPath(): ?string {
        $jar = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tools'
            . DIRECTORY_SEPARATOR . 'kosit' . DIRECTORY_SEPARATOR . 'validator.jar';
        return File::exists($jar) ? $jar : null;
    }

    private static function envOrNull(string $name): ?string {
        $value = getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }
}
