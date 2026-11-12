# PHP E-Rechnung Toolkit

PHP library for creating, parsing and validating electronic invoices (XRechnung, ZUGFeRD/Factur-X) according to EN 16931.

## Features

- **XRechnung**: German e-invoicing standard for public sector
- **ZUGFeRD 2.x / Factur-X**: Hybrid PDF/A-3 with embedded XML
- **ZUGFeRD 1.0** (2014, `rsm:CrossIndustryDocument`): read-only legacy dialect — not EN 16931; generators write the 2.x successor profile
- **EN 16931**: European e-invoicing standard
- **XBestellung / Peppol BIS Order**: UBL purchase orders ([docs](docs/XBestellung/README.md))
- **Order-X**: CII purchase orders + hybrid PDF/A-3 (order-side Factur-X)
- **Despatch Advice**: UBL delivery notes (Peppol BIS Despatch Advice)
- **Peppol**: participant/document identifiers, SBDH envelope, SML/SMP lookup, BIS core rules (no AS4 stack)
- **UBL 2.1**: Universal Business Language
- **UN/CEFACT CII D16B**: Cross Industry Invoice

### Beyond E-Invoicing

The toolkit also covers the exchange formats that surround an invoice in the construction and wholesale trades. None of these were listed here before, although parsers and generators have shipped for some time:

- **GAEB**: DA XML 3.x (`GaebDaXmlParser` / `GaebDaXmlGenerator`), the older families GAEB 90 (`d8x`) and GAEB 2000 (`p8x`), plus quantity/costing phases (`GaebCostingParser`) — bills of quantities, tenders and awards ([docs](docs/GAEB/README.md))
- **DATANORM 4/5**: `DatanormParser` / `DatanormGenerator` for article and price catalogues, including the DA-11 price-update variant (`Da11Parser` / `Da11Generator`)
- **BMEcat**: `BmecatParser` for supplier catalogues
- **UGL**: `UglParser`, `UglInvoiceParser` and `UglGenerator` — the German trade data exchange used between wholesalers and craft businesses
- **IDS-Connect**: `IdsCartParser` / `IdsCartGenerator` for the wholesale shop cart (`Warenkorb`, actions WKE/WKS) and `IdsConnectRequest::formFields()` for the shop jump (WKE, WKS, ADL); raw-material shares, item characters and the return flag included. Parsing is DTD-free and namespace-agnostic
- **openTRANS 2.1**: `OpenTransOrderParser` / `OpenTransOrderGenerator` (ORDER), `OpenTransOrderResponseParser` / `OpenTransOrderResponseGenerator` (ORDERRESPONSE onto `OrderResponse`) and `OpenTransDispatchNotificationParser` / `OpenTransDispatchNotificationGenerator` (DISPATCHNOTIFICATION onto the shared `DespatchAdvice`, like the Peppol despatch advice). Element order follows openTRANS 2.1; no XSD validation is bundled

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

Input that is not an e-invoice is reported with typed exceptions and without an
error log entry, so intake channels (mail, upload, PDF attachments) decide the
severity themselves: `MalformedXmlException` when libxml cannot load the input
(binary data, plain text, truncated file) and `UnsupportedFormatException` for
well-formed XML with an unknown root element. Both extend `RuntimeException`;
the same applies to the Order-X, UBL Order, Despatch Advice, BMEcat and
openTRANS parsers.

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
| ZUGFERD1_BASIC / ZUGFERD1_COMFORT / ZUGFERD1_EXTENDED | ZUGFeRD 1.0 (legacy, parse only; `isLegacy()`, `successor()`) |

### Order Profiles (XBestellung / Peppol BIS Order)

| Profile | Description |
| ------- | ----------- |
| PEPPOL_ORDER_ONLY | Peppol BIS Order only 3 (international) |
| XBESTELLUNG | XBestellung v1.0 (German CIUS) |

## Creating an Order (XBestellung)

```php
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Enums\UnitCode;

$order = OrderBuilder::xbestellung('ORD-2026-001')
    ->withBuyer('Stadt Musterstadt')                       // ordering party (sender)
    ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
    ->withBuyerEndpoint('04011000-12345-67', '0204')       // Leitweg-ID
    ->withSeller('Lieferant GmbH', 'DE123456789')          // supplier (recipient)
    ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
    ->withSellerEndpoint('DE123456789', '9930')
    ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
    ->build();

$xml = $order->toUblXml();

// Parsing back
use ERechnungToolkit\Parsers\OrderParser;
$parsed = (new OrderParser)->parse($xml);
```

> The bundled KoSIT validator is scenario-driven and can validate orders once the
> official XBestellung validation artifacts are added to `data/kosit/scenarios.xml`.
> See [docs/XBestellung](docs/XBestellung/README.md) for details.

## Supporting Documents, Periods and Object Identifiers (since 0.21.0)

Timesheets and activity reports travel inside the e-invoice as **additional
supporting documents** (BG-24): `SupportingDocument` carries the reference
(BT-122, mandatory), a description (BT-123), an optional link (BT-124) and the
embedded file (BT-125) with MIME code and filename. German tax law only accepts
the embedded file as part of the invoice; a link alone does not count (BMF
letters of 15 Oct 2025, Rn. 35, and 15 Oct 2026, Rn. 12). The time of supply
belongs in the structured part: `Period` for the invoicing period (BG-14) and the
line period (BG-26), next to the delivery date (BT-72).

```php
use ERechnungToolkit\Entities\{InvoiceLine, Period, SupportingDocument};
use ERechnungToolkit\Enums\AttachmentMimeCode;

$invoice = ERechnungDocumentBuilder::xrechnung('XR-2026-0818', $leitwegId)
    // seller, buyer, payment ...
    ->withInvoicingPeriod(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'))
    ->withInvoicedObject('SLA-2026-01')                              // BT-18
    ->addSupportingDocument(SupportingDocument::embedded(
        'LN-1', $pdfBytes, AttachmentMimeCode::PDF, 'nachweis-1.pdf', 'Leistungsnachweis September 2026'
    ))
    ->addSupportingDocument(SupportingDocument::fromFile('LN-2', '/path/stunden.csv'))
    ->addInvoiceLine(new InvoiceLine(
        id: '1', quantity: 2.5, unitCode: UnitCode::HOUR, /* amounts, tax ... */
        period: Period::between(new DateTimeImmutable('2026-09-02'), new DateTimeImmutable('2026-09-09')),
        objectIdentifier: 'TICKET-4711',                             // BT-128
    ))
    ->build();
```

- `AttachmentMimeCode` lists the codes every recipient must accept: PDF, PNG,
  JPEG, CSV, XLSX, ODS (EN 16931 BR-CL-24, Peppol CL001); the XRechnung
  extension adds `application/xml` (BR-DEX-01).
- The generator writes BG-24 as `cac:AdditionalDocumentReference` (UBL) or
  `ram:AdditionalReferencedDocument` with TypeCode 916 (CII); BT-18 and BT-128
  use document type 130. Factur-X MINIMUM, BASIC WL and BASIC have no
  referenced documents, MINIMUM no invoicing period and BASIC WL no line period
  (`ERechnungProfile::supportsReferencedDocuments()`, `supportsInvoicingPeriod()`,
  `supportsLinePeriod()`); the CII output leaves them out there.
- `ERechnungParser` reads all of it back. `new ERechnungParser(maxAttachmentBytes: …, maxAttachments: …)`
  keeps attachments over the limits without content and reports them in `getNotices()`,
  as it does for invalid Base64.
- `DocumentRulesValidator` checks the data model before XML exists: BR-52,
  BR-CL-24, BR-DE-22 (ignoring case, as the rule text demands — the KoSIT
  validator compares exactly), missing MIME code or filename, path characters,
  empty files, extension and content that do not fit the MIME code, the profile,
  and the period rules BR-29, BR-30, BR-CO-19, BR-CO-20 and PEPPOL-EN16931-R110/R111.
  A link in BT-124 is reported as information.
- Recipients set size limits, the standard does not: Base64 adds about a third
  (`SupportingDocument::encodedSizeBytes()`).

### Adding supporting documents to an existing invoice

An invoice issued by another system (UBL Invoice, UBL CreditNote or CII) is
extended in place, not regenerated: parsing into a `Document` and generating
again would drop everything the data model does not know.
`SupportingDocumentAugmenter` inserts the new BG-24 elements behind the existing
ones (otherwise where UBL 2.1 or CII D16B expects them) and keeps every other
node: number, parties, lines, taxes, payment data, foreign extensions,
comments, processing instructions, namespace prefixes and whitespace. The new
elements are built by the same code as in the generator
(`Helper\SupportingDocumentXml`). The result is canonically equal to the
original apart from the new elements; libxml may still write the XML
declaration, quotes and empty elements in its own form.

