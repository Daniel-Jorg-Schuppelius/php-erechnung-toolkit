<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use ERechnungToolkit\Entities\Document;
use ERRORToolkit\Traits\ErrorLog;
use Throwable;

/**
 * Parser für ZUGFeRD/Factur-X PDF-Rechnungen.
 * 
 * Extrahiert die eingebettete XML-Rechnung aus PDF/A-3 Dokumenten
 * und parst sie zu einem E-Rechnung Document.
 * 
 * Voraussetzung: daniel-jorg-schuppelius/php-pdf-toolkit muss installiert sein.
 * 
 * Beispiel:
 * ```php
 * $parser = new ZugferdPdfParser();
 * $document = $parser->parseFile('/path/to/invoice.pdf');
 * echo $document->getId(); // Rechnungsnummer
 * ```
 * 
 * @package ERechnungToolkit\Parsers
 */
final class ZugferdPdfParser {
    use ErrorLog;

    private const ZUGFERD_READER_CLASS = 'PDFToolkit\\Readers\\ZugferdReader';

    private ?object $reader = null;

    /**
     * Prüft ob das PDF-Toolkit verfügbar ist.
     */
    public function isAvailable(): bool {
        if (!class_exists(self::ZUGFERD_READER_CLASS)) {
            return false;
        }

        try {
            $reader = $this->getReader();
            return $reader->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Prüft ob die PDF-Datei eine ZUGFeRD/Factur-X Rechnung enthält.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return bool True wenn eine eingebettete Rechnung gefunden wurde
     */
    public function isZugferdPdf(string $pdfPath): bool {
        if (!$this->isAvailable()) {
            return $this->logErrorAndReturn(false, 'ZUGFeRD PDF parsing requires dschuppelius/php-pdf-toolkit');
        }

        $reader = $this->getReader();
        return $reader->isZugferdPdf($pdfPath);
    }

    /**
     * Parst eine ZUGFeRD/Factur-X PDF und gibt ein Document zurück.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return Document|null Das geparste Document oder null bei Fehler
     */
    public function parseFile(string $pdfPath): ?Document {
        $xml = $this->extractXml($pdfPath);

        if ($xml === null) {
            return null;
        }

        return $this->parseXml($xml);
    }

    /**
     * Extrahiert die XML-Rechnung aus der PDF (ohne zu parsen).
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return string|null XML-Inhalt oder null bei Fehler
     */
    public function extractXml(string $pdfPath): ?string {
        if (!$this->isAvailable()) {
            return $this->logErrorAndReturn(null, 'ZUGFeRD PDF parsing requires dschuppelius/php-pdf-toolkit. Install with: composer require dschuppelius/php-pdf-toolkit');
        }

        $reader = $this->getReader();
        $xml = $reader->extractInvoiceXml($pdfPath);

        if ($xml === null) {
            return $this->logErrorAndReturn(null, 'No ZUGFeRD/Factur-X XML found in PDF', ['path' => $pdfPath]);
        }

        $this->logDebug('Extracted ZUGFeRD XML from PDF', [
            'path' => $pdfPath,
            'xmlSize' => strlen($xml)
        ]);

        return $xml;
    }

    /**
     * Parst die extrahierte XML zu einem Document.
     * 
     * @param string $xml XML-Inhalt
     * @return Document|null Das geparste Document oder null bei Fehler
     */
    public function parseXml(string $xml): ?Document {
        $parser = new ERechnungParser();

        try {
            return $parser->parse($xml);
        } catch (Throwable $e) {
            return $this->logErrorAndReturn(null, 'Failed to parse ZUGFeRD XML', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Listet alle eingebetteten Dateien in der PDF auf.
     * 
     * @param string $pdfPath Pfad zur PDF-Datei
     * @return string[] Liste der Dateinamen
     */
    public function listAttachments(string $pdfPath): array {
        if (!$this->isAvailable()) {
            return [];
        }

        $reader = $this->getReader();
        return $reader->listAttachments($pdfPath);
    }

    /**
     * Gibt den ZugferdReader zurück (lazy loading).
     */
    private function getReader(): object {
        if ($this->reader === null) {
            $readerClass = self::ZUGFERD_READER_CLASS;
            $this->reader = new $readerClass();
        }
        return $this->reader;
    }
}