<?php
/*
 * Created on   : Sat Jan 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InvoiceHtmlGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\Entities\HTML\Document as HtmlDocument;
use CommonToolkit\Entities\HTML\Element;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Entities\TaxSubtotal;
use ERechnungToolkit\Enums\NoteSubjectCode;

/**
 * Generator für HTML-Darstellung von E-Rechnungen.
 * 
 * Nutzt das CommonToolkit HTML Document für strukturierte HTML-Erzeugung.
 * 
 * Beispiel:
 * ```php
 * $generator = new InvoiceHtmlGenerator();
 * $html = $generator->generate($invoice);
 * ```
 * 
 * @package ERechnungToolkit\Generators
 */
final class InvoiceHtmlGenerator {
    /**
     * Generiert ein vollständiges HTML-Dokument für eine E-Rechnung.
     */
    public function generate(Document $invoice, bool $pretty = true): string {
        $invoiceTypeLabel = $invoice->getInvoiceType()->label();
        $title = "{$invoiceTypeLabel} {$invoice->getId()}";

        $htmlDoc = new HtmlDocument($title, 'de', 'UTF-8');
        $htmlDoc = $htmlDoc->withStyle($this->createStyleElement());
        $htmlDoc = $this->addBodyContent($htmlDoc, $invoice);

        return $htmlDoc->render($pretty);
    }

    /**
     * Generiert nur den Body-Inhalt als HTML-String (für Embedding).
     */
    public function generateBodyContent(Document $invoice, bool $pretty = true): string {
        $elements = $this->createBodyElements($invoice);
        $html = '';
        foreach ($elements as $element) {
            $html .= $element->render($pretty);
        }
        return $html;
    }

    /**
     * Erstellt das Style-Element mit allen CSS-Regeln.
     */
    private function createStyleElement(): Element {
        $css = <<<'CSS'
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
    margin: 0 auto;
    padding: 5mm 5mm 5mm 20mm;
}

/* Tabellen-Grundlagen */
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: top; }

/* Kopfbereich - Absender rechtsbündig */
.header-section { margin-bottom: 20pt; }
.sender-block { text-align: right; font-size: 9pt; line-height: 160%; }
.sender-block .company-name { font-size: 14pt; font-weight: bold; margin-bottom: 20pt; }

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
    padding: 6pt 10pt 6pt 0;
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

