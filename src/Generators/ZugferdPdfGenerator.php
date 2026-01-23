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
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $currency = $invoice->getCurrency()->value;

        $linesHtml = $this->generateLinesHtml($invoice);
        $taxHtml = $this->generateTaxHtml($invoice);

        $sellerAddress = $seller->getPostalAddress();
        $buyerAddress = $buyer->getPostalAddress();

        $sellerAddressHtml = $sellerAddress ? $this->formatAddress($sellerAddress) : '';
        $buyerAddressHtml = $buyerAddress ? $this->formatAddress($buyerAddress) : '';

        $invoiceTypeLabel = $invoice->getInvoiceType()->label();
        $issueDate = $invoice->getIssueDate()->format('d.m.Y');
        $dueDate = $invoice->getDueDate()?->format('d.m.Y') ?? '-';

        $netAmount = number_format($invoice->getNetAmount(), 2, ',', '.');
        $grossAmount = number_format($invoice->getGrossAmount(), 2, ',', '.');

        $vatIdHtml = $seller->getVatId() ? "<p><strong>USt-IdNr.:</strong> {$seller->getVatId()}</p>" : '';
        $buyerVatIdHtml = $buyer->getVatId() ? "<p><strong>USt-IdNr.:</strong> {$buyer->getVatId()}</p>" : '';

        $bankingHtml = '';
        if ($seller->hasBankingInfo()) {
            $bankName = $seller->getBankName() ?? '';
            $bankingHtml = <<<HTML
                <td>
                    <div class="section-title">Bankverbindung</div>
                    <p>IBAN: {$seller->getIban()}</p>
                    <p>BIC: {$seller->getBic()}</p>
                    <p>{$bankName}</p>
                </td>
HTML;
        }

        // Kontaktdaten für Fußzeile
        $sellerContactHtml = '';
        $contactParts = [];
        if ($seller->getContactEmail()) {
            $contactParts[] = "E-Mail: {$seller->getContactEmail()}";
        }
        if ($seller->getContactPhone()) {
            $contactParts[] = "Tel.: {$seller->getContactPhone()}";
        }
        if (!empty($contactParts)) {
            $sellerContactHtml = '<p>' . implode('</p><p>', $contactParts) . '</p>';
        }

        $buyerReference = $invoice->getBuyerReference();
        $buyerRefHtml = $buyerReference ? "<p>Leitweg-ID: {$buyerReference}</p>" : '';

        // Kompakte Absenderzeile für Fensterkuvert
        $sellerOneLine = $seller->getName();
        if ($sellerAddress) {
            $parts = [];
            if ($street = $sellerAddress->getStreetName()) {
                $line = $street;
                if ($building = $sellerAddress->getBuildingNumber()) {
                    $line .= ' ' . $building;
                }
                $parts[] = $line;
            }
            if ($postalCode = $sellerAddress->getPostalCode()) {
                $parts[] = $postalCode . ' ' . ($sellerAddress->getCity() ?? '');
            } elseif ($city = $sellerAddress->getCity()) {
                $parts[] = $city;
            }
            if (!empty($parts)) {
                $sellerOneLine .= ' · ' . implode(' · ', $parts);
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>{$invoiceTypeLabel} {$invoice->getId()}</title>
    <style>
        @page { 
            size: A4; 
            margin: 20mm 15mm 20mm 20mm; 
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif; 
            font-size: 10pt; 
            line-height: 1.4;
            color: #333;
            width: 100%;
        }
        
        /* Kopfbereich mit Absender rechts */
        .letterhead { width: 100%; margin-bottom: 10px; }
        .letterhead td { vertical-align: top; }
        .letterhead .sender-block { text-align: right; }
        .letterhead .sender-block .company-name { font-size: 14pt; font-weight: bold; color: #000; }
        .letterhead .sender-block p { font-size: 9pt; color: #555; margin: 2px 0; }
        
        /* Adressbereich (Fensterkuvert-kompatibel) */
        .address-area { width: 100%; margin-bottom: 20px; }
        .address-area td { vertical-align: top; }
        .address-area .recipient { width: 55%; }
        .address-area .sender-line { font-size: 7pt; color: #666; border-bottom: 1px solid #ccc; padding-bottom: 3px; margin-bottom: 8px; }
        .address-area .recipient-address { font-size: 10pt; line-height: 1.5; }
        .address-area .recipient-address .name { font-weight: bold; }
        .address-area .invoice-details { width: 45%; padding-left: 20px; }
        .address-area .invoice-details table { width: 100%; }
        .address-area .invoice-details td { padding: 3px 0; font-size: 9pt; }
        .address-area .invoice-details td.label { color: #666; width: 45%; }
        .address-area .invoice-details td.value { font-weight: bold; text-align: right; }
        
        /* Rechnungstitel */
        .invoice-title { font-size: 16pt; font-weight: bold; margin: 20px 0 15px 0; border-bottom: 2px solid #333; padding-bottom: 5px; }
        
        /* Positionstabelle */
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.lines th { 
            background: #f5f5f5; color: #333; padding: 8px; 
            text-align: left; font-size: 9pt; font-weight: bold;
            border-bottom: 2px solid #333;
        }
        table.lines th.right, table.lines td.right { text-align: right; }
        table.lines td { padding: 8px; border-bottom: 1px solid #ddd; font-size: 9pt; }
        table.lines tr:hover { background: #fafafa; }
        
        /* Summenbereich */
        .totals { width: 300px; margin-left: auto; margin-bottom: 25px; }
        .totals table { width: 100%; }
        .totals td { padding: 5px 0; font-size: 9pt; }
        .totals td.right { text-align: right; }
        .totals tr.subtotal td { border-top: 1px solid #ddd; }
        .totals tr.grand-total td { font-weight: bold; font-size: 11pt; border-top: 2px solid #333; padding-top: 8px; }
        
        /* Fußbereich */
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; }
        .footer table { width: 100%; }
        .footer td { vertical-align: top; font-size: 8pt; color: #666; padding-right: 20px; }
        .footer .section-title { font-weight: bold; color: #333; margin-bottom: 3px; }
        
        .zugferd-note { 
            margin-top: 20px; padding: 8px 10px; 
            background: #f0f7f0; border-left: 3px solid #4a4; 
            font-size: 8pt; color: #555;
        }
    </style>
</head>
<body>
    <!-- Briefkopf mit Absender rechts -->
    <table class="letterhead">
        <tr>
            <td style="width: 50%;"></td>
            <td class="sender-block">
                <div class="company-name">{$seller->getName()}</div>
                {$sellerAddressHtml}
                {$vatIdHtml}
            </td>
        </tr>
    </table>
    
    <!-- Adressbereich und Rechnungsdetails nebeneinander -->
    <table class="address-area">
        <tr>
            <td class="recipient">
                <div class="sender-line">{$sellerOneLine}</div>
                <div class="recipient-address">
                    <div class="name">{$buyer->getName()}</div>
                    {$buyerAddressHtml}
                    {$buyerRefHtml}
                </div>
            </td>
            <td class="invoice-details">
                <table>
                    <tr>
                        <td class="label">Rechnungsnummer:</td>
                        <td class="value">{$invoice->getId()}</td>
                    </tr>
                    <tr>
                        <td class="label">Rechnungsdatum:</td>
                        <td class="value">{$issueDate}</td>
                    </tr>
                    <tr>
                        <td class="label">Fällig am:</td>
                        <td class="value">{$dueDate}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    
    <!-- Rechnungstitel -->
    <div class="invoice-title">{$invoiceTypeLabel}</div>
    
    <table class="lines">
        <thead>
            <tr>
                <th style="width: 8%;">Pos.</th>
                <th style="width: 40%;">Bezeichnung</th>
                <th class="right" style="width: 10%;">Menge</th>
                <th style="width: 8%;">Einheit</th>
                <th class="right" style="width: 14%;">Einzelpreis</th>
                <th class="right" style="width: 10%;">MwSt.</th>
                <th class="right" style="width: 14%;">Gesamt</th>
            </tr>
        </thead>
        <tbody>
            {$linesHtml}
        </tbody>
    </table>
    
    <div class="totals">
        <table>
            <tr>
                <td>Nettobetrag:</td>
                <td class="right">{$netAmount} {$currency}</td>
            </tr>
            {$taxHtml}
            <tr class="grand-total">
                <td>Gesamtbetrag:</td>
                <td class="right">{$grossAmount} {$currency}</td>
            </tr>
        </table>
    </div>
    
    <!-- Fußzeile mit Firmendaten -->
    <div class="footer">
        <table>
            <tr>
                <td>
                    <div class="section-title">{$seller->getName()}</div>
                    {$sellerAddressHtml}
                </td>
                <td>
                    <div class="section-title">Kontakt</div>
                    {$sellerContactHtml}
                    {$vatIdHtml}
                </td>
                {$bankingHtml}
            </tr>
        </table>
    </div>
    
    <div class="zugferd-note">
        📄 <strong>Elektronische Rechnung (ZUGFeRD/Factur-X)</strong> – 
        Diese Rechnung enthält eine maschinenlesbare XML-Datei gemäß EN 16931.
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generiert HTML für die Rechnungspositionen.
     */
    private function generateLinesHtml(Document $invoice): string {
        $html = '';
        $currency = $invoice->getCurrency()->value;
        $pos = 1;

        foreach ($invoice->getLines() as $line) {
            $quantity = number_format($line->getQuantity(), 2, ',', '.');
            $unitPrice = number_format($line->getUnitPrice(), 2, ',', '.');
            $netAmount = number_format($line->getNetAmount(), 2, ',', '.');
            $taxPercent = number_format($line->getTaxPercent(), 0);
            $unit = $line->getUnitCode()->abbreviation();

            $html .= <<<HTML
            <tr>
                <td>{$pos}</td>
                <td>{$line->getItemName()}</td>
                <td class="right">{$quantity}</td>
                <td>{$unit}</td>
                <td class="right">{$unitPrice} {$currency}</td>
                <td class="right">{$taxPercent}%</td>
                <td class="right">{$netAmount} {$currency}</td>
            </tr>
HTML;
            $pos++;
        }

        return $html;
    }

    /**
     * Generiert HTML für die Steueraufschlüsselung.
     */
    private function generateTaxHtml(Document $invoice): string {
        $html = '';
        $currency = $invoice->getCurrency()->value;
        $taxTotal = $invoice->getTaxTotal();

        if ($taxTotal === null) {
            $taxAmount = number_format($invoice->getTaxAmount(), 2, ',', '.');
            return "<tr><td>MwSt.:</td><td class=\"right\">{$taxAmount} {$currency}</td></tr>";
        }

        foreach ($taxTotal->getSubtotals() as $subtotal) {
            $percent = number_format($subtotal->getPercent(), 0);
            $taxAmount = number_format($subtotal->getTaxAmount(), 2, ',', '.');
            $category = $subtotal->getCategory()->label();

            $html .= <<<HTML
            <tr>
                <td>MwSt. {$percent}% ({$category}):</td>
                <td class="right">{$taxAmount} {$currency}</td>
            </tr>
HTML;
        }

        return $html;
    }

    /**
     * Formatiert eine Adresse als HTML.
     */
    private function formatAddress(object $address): string {
        $parts = [];

        if ($street = $address->getStreetName()) {
            $line = $street;
            if ($building = $address->getBuildingNumber()) {
                $line .= ' ' . $building;
            }
            $parts[] = "<p>{$line}</p>";
        }

        if ($additional = $address->getAdditionalStreetName()) {
            $parts[] = "<p>{$additional}</p>";
        }

        $cityLine = '';
        if ($postalCode = $address->getPostalCode()) {
            $cityLine .= $postalCode . ' ';
        }
        if ($city = $address->getCity()) {
            $cityLine .= $city;
        }
        if ($cityLine) {
            $parts[] = "<p>{$cityLine}</p>";
        }

        if ($country = $address->getCountry()) {
            $parts[] = "<p>{$country->value}</p>";
        }

        return implode("\n", $parts);
    }
}
