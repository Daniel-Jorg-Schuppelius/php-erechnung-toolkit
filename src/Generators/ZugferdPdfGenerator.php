<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator für ZUGFeRD/Factur-X PDF-Rechnungen.
 * 
 * Erstellt PDF/A-3 konforme Dateien mit eingebetteter XML-Rechnung.
 * 
 * Voraussetzung: dschuppelius/php-pdf-toolkit muss installiert sein.
 * 
 * Beispiel:
 * ```php
 * $generator = new ZugferdPdfGenerator();
 * $pdfBytes = $generator->generate($invoice, $htmlTemplate);
 * file_put_contents('invoice.pdf', $pdfBytes);
 * ```
 * 
 * @package ERechnungToolkit\Generators
 */
final class ZugferdPdfGenerator {
    use ErrorLog;

    private const ZUGFERD_WRITER_CLASS = 'PDFToolkit\\Writers\\ZugferdWriter';
    private const PDF_CONTENT_CLASS    = 'PDFToolkit\\Entities\\PDFContent';

    private ?object $writer = null;

    /**
     * Prüft ob das PDF-Toolkit verfügbar ist.
     */
    public function isAvailable(): bool {
        return class_exists(self::ZUGFERD_WRITER_CLASS)
            && class_exists(self::PDF_CONTENT_CLASS);
    }

    /** ZUGFeRD Conformance Levels */
    public const LEVEL_MINIMUM  = 'MINIMUM';
    public const LEVEL_BASIC_WL = 'BASIC WL';
    public const LEVEL_BASIC    = 'BASIC';
    public const LEVEL_EN16931  = 'EN 16931';
    public const LEVEL_EXTENDED = 'EXTENDED';

    /**
     * Generiert ein ZUGFeRD/Factur-X PDF aus einer E-Rechnung.
     * 
     * @param Document $invoice Das E-Rechnung Document
     * @param string|null $visualHtml Optionales HTML für visuelle Darstellung (sonst wird Standard-Template verwendet)
     * @param array $options Zusätzliche Optionen für die PDF-Generierung
     * @return string|null PDF-Inhalt als Bytes oder null bei Fehler
     */
    public function generate(Document $invoice, ?string $visualHtml = null, array $options = []): ?string {
        if (!$this->isAvailable()) {
            $this->logError('ZUGFeRD PDF generation requires dschuppelius/php-pdf-toolkit. Install with: composer require dschuppelius/php-pdf-toolkit');
            return null;
        }

        // Standard-HTML-Template verwenden wenn keines angegeben
        if ($visualHtml === null) {
            $visualHtml = $this->generateDefaultHtml($invoice);
        }

        // XML aus dem Document generieren (CII für ZUGFeRD/Factur-X)
        $generator = new ERechnungGenerator();
        $invoiceXml = $generator->generateCii($invoice);

        // PDFContent mit eingebetteter XML erstellen
        $contentClass = self::PDF_CONTENT_CLASS;
        $content = $contentClass::fromHtml($visualHtml, [
            'invoice_xml' => $invoiceXml,
            'title' => 'Rechnung ' . ($invoice->getId() ?? ''),
            'subject' => 'ZUGFeRD/Factur-X E-Rechnung',
        ]);

        // Writer mit korrekten Optionen aufrufen
        // Dompdf wird bevorzugt (bessere CSS-Unterstützung), FPDI importiert das PDF
        $writer = $this->getWriter();
        return $writer->createPdfString($content, array_merge($options, [
            'facturx' => true,
            'zugferd_profile' => $this->mapProfileToLevel($invoice),
        ]));
    }

    /**
     * Mapped E-Rechnung Profile zu ZUGFeRD Level.
     */
    private function mapProfileToLevel(Document $invoice): string {
        $profile = $invoice->getProfile();

        return match ($profile) {
            ERechnungProfile::MINIMUM => self::LEVEL_MINIMUM,
            ERechnungProfile::BASIC_WL => self::LEVEL_BASIC_WL,
            ERechnungProfile::BASIC => self::LEVEL_BASIC,
            ERechnungProfile::EN16931 => self::LEVEL_EN16931,
            ERechnungProfile::EXTENDED => self::LEVEL_EXTENDED,
            ERechnungProfile::XRECHNUNG, ERechnungProfile::XRECHNUNG_EXTENSION => self::LEVEL_EN16931,
            default => self::LEVEL_EN16931,
        };
    }

    /**
     * Generiert ein ZUGFeRD/Factur-X PDF und speichert es als Datei.
     * 
     * @param Document $invoice Das E-Rechnung Document
     * @param string $outputPath Dateipfad für das PDF
     * @param string|null $visualHtml Optionales HTML für visuelle Darstellung
     * @param array $options Zusätzliche Optionen
     * @return bool True bei Erfolg
     */
    public function generateToFile(
        Document $invoice,
        string $outputPath,
        ?string $visualHtml = null,
        array $options = []
    ): bool {
        $pdfBytes = $this->generate($invoice, $visualHtml, $options);

        if ($pdfBytes === null) {
            return false;
        }

        return file_put_contents($outputPath, $pdfBytes) !== false;
    }

    /**
     * Gibt den ZugferdWriter zurück (lazy loading).
     */
    private function getWriter(): object {
        if ($this->writer === null) {
            $writerClass = self::ZUGFERD_WRITER_CLASS;
            $this->writer = new $writerClass();
        }
        return $this->writer;
    }

    /**
     * Generiert ein Standard-HTML-Template für die visuelle Darstellung.
     */
    private function generateDefaultHtml(Document $invoice): string {
        $htmlGenerator = new InvoiceHtmlGenerator();
        return $htmlGenerator->generate($invoice);
    }
}