/* Lieferadresse */
.delivery-section { margin-bottom: 20pt; }
.section-label { font-weight: bold; font-size: 9pt; color: #666; margin-bottom: 5pt; }

/* E-Rechnungs-Hinweis am Ende */
.zugferd-note {
    text-align: center;
    font-size: 7pt;
    color: #666;
    padding-top: 35pt;
    border-top: 1px solid #ddd;
}
CSS;

        return new Element('style', $css);
    }

    /**
     * Fügt den Body-Inhalt zum HTML-Dokument hinzu.
     */
    private function addBodyContent(HtmlDocument $htmlDoc, Document $invoice): HtmlDocument {
        foreach ($this->createBodyElements($invoice) as $element) {
            $htmlDoc = $htmlDoc->withBodyElement($element);
        }
        return $htmlDoc;
    }

    /**
     * Erstellt alle Body-Elemente für die Rechnung.
     * 
     * @return Element[]
     */
    private function createBodyElements(Document $invoice): array {
        $elements = [];

        // Header mit Absenderdaten
        $elements[] = $this->createHeaderSection($invoice);

        // Hauptbereich: Empfänger links, Rechnungstitel rechts
        $elements[] = $this->createMainSection($invoice);

        // Meta-Informationen (Kundennr., Bestellnr., etc.)
        $elements[] = $this->createMetaSection($invoice);

        // Rechnungspositionen
        $elements[] = $this->createLinesSection($invoice);

        // Summen
        $elements[] = $this->createTotalsSection($invoice);

        // Kommentare/Hinweise
        $commentBox = $this->createCommentBox($invoice);
        if ($commentBox !== null) {
            $elements[] = $commentBox;
        }

        // Lieferadresse
        $deliverySection = $this->createDeliverySection($invoice);
        if ($deliverySection !== null) {
            $elements[] = $deliverySection;
        }

        // Zahlungsinformationen
        $paymentSection = $this->createPaymentSection($invoice);
        if ($paymentSection !== null) {
            $elements[] = $paymentSection;
        }

        // ZUGFeRD-Hinweis
        $elements[] = $this->createZugferdNote();

        return $elements;
    }

    /**
     * Erstellt den Header-Bereich mit Absenderdaten.
     */
    private function createHeaderSection(Document $invoice): Element {
        $seller = $invoice->getSeller();
        $sellerAddress = $seller->getPostalAddress();

        $sellerPhone = $seller->getContactPhone() ?? '-';
        $sellerEmail = $seller->getContactEmail() ?? '-';

        // Container
        $container = Element::withAttributes('div', ['style' => 'text-align: right; font-size: 9pt; line-height: 150%;']);

        // Firmenname
        $container = $container->withChild(
            Element::create('div', $seller->getName())
                ->withStyle('font-size: 14pt; font-weight: bold; margin-bottom: 3pt;')
        );

        // Adresszeilen als einzelne divs
        foreach ($this->formatAddressLines($sellerAddress) as $line) {
            $container = $container->withChild(Element::create('div', $line));
        }

        // Leerzeile
        $container = $container->withChild(Element::create('div', '')->withStyle('height: 10pt;'));

        // Kontakt
        $container = $container->withChild(Element::create('div', "Tel: {$sellerPhone}"));
        $container = $container->withChild(Element::create('div', "E-Mail: {$sellerEmail}"));

        return $container;
    }

    /**
     * Erstellt den Hauptbereich mit Empfänger und Rechnungstitel.
     */
    private function createMainSection(Document $invoice): Element {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();
        $sellerAddress = $seller->getPostalAddress();
        $buyerAddress = $buyer->getPostalAddress();

        $invoiceTypeLabel = $invoice->getInvoiceType()->label();
        $issueDate = $invoice->getIssueDate()->format('d.m.Y');
        $dueDate = $invoice->getDueDate()?->format('d.m.Y') ?? '-';

        // Absenderzeile für Fensterkuvert
        $sellerOneLine = $this->formatAddressOneLine($seller->getName(), $sellerAddress);

        // Linke Spalte: Empfängeradresse
        $leftContent = Element::create('div', $sellerOneLine)->withClass('sender-line');

        // Adressblock aufbauen
        $addressBlock = Element::withAttributes('div', ['class' => 'address-block']);
        $addressBlock = $addressBlock->withChild(Element::create('strong', $buyer->getName()));
        foreach ($this->formatAddressLines($buyerAddress) as $line) {
            $addressBlock = $addressBlock->withChild(Element::create('div', $line));
        }

        $leftCell = Element::withChildren('td', [$leftContent, $addressBlock])
            ->withStyle('width: 55%; vertical-align: top;');

        // Rechte Spalte: Rechnungstitel und Meta
        $titleDiv = Element::create('div', $invoiceTypeLabel)
            ->withStyle('font-size: 22pt; font-weight: bold; color: #595959; text-transform: uppercase; margin-bottom: 8pt; margin-top: 20pt;');

        // Meta-Block
        $metaBlock = Element::withAttributes('div', ['style' => 'font-size: 10pt; line-height: 180%;']);
        $metaBlock = $metaBlock->withChild(
            Element::withChildren('div', [
                Element::create('strong', "Nr. {$invoice->getId()}")
            ])
        );
        $metaBlock = $metaBlock->withChild(Element::create('div', "Datum: {$issueDate}"));
        $metaBlock = $metaBlock->withChild(Element::create('div', "Fällig: {$dueDate}"));

        $rightCell = Element::withChildren('td', [$titleDiv, $metaBlock])
            ->withStyle('width: 45%; text-align: right; vertical-align: top;');

        $row = Element::withChildren('tr', [$leftCell, $rightCell]);
        return Element::withChildren('table', [$row])
            ->withStyle('width: 100%; margin-bottom: 40pt;');
    }

    /**
     * Erstellt den Meta-Bereich (Kundennr., Bestellnr., etc.).
     */
    private function createMetaSection(Document $invoice): Element {
        $seller = $invoice->getSeller();
        $buyer = $invoice->getBuyer();

        $customerNumber = $buyer->getEndpointId() ?? $invoice->getBuyerReference() ?? '-';
        $orderReference = $invoice->getOrderReference() ?? '-';
        $buyerReference = $invoice->getBuyerReference() ?? '';
        $sellerVatId = $seller->getVatId() ?? '-';

        $paymentTermsShort = 'Fällig bei Erhalt';
        if ($invoice->getDueDate()) {
            $days = $invoice->getIssueDate()->diff($invoice->getDueDate())->days;
            $paymentTermsShort = "{$days} Tage netto";
        }

        // Header-Zeile
        $headerRow = Element::withChildren('tr', [
            Element::create('th', 'Kunden-Nr.'),
            Element::create('th', 'Bestell-Nr.'),
            Element::create('th', 'Leitweg-ID'),
            Element::create('th', 'Ihre USt-IdNr.'),
            Element::create('th', 'Zahlungsbedingungen'),
        ]);

        // Daten-Zeile
        $dataRow = Element::withChildren('tr', [
            Element::create('td', $customerNumber),
            Element::create('td', $orderReference),
            Element::create('td', $buyerReference),
            Element::create('td', $sellerVatId),
            Element::create('td', $paymentTermsShort),
        ]);

        $table = Element::withChildren('table', [$headerRow, $dataRow]);

        return Element::withChildren('div', [$table])->withClass('meta-section');
    }

    /**
     * Erstellt die Positionstabelle.
     */
    private function createLinesSection(Document $invoice): Element {
        $currency = $invoice->getCurrency()->value;

        // Header
        $headerRow = Element::withChildren('tr', [
            Element::withAttributes('th', ['style' => 'width: 12%;'], 'Menge'),
            Element::withAttributes('th', ['style' => 'width: 50%;'], 'Beschreibung'),
            Element::withAttributes('th', ['style' => 'width: 19%;', 'class' => 'right'], 'Einzelpreis'),
            Element::withAttributes('th', ['style' => 'width: 19%;', 'class' => 'right'], 'Betrag'),
        ]);

        // Positionen
        $rows = [$headerRow];
        foreach ($invoice->getLines() as $line) {
            $rows[] = $this->createLineRow($line, $currency);
        }

        $table = Element::withChildren('table', $rows)->withClass('lines-table');
        return Element::withChildren('div', [$table])->withClass('lines-section');
    }

    /**
     * Erstellt eine Tabellenzeile für eine Rechnungsposition.
     */
    private function createLineRow(InvoiceLine $line, string $currency): Element {
        $quantity = number_format($line->getQuantity(), 0, ',', '.');
        $unit = $line->getUnitCode()->abbreviation();
        $unitPrice = number_format($line->getUnitPrice(), 2, ',', '.') . ' ' . $currency;
        $netAmount = number_format($line->getNetAmount(), 2, ',', '.') . ' ' . $currency;

        $itemName = $line->getItemName();

        // Beschreibungszelle
        $descCell = Element::create('td', $itemName);
        if ($desc = $line->getItemDescription()) {
            // Mit Beschreibung: Hauptelement mit Kind
            $descCell = Element::withAttributes('td', []);
            $descCell = $descCell->withChild(Element::create('span', $itemName));
            $descCell = $descCell->withChild(
                Element::create('small', $desc)->withStyle('display: block; color: #666;')
            );
        }

        return Element::withChildren('tr', [
            Element::create('td', "{$quantity} {$unit}")->withClass('qty'),
            $descCell,
            Element::create('td', $unitPrice)->withClass('amount'),
            Element::create('td', $netAmount)->withClass('amount'),
        ]);
    }

    /**
     * Erstellt den Summenbereich.
     */
    private function createTotalsSection(Document $invoice): Element {
        $currency = $invoice->getCurrency()->value;
        $netAmount = number_format($invoice->getNetAmount(), 2, ',', '.');
        $grossAmount = number_format($invoice->getGrossAmount(), 2, ',', '.');

        $rows = [];

        // Zwischensumme
        $rows[] = Element::withChildren('tr', [
            Element::create('td')->withClass('spacer'),
            Element::create('td', 'Zwischensumme')->withClass('label'),
            Element::create('td', "{$netAmount} {$currency}")->withClass('value'),
        ]);

        // Steuern
        $taxTotal = $invoice->getTaxTotal();
        if ($taxTotal !== null) {
            foreach ($taxTotal->getSubtotals() as $subtotal) {
                $rows[] = $this->createTaxRow($subtotal, $currency);
            }
        } else {
            $taxAmount = number_format($invoice->getTaxAmount(), 2, ',', '.');
            $rows[] = Element::withChildren('tr', [
                Element::create('td')->withClass('spacer'),
                Element::create('td', 'Mehrwertsteuer')->withClass('label'),
                Element::create('td', "{$taxAmount} {$currency}")->withClass('value'),
            ]);
        }

        // Gesamtbetrag
        $rows[] = Element::withChildren('tr', [
            Element::create('td')->withClass('spacer'),
            Element::create('td', 'Gesamtbetrag')->withClass('label'),
            Element::create('td', "{$grossAmount} {$currency}")->withClass('value'),
        ])->withClass('total');

        $table = Element::withChildren('table', $rows)->withClass('totals-table');
        return Element::withChildren('div', [$table])->withClass('totals-section');
    }

    /**
     * Erstellt eine Steuerzeile.
     */
    private function createTaxRow(TaxSubtotal $subtotal, string $currency): Element {
        $percent = number_format($subtotal->getPercent(), 0);
        $taxAmount = number_format($subtotal->getTaxAmount(), 2, ',', '.');

        return Element::withChildren('tr', [
            Element::create('td')->withClass('spacer'),
            Element::create('td', "Mehrwertsteuer ({$percent}%)")->withClass('label'),
            Element::create('td', "{$taxAmount} {$currency}")->withClass('value'),
        ]);
    }

    /**
     * Erstellt die Kommentar-Box (falls Notizen vorhanden).
     */
    private function createCommentBox(Document $invoice): ?Element {
        $notes = $invoice->getNotes();
        if (empty($notes)) {
            return null;
        }

        $container = Element::withAttributes('div', ['class' => 'comment-box']);
        $container = $container->withChild(
            Element::create('div', 'Hinweise:')->withClass('section-label')
        );

        // Formatiere Notes mit Subject Code Label als Präfix
        foreach ($notes as $note) {
            $parsed = NoteSubjectCode::parseNote($note);
            $text = $parsed['text'];

            if ($parsed['code'] !== null) {
                $label = $parsed['code']->getLabel();
                $noteDiv = Element::withAttributes('div', []);
                $noteDiv = $noteDiv->withChild(Element::create('strong', "{$label}: "));
                $noteDiv = $noteDiv->withChild(Element::create('span', $text));
                $container = $container->withChild($noteDiv);
            } else {
                $container = $container->withChild(Element::create('div', $text));
            }
        }

        return $container;
    }

    /**
     * Erstellt den Lieferadress-Bereich.
     */
    private function createDeliverySection(Document $invoice): ?Element {
        $deliveryParty = $invoice->getDeliveryParty();
        if ($deliveryParty === null || $deliveryParty->getPostalAddress() === null) {
            return null;
        }

        $address = $deliveryParty->getPostalAddress();

        $container = Element::withAttributes('div', ['class' => 'delivery-section']);
        $container = $container->withChild(
            Element::create('div', 'Lieferadresse:')->withClass('section-label')
        );

        $addressBlock = Element::withAttributes('div', ['class' => 'address-block']);
        $addressBlock = $addressBlock->withChild(Element::create('strong', $deliveryParty->getName()));
        foreach ($this->formatAddressLines($address) as $line) {
            $addressBlock = $addressBlock->withChild(Element::create('div', $line));
        }

        $container = $container->withChild($addressBlock);
        return $container;
    }

    /**
     * Erstellt den Zahlungsbereich.
     */
    private function createPaymentSection(Document $invoice): ?Element {
        $seller = $invoice->getSeller();
        if (!$seller->hasBankingInfo()) {
            return null;
        }

        $bankName = $seller->getBankName() ?? '';

        $container = Element::withAttributes('div', ['class' => 'payment-section']);

        $paragraph = Element::withAttributes('p', []);
        $paragraph = $paragraph->withChild(Element::create('strong', 'Zahlung: '));
        $paragraph = $paragraph->withChild(
            Element::create('span', "{$seller->getName()}, IBAN: {$seller->getIban()}, BIC: {$seller->getBic()} ({$bankName})")
        );

        $container = $container->withChild($paragraph);
        return $container;
    }

    /**
     * Erstellt den ZUGFeRD-Hinweis.
     */
    private function createZugferdNote(): Element {
        $container = Element::withAttributes('div', ['class' => 'zugferd-note']);
        $container = $container->withChild(Element::create('strong', 'Elektronische Rechnung (ZUGFeRD/Factur-X)'));
        $container = $container->withChild(
            Element::create('span', ' — Diese Rechnung enthält eine maschinenlesbare XML-Datei gemäß EN 16931.')
        );
        return $container;
    }

    /**
     * Formatiert Adresszeilen als Array.
     * 
     * @return string[]
     */
    private function formatAddressLines(?PostalAddress $address): array {
        if ($address === null) {
            return [];
        }

        $lines = [];

        if ($street = $address->getStreetName()) {
            $line = $street;
            if ($building = $address->getBuildingNumber()) {
                $line .= ' ' . $building;
            }
            $lines[] = $line;
        }

        if ($postalCode = $address->getPostalCode()) {
            $lines[] = $postalCode . ' ' . ($address->getCity() ?? '');
        } elseif ($city = $address->getCity()) {
            $lines[] = $city;
        }

        if ($country = $address->getCountry()) {
            $lines[] = $country->getLabel();
        }

        return $lines;
    }

    /**
     * Formatiert eine Adresse als einzeilige Zeichenkette.
     */
    private function formatAddressOneLine(string $name, ?PostalAddress $address): string {
        if ($address === null) {
            return $name;
        }

        $parts = [];

        if ($street = $address->getStreetName()) {
            $line = $street;
            if ($building = $address->getBuildingNumber()) {
                $line .= ' ' . $building;
            }
            $parts[] = $line;
        }

        if ($postalCode = $address->getPostalCode()) {
            $parts[] = $postalCode . ' ' . ($address->getCity() ?? '');
        } elseif ($city = $address->getCity()) {
            $parts[] = $city;
        }

        if (empty($parts)) {
            return $name;
        }

        return $name . ' · ' . implode(' · ', $parts);
    }
}
