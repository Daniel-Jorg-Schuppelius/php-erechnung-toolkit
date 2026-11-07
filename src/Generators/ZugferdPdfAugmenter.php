<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfAugmenter.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\Helper\FileSystem\File;
use ERechnungToolkit\Entities\SupportingDocument;
use ERechnungToolkit\Enums\{AugmentFailure, ERechnungProfile};
use ERechnungToolkit\Exceptions\AugmentException;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Ergänzt eine vorhandene ZUGFeRD/Factur-X-PDF um rechnungsbegründende
 * Unterlagen (BG-24).
 *
 * Die eingebettete Rechnungs-XML wird mit {@see SupportingDocumentAugmenter}
 * verlustfrei ergänzt; das PDF schreibt php-pdf-toolkit (ab 0.31) neu:
 * Seiten unverändert, neue XML unter dem Namen des Originals (AFRelationship
 * Alternative, Konformitätsstufe im XMP aus dem Profil der XML), die neuen
 * Unterlagen und die übrigen Anhänge des Originals als Supplement.
 *
 * Grenzen des Neuschreibens: Lesezeichen, Links, Formularfelder, Struktur-Tags
 * und Metadaten des Originals entfallen; übernommene Anhänge verlieren
 * Beschreibung, ursprüngliche Beziehung und Datumsangaben ({@see getNotices()}).
 * Verschlüsselte und signierte PDFs werden abgelehnt.
 */
final class ZugferdPdfAugmenter {
    use ErrorLog;

    private const ZUGFERD_WRITER_CLASS = 'PDFToolkit\\Writers\\ZugferdWriter';
    private const ZUGFERD_READER_CLASS = 'PDFToolkit\\Readers\\ZugferdReader';
    private const SECURITY_HELPER_CLASS = 'PDFToolkit\\Helper\\PDFSecurityHelper';

    /** Die ursprüngliche Beziehung eines Anhangs liest pdfdetach nicht aus. */
    private const CARRIED_RELATIONSHIP = 'Supplement';

    /** @var list<string> */
    private array $notices = [];

    // Bewusst ohne Klassentyp: das Toolkit ist optional, ältere Fassungen kennen die Methoden nicht.
    private ?object $writer = null;
    private ?object $reader = null;

    /**
     * Hinweise des letzten augment()-Laufs, etwa zu übernommenen Anhängen.
     *
     * @return list<string>
     */
    public function getNotices(): array {
        return $this->notices;
    }

    /**
     * php-pdf-toolkit ab 0.31 mit Seitenimport, pdfdetach und mutool vorhanden?
     */
    public function isAvailable(): bool {
        if (!class_exists(self::ZUGFERD_WRITER_CLASS) || !class_exists(self::ZUGFERD_READER_CLASS) || !self::hasMethod(self::SECURITY_HELPER_CLASS, 'isSigned')) {
            return false;
        }

        $writer = $this->writer();
        $reader = $this->reader();

        return method_exists($writer, 'embedIntoPdf') && method_exists($writer, 'isImportAvailable') && $writer->isImportAvailable() === true
            && method_exists($reader, 'extractInvoice') && method_exists($reader, 'isAvailable') && $reader->isAvailable() === true
            && self::callStatic(self::SECURITY_HELPER_CLASS, 'isAvailable') === true;
    }

    /**
     * Vorprüfung ohne Unterlagen: der Grund, aus dem {@see augment()} ablehnen
     * würde (Toolkit fehlt, kein PDF, verschlüsselt, signiert, keine
     * Rechnungs-XML, XML nicht ergänzbar), oder null.
     */
    public function canAugment(string $pdfPath): ?AugmentFailure {
        try {
            $invoice = $this->inspect($pdfPath);
        } catch (AugmentException $e) {
            return $e->getReason();
        }

        return (new SupportingDocumentAugmenter)->canAugment($invoice['xml']);
    }

    /**
     * Liefert das ergänzte PDF; ohne Unterlagen die Datei unverändert.
     *
     * @param iterable<SupportingDocument> $documents
     * @throws AugmentException
     */
    public function augment(string $pdfPath, iterable $documents): string {
        $this->notices = [];
        $documents = is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);

        $invoice = $this->inspect($pdfPath);
        $xmlAugmenter = new SupportingDocumentAugmenter;
        $xml = $xmlAugmenter->augment($invoice['xml'], $documents);
        if ($documents === []) {
            return File::read($pdfPath);
        }

        $carried = $this->carriedAttachments($pdfPath, $invoice['filename'], $documents);
        $profile = $xmlAugmenter->detectProfile($xml) ?? ERechnungProfile::EN16931;

        $writer = $this->writer();
        $pdf = method_exists($writer, 'embedIntoPdf') ? $writer->embedIntoPdf($pdfPath, $xml, [
            'zugferd_profile' => ZugferdPdfGenerator::conformanceLevel($profile),
            'invoice_filename' => $invoice['filename'],
            'attachments' => [...$carried, ...ZugferdPdfGenerator::supportingAttachments($documents)],
        ]) : null;
        if (!is_string($pdf) || $pdf === '') {
            throw $this->reject(AugmentFailure::PDF_WRITE_FAILED, 'Das ergänzte PDF ließ sich nicht schreiben; den Grund nennt das Protokoll von php-pdf-toolkit.');
        }

        $this->logInfo('Unterlagen in ZUGFeRD-PDF eingefügt', ['path' => $pdfPath, 'documents' => count($documents), 'carried' => count($carried)]);

