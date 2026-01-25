<?php

/**
 * Demo-Rechnung Generator
 * 
 * Erstellt eine Test-PDF-Rechnung zur Überprüfung des Layouts.
 * 
 * Usage: php tools/generate_demo_invoice.php [output.pdf]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Entities\MonetaryTotal;
use ERechnungToolkit\Entities\TaxTotal;
use ERechnungToolkit\Entities\TaxSubtotal;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Generators\ZugferdPdfGenerator;
use CommonToolkit\Enums\CurrencyCode;

// Output-Datei aus Argument oder Standard
$outputFile = $argv[1] ?? __DIR__ . '/../test_rechnung.pdf';

echo "=== Demo-Rechnung Generator ===\n\n";

// Verkäufer anlegen
$seller = new Party(
    name: "Muster GmbH",
    postalAddress: new PostalAddress(
        streetName: "Musterstraße 1",
        postalCode: "12345",
        city: "Musterstadt",
        country: "DE"
    ),
    vatId: "DE123456789",
    contactPhone: "+49 123 456789",
    contactEmail: "info@muster.de",
    iban: "DE89 3704 0044 0532 0130 00",
    bic: "COBADEFFXXX",
    bankName: "Commerzbank"
);

echo "✓ Verkäufer: {$seller->getName()}\n";

// Käufer anlegen
$buyer = new Party(
    name: "Kunde AG",
    postalAddress: new PostalAddress(
        streetName: "Kundenweg 42",
        postalCode: "54321",
        city: "Kundenstadt",
        country: "DE"
    ),
    endpointId: "04011000-12345-67",
    endpointScheme: "0204"
);

echo "✓ Käufer: {$buyer->getName()}\n";

// Dokument erstellen
$doc = new Document(
    id: "R-2025-0042",
    issueDate: new DateTimeImmutable(),
    invoiceType: InvoiceType::INVOICE,
    seller: $seller,
    buyer: $buyer,
    currency: CurrencyCode::Euro,
    profile: ERechnungProfile::XRECHNUNG,
    dueDate: new DateTimeImmutable('+30 days'),
    buyerReference: "04011000-12345-67"
);

// Rechnungspositionen hinzufügen
$lines = [
    new InvoiceLine("1", 10.0, UnitCode::HOUR, 850.00, "Webentwicklung Dienstleistung", 85.00, TaxCategory::STANDARD, 19.0),
    new InvoiceLine("2", 1.0, UnitCode::PIECE, 299.00, "Hosting-Paket Premium (12 Monate)", 299.00, TaxCategory::STANDARD, 19.0),
    new InvoiceLine("3", 1.0, UnitCode::PIECE, 149.00, "SSL-Zertifikat Wildcard", 149.00, TaxCategory::STANDARD, 19.0),
    new InvoiceLine("4", 3.0, UnitCode::PIECE, 36.00, "Domain-Registrierung (.de)", 12.00, TaxCategory::STANDARD, 19.0),
    new InvoiceLine("5", 1.0, UnitCode::PIECE, 59.00, "E-Mail-Postfächer Business (10 Stück)", 59.00, TaxCategory::STANDARD, 19.0),
];

foreach ($lines as $line) {
    $doc->addLine($line);
}

echo "✓ " . count($lines) . " Positionen hinzugefügt\n";

// Summen berechnen
$net = 850 + 299 + 149 + 36 + 59; // 1393.00
$tax = round($net * 0.19, 2);      // 264.67
$gross = $net + $tax;              // 1657.67

$doc->setMonetaryTotal(new MonetaryTotal(
    lineExtensionAmount: $net,
    taxExclusiveAmount: $net,
    taxInclusiveAmount: $gross,
    payableAmount: $gross,
    currency: CurrencyCode::Euro
));
$doc->setTaxTotal(new TaxTotal(
    taxAmount: $tax,
    currency: CurrencyCode::Euro,
    subtotals: [new TaxSubtotal($net, $tax, TaxCategory::STANDARD, 19.0)]
));

// Hinweise/Notizen hinzufügen
$doc->addNote("Zahlbar innerhalb von 30 Tagen ohne Abzug.");
$doc->addNote("Lieferung erfolgte am " . (new DateTimeImmutable())->format('d.m.Y') . ".");

echo "✓ Summen: Netto {$net} EUR, MwSt {$tax} EUR, Brutto {$gross} EUR\n";
echo "✓ Hinweise: " . count($doc->getNotes()) . " Notizen hinzugefügt\n";
echo "✓ Profil: " . $doc->getProfile()->name . "\n\n";

// HTML-Vorschau generieren
$htmlFile = str_replace('.pdf', '.html', $outputFile);
$htmlGenerator = new ERechnungToolkit\Generators\InvoiceHtmlGenerator();
$html = $htmlGenerator->generate($doc);
file_put_contents($htmlFile, $html);
echo "✓ HTML-Vorschau: {$htmlFile}\n\n";

// PDF generieren
echo "Generiere PDF...\n";

$generator = new ZugferdPdfGenerator();
$pdf = $generator->generate($doc);

// Speichern
file_put_contents($outputFile, $pdf);

$size = round(strlen($pdf) / 1024, 1);
echo "\n✓ PDF gespeichert: {$outputFile}\n";
echo "  Größe: {$size} KB\n";
echo "\nFertig!\n";
