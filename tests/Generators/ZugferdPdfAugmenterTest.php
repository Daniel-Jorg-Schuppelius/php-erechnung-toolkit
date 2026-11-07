<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ZugferdPdfAugmenterTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{Document, SupportingDocument};
use ERechnungToolkit\Enums\{AttachmentMimeCode, AugmentFailure, ERechnungProfile};
use ERechnungToolkit\Exceptions\AugmentException;
use ERechnungToolkit\Generators\{ERechnungGenerator, ZugferdPdfAugmenter, ZugferdPdfGenerator};
use ERechnungToolkit\Helper\SupportingDocumentXml;
use ERechnungToolkit\Parsers\{ERechnungParser, ZugferdPdfParser};
use PDFToolkit\Entities\PDFContent;
use PDFToolkit\Helper\{PDFHelper, PDFSecurityHelper};
use PDFToolkit\Writers\ZugferdWriter;
use Tests\Contracts\BaseTestCase;

/**
 * Ergänzung einer vorhandenen ZUGFeRD-PDF: neue XML unter dem alten Namen,
 * Unterlagen und Fremdanhänge als Supplement, Seiten unverändert.
 * Braucht php-pdf-toolkit ab 0.31; mit älteren Fassungen bleibt nur der
 * Test der Meldung „nicht verfügbar“.
 */
class ZugferdPdfAugmenterTest extends BaseTestCase {
    private ZugferdPdfAugmenter $augmenter;
    private string $workDir;

    protected function setUp(): void {
        parent::setUp();
        $this->augmenter = new ZugferdPdfAugmenter;
        $this->workDir = sys_get_temp_dir() . '/zugferd-augment-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700);
    }