        return $pdf;
    }

    /**
     * @return array{filename: string, xml: string}
     */
    private function inspect(string $pdfPath): array {
        if (!$this->isAvailable()) {
            throw $this->reject(AugmentFailure::PDF_TOOLKIT_UNAVAILABLE, 'Benötigt php-pdf-toolkit ab 0.31 mit pdfdetach (poppler) und mutool.');
        }
        if (!File::exists($pdfPath)) {
            throw $this->reject(AugmentFailure::INVALID_PDF, "PDF nicht gefunden: {$pdfPath}");
        }

        $probe = self::callStatic(self::SECURITY_HELPER_CLASS, 'probe', $pdfPath);
        if (!is_array($probe)) {
            throw $this->reject(AugmentFailure::INVALID_PDF, 'Das PDF ist nicht lesbar.');
        }
        if (($probe['encrypted'] ?? false) === true) {
            throw $this->reject(AugmentFailure::ENCRYPTED, 'Das PDF ist verschlüsselt; ein Neuschreiben hebe den Schutz auf oder scheitere.');
        }
        if (self::callStatic(self::SECURITY_HELPER_CLASS, 'isSigned', $pdfPath) === true) {
            throw $this->reject(AugmentFailure::SIGNED, 'Das PDF ist digital signiert; ein Neuschreiben bräche die Signatur.');
        }

        $reader = $this->reader();
        $invoice = method_exists($reader, 'extractInvoice') ? $reader->extractInvoice($pdfPath) : null;
        if (!is_array($invoice) || !is_string($invoice['filename'] ?? null) || !is_string($invoice['xml'] ?? null)) {
            throw $this->reject(AugmentFailure::NO_INVOICE_XML, 'Das PDF enthält keine Rechnungs-XML.');
        }

        return ['filename' => $invoice['filename'], 'xml' => $invoice['xml']];
    }

    /**
     * Die übrigen Anhänge des Originals; jeder, der sich nicht lesen ließe oder
     * mit einer neuen Unterlage kollidierte, verhindert die Ergänzung.
     *
     * @param list<SupportingDocument> $documents
     * @return list<array{name: string, content: string, mime: string, description: string, relationship: string}>
     */
    private function carriedAttachments(string $pdfPath, string $invoiceFilename, array $documents): array {
        $new = [];
        foreach ($documents as $document) {
            $filename = $document->getFilename();
            if ($document->isEmbedded() && $filename !== null) {
                $new[mb_strtolower($filename)] = $filename;
            }
        }
        if (isset($new[mb_strtolower($invoiceFilename)])) {
            throw $this->reject(AugmentFailure::DUPLICATE_FILENAME, "Der Dateiname \"{$invoiceFilename}\" gehört der Rechnungs-XML.");
        }

        $reader = $this->reader();
        $names = method_exists($reader, 'listAttachments') ? $reader->listAttachments($pdfPath) : [];
        $taken = [mb_strtolower($invoiceFilename) => true];
        $carried = [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (!is_string($name) || $name === $invoiceFilename) {
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($new[$key])) {
                throw $this->reject(AugmentFailure::DUPLICATE_FILENAME, "Das PDF trägt schon eine Datei \"{$name}\"; die Unterlage \"{$new[$key]}\" braucht einen anderen Namen.");
            }
            if (isset($taken[$key])) {
                throw $this->reject(AugmentFailure::ATTACHMENT_UNREADABLE, "Mehrere Anhänge heißen ohne Rücksicht auf die Schreibweise \"{$name}\"; das neue PDF trüge nur einen davon.");
            }
            $taken[$key] = true;

            $content = method_exists($reader, 'extractAttachment') ? $reader->extractAttachment($pdfPath, $name) : null;
            if (!is_string($content) || $content === '') {
                throw $this->reject(AugmentFailure::ATTACHMENT_UNREADABLE, "Der Anhang \"{$name}\" ist leer oder ließ sich nicht lesen und ginge verloren.");
            }

            $mime = File::mimeTypeFromContent($content) ?: 'application/octet-stream';
            $carried[] = ['name' => $name, 'content' => $content, 'mime' => $mime, 'description' => '', 'relationship' => self::CARRIED_RELATIONSHIP];
            $this->notices[] = "Anhang {$name} übernommen als Supplement mit MIME-Typ {$mime} aus dem Inhalt; Beschreibung, ursprüngliche Beziehung und Datumsangaben entfallen.";
        }

        return $carried;
    }

    private function writer(): object {
        $class = self::ZUGFERD_WRITER_CLASS;

        return $this->writer ??= new $class;
    }

    private function reader(): object {
        $class = self::ZUGFERD_READER_CLASS;

        return $this->reader ??= new $class;
    }

    // Klassenname als Parameter: PHPStan prüft sonst gegen die installierte (ältere) Fassung.
    private static function hasMethod(string $class, string $method): bool {
        return class_exists($class) && method_exists($class, $method);
    }

    private static function callStatic(string $class, string $method, mixed ...$arguments): mixed {
        $callable = [$class, $method];

        return self::hasMethod($class, $method) && is_callable($callable) ? $callable(...$arguments) : null;
    }

    private function reject(AugmentFailure $reason, string $message): AugmentException {
        $this->logInfo('Ergänzung abgelehnt: ' . $message, ['reason' => $reason->value]);

        return new AugmentException($reason, $message);
    }
}
