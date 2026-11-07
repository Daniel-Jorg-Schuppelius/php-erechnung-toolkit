<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Generators\ERechnungGenerator;
use ERechnungToolkit\Parsers\ERechnungParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

/**
 * Rundreise der Unterlagen, Zeiträume und Objektkennungen durch UBL und CII
 * sowie die Grenzen für eingebettete Anhänge.
 */
class SupportingDocumentParserTest extends BaseTestCase {
    use XRechnungDocuments;

    /**
     * @return array<string, array{string}>
     */
    public static function syntaxes(): array {
        return ['UBL' => ['ubl'], 'CII' => ['cii']];
    }

    #[DataProvider('syntaxes')]
    public function test_round_trip_keeps_everything(string $syntax): void {
        $parsed = (new ERechnungParser)->parse($this->xml($syntax));

        $invoicingPeriod = $parsed->getInvoicingPeriod();
        $this->assertNotNull($invoicingPeriod);
        $this->assertSame('2026-09-01', $invoicingPeriod->getStart()?->format('Y-m-d'));
        $this->assertSame('2026-09-30', $invoicingPeriod->getEnd()?->format('Y-m-d'));
        $this->assertSame('SLA-2026-01', $parsed->getInvoicedObjectIdentifier());
        $this->assertSame('AAJ', $parsed->getInvoicedObjectScheme());

        $documents = $parsed->getSupportingDocuments();
        $this->assertCount(3, $documents);
        $this->assertSame(['LN-1', 'LN-2', 'LN-3'], array_map(static fn ($d) => $d->getReference(), $documents));
        $this->assertSame($this->samplePdf(), $documents[0]->getContent());
        $this->assertSame('application/pdf', $documents[0]->getMimeCode());
        $this->assertSame('nachweis-1.pdf', $documents[0]->getFilename());
        $this->assertSame('Leistungsnachweis September 2026', $documents[0]->getDescription());
        $this->assertSame($this->sampleCsv(), $documents[1]->getContent());
        $this->assertFalse($documents[2]->isEmbedded());
        $this->assertSame('https://rechnung.example.org/anlage/123', $documents[2]->getExternalUri());

        $line = $parsed->getLines()[0];
        $linePeriod = $line->getPeriod();
        $this->assertNotNull($linePeriod);
        $this->assertSame('2026-09-02', $linePeriod->getStart()?->format('Y-m-d'));
        $this->assertSame('2026-09-09', $linePeriod->getEnd()?->format('Y-m-d'));
        $this->assertSame('TICKET-4711', $line->getObjectIdentifier());
        $this->assertSame('2026-09-02', $parsed->getLines()[1]->getPeriod()?->getEnd()?->format('Y-m-d'));
        $this->assertNull($parsed->getLines()[1]->getObjectIdentifier());
    }

    #[DataProvider('syntaxes')]
    public function test_attachment_over_the_size_limit_keeps_metadata_only(string $syntax): void {
        $parser = new ERechnungParser(maxAttachmentBytes: 40);
        $documents = $parser->parse($this->xml($syntax))->getSupportingDocuments();

        $this->assertNull($documents[0]->getContent(), 'PDF sample is larger than 40 bytes');
        $this->assertSame('nachweis-1.pdf', $documents[0]->getFilename());
        $this->assertCount(2, $parser->getNotices());
        $this->assertStringContainsString('LN-1', $parser->getNotices()[0]);
    }

    public function test_attachment_count_limit_and_reset_between_runs(): void {
        $parser = new ERechnungParser(maxAttachments: 1);
        $documents = $parser->parse($this->xml('ubl'))->getSupportingDocuments();

        $this->assertNotNull($documents[0]->getContent());
        $this->assertNull($documents[1]->getContent());
        $this->assertCount(1, $parser->getNotices());

        $parser->parse($this->xml('cii'));
        $this->assertCount(1, $parser->getNotices(), 'Notices and counter start over with each parse');
    }

    public function test_invalid_base64_is_reported(): void {
        $xml = (string) preg_replace(
            '#(<cbc:EmbeddedDocumentBinaryObject[^>]*filename="nachweis-1.pdf"[^>]*>)[^<]+#',
            '$1%%%kein-base64%%%',
            $this->xml('ubl')
        );
        $parser = new ERechnungParser;
        $documents = $parser->parse($xml)->getSupportingDocuments();

        $this->assertNull($documents[0]->getContent());
        $this->assertStringContainsString('Base64', $parser->getNotices()[0]);
    }

    public function test_invoice_without_new_fields_has_none(): void {
        $parsed = (new ERechnungParser)->parse((new ERechnungGenerator)->generateUbl($this->fullXRechnung()));

        $this->assertNull($parsed->getInvoicingPeriod());
        $this->assertNull($parsed->getInvoicedObjectIdentifier());
        $this->assertSame([], $parsed->getSupportingDocuments());
    }

    private function xml(string $syntax): string {
        $document = $this->supportingDocumentXRechnung();

        $generator = new ERechnungGenerator;

        return $syntax === 'cii' ? $generator->generateCii($document) : $generator->generateUbl($document);
    }
}