    protected function tearDown(): void {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->workDir);
    }

    public function test_missing_toolkit_is_reported(): void {
        if ($this->augmenter->isAvailable()) {
            $this->markTestSkipped('php-pdf-toolkit >= 0.31 ist installiert');
        }

        $this->assertSame(AugmentFailure::PDF_TOOLKIT_UNAVAILABLE, $this->augmenter->canAugment($this->workDir . '/rechnung.pdf'));
        $this->assertRejected(AugmentFailure::PDF_TOOLKIT_UNAVAILABLE, $this->workDir . '/rechnung.pdf');
    }

    public function test_augmented_pdf_carries_the_new_xml_and_files(): void {
        $this->requireToolkit();
        $original = $this->zugferdPdf($this->invoice('RE-2026-0200', ERechnungProfile::EN16931));
        $originalXml = (string) (new ZugferdPdfParser)->extractXml($original);

        $this->assertNull($this->augmenter->canAugment($original));
        $augmented = $this->write('ergaenzt.pdf', $this->augmenter->augment($original, $this->newDocuments()));

        $this->assertSame(PDFHelper::getPageCount($original), PDFHelper::getPageCount($augmented));
        $this->assertSame(2, PDFHelper::getPageCount($augmented));

        // listAttachments() liest über pdfdetach -list
        $this->assertEqualsCanonicalizing(['factur-x.xml', 'nachweis-1.csv', 'nachweis-4.pdf', 'nachweis-5.csv'], (new ZugferdPdfParser)->listAttachments($augmented));
        $bytes = (string) file_get_contents($augmented);
        $this->assertSame(1, substr_count($bytes, '/AFRelationship /Alternative'));
        $this->assertSame(3, substr_count($bytes, '/AFRelationship /Supplement'));
        $this->assertStringContainsString('<fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>', $bytes);
        $this->assertStringContainsString('<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>', $bytes);

        $xml = (string) (new ZugferdPdfParser)->extractXml($augmented);
        $this->assertSame($this->canonical($originalXml), $this->canonical($xml, ['LN-4', 'LN-5', 'LN-6']), 'Invoice content is unchanged');
        $references = array_map(static fn (SupportingDocument $d): string => $d->getReference(), (new ERechnungParser)->parse($xml)->getSupportingDocuments());
        $this->assertSame(['LN-1', 'LN-4', 'LN-5', 'LN-6'], $references);
        $this->assertSame("%PDF-1.4\n% Nachweis Oktober\n%%EOF\n", $this->extract($augmented, 'nachweis-4.pdf'));
        $this->assertSame("datum;minuten\n2026-09-02;90\n", $this->extract($augmented, 'nachweis-1.csv'), 'Supplement of the original is carried over');

        $notices = $this->augmenter->getNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('nachweis-1.csv', $notices[0]);
    }

    /**
     * Ein Anhang, den die XML nicht kennt, kommt mit; sein Name ist für neue Unterlagen vergeben.
     */
    public function test_foreign_attachments_are_carried_over_and_keep_their_name(): void {
        $this->requireToolkit();
        $invoice = $this->invoice('RE-2026-0201', ERechnungProfile::EXTENDED);
        $content = PDFContent::fromHtml('<h1>Rechnung RE-2026-0201</h1>', ['invoice_xml' => (new ERechnungGenerator)->generateCii($invoice)]);
        $pdf = (new ZugferdWriter)->createPdfString($content, [
            'render_engine' => ZugferdWriter::ENGINE_TCPDF,
            'invoice_filename' => ZugferdWriter::INVOICE_FILENAME_ZUGFERD_LEGACY,
            'zugferd_profile' => ZugferdWriter::LEVEL_EXTENDED,
            'attachments' => [['name' => 'fremd.csv', 'content' => "x;y\n1;2\n", 'mime' => 'text/csv', 'relationship' => 'Data']],
        ]);
        $this->assertNotNull($pdf);
        $original = $this->write('fremd.pdf', $pdf);

        $augmented = $this->write('ergaenzt.pdf', $this->augmenter->augment($original, $this->newDocuments()));

        $this->assertEqualsCanonicalizing(['zugferd-invoice.xml', 'fremd.csv', 'nachweis-4.pdf', 'nachweis-5.csv'], (new ZugferdPdfParser)->listAttachments($augmented));
        $this->assertSame("x;y\n1;2\n", $this->extract($augmented, 'fremd.csv'));
        $bytes = (string) file_get_contents($augmented);
        $this->assertMatchesRegularExpression('#<(\w+):DocumentFileName>zugferd-invoice\.xml</\1:DocumentFileName>#', $bytes);
        $this->assertMatchesRegularExpression('#<(\w+):ConformanceLevel>EXTENDED</\1:ConformanceLevel>#', $bytes);
        $this->assertStringContainsString('fremd.csv', implode("\n", $this->augmenter->getNotices()));

        $this->assertRejected(AugmentFailure::DUPLICATE_FILENAME, $original, [SupportingDocument::embedded('LN-9', "a;b\n", AttachmentMimeCode::CSV, 'FREMD.CSV')]);
    }

    /**
     * Echte PDFs als Beilage (neu und schon im Original): Die Engine legt sie
     * roh ab; die Querverweise des neuen PDF müssen trotzdem stimmen.
     */
    public function test_real_pdf_attachments_keep_the_pdf_readable(): void {
        $this->requireToolkit();
        $existing = $this->tcpdfDocument('Nachweis September');
        $invoice = ERechnungDocumentBuilder::zugferd('RE-2026-0205', ERechnungProfile::EN16931)
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addSupportingDocument(SupportingDocument::embedded('LN-1', $existing, AttachmentMimeCode::PDF, 'nachweis-1.pdf', 'Nachweis September'))
            ->addLine('Beratung', 1, 100.00)
            ->build();
        $original = $this->zugferdPdf($invoice);
        $this->assertReadable($original, 2);

        $new = $this->tcpdfDocument('Nachweis Oktober');
        $augmented = $this->write('ergaenzt.pdf', $this->augmenter->augment($original, [
            SupportingDocument::embedded('LN-4', $new, AttachmentMimeCode::PDF, 'nachweis-4.pdf', 'Nachweis Oktober'),
        ]));

        $this->assertReadable($augmented, 2);
        $this->assertEqualsCanonicalizing(['factur-x.xml', 'nachweis-1.pdf', 'nachweis-4.pdf'], (new ZugferdPdfParser)->listAttachments($augmented));
        $this->assertSame($existing, $this->extract($augmented, 'nachweis-1.pdf'));
        $this->assertSame($new, $this->extract($augmented, 'nachweis-4.pdf'));
        $documents = (new ZugferdPdfParser)->parseFile($augmented)?->getSupportingDocuments() ?? [];
        $this->assertSame([$existing, $new], array_map(static fn (SupportingDocument $d): ?string => $d->getContent(), $documents));
    }

    private function assertReadable(string $pdfPath, int $pages): void {
        $this->assertSame($pages, PDFHelper::getPageCount($pdfPath));
        if (trim((string) shell_exec('command -v qpdf')) === '') {
            return;
        }
        $this->assertSame((string) $pages, trim((string) shell_exec('qpdf --show-npages ' . escapeshellarg($pdfPath) . ' 2>&1')));
        exec('qpdf --check ' . escapeshellarg($pdfPath) . ' 2>&1', $check, $code);
        $this->assertSame(0, $code, implode("\n", $check));
        $this->assertStringNotContainsString('WARNING', implode("\n", $check));
    }

    private function tcpdfDocument(string $text): string {
        $pdf = new \TCPDF;
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        foreach ([1, 2] as $page) {
            $pdf->AddPage();
            $pdf->Write(5, "{$text}, Seite {$page}");
        }

        return (string) $pdf->Output('', 'S');
    }

    public function test_without_documents_the_pdf_comes_back_unchanged(): void {
        $this->requireToolkit();
        $original = $this->zugferdPdf($this->invoice('RE-2026-0202', ERechnungProfile::EN16931));

        $this->assertSame(file_get_contents($original), $this->augmenter->augment($original, []));
    }

    public function test_unsuitable_pdfs_are_rejected(): void {
        $this->requireToolkit();
        $original = $this->zugferdPdf($this->invoice('RE-2026-0203', ERechnungProfile::EN16931));

        $locked = $this->workDir . '/verschluesselt.pdf';
        $this->assertTrue(PDFSecurityHelper::encrypt($original, $locked, 'geheim'));
        $this->assertSame(AugmentFailure::ENCRYPTED, $this->augmenter->canAugment($locked));
        $this->assertRejected(AugmentFailure::ENCRYPTED, $locked);

        $signed = $this->signedCopy($original);
        $this->assertSame(AugmentFailure::SIGNED, $this->augmenter->canAugment($signed));
        $this->assertRejected(AugmentFailure::SIGNED, $signed);

        $plain = $this->plainPdf();
        $this->assertSame(AugmentFailure::NO_INVOICE_XML, $this->augmenter->canAugment($plain));
        $this->assertRejected(AugmentFailure::NO_INVOICE_XML, $plain);

        $this->assertSame(AugmentFailure::INVALID_PDF, $this->augmenter->canAugment($this->workDir . '/fehlt.pdf'));
        $this->assertRejected(AugmentFailure::INVALID_PDF, $this->workDir . '/fehlt.pdf');

        $basic = $this->zugferdPdf($this->invoice('RE-2026-0204', ERechnungProfile::BASIC), 'basic.pdf');
        $this->assertSame(AugmentFailure::UNSUPPORTED_PROFILE, $this->augmenter->canAugment($basic));
        $this->assertRejected(AugmentFailure::UNSUPPORTED_PROFILE, $basic);

        $this->assertRejected(AugmentFailure::DUPLICATE_REFERENCE, $original, [SupportingDocument::external('LN-1', 'https://example.org/x')]);
    }

    /**
     * @param list<SupportingDocument>|null $documents
     */
    private function assertRejected(AugmentFailure $reason, string $pdfPath, ?array $documents = null): void {
        try {
            $this->augmenter->augment($pdfPath, $documents ?? $this->newDocuments());
            $this->fail("Expected rejection {$reason->value}");
        } catch (AugmentException $e) {
            $this->assertSame($reason, $e->getReason(), $e->getMessage());
        }
    }

    private function requireToolkit(): void {
        if (!$this->augmenter->isAvailable()) {
            $this->markTestSkipped('php-pdf-toolkit >= 0.31 mit pdfdetach und mutool ist nicht verfügbar');
        }
    }

    /**
     * @return list<SupportingDocument>
     */
    private function newDocuments(): array {
        return [
            SupportingDocument::embedded('LN-4', "%PDF-1.4\n% Nachweis Oktober\n%%EOF\n", AttachmentMimeCode::PDF, 'nachweis-4.pdf', 'Leistungsnachweis Oktober'),
            SupportingDocument::embedded('LN-5', "datum;minuten\n2026-10-02;45\n", AttachmentMimeCode::CSV, 'nachweis-5.csv', 'Stunden Oktober'),
            SupportingDocument::external('LN-6', 'https://rechnung.example.org/anlage/456', 'Anlage im Portal'),
        ];
    }

    private function invoice(string $id, ERechnungProfile $profile): Document {
        return ERechnungDocumentBuilder::zugferd($id, $profile)
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withBuyer('Kunde AG')
            ->withBuyerAddress('Kundenweg 2', '54321', 'München')
            ->addSupportingDocument(SupportingDocument::embedded('LN-1', "datum;minuten\n2026-09-02;90\n", AttachmentMimeCode::CSV, 'nachweis-1.csv', 'Stunden September'))
            ->addLine('Beratung', 1, 100.00)
            ->build();
    }

    private function zugferdPdf(Document $invoice, string $name = 'rechnung.pdf'): string {
        $html = '<h1>Rechnung ' . $invoice->getId() . '</h1><p>Seite 1</p><br pagebreak="true"/><p>Seite 2</p>';
        $pdf = (new ZugferdPdfGenerator)->generate($invoice, $html, ['render_engine' => ZugferdWriter::ENGINE_TCPDF]);
        $this->assertNotNull($pdf);

        return $this->write($name, $pdf);
    }

    private function plainPdf(): string {
        $pdf = new \TCPDF;
        $pdf->AddPage();
        $pdf->Write(5, 'Brief ohne Rechnung');

        return $this->write('brief.pdf', (string) $pdf->Output('', 'S'));
    }

    /**
     * Signierte Kopie der ersten Seite: selbst ausgestelltes Zertifikat, Signatur über tc-lib-pdf.
     */
    private function signedCopy(string $source): string {
        if (!extension_loaded('openssl') || !class_exists(\Com\Tecnick\Pdf\Tcpdf::class)) {
            $this->markTestSkipped('openssl oder tc-lib-pdf nicht verfügbar');
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'Test Signer'], $key);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        $this->assertNotFalse($cert);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        $engineDir = dirname((string) (new \ReflectionClass(\Com\Tecnick\Pdf\Tcpdf::class))->getFileName());
        $pdf = new \Com\Tecnick\Pdf\Tcpdf('mm', true, false, true, '', null, ['allowedPaths' => [$engineDir, sys_get_temp_dir()]]);
        $pdf->addPageFromImport($pdf->setImportSourceFile($source), 1);
        $pdf->setSignature([
            'signcert' => (string) $certPem,
            'privkey' => (string) $keyPem,
            'password' => '',
            'cert_type' => 2,
            'extracerts' => '',
            'info' => ['Name' => 'Test Signer', 'Location' => '', 'Reason' => 'Test', 'ContactInfo' => ''],
            'approval' => '',
            'appearance' => ['empty' => [], 'name' => 'Signature', 'page' => 0, 'rect' => ''],
        ]);

        return $this->write('signiert.pdf', $pdf->getOutPDFString());
    }

    private function extract(string $pdfPath, string $name): ?string {
        return (new \PDFToolkit\Readers\ZugferdReader)->extractAttachment($pdfPath, $name);
    }

    private function write(string $name, string $content): string {
        $path = $this->workDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param list<string> $withoutReferences
     */
    private function canonical(string $xml, array $withoutReferences = []): string {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $this->assertTrue($dom->loadXML($xml));
        $x = new DOMXPath($dom);
        $x->registerNamespace('ram', SupportingDocumentXml::RAM_NS);
        foreach ($withoutReferences as $reference) {
            foreach ($x->query("//ram:AdditionalReferencedDocument[ram:IssuerAssignedID='{$reference}']") ?: [] as $node) {
                if ($node instanceof \DOMElement) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        return (string) $dom->C14N(false, true);
    }
}
