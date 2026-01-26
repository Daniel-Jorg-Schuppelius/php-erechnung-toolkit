# PHP E-Rechnung Toolkit

PHP library for creating, parsing and validating electronic invoices (XRechnung, ZUGFeRD/Factur-X) according to EN 16931.

## Features

- **XRechnung**: German e-invoicing standard for public sector
- **ZUGFeRD 2.x / Factur-X**: Hybrid PDF/A-3 with embedded XML
- **EN 16931**: European e-invoicing standard
- **UBL 2.1**: Universal Business Language
- **UN/CEFACT CII D16B**: Cross Industry Invoice

## Installation

```bash
composer require dschuppelius/php-erechnung-toolkit
```

## Requirements

- PHP >= 8.2
- ext-dom
- ext-libxml
- dschuppelius/php-common-toolkit ^1.0

## Quick Start

### Creating an Invoice

```php
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use DateTimeImmutable;

// Create a basic invoice
$invoice = ERechnungDocumentBuilder::create('INV-2026-001')
    ->withIssueDate(new DateTimeImmutable())
    ->withSeller('Muster GmbH', 'DE123456789')
    ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
    ->withBuyer('Kunde AG')
    ->withBuyerAddress('Kundenweg 2', '54321', 'München')
    ->addLine('Beratungsleistung', 10, 150.00)
    ->build();

// Generate UBL XML (XRechnung)
$ublXml = $invoice->toUblXml();

// Generate CII XML (ZUGFeRD)
$ciiXml = $invoice->toCiiXml();
```

### Creating an XRechnung

```php
$leitwegId = '04011000-12345-67';

$xrechnung = ERechnungDocumentBuilder::xrechnung('XR-2026-001', $leitwegId)
    ->withIssueDate(new DateTimeImmutable())
    ->withSeller('Verkäufer GmbH', 'DE123456789')
    ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
    ->withSellerEndpoint('seller@example.com', 'EM')
    ->withBuyer('Öffentliche Verwaltung')
    ->withBuyerAddress('Amtsweg 1', '80333', 'München')
    ->withBuyerLeitwegId($leitwegId)
    ->addLine('Dienstleistung', 1, 1000.00)
    ->build();
```

### Parsing an Invoice

```php
use ERechnungToolkit\Parsers\ERechnungParser;

$parser = new ERechnungParser();

// Parse from XML string
$document = $parser->parse($xmlContent);

// Parse from file
$document = $parser->parseFile('/path/to/invoice.xml');

// Access invoice data
echo $document->getId();
echo $document->getSeller()->getName();
foreach ($document->getLines() as $line) {
    echo $line->getItemName() . ': ' . $line->getNetAmount();
}
```

## Supported Profiles

| Profile | Description |
| ------- | ----------- |
| MINIMUM | ZUGFeRD 2.x MINIMUM |
| BASIC_WL | ZUGFeRD 2.x BASIC WL |
| BASIC | ZUGFeRD 2.x BASIC |
| EN16931 | EN 16931 (COMFORT) |
| EXTENDED | ZUGFeRD 2.x EXTENDED |
| XRECHNUNG | XRechnung 3.0 |
| XRECHNUNG_EXTENSION | XRechnung 3.0 Extension |

## ZUGFeRD/Factur-X PDF Generation

Generate PDF/A-3 invoices with embedded XML for automated processing. Requires `dschuppelius/php-pdf-toolkit`:

```bash
composer require daniel-jorg-schuppelius/php-pdf-toolkit
```

```php
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Generators\ZugferdPdfGenerator;

// Create invoice
$invoice = ERechnungDocumentBuilder::zugferd('ZF-2026-001')
    ->withIssueDate(new DateTimeImmutable())
    ->withSeller('Verkäufer GmbH', 'DE123456789')
    ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
    ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
    ->withBuyer('Käufer AG')
    ->withBuyerAddress('Kundenweg 2', '54321', 'München')
    ->addLine('Beratung', 10, 150.00)
    ->build();

// Generate ZUGFeRD PDF with embedded XML
$pdfGenerator = new ZugferdPdfGenerator();
$pdfGenerator->generateToFile($invoice, '/path/to/invoice.pdf');

// Or get PDF as bytes
$pdfBytes = $pdfGenerator->generate($invoice);

// With custom HTML template
$customHtml = '<html>...</html>';
$pdfBytes = $pdfGenerator->generate($invoice, $customHtml);
```

The generated PDF:

- Is PDF/A-3 compliant for long-term archiving
- Contains the embedded XML invoice (CII format)
- Can be processed automatically by accounting software
- Is visually readable as a normal PDF invoice

## License

AGPL-3.0-or-later

## Author

Daniel Jörg Schuppelius - [schuppelius.org](https://schuppelius.org)