```php
use ERechnungToolkit\Enums\AugmentFailure;
use ERechnungToolkit\Exceptions\AugmentException;
use ERechnungToolkit\Generators\SupportingDocumentAugmenter;

$augmenter = new SupportingDocumentAugmenter();

$augmenter->detectSyntax($xml);   // ERechnungSyntax::UBL_INVOICE ('ubl-invoice'), UBL_CREDIT_NOTE ('ubl-creditnote'), CII ('cii') or null
$augmenter->isSigned($xml);       // ds:Signature anywhere in the document
$augmenter->canAugment($xml);     // AugmentFailure or null, without documents

try {
    $xml = $augmenter->augment($xml, [
        SupportingDocument::embedded('LN-4', $pdfBytes, AttachmentMimeCode::PDF, 'nachweis-4.pdf', 'Leistungsnachweis Oktober'),
    ]);
} catch (AugmentException $e) {
    $e->getReason(); // e.g. AugmentFailure::DUPLICATE_FILENAME
}
```

`augment()` changes nothing silently; it throws `AugmentException` with an
`AugmentFailure` reason: `invalid_xml`, `unsupported_syntax` (anything but the
three roots, also ZUGFeRD 1.0), `signed` (XML signature), `unsupported_profile`
(MINIMUM, BASIC WL, BASIC — `ERechnungProfile::fromSpecificationIdentifier()`
also reads the Factur-X form `…#compliant#urn:factur-x.eu:1p0:basic`),
`invalid_structure`, `invalid_document` (BR-52, BR-CL-24 and the attachment
checks of `DocumentRulesValidator`), `duplicate_filename` (BR-DE-22, ignoring
case, against every embedded file of the invoice) and `duplicate_reference`
(BT-122 of the existing supporting documents; the invoiced object, type 130,
does not count).

`ZugferdPdfAugmenter` does the same for a ZUGFeRD/Factur-X PDF (php-pdf-toolkit
0.31 or newer, `pdfdetach` and `mutool`): it takes the embedded XML, extends it
and lets `ZugferdWriter::embedIntoPdf()` write a new PDF/A-3 with the pages of
the original unchanged, the new XML under the original name (e.g.
`factur-x.xml`, `zugferd-invoice.xml`, `xrechnung.xml`; AFRelationship
`Alternative`, conformance level in the XMP from the profile of the XML), the
new embedded documents and the other files of the original as `Supplement`.

```php
use ERechnungToolkit\Generators\ZugferdPdfAugmenter;

$pdfAugmenter = new ZugferdPdfAugmenter();
$pdfAugmenter->canAugment('/path/rechnung.pdf'); // AugmentFailure or null
$pdfBytes = $pdfAugmenter->augment('/path/rechnung.pdf', $documents);
$pdfAugmenter->getNotices();                      // files taken over from the original
```

Additional reasons: `pdf_toolkit_unavailable`, `invalid_pdf`, `encrypted`,
`signed` (digital signature), `no_invoice_xml`, `attachment_unreadable` (a file
of the original could not be read and would be lost) and `pdf_write_failed`.
Writing a new PDF loses what is not page content: bookmarks, links and other
annotations, form fields, structure tags and the metadata of the original. Files
taken over from the original keep name and content but lose description,
original relationship and dates (MIME type from the content); `getNotices()`
lists them.

## Deliver-to, VAT Categories, Registration Identifiers and Leitweg-ID (since 0.22.0)

```php
use ERechnungToolkit\Entities\LeitwegId;
use ERechnungToolkit\Enums\TaxCategory;

$invoice = ERechnungDocumentBuilder::xrechnung('XR-2026-0901', '04011000-12345-03')
    // seller, buyer, payment ...
    ->withDeliveryAddress('Havenweg 5', '3011 AA', 'Rotterdam', 'NL', name: 'Lager Rotterdam') // BG-15, BT-80, BT-70
    ->withTaxExemptionReasonFor(TaxCategory::INTRA_COMMUNITY, 'Steuerfreie innergemeinschaftliche Lieferung', 'VATEX-EU-IC')
    ->withBuyerLegalRegistration('HRB 98765')                                                   // BT-47
    ->addLine('Werkbank', 2, 1250.00, 0.0, UnitCode::PIECE, TaxCategory::INTRA_COMMUNITY)
    ->build();

LeitwegId::of('04011000-12345-03');          // throws InvalidArgumentException with the reason
LeitwegId::isValid('04011000-12345-67');     // false: check digits 67, expected 03
LeitwegId::checkDigitsFor('04011000', '12345'); // '03'
```

- **Deliver-to (BG-13):** `withDeliveryAddress()` sets the deliver-to address
  (BG-15) with the country code BT-80 that intra-community supplies need
  (BR-IC-12; BR-57 makes it mandatory for every address). XRechnung also wants
  city and post code (BR-DE-10, BR-DE-11). `withDeliveryParty(Party)` adds the
  location identifier BT-71 from the party identifier and scheme. UBL writes
  `cac:Delivery/cac:DeliveryLocation` and `cac:DeliveryParty`, CII
  `ram:ShipToTradeParty` (not in Factur-X MINIMUM, see
  `ERechnungProfile::supportsDeliverTo()`).
- **Not subject to VAT (`O`):** lines, allowances and charges carry no VAT rate
  (BR-O-05 to BR-O-07, `TaxCategory::hasRate()`); the VAT breakdown keeps the
  rate 0, which XRechnung requires (BR-DE-14). The totals count `O` without tax
  even if a line carries a rate.
- **Exemption reason per category:** `withTaxExemptionReasonFor()` (or
  `Document::setTaxExemptionFor()`) sets BT-120/BT-121 for one category. The
  common `withTaxExemptionReason()` stays as the default for the categories
  without their own reason. Only E, AE, K, G and O carry a reason
  (`TaxCategory::requiresExemptionReason()`); S, Z, L and M never do (BR-S-10,
  BR-Z-10, BR-AF-10, BR-AG-10) — the per-category call throws for them.
- **Legal registration identifier (BT-30, BT-47):** `Party::withLegalRegistration()`,
  `withSellerLegalRegistration()`, `withBuyerLegalRegistration()`; UBL
  `cac:PartyLegalEntity/cbc:CompanyID`, CII `ram:SpecifiedLegalOrganization/ram:ID`.
  For reverse charge to a buyer without VAT identifier BT-47 fulfils BR-AE-02.
- **Parser:** reads BG-13 into `getDeliveryParty()`, BT-121 into
  `TaxSubtotal::getExemptionReasonCode()` and BT-30/BT-47 into
  `Party::getLegalRegistrationId()`.
- **Leitweg-ID:** `LeitwegId` checks the format specification 2.0.2 of 28 July
  2021 (KoSIT): coarse address of 2, 3, 5, 8, 9 or 12 digits starting with a
  state code 01–16 or 99, optional fine address of up to 30 characters A–Z/0–9,
  two check digits ISO/IEC 7064 Mod 97-10, hyphens as separators.
  `LeitwegId::normalize()` uppercases, removes whitespace and turns typographic
  dashes into hyphens. `ParticipantId::leitwegId()`, `Party::withLeitwegId()`,
  `withBuyerLeitwegId()` and the `xrechnung()` factories normalize but do not
  reject; `DocumentRulesValidator` reports an invalid Leitweg-ID of a buyer
  addressed with scheme 0204 (BT-49, BT-10) as warning `TK-LEITWEG-ID`.

Upgrading from 0.21: `withTaxExemptionReason()` no longer reaches zero-rated
breakdowns (it made them fail BR-Z-10); `O` lines and allowances lose their
rate; the UBL parser no longer reads `cac:PartyLegalEntity/cbc:CompanyID` as
tax registration number (it is BT-30/BT-47, `getLegalRegistrationId()`); both
parsers keep an address without street; CII writes and reads the country
subdivision (BT-39, BT-54, BT-79); Leitweg-IDs passed to the existing entry
points are normalized (uppercase, no whitespace).

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
- Carries the supporting documents of the invoice a second time as PDF/A-3
  files with AFRelationship `Supplement` (php-pdf-toolkit 0.31 or newer; option
  `embed_supporting_documents`, default `true`); the CII XML holds them in any case

## Validation

Two layers:

**XSD schema (pure PHP, no Java)** — `UblSchemaValidator` validates UBL documents
(Invoice, CreditNote, Order, DespatchAdvice) against the bundled official OASIS
UBL 2.1 schemas via libxml. Catches structure / data type / element-order errors.

```php
use ERechnungToolkit\Validators\UblSchemaValidator;

$errors = (new UblSchemaValidator)->validate($document->toUblXml()); // [] = valid
```

`CiiSchemaValidator` does the same for UN/CEFACT CII (`rsm:CrossIndustryInvoice`,
XRechnung-CII and ZUGFeRD/Factur-X) against the bundled CII D16B schema. D16B is
the superset of the Factur-X profile schemas, so a narrow profile (MINIMUM,
BASIC WL) can still carry elements it does not allow.

```php
use ERechnungToolkit\Validators\CiiSchemaValidator;

$errors = (new CiiSchemaValidator)->validate($document->toCiiXml()); // [] = valid
```

**XRechnung in both syntaxes.** `generateUbl()` and `generateCii()` produce an
XRechnung that the KoSIT validator accepts without warnings — including credit
notes (BT-25), line and document allowances and tax-exempt invoices
(`tests/Generators/XRechnungConformanceTest.php`). UBL stays the common choice;
use CII when a recipient asks for it.

**Small businesses without a VAT ID (§ 19 UStG).** EN 16931 rule BR-CO-26 requires
the seller identifier (BT-29), the legal registration identifier (BT-30) or the VAT
identifier (BT-31). A tax number alone (BT-32) is not enough — KoSIT rejects the
invoice. Pass the tax number as seller identifier as well:

```php
$document = ERechnungDocumentBuilder::xrechnung('XR-2026-0818', $leitwegId)
    ->withSeller('Kleinbetrieb Anna Schmidt', '', '201/987/65432') // no VAT ID, tax number BT-32
    ->withSellerIdentifier('201/987/65432')                       // BT-29
    ->withTaxExemptionReason('Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG')
    // …
    ->build();
```

