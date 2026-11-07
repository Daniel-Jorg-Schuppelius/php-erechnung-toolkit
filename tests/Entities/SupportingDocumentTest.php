<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : SupportingDocumentTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use DateTimeImmutable;
use ERechnungToolkit\Entities\{Period, SupportingDocument};
use ERechnungToolkit\Enums\{AttachmentMimeCode, ERechnungProfile};
use Tests\Contracts\BaseTestCase;

class SupportingDocumentTest extends BaseTestCase {
    public function test_embedded_document_reports_sizes_and_base64(): void {
        $document = SupportingDocument::embedded('LN-1', 'abcd', AttachmentMimeCode::CSV, 'n.csv', 'Nachweis');

        $this->assertTrue($document->isEmbedded());
        $this->assertSame('text/csv', $document->getMimeCode());
        $this->assertSame(AttachmentMimeCode::CSV, $document->getMimeCodeEnum());
        $this->assertSame('YWJjZA==', $document->base64());
        $this->assertSame(4, $document->sizeBytes());
        $this->assertSame(8, $document->encodedSizeBytes());
    }

    public function test_from_file_follows_the_extension(): void {
        $path = sys_get_temp_dir() . '/supporting_' . uniqid() . '.csv';
        file_put_contents($path, "a;b\n1;2\n");

        try {
            $document = SupportingDocument::fromFile('LN-2', $path, description: 'Stunden');

            $this->assertSame("a;b\n1;2\n", $document->getContent());
            $this->assertSame('text/csv', $document->getMimeCode());
            $this->assertSame(basename($path), $document->getFilename());
            $this->assertSame('Stunden', $document->getDescription());
        } finally {
            @unlink($path);
        }
    }

    public function test_external_document_has_no_content(): void {
        $document = SupportingDocument::external('LN-3', 'https://example.org/a');

        $this->assertFalse($document->isEmbedded());
        $this->assertNull($document->base64());
        $this->assertSame(0, $document->encodedSizeBytes());
        $this->assertSame('https://example.org/a', $document->getExternalUri());
    }

    public function test_period_order_and_containment_use_calendar_days(): void {
        $september = Period::between(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30 00:00'));

        $this->assertTrue($september->isOrdered());
        $this->assertFalse(Period::between(new DateTimeImmutable('2026-09-02'), new DateTimeImmutable('2026-09-01'))->isOrdered());
        $this->assertTrue($september->contains(Period::day(new DateTimeImmutable('2026-09-30 18:00'))));
        $this->assertFalse($september->contains(Period::day(new DateTimeImmutable('2026-10-01'))));
        $this->assertTrue((new Period(new DateTimeImmutable('2026-09-01')))->contains(Period::day(new DateTimeImmutable('2027-01-01'))), 'Open end');
        $this->assertTrue((new Period)->isEmpty());
    }

    public function test_mime_codes_per_profile(): void {
        $this->assertCount(6, AttachmentMimeCode::allowedFor(ERechnungProfile::XRECHNUNG));
        $this->assertFalse(AttachmentMimeCode::XML->isAllowedFor(ERechnungProfile::EN16931));
        $this->assertTrue(AttachmentMimeCode::XML->isAllowedFor(ERechnungProfile::XRECHNUNG_EXTENSION));
        $this->assertSame(AttachmentMimeCode::JPEG, AttachmentMimeCode::fromExtension('.JPEG'));
        $this->assertNull(AttachmentMimeCode::fromExtension('docx'));
        $this->assertTrue(AttachmentMimeCode::CSV->acceptsDetected('text/plain; charset=us-ascii'));
        $this->assertTrue(AttachmentMimeCode::XLSX->acceptsDetected('application/zip'));
        $this->assertFalse(AttachmentMimeCode::PDF->acceptsDetected('image/png'));
    }

    public function test_profile_capabilities(): void {
        $this->assertTrue(ERechnungProfile::EN16931->supportsReferencedDocuments());
        $this->assertTrue(ERechnungProfile::ZUGFERD1_COMFORT->supportsReferencedDocuments(), 'Legacy profiles write their successor');
        $this->assertFalse(ERechnungProfile::BASIC->supportsReferencedDocuments());
        $this->assertTrue(ERechnungProfile::BASIC_WL->supportsInvoicingPeriod());
        $this->assertFalse(ERechnungProfile::MINIMUM->supportsInvoicingPeriod());
        $this->assertTrue(ERechnungProfile::BASIC->supportsLinePeriod());
        $this->assertFalse(ERechnungProfile::BASIC_WL->supportsLinePeriod());
    }
}
