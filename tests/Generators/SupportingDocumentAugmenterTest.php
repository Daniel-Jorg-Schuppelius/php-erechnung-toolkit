<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentAugmenterTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\SupportingDocument;
use ERechnungToolkit\Enums\{AttachmentMimeCode, AugmentFailure, ERechnungProfile, ERechnungSyntax};
use ERechnungToolkit\Exceptions\AugmentException;
use ERechnungToolkit\Generators\{ERechnungGenerator, SupportingDocumentAugmenter};
use ERechnungToolkit\Helper\SupportingDocumentXml;
use ERechnungToolkit\Parsers\ERechnungParser;
use ERechnungToolkit\Validators\{CiiSchemaValidator, KositValidator, UblSchemaValidator};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

/**
 * Ergänzung fremd erzeugter Rechnungen um Unterlagen (BG-24): schemakonform,
 * KoSIT-valide, und außer den neuen Elementen bleibt jeder Knoten gleich.
 */
class SupportingDocumentAugmenterTest extends BaseTestCase {
    use XRechnungDocuments;

    private const NEW_REFERENCES = ['LN-4', 'LN-5', 'LN-6'];

    private const EXT_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private SupportingDocumentAugmenter $augmenter;

    protected function setUp(): void {
        parent::setUp();
        $this->augmenter = new SupportingDocumentAugmenter;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invoices(): array {
        return [
            'UBL Rechnung mit Unterlagen' => ['ubl-supporting'],
            'UBL Gutschrift mit Unterlagen' => ['ubl-supporting-credit'],
            'CII Rechnung mit Unterlagen' => ['cii-supporting'],
            'UBL Rechnung ohne Unterlagen' => ['ubl-plain'],
            'UBL Gutschrift ohne Unterlagen' => ['ubl-plain-credit'],
            'CII Rechnung ohne Unterlagen' => ['cii-plain'],
            'CII Gutschrift ohne Unterlagen' => ['cii-plain-credit'],
        ];
    }

    #[DataProvider('invoices')]
    public function test_augmented_invoice_is_schema_valid_and_otherwise_unchanged(string $kind): void {
        $xml = $this->invoiceXml($kind);
        $augmented = $this->augmenter->augment($xml, $this->newDocuments());

        $validator = str_starts_with($kind, 'cii') ? new CiiSchemaValidator : new UblSchemaValidator;
        $this->assertSame([], $validator->validate($augmented));

        // Ohne die neuen Elemente ist das Dokument kanonisch das Original, Kommentare eingeschlossen.
        $this->assertSame($this->canonical($xml), $this->canonical($augmented, self::NEW_REFERENCES));

        $before = (new ERechnungParser)->parse($xml)->getSupportingDocuments();
        $after = (new ERechnungParser)->parse($augmented)->getSupportingDocuments();
        $this->assertSame(
            [...$this->summaries($before), ...$this->summaries($this->newDocuments())],
            $this->summaries($after),
            'Existing documents stay first and unchanged, the new ones follow in order'
        );
    }

    #[DataProvider('invoices')]
    public function test_augmented_invoice_is_kosit_valid(string $kind): void {
        $validator = new KositValidator;
        if (!$validator->isAvailable()) {
            $this->markTestSkipped('KoSIT-Validator nicht verfügbar (Java-Laufzeit fehlt).');
        }

        $result = $validator->validate($this->augmenter->augment($this->invoiceXml($kind), $this->newDocuments()));

        $this->assertTrue(
            $result->isValid(),
            "{$kind} ist nicht valide: " . implode('; ', array_map(
                static fn ($e) => $e->getCode() . ' ' . $e->getText(),
                [...$result->getErrors(), ...$result->getWarnings()]
            ))
        );
    }

    /**
     * Ergänzung und Generator bauen dieselben BG-24-Elemente.
     *
     * @return array<string, array{bool}>
     */
    public static function syntaxes(): array {
        return ['UBL' => [false], 'CII' => [true]];
    }

    #[DataProvider('syntaxes')]
    public function test_augmenter_writes_the_same_elements_as_the_generator(bool $cii): void {
        $generator = new ERechnungGenerator;
        $withDocuments = $this->supportingDocumentXRechnung();
        $generated = $cii ? $generator->generateCii($withDocuments) : $generator->generateUbl($withDocuments);
        $plain = $cii ? $generator->generateCii($this->fullXRechnung()) : $generator->generateUbl($this->fullXRechnung());

        $augmented = $this->augmenter->augment($plain, $withDocuments->getSupportingDocuments());

        foreach (['LN-1', 'LN-2', 'LN-3'] as $reference) {
            $this->assertSame($this->exclusiveElement($generated, $reference), $this->exclusiveElement($augmented, $reference), $reference);
        }
    }

    public function test_without_documents_the_input_comes_back_unchanged(): void {
        $xml = $this->invoiceXml('ubl-supporting');

        $this->assertSame($xml, $this->augmenter->augment($xml, []));
    }

    public function test_new_elements_follow_the_last_existing_document(): void {
        $augmented = $this->augmenter->augment($this->invoiceXml('ubl-supporting'), $this->newDocuments());
        $x = $this->xpath($augmented);

        $ids = [];
        foreach ($x->query('/*/cac:AdditionalDocumentReference/cbc:ID') ?: [] as $node) {
            $ids[] = $node->nodeValue;
        }
        $this->assertSame(['SLA-2026-01', 'LN-1', 'LN-2', 'LN-3', 'LN-4', 'LN-5', 'LN-6'], $ids);
        $this->assertSame('AccountingSupplierParty', $this->nextElementName($x, "/*/cac:AdditionalDocumentReference[cbc:ID='LN-6']"));
    }

    public function test_cii_documents_go_before_the_following_schema_elements(): void {
        $xml = $this->invoiceXml('cii-plain');
        // ProcuringProject folgt laut Schema auf AdditionalReferencedDocument
        $xml = str_replace(
            '</ram:ApplicableHeaderTradeAgreement>',
            '<ram:SpecifiedProcuringProject><ram:ID>P-1</ram:ID><ram:Name>Projekt</ram:Name></ram:SpecifiedProcuringProject></ram:ApplicableHeaderTradeAgreement>',
            $xml
        );

        $augmented = $this->augmenter->augment($xml, $this->newDocuments());

        $this->assertSame([], (new CiiSchemaValidator)->validate($augmented));
        $this->assertSame(
            'SpecifiedProcuringProject',
            $this->nextElementName($this->xpath($augmented), "//ram:ApplicableHeaderTradeAgreement/ram:AdditionalReferencedDocument[ram:IssuerAssignedID='LN-6']")
        );
    }

    public function test_existing_prefixes_are_reused(): void {
        $ubl = strtr($this->invoiceXml('ubl-plain'), ['<cac:' => '<a:', '</cac:' => '</a:', 'xmlns:cac=' => 'xmlns:a=', '<cbc:' => '<b:', '</cbc:' => '</b:', 'xmlns:cbc=' => 'xmlns:b=']);
        $augmented = $this->augmenter->augment($ubl, $this->newDocuments());

        $this->assertStringContainsString('<a:AdditionalDocumentReference><b:ID>LN-4</b:ID>', $augmented);
        $this->assertStringNotContainsString('xmlns:cac=', $augmented);
        $this->assertStringNotContainsString('xmlns:cbc=', $augmented);
        $this->assertSame([], (new UblSchemaValidator)->validate($augmented));
        $this->assertSame($this->canonical($ubl), $this->canonical($augmented, self::NEW_REFERENCES));

        $cii = strtr($this->invoiceXml('cii-plain'), ['<ram:' => '<r:', '</ram:' => '</r:', 'xmlns:ram=' => 'xmlns:r=']);
        $augmented = $this->augmenter->augment($cii, $this->newDocuments());

        $this->assertStringContainsString('<r:AdditionalReferencedDocument><r:IssuerAssignedID>LN-4</r:IssuerAssignedID>', $augmented);
        $this->assertStringNotContainsString('xmlns:ram=', $augmented);
        $this->assertSame([], (new CiiSchemaValidator)->validate($augmented));
    }

    /**
     * Deklariert das Original cbc nur an den einzelnen Elementen, trägt das
     * neue Element die Deklaration selbst.
     */
    public function test_missing_namespace_declaration_is_added_to_the_new_element(): void {
        $xml = $this->invoiceXml('ubl-plain');
        $xml = (string) preg_replace('/(<Invoice[^>]*?) xmlns:cbc="[^"]*"/', '$1', $xml, 1);
        $xml = (string) preg_replace('/<cbc:(\w+)(?![^>]*xmlns:cbc=)/', '<cbc:$1 xmlns:cbc="' . SupportingDocumentXml::CBC_NS . '"', $xml);
        $root = new DOMDocument;
        $root->loadXML($xml);
        $this->assertNull($root->documentElement?->lookupPrefix(SupportingDocumentXml::CBC_NS), 'Precondition: root does not declare cbc');

        $augmented = $this->augmenter->augment($xml, $this->newDocuments());

        $this->assertSame([], (new UblSchemaValidator)->validate($augmented));
        $this->assertSame($this->canonical($xml), $this->canonical($augmented, self::NEW_REFERENCES));
        $this->assertCount(3, $this->xpath($augmented)->query("/*/cac:AdditionalDocumentReference[starts-with(cbc:ID, 'LN-')]") ?: []);
    }

    public function test_foreign_extensions_comments_and_instructions_are_kept(): void {
        $foreign = '<ext:UBLExtensions xmlns:ext="' . self::EXT_NS . '"><ext:UBLExtension><ext:ExtensionContent>'
            . '<foo:Bar xmlns:foo="urn:example:foo" foo:attr="1">Wert &amp; mehr</foo:Bar>'
            . '</ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>';
        $xml = (string) preg_replace('/(<Invoice[^>]*>)/', '$1<!-- Fremdsystem 4.2 -->' . $foreign . '<?fremd verarbeitung="ja"?>', $this->invoiceXml('ubl-supporting'), 1);
        $xml = str_replace('</cac:AdditionalDocumentReference>', '</cac:AdditionalDocumentReference><!-- zwischen -->', $xml);

        $augmented = $this->augmenter->augment($xml, $this->newDocuments());

        $this->assertStringContainsString('<!-- Fremdsystem 4.2 -->' . $foreign . '<?fremd verarbeitung="ja"?>', $augmented);
        $this->assertSame(substr_count($xml, '<!-- zwischen -->'), substr_count($augmented, '<!-- zwischen -->'));
        $this->assertSame([], (new UblSchemaValidator)->validate($augmented));
        $this->assertSame($this->canonical($xml), $this->canonical($augmented, self::NEW_REFERENCES));

        $cii = str_replace('<ram:SellerTradeParty>', '<!-- Verkäufer --><?fremd x?><ram:SellerTradeParty>', $this->invoiceXml('cii-supporting'));
        $augmented = $this->augmenter->augment($cii, $this->newDocuments());

        $this->assertStringContainsString('<!-- Verkäufer --><?fremd x?><ram:SellerTradeParty>', $augmented);
        $this->assertSame($this->canonical($cii), $this->canonical($augmented, self::NEW_REFERENCES));
    }

    public function test_detects_syntax_signature_and_profile(): void {
        $this->assertSame(ERechnungSyntax::UBL_INVOICE, $this->augmenter->detectSyntax($this->invoiceXml('ubl-plain')));
        $this->assertSame(ERechnungSyntax::UBL_CREDIT_NOTE, $this->augmenter->detectSyntax($this->invoiceXml('ubl-plain-credit')));
        $this->assertSame(ERechnungSyntax::CII, $this->augmenter->detectSyntax($this->invoiceXml('cii-plain-credit')));
        $this->assertSame('ubl-creditnote', $this->augmenter->detectSyntax($this->invoiceXml('ubl-plain-credit'))?->value);
        $this->assertNull($this->augmenter->detectSyntax('<Order xmlns="urn:oasis:names:specification:ubl:schema:xsd:Order-2"/>'));
        $this->assertNull($this->augmenter->detectSyntax('kein xml'));
        $this->assertNull($this->augmenter->detectSyntax(''));

        $this->assertFalse($this->augmenter->isSigned($this->invoiceXml('ubl-plain')));
        $this->assertTrue($this->augmenter->isSigned($this->signedUbl()));
        $this->assertFalse($this->augmenter->isSigned('kein xml'));
        // cac:Signature verweist nur auf eine Signatur, ist selbst keine
        $this->assertFalse($this->augmenter->isSigned(str_replace('<cac:AccountingSupplierParty>', '<cac:Signature><cbc:ID>S-1</cbc:ID><cac:SignatoryParty/></cac:Signature><cac:AccountingSupplierParty>', $this->invoiceXml('ubl-plain'))));

        $this->assertSame(ERechnungProfile::XRECHNUNG, $this->augmenter->detectProfile($this->invoiceXml('cii-plain')));
        $this->assertNull($this->augmenter->detectProfile('kein xml'));

        $this->assertNull($this->augmenter->canAugment($this->invoiceXml('ubl-supporting')));
        $this->assertNull($this->augmenter->canAugment($this->invoiceXml('cii-plain')));
        $this->assertSame(AugmentFailure::SIGNED, $this->augmenter->canAugment($this->signedUbl()));
        $this->assertSame(AugmentFailure::INVALID_XML, $this->augmenter->canAugment('kein xml'));
    }

    /**
     * @return array<string, array{string, AugmentFailure}>
     */
    public static function rejectedInvoices(): array {
        return [
            'UBL signiert' => ['signed-ubl', AugmentFailure::SIGNED],
            'CII signiert' => ['signed-cii', AugmentFailure::SIGNED],
            'MINIMUM' => ['minimum', AugmentFailure::UNSUPPORTED_PROFILE],
            'BASIC WL' => ['basicwl', AugmentFailure::UNSUPPORTED_PROFILE],
            'BASIC' => ['basic', AugmentFailure::UNSUPPORTED_PROFILE],
            'BASIC in Factur-X-Schreibweise' => ['basic-facturx-urn', AugmentFailure::UNSUPPORTED_PROFILE],
            'UBL-Bestellung' => ['order', AugmentFailure::UNSUPPORTED_SYNTAX],
            'ZUGFeRD 1.0' => ['zugferd1', AugmentFailure::UNSUPPORTED_SYNTAX],
            'kaputtes XML' => ['malformed', AugmentFailure::INVALID_XML],
            'leer' => ['empty', AugmentFailure::INVALID_XML],
            'UBL ohne Verkäufer' => ['ubl-without-supplier', AugmentFailure::INVALID_STRUCTURE],
            'CII ohne Vereinbarung' => ['cii-without-agreement', AugmentFailure::INVALID_STRUCTURE],
        ];
    }

    #[DataProvider('rejectedInvoices')]
    public function test_unsuitable_invoices_are_rejected(string $kind, AugmentFailure $reason): void {
        $xml = match ($kind) {
            'signed-ubl' => $this->signedUbl(),
            'signed-cii' => str_replace('</rsm:CrossIndustryInvoice>', '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"/></rsm:CrossIndustryInvoice>', $this->invoiceXml('cii-plain')),
            'minimum' => $this->zugferdCii(ERechnungProfile::MINIMUM),
            'basicwl' => $this->zugferdCii(ERechnungProfile::BASIC_WL),
            'basic' => $this->zugferdCii(ERechnungProfile::BASIC),
            'basic-facturx-urn' => str_replace(
                '<ram:ID>' . ERechnungProfile::EN16931->value . '</ram:ID>',
                '<ram:ID>urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic</ram:ID>',
                $this->zugferdCii(ERechnungProfile::EN16931)
            ),
            'order' => '<Order xmlns="urn:oasis:names:specification:ubl:schema:xsd:Order-2"><cbc:ID xmlns:cbc="' . SupportingDocumentXml::CBC_NS . '">B-1</cbc:ID></Order>',
            'zugferd1' => '<rsm:CrossIndustryDocument xmlns:rsm="urn:ferd:CrossIndustryDocument:invoice:1p0"/>',
            'malformed' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><cbc:ID>',
            'empty' => '',
            'ubl-without-supplier' => $this->withoutElement($this->invoiceXml('ubl-plain'), '/*/cac:AccountingSupplierParty'),
            'cii-without-agreement' => $this->withoutElement($this->invoiceXml('cii-plain'), '//ram:ApplicableHeaderTradeAgreement'),
            default => throw new \LogicException("Unbekannter Fall {$kind}"),
        };

        $this->assertSame($reason, $this->augmenter->canAugment($xml));
        $this->assertRejected($reason, $xml, $this->newDocuments());
    }

    public function test_en16931_profile_is_accepted(): void {
        $this->assertNull($this->augmenter->canAugment($this->zugferdCii(ERechnungProfile::EN16931)));
        $this->assertNull($this->augmenter->canAugment($this->zugferdCii(ERechnungProfile::EXTENDED)));
    }

    /**
     * @return array<string, array{list<SupportingDocument>, AugmentFailure}>
     */
    public static function rejectedDocuments(): array {
        $csv = "a;b\n";

        return [
            'Dateiname vorhanden, andere Schreibweise' => [[SupportingDocument::embedded('LN-9', $csv, AttachmentMimeCode::CSV, 'NACHWEIS-2.CSV')], AugmentFailure::DUPLICATE_FILENAME],
            'Dateiname doppelt unter den neuen' => [[
                SupportingDocument::embedded('LN-8', $csv, AttachmentMimeCode::CSV, 'neu.csv'),
                SupportingDocument::embedded('LN-9', $csv, AttachmentMimeCode::CSV, 'Neu.csv'),
            ], AugmentFailure::DUPLICATE_FILENAME],
            'Kennung vorhanden' => [[SupportingDocument::external('LN-1', 'https://example.org/x')], AugmentFailure::DUPLICATE_REFERENCE],
            'Kennung doppelt unter den neuen' => [[
                SupportingDocument::external('LN-9', 'https://example.org/x'),
                SupportingDocument::external('LN-9', 'https://example.org/y'),
            ], AugmentFailure::DUPLICATE_REFERENCE],
            'Kennung leer' => [[SupportingDocument::external(' ', 'https://example.org/x')], AugmentFailure::INVALID_DOCUMENT],
            'MIME-Code nicht zulässig' => [[SupportingDocument::embedded('LN-9', 'PK', 'application/zip', 'archiv.zip')], AugmentFailure::INVALID_DOCUMENT],
            'Anhang ohne Dateinamen' => [[new SupportingDocument('LN-9', null, null, $csv, AttachmentMimeCode::CSV->value)], AugmentFailure::INVALID_DOCUMENT],
        ];
    }

    /**
     * @param list<SupportingDocument> $documents
     */
    #[DataProvider('rejectedDocuments')]
    public function test_unsuitable_documents_are_rejected(array $documents, AugmentFailure $reason): void {
        foreach (['ubl-supporting', 'cii-supporting'] as $kind) {
            $this->assertRejected($reason, $this->invoiceXml($kind), $documents);
        }
    }

    public function test_reference_of_the_invoiced_object_is_no_conflict(): void {
        $augmented = $this->augmenter->augment($this->invoiceXml('ubl-supporting'), [SupportingDocument::external('SLA-2026-01', 'https://example.org/sla')]);

        $this->assertSame(2.0, $this->xpath($augmented)->evaluate("count(/*/cac:AdditionalDocumentReference[cbc:ID='SLA-2026-01'])"));
    }

    /**
     * @param list<SupportingDocument> $documents
     */
    private function assertRejected(AugmentFailure $reason, string $xml, array $documents): void {
        try {
            $this->augmenter->augment($xml, $documents);
            $this->fail("Expected rejection {$reason->value}");
        } catch (AugmentException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * @return list<SupportingDocument>
     */
    private function newDocuments(): array {
        return [
            SupportingDocument::embedded('LN-4', "%PDF-1.4\n% Nachweis Oktober\n%%EOF\n", AttachmentMimeCode::PDF, 'nachweis-4.pdf', 'Leistungsnachweis Oktober & November'),
            SupportingDocument::embedded('LN-5', "datum;minuten\n2026-10-02;45\n", AttachmentMimeCode::CSV, 'nachweis-5.csv', 'Stunden <Oktober>'),
            SupportingDocument::external('LN-6', 'https://rechnung.example.org/anlage/456?teil=1&format=pdf', 'Anlage im Portal'),
        ];
    }

    private function invoiceXml(string $kind): string {
        [$syntax, $variant] = explode('-', $kind, 2);
        $document = match ($variant) {
            'supporting' => $this->supportingDocumentXRechnung(),
            'supporting-credit' => $this->supportingDocumentXRechnung(creditNote: true),
            'plain-credit' => $this->fullXRechnung(creditNote: true),
            default => $this->fullXRechnung(),
        };
        $generator = new ERechnungGenerator;

        return $syntax === 'cii' ? $generator->generateCii($document) : $generator->generateUbl($document);
    }

    private function zugferdCii(ERechnungProfile $profile): string {
        return (new ERechnungGenerator)->generateCii(
            ERechnungDocumentBuilder::zugferd('RE-2026-0300', $profile)
                ->withIssueDate(new DateTimeImmutable('2026-10-01'))
                ->withSeller('Verkäufer GmbH', 'DE123456789')
                ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
                ->withBuyer('Kunde AG')
                ->withBuyerAddress('Kundenweg 2', '54321', 'München')
                ->addLine('Beratung', 1, 100.00)
                ->build()
        );
    }

    private function signedUbl(): string {
        $signature = '<ext:UBLExtensions xmlns:ext="' . self::EXT_NS . '"><ext:UBLExtension><ext:ExtensionContent>'
            . '<sig:UBLDocumentSignatures xmlns:sig="urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2">'
            . '<sac:SignatureInformation xmlns:sac="urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2">'
            . '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="sig-1"><ds:SignedInfo/></ds:Signature>'
            . '</sac:SignatureInformation></sig:UBLDocumentSignatures></ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>';

        return (string) preg_replace('/(<Invoice[^>]*>)/', '$1' . $signature, $this->invoiceXml('ubl-supporting'), 1);
    }

    private function withoutElement(string $xml, string $expression): string {
        $dom = new DOMDocument;
        $dom->loadXML($xml);
        foreach ($this->domXPath($dom)->query($expression) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $node->parentNode?->removeChild($node);
            }
        }

        return (string) $dom->saveXML();
    }

    /**
     * Inklusive C14N mit Kommentaren, ohne die Unterlagen mit den genannten Kennungen.
     *
     * @param list<string> $withoutReferences
     */
    private function canonical(string $xml, array $withoutReferences = []): string {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $this->assertTrue($dom->loadXML($xml));

        $x = $this->domXPath($dom);
        foreach ($withoutReferences as $reference) {
            $nodes = $x->query("//cac:AdditionalDocumentReference[cbc:ID='{$reference}'] | //ram:AdditionalReferencedDocument[ram:IssuerAssignedID='{$reference}']") ?: [];
            $this->assertCount(1, $nodes, "New element {$reference}");
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        return (string) $dom->C14N(false, true);
    }

    /**
     * Exklusive C14N ohne Leerraum-Knoten: Der Generator rückt ein, die Ergänzung nicht.
     */
    private function exclusiveElement(string $xml, string $reference): string {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        $nodes = $this->domXPath($dom)->query("//cac:AdditionalDocumentReference[cbc:ID='{$reference}'] | //ram:AdditionalReferencedDocument[ram:IssuerAssignedID='{$reference}']");
        $node = $nodes !== false ? $nodes->item(0) : null;
        $this->assertInstanceOf(DOMElement::class, $node, $reference);

        return (string) $node->C14N(true);
    }

    private function nextElementName(DOMXPath $x, string $expression): ?string {
        $nodes = $x->query($expression . '/following-sibling::*[1]');
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node->localName : null;
    }

    /**
     * @param array<SupportingDocument> $documents
     * @return list<array<string, string|null>>
     */
    private function summaries(array $documents): array {
        return array_map(static fn (SupportingDocument $d): array => [
            'reference' => $d->getReference(),
            'description' => $d->getDescription(),
            'uri' => $d->getExternalUri(),
            'mime' => $d->getMimeCode(),
            'filename' => $d->getFilename(),
            'content' => $d->getContent(),
        ], array_values($documents));
    }

    private function xpath(string $xml): DOMXPath {
        $dom = new DOMDocument;
        $dom->loadXML($xml);

        return $this->domXPath($dom);
    }

    private function domXPath(DOMDocument $dom): DOMXPath {
        $x = new DOMXPath($dom);
        $x->registerNamespace('cac', SupportingDocumentXml::CAC_NS);
        $x->registerNamespace('cbc', SupportingDocumentXml::CBC_NS);
        $x->registerNamespace('ram', SupportingDocumentXml::RAM_NS);
        $x->registerNamespace('rsm', ERechnungSyntax::CII->namespace());

        return $x;
    }
}
