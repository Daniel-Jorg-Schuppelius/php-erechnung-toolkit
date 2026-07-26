<?php
/*
 * Created on   : Sun Jun 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglInvoiceParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use ERechnungToolkit\Entities\UglInvoice;
use ERechnungToolkit\Parsers\UglInvoiceParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the inbound UGL 5.0 invoice parser (Satzart RGD).
 */
class UglInvoiceParserTest extends BaseTestCase {
    private UglInvoiceParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new UglInvoiceParser;
    }

    private function blank(): string {
        return str_repeat(' ', 350);
    }

    private function alpha(string $rec, int $from, int $to, string $value): string {
        $len = $to - $from + 1;

        return substr_replace($rec, str_pad(substr($value, 0, $len), $len), $from - 1, $len);
    }

    private function num(string $rec, int $from, int $to, float $value, int $decimals): string {
        $len = $to - $from + 1;
        $digits = str_pad((string) (int) round($value * (10 ** $decimals)), $len, '0', STR_PAD_LEFT);

        return substr_replace($rec, $digits, $from - 1, $len);
    }

    /** Builds a minimal RGD + POA + END invoice file. */
    private function sampleInvoice(string $documentType = 'RG'): string {
        $rgd = $this->alpha($this->blank(), 1, 3, 'RGD');
        $rgd = $this->alpha($rgd, 4, 13, 'RE-2026-5');
        $rgd = $this->alpha($rgd, 14, 15, $documentType);
        $rgd = $this->alpha($rgd, 16, 23, '20260628');
        $rgd = $this->alpha($rgd, 24, 26, 'EUR');
        $rgd = $this->num($rgd, 27, 37, 119.00, 2); // Brutto
        $rgd = $this->num($rgd, 38, 48, 19.00, 2);  // MwSt-Betrag
        $rgd = $this->num($rgd, 54, 64, 100.00, 2); // Netto-Warenwert
        $rgd = $this->alpha($rgd, 113, 120, '20260728'); // Netto-Fälligkeit

        $poa = $this->alpha($this->blank(), 1, 3, 'POA');
        $poa = $this->num($poa, 4, 13, 1, 0);
        $poa = $this->alpha($poa, 24, 38, 'ART-1');
        $poa = $this->num($poa, 39, 49, 2, 3);       // Menge
        $poa = $this->alpha($poa, 50, 89, 'Pumpe');
        $poa = $this->num($poa, 130, 140, 50.00, 2); // Brutto je PE
        $poa = $this->num($poa, 142, 152, 100.00, 2); // Netto-Positionswert
        $poa = $this->alpha($poa, 184, 186, 'ST');

        $end = $this->alpha($this->blank(), 1, 3, 'END');

        return implode("\r\n", [$rgd, $poa, $end]) . "\r\n";
    }

    public function test_parses_rgd_header_and_positions(): void {
        $invoice = $this->parser->parse($this->sampleInvoice());

        $this->assertSame('RE-2026-5', $invoice->getNumber());
        $this->assertSame(UglInvoice::TYPE_INVOICE, $invoice->getDocumentType());
        $this->assertFalse($invoice->isCreditNote());
        $this->assertSame('2026-06-28', $invoice->getDate()->format('Y-m-d'));
        $this->assertSame('EUR', $invoice->getCurrency()->value);
        $this->assertSame('119.00', $invoice->getGrossTotal()->getAmount());
        $this->assertSame('19.00', $invoice->getVatAmount()->getAmount());
        $this->assertSame('100.00', $invoice->getNetTotal()->getAmount());
        $this->assertSame('2026-07-28', $invoice->getDueDate()?->format('Y-m-d'));

        $this->assertSame(1, $invoice->countLines());
        $line = $invoice->getLines()[0];
        $this->assertSame('ART-1', $line->getSellersItemId());
        $this->assertSame('Pumpe', $line->getItemName());
        $this->assertSame(2.0, $line->getQuantity());
        $this->assertSame('100.00', $line->getNetAmount()->getAmount());
    }

    public function test_detects_credit_note(): void {
        $invoice = $this->parser->parse($this->sampleInvoice('GS'));

        $this->assertTrue($invoice->isCreditNote());
        $this->assertSame(UglInvoice::TYPE_CREDIT, $invoice->getDocumentType());
    }

    public function test_rejects_content_without_rgd(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse("KOP" . str_repeat(' ', 347) . "\r\n");
    }
}