**Business rules (EN16931 / XRechnung)** — validate UBL/CII against XML Schema, the
EN16931 Schematron rules and the XRechnung CIUS using the official [KoSIT validator](https://github.com/itplr-kosit/validator).
Both the validator jar (`tools/kosit/validator.jar`) and its configuration
(scenarios + schemas, `data/kosit/`) ship with this package, so validation works
out of the box.

Because the Schematron rules are distributed as XSLT 2.0 (not executable by PHP's
XSLT 1.0 engine), the validator runs as an external Java process.

### Validation Requirements

- A Java runtime (`java` on `PATH`) — availability is checked through the
  Common-Toolkit executable configuration; the call runs via `Java::execute()`
- The KoSIT validator jar — configured as a `javaExecutables` entry in
  `config/erechnung_executables.json` (default `tools/kosit/validator.jar`).
  Point `path` at your own jar there, or override per call with an explicit
  path or the `KOSIT_VALIDATOR_JAR` env var

```php
use ERechnungToolkit\Validators\KositValidator;

$validator = new KositValidator(); // uses the bundled jar + Java from PATH

if ($validator->isAvailable()) {
    $result = $validator->validate($ublXml);          // or ->validateFile('/path/to/invoice.xml')

    if ($result->isValid()) {
        echo 'Konform: ' . $result->getScenarioName();
    } else {
        foreach ($result->getErrors() as $error) {
            echo $error->getCode() . ': ' . $error->getText() . PHP_EOL;
        }
    }
}
```

`ValidationResult` exposes the conformance verdict (`isValid()`), the
accept/reject recommendation (`isAccepted()`), all rule messages by severity
(`getErrors()`, `getWarnings()`, `getMessages()`), whether a validation
scenario matched at all (`isScenarioMatched()` - false for formats the
configuration does not know, e.g. ZUGFeRD 1.0: rejected without any rule
message) and the raw KoSIT report (`getRawReport()`).

The validator's exit code is its verdict (1 = at least one document rejected)
and is deliberately not treated as a failure: the call runs with
`expectedResultCode: null` (common-toolkit >= 2.11), so a rejected invoice
produces no warning in the log.

## Peppol

Format-neutral building blocks for the Peppol network — identifiers, envelope,
participant lookup and the BIS core rules. The toolkit deliberately does **not**
implement an AS4 access point: PKI, certification and operations stay with a
certified provider. `AccessPointClientInterface` is the seam an application
implements for its provider.

### Identifiers

```php
use ERechnungToolkit\Peppol\{DocumentTypeId, ParticipantId};

$seller = ParticipantId::germanVatId('DE123456789');   // 9930:DE123456789
$buyer  = ParticipantId::leitwegId('04011000-12345-67'); // 0204:...
$buyer->canonical();  // iso6523-actorid-upis::0204:04011000-12345-67
$buyer->urlEncoded(); // for SMP URLs
$buyer->getIcdLabel(); // "DE:LWID (Leitweg-ID)"

$documentTypeId = DocumentTypeId::peppolBisBillingInvoice();
$fromDocument   = DocumentTypeId::fromUbl($invoiceXml); // derived from root element + CustomizationID
```

Participant identifiers are validated against the Peppol identifier policy
(4-digit ICD, max. 50 characters) and compared case-insensitively.

### SBDH envelope (Peppol Business Message Envelope 2.0)

```php
use ERechnungToolkit\Peppol\Sbdh;

$sbdh = Sbdh::forUbl($invoiceXml, $seller, $buyer, 'DE'); // COUNTRY_C1 = sender country
$envelope = $sbdh->envelope($invoiceXml);

$parsed  = Sbdh::parse($envelope);       // header data
$payload = Sbdh::payloadOf($envelope);   // the UBL document
```

Document type, standard, root element, syntax version and the process id
(`cbc:ProfileID`) are derived from the document; instance identifier defaults to
a UUID v4.

### SML / SMP lookup

```php
use ERechnungToolkit\Enums\SmlZone;
use ERechnungToolkit\Peppol\Http\Psr18SmpClient;
use ERechnungToolkit\Peppol\SmpLookup;

// DNS name of the participant (NAPTR/BDXL: base32(sha256), lowercase, unpadded)
SmpLookup::dnsName($buyer, SmlZone::PRODUCTION);
// legacy CNAME scheme: "B-" + md5
SmpLookup::legacyDnsName($buyer, SmlZone::LEGACY_PRODUCTION);

$lookup = new SmpLookup(new Psr18SmpClient($psr18Client, $psr17RequestFactory));

$group = $lookup->fetchServiceGroup($buyer, 'https://smp.example.org');
if ($group->supports($documentTypeId)) {
    $endpoint = $lookup->resolveEndpoint($buyer, $documentTypeId, 'https://smp.example.org');
    $endpoint?->getUrl();              // AS4 delivery address of the receiver
    $endpoint?->getCertificateInfo();  // subject/issuer/validity (needs ext-openssl)
}
```

HTTP and DNS stay outside the toolkit: `SmpHttpClientInterface` (PSR-18 adapter
included) and `DnsNaptrResolverInterface` (`SystemNaptrResolver` uses
`dns_get_record`) are injected, so lookups are testable without network access.
Not implemented: verifying the XML signature of SMP responses and `Redirect`
entries — both belong to the access point provider.

### BIS core rules

```php
use ERechnungToolkit\Peppol\BisValidator;

$result = (new BisValidator)->validate($invoiceXml);
$result = (new BisValidator)->validateEnvelope($envelope); // envelope + payload
```

`BisValidator` is a **documented subset**, not a conformance statement: it covers
the EN 16931 mandatory fields (BR-01..BR-16, BR-62/BR-63), the sum rules
BR-CO-10/BR-CO-15 and the Peppol rules PEPPOL-EN16931-R001/-R003/-R004/-R010/
-R020/-R053, the MIME code list of attachments PEPPOL-EN16931-CL001 and the
German filename rule DE-R-022 — the rules that are decidable without Schematron. For the full rule
set inject a Schematron validator (`new BisValidator($kositValidator)`); its
messages are merged into the same `ValidationResult`.

### Sending and receiving

```php
use ERechnungToolkit\Contracts\AccessPointClientInterface;

final class MyProviderClient implements AccessPointClientInterface {
    public function isAvailable(): bool { /* ... */ }
    public function send(string $sbdhEnvelopeXml): TransportReceipt { /* ... */ }
    public function receive(int $limit = 50): array { /* list<InboundDocument> */ }
    public function acknowledge(string $messageId): bool { /* ... */ }
}
```

`TransportReceipt` carries the technical delivery status (`PeppolTransportStatus`),
message id, timestamp and the raw provider response; `InboundDocument` wraps a
received SBDH envelope and exposes header and payload.

## License

AGPL-3.0-or-later

## Author

Daniel Jörg Schuppelius - [schuppelius.org](https://schuppelius.org)

## Versions

Releases are tagged in Git; `git tag --sort=-v:refname` lists them (latest: v0.19.0). There is no separate changelog file — the tags and the commit history are the record.
