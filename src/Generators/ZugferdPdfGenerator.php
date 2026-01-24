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
use ERechnungToolkit\Enums\NoteSubjectCode;
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

        // Tabellen-Format für Letterhead und Adressen
        $sellerAddressTableHtml = $sellerAddress ? $this->formatAddressTable($sellerAddress) : '';
        $buyerAddressTableHtml = $buyerAddress ? $this->formatAddressTable($buyerAddress, false) : '';
        $vatIdTableHtml = $seller->getVatId()
            ? "<tr><td class=\"label\">USt-IdNr.:</td><td>{$seller->getVatId()}</td></tr>"
            : '';

        $buyerReference = $invoice->getBuyerReference();
        $buyerRefTableHtml = $buyerReference
            ? "<tr><td class=\"label\">Leitweg-ID:</td><td>{$buyerReference}</td></tr>"
            : '';

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

        // Einzeilige Adresse für Kopfbereich
        $sellerAddressOneLine = '';
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
            }
            if ($country = $sellerAddress->getCountry()) {
                $parts[] = $country->getLabel();
            }
            $sellerAddressOneLine = implode(', ', $parts);
        }

        // Kontakt einzeilig für Kopfbereich
        $sellerContactOneLine = '';
        $contactParts = [];
        if ($seller->getContactPhone()) {
            $contactParts[] = "Tel.: {$seller->getContactPhone()}";
        }
        if ($seller->getContactEmail()) {
            $contactParts[] = $seller->getContactEmail();
        }
        $sellerContactOneLine = implode(' · ', $contactParts);

        // Käuferadresse als einfache Zeilen
        $buyerAddressLines = '';
        if ($buyerAddress) {
            $lines = [];
            if ($street = $buyerAddress->getStreetName()) {
                $line = $street;
                if ($building = $buyerAddress->getBuildingNumber()) {
                    $line .= ' ' . $building;
                }
                $lines[] = $line;
            }
            if ($postalCode = $buyerAddress->getPostalCode()) {
                $lines[] = $postalCode . ' ' . ($buyerAddress->getCity() ?? '');
            } elseif ($city = $buyerAddress->getCity()) {
                $lines[] = $city;
            }
            if ($country = $buyerAddress->getCountry()) {
                $lines[] = $country->getLabel();
            }
            $buyerAddressLines = implode('<br>', $lines);
        }

        // Seller VAT ID für Rechnungsbox
        $sellerVatId = $seller->getVatId() ?? '-';

        // Seller Kontaktdaten
        $sellerPhone = $seller->getContactPhone() ?? '-';
        $sellerEmail = $seller->getContactEmail() ?? '-';
        $contactName = $seller->getContactName() ?? '-';

        // Seller Adresse als Zeilen
        $sellerAddressLines = '';
        $sellerAddressOneLine = '';
        if ($sellerAddress) {
            $lines = [];
            if ($street = $sellerAddress->getStreetName()) {
                $line = $street;
                if ($building = $sellerAddress->getBuildingNumber()) {
                    $line .= ' ' . $building;
                }
                $lines[] = $line;
            }
            if ($postalCode = $sellerAddress->getPostalCode()) {
                $lines[] = $postalCode . ' ' . ($sellerAddress->getCity() ?? '');
            } elseif ($city = $sellerAddress->getCity()) {
                $lines[] = $city;
            }
            $sellerAddressLines = implode('<br>', $lines);
            $sellerAddressOneLine = implode(', ', $lines);
        }

        // Leitweg-ID (Buyer Reference) für XRechnung
        $buyerReference = $invoice->getBuyerReference() ?? '';

        // Kundenummer (Endpoint-ID oder Buyer-Reference)
        $customerNumber = $buyer->getEndpointId() ?? $invoice->getBuyerReference() ?? '-';

        // Bestellnummer
        $orderReference = $invoice->getOrderReference() ?? '-';

        // Zahlungsbedingungen
        $paymentTermsShort = 'Fällig bei Erhalt';
        if ($invoice->getDueDate()) {
            $days = $invoice->getIssueDate()->diff($invoice->getDueDate())->days;
            $paymentTermsShort = "{$days} Tage netto";
        }

        // Lieferadresse (falls vorhanden)
        $deliveryAddressHtml = '';
        $deliveryParty = $invoice->getDeliveryParty();
        if ($deliveryParty && $deliveryParty->getPostalAddress()) {
            $delAddr = $deliveryParty->getPostalAddress();
            $delLines = [];
            if ($street = $delAddr->getStreetName()) {
                $line = $street;
                if ($building = $delAddr->getBuildingNumber()) {
                    $line .= ' ' . $building;
                }
                $delLines[] = $line;
            }
            if ($postalCode = $delAddr->getPostalCode()) {
                $delLines[] = $postalCode . ' ' . ($delAddr->getCity() ?? '');
            }
            $deliveryAddressHtml = '<div class="section-label">Lieferadresse:</div><div class="address-block">' .
                $deliveryParty->getName() . '<br>' . implode('<br>', $delLines) . '</div>';
        }

        // Kommentar-Box (falls Notizen vorhanden)
        $commentBoxHtml = '';
        $notes = $invoice->getNotes();
        if (!empty($notes)) {
            // Formatiere Notes mit Subject Code Label als Präfix
            $formattedNotes = array_map(function (string $note): string {
                $parsed = NoteSubjectCode::parseNote($note);
                $text = htmlspecialchars($parsed['text']);
                if ($parsed['code'] !== null) {
                    $label = htmlspecialchars($parsed['code']->getLabel());
                    return "<strong>{$label}:</strong> {$text}";
                }
                return $text;
            }, $notes);
            $notesText = implode('<br>', $formattedNotes);
            $commentBoxHtml = <<<HTML
    <div class="comment-box">
        <div class="section-label">Hinweise:</div>
        <div>{$notesText}</div>
    </div>
HTML;
        }

        // Bankinfo-Text für Fußbereich
        $bankInfoText = '';
        if ($seller->hasBankingInfo()) {
            $bankName = $seller->getBankName() ?? '';
            $bankInfoText = "<strong>Zahlung:</strong> {$seller->getName()}, IBAN: {$seller->getIban()}, BIC: {$seller->getBic()} ({$bankName})";
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
            margin: 5mm 20mm 25mm 20mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: Arial, sans-serif; 
            font-size: 9pt; 
            line-height: 150%;
            color: #333;
        }
        
        /* Tabellen-Grundlagen */
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        
        /* Kopfbereich - Absender rechtsbündig */
        .header-section { margin-bottom: 10pt; }
        .sender-block { text-align: right; font-size: 9pt; line-height: 160%; }
        .sender-block .company-name { font-size: 14pt; font-weight: bold; margin-bottom: 5pt; }
        
        /* Hauptbereich - Adresse links, Rechnung rechts auf gleicher Höhe */
        .main-section { margin-bottom: 50pt; }
        .main-section td { padding: 0; }
        .main-section .left-col { width: 55%; }
        .main-section .right-col { width: 45%; text-align: right; vertical-align: top; }
        .invoice-title { font-size: 22pt; font-weight: bold; color: #595959; text-transform: uppercase; margin-bottom: 8pt; }
        .invoice-meta { font-size: 10pt; line-height: 180%; }
        
        /* Absenderzeile klein über Empfänger */
        .sender-line { font-size: 7pt; color: #666; border-bottom: 1px solid #999; padding-bottom: 2pt; margin-bottom: 8pt; display: inline-block; }
        
        /* Empfängeradresse */
        .address-block { font-size: 10pt; line-height: 170%; }
        
        /* Kommentar-Box */
        .comment-box { 
            padding: 12pt 0; 
            margin-bottom: 20pt;
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
        }
        .comment-box .section-label { margin-bottom: 8pt; font-weight: bold; font-size: 9pt; color: #666; }
        
        /* Meta-Zeile */
        .meta-section { margin-bottom: 25pt; }
        .meta-section table { width: 100%; }
        .meta-section th { 
            text-align: left; 
            font-size: 8pt; 
            font-weight: normal;
            color: #666;
            padding: 0 15pt 3pt 0;
            border-bottom: 1px solid #ddd;
        }
        .meta-section td { 
            font-size: 9pt;
            font-weight: bold;
            padding: 8pt 15pt 0 0;
        }
        
        /* Positionstabelle */
        .lines-section { margin-bottom: 20pt; }
        .lines-table { width: 100%; }
        .lines-table th { 
            text-align: left; 
            font-size: 8pt; 
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            padding: 8pt 10pt 8pt 0;
            border-bottom: 2px solid #333;
        }
        .lines-table th.right { text-align: right; padding-right: 0; }
        .lines-table td { 
            padding: 10pt 10pt 10pt 0;
            border-bottom: 1px solid #eee;
            font-size: 9pt;
        }
        .lines-table td.qty { text-align: left; }
        .lines-table td.amount { text-align: right; padding-right: 0; }
        .lines-table tr:last-child td { border-bottom: 2px solid #333; }
        
        /* Summenbereich - rechtsbündig zur Tabelle */
        .totals-section { margin-bottom: 30pt; }
        .totals-table { width: 100%; }
        .totals-table td { padding: 6pt 0; font-size: 9pt; }
        .totals-table td.spacer { width: 50%; }
        .totals-table td.label { text-align: right; padding-right: 0; }
        .totals-table td.value { text-align: right; width: 16%; }
        .totals-table tr.total td { 
            font-weight: bold; 
            font-size: 11pt; 
            padding-top: 10pt;
            border-top: 1px solid #333;
        }
        
        /* Zahlungshinweise */
        .payment-section { margin-bottom: 25pt; }
        .payment-section p { font-size: 9pt; line-height: 160%; margin-bottom: 5pt; }
        
        /* E-Rechnungs-Hinweis am Ende */
        .zugferd-note {
            text-align: center;
            font-size: 7pt;
            color: #666;
            padding-top: 15pt;
            border-top: 1px solid #ddd;
        }
    </style>
</head>
<body>

<div style="text-align: right; font-size: 9pt; line-height: 150%;">
<div style="font-size: 14pt; font-weight: bold; margin-bottom: 3pt;">{$seller->getName()}</div>
{$sellerAddressLines}
<br><br>
Tel: {$sellerPhone}<br>
E-Mail: {$sellerEmail}
</div>

<table style="width: 100%; margin-bottom: 80pt;">
<tr>
<td style="width: 55%; vertical-align: top;"><div style="font-size: 7pt; color: #666; border-bottom: 1px solid #999; padding-bottom: 2pt; margin-bottom: 8pt; display: inline-block;">{$seller->getName()} · {$sellerAddressOneLine}</div><div style="font-size: 10pt; line-height: 170%;"><strong>{$buyer->getName()}</strong><br>{$buyerAddressLines}</div></td>
<td style="width: 45%; text-align: right; vertical-align: top;"><br><br><div style="font-size: 22pt; font-weight: bold; color: #595959; text-transform: uppercase; margin-bottom: 8pt;">{$invoiceTypeLabel}</div><div style="font-size: 10pt; line-height: 180%;"><strong>Nr. {$invoice->getId()}</strong><br>Datum: {$issueDate}<br>Fällig: {$dueDate}</div></td>
</tr>
</table>

<div class="meta-section"><br /><br />
<table>
<tr><th>Kunden-Nr.</th><th>Bestell-Nr.</th><th>Leitweg-ID</th><th>Ihre USt-IdNr.</th><th>Zahlungsbedingungen</th></tr>
<tr><td>{$customerNumber}</td><td>{$orderReference}</td><td>{$buyerReference}</td><td>{$sellerVatId}</td><td>{$paymentTermsShort}</td></tr>
</table>
</div>

<div class="lines-section">
<table class="lines-table">
<tr><th style="width: 12%;">Menge</th><th style="width: 50%;">Beschreibung</th><th class="right" style="width: 19%;">Einzelpreis</th><th class="right" style="width: 19%;">Betrag</th></tr>
{$linesHtml}
</table>
</div>

<div class="totals-section">
<table class="totals-table">
<tr><td class="spacer"></td><td class="label">Zwischensumme</td><td class="value">{$netAmount} {$currency}</td></tr>
{$taxHtml}
<tr class="total"><td class="spacer"></td><td class="label">Gesamtbetrag</td><td class="value">{$grossAmount} {$currency}</td></tr>
</table>
</div>
{$commentBoxHtml}
{$deliveryAddressHtml}
<div class="payment-section">
<p>{$bankInfoText}</p>
</div>
<div class="zugferd-note" style="text-align: center; font-size: 7pt; color: #666; padding-top: 35pt; border-top: 1px solid #ddd;"><strong>Elektronische Rechnung (ZUGFeRD/Factur-X)</strong> &mdash; Diese Rechnung enthält eine maschinenlesbare XML-Datei gemäß EN 16931.</div>
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

        foreach ($invoice->getLines() as $line) {
            $quantity = number_format($line->getQuantity(), 0, ',', '.');
            $unit = $line->getUnitCode()->abbreviation();
            $unitPrice = number_format($line->getUnitPrice(), 2, ',', '.') . ' ' . $currency;
            $netAmount = number_format($line->getNetAmount(), 2, ',', '.') . ' ' . $currency;

            $itemName = htmlspecialchars($line->getItemName());
            $itemDescription = '';
            if ($desc = $line->getItemDescription()) {
                $itemDescription = "<br><small>" . htmlspecialchars($desc) . "</small>";
            }

            $html .= <<<HTML
        <tr>
            <td class="qty">{$quantity} {$unit}</td>
            <td>{$itemName}{$itemDescription}</td>
            <td class="amount">{$unitPrice}</td>
            <td class="amount">{$netAmount}</td>
        </tr>
HTML;
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
            return "<tr><td class=\"spacer\"></td><td class=\"label\">Mehrwertsteuer</td><td class=\"value\">{$taxAmount} {$currency}</td></tr>";
        }

        foreach ($taxTotal->getSubtotals() as $subtotal) {
            $percent = number_format($subtotal->getPercent(), 0);
            $taxAmount = number_format($subtotal->getTaxAmount(), 2, ',', '.');

            $html .= <<<HTML
            <tr>
                <td class="spacer"></td>
                <td class="label">Mehrwertsteuer ({$percent}%)</td>
                <td class="value">{$taxAmount} {$currency}</td>
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

    /**
     * Formatiert eine Adresse als HTML-Tabellenzeilen.
     * 
     * @param object $address Die Adresse
     * @param bool $withLabels Wenn true, wird "Adresse:" als Label angezeigt
     */
    private function formatAddressTable(object $address, bool $withLabels = true): string {
        $rows = [];
        $firstLabel = $withLabels ? 'Adresse:' : '';

        if ($street = $address->getStreetName()) {
            $line = $street;
            if ($building = $address->getBuildingNumber()) {
                $line .= ' ' . $building;
            }
            $rows[] = "<tr><td class=\"label\">{$firstLabel}</td><td class=\"value\">{$line}</td></tr>";
        }

        if ($additional = $address->getAdditionalStreetName()) {
            $rows[] = "<tr><td class=\"label\"></td><td class=\"value\">{$additional}</td></tr>";
        }

        $cityLine = '';
        if ($postalCode = $address->getPostalCode()) {
            $cityLine .= $postalCode . ' ';
        }
        if ($city = $address->getCity()) {
            $cityLine .= $city;
        }
        if ($cityLine) {
            $rows[] = "<tr><td class=\"label\"></td><td class=\"value\">{$cityLine}</td></tr>";
        }

        if ($country = $address->getCountry()) {
            $rows[] = "<tr><td class=\"label\"></td><td class=\"value\">{$country->value}</td></tr>";
        }

        return implode("\n", $rows);
    }
}