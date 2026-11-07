<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DocumentRulesValidatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Validators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{Document, InvoiceLine, Period, SupportingDocument};
use ERechnungToolkit\Enums\{AttachmentMimeCode, ERechnungProfile, TaxCategory, UnitCode, ValidationSeverity};
use ERechnungToolkit\Validators\{DocumentRulesValidator, ValidationMessage, ValidationResult};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\{BaseTestCase, XRechnungDocuments};

class DocumentRulesValidatorTest extends BaseTestCase {
    use XRechnungDocuments;

    private DocumentRulesValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new DocumentRulesValidator;
    }

    public function test_complete_document_only_notes_the_link(): void {
        $result = $this->validator->validate($this->supportingDocumentXRechnung());

        $this->assertTrue($result->isValid());
        $this->assertSame(['TK-ATT-LINK'], $this->codes($result));
        $this->assertSame(ValidationSeverity::INFORMATION, $result->getMessages()[0]->getSeverity());
    }

    /**
     * @return array<string, array{SupportingDocument, string}>
     */
    public static function brokenAttachments(): array {
        $pdf = "%PDF-1.4\n%%EOF\n";

        return [
            'Kennung fehlt' => [SupportingDocument::embedded(' ', $pdf, AttachmentMimeCode::PDF, 'a.pdf'), 'BR-52'],
            'MIME nicht zulässig' => [SupportingDocument::embedded('A', $pdf, 'application/msword', 'a.doc'), 'BR-CL-24'],
            'XML ohne Extension' => [SupportingDocument::embedded('A', '<a/>', AttachmentMimeCode::XML, 'a.xml'), 'BR-CL-24'],
            'MIME fehlt' => [new SupportingDocument('A', null, null, $pdf, null, 'a.pdf'), 'TK-ATT-MIME'],
            'Dateiname fehlt' => [new SupportingDocument('A', null, null, $pdf, 'application/pdf', null), 'TK-ATT-FILENAME'],
            'Dateiname mit Pfad' => [SupportingDocument::embedded('A', $pdf, AttachmentMimeCode::PDF, '../a.pdf'), 'TK-ATT-FILENAME'],
            'leerer Inhalt' => [SupportingDocument::embedded('A', '', AttachmentMimeCode::PDF, 'a.pdf'), 'TK-ATT-EMPTY'],
            'Endung passt nicht' => [SupportingDocument::embedded('A', $pdf, AttachmentMimeCode::PDF, 'a.csv'), 'TK-ATT-EXTENSION'],
            'Inhalt passt nicht' => [SupportingDocument::embedded('A', "%PNG\r\n\x1a\n" . str_repeat("\0", 16), AttachmentMimeCode::PDF, 'a.pdf'), 'TK-ATT-CONTENT'],
        ];
    }

    #[DataProvider('brokenAttachments')]
    public function test_broken_attachment_is_reported(SupportingDocument $attachment, string $code): void {
        $document = $this->baseDocument(ERechnungProfile::XRECHNUNG)->addSupportingDocument($attachment);

        $this->assertContains($code, $this->codes($this->validator->validate($document)));
    }

    public function test_xml_attachment_is_allowed_in_the_extension(): void {
        $document = $this->baseDocument(ERechnungProfile::XRECHNUNG_EXTENSION)
            ->addSupportingDocument(SupportingDocument::embedded('A', '<?xml version="1.0"?><a/>', AttachmentMimeCode::XML, 'a.xml'));

        $this->assertSame([], $this->codes($this->validator->validate($document)));
    }

    public function test_filenames_must_be_unique_ignoring_case(): void {
        $pdf = "%PDF-1.4\n%%EOF\n";
        $document = $this->baseDocument(ERechnungProfile::XRECHNUNG)
            ->addSupportingDocument(SupportingDocument::embedded('A', $pdf, AttachmentMimeCode::PDF, 'Stundenzettel.pdf'))
            ->addSupportingDocument(SupportingDocument::embedded('B', $pdf, AttachmentMimeCode::PDF, 'stundenzettel.PDF'));

        $result = $this->validator->validate($document);

        $this->assertSame(['BR-DE-22'], $this->codes($result));
        $this->assertFalse($result->isValid());
    }

    public function test_basic_profile_rejects_referenced_documents(): void {
        $document = $this->baseDocument(ERechnungProfile::BASIC)
            ->addSupportingDocument(SupportingDocument::embedded('A', "%PDF-1.4\n", AttachmentMimeCode::PDF, 'a.pdf'));

        $this->assertContains('TK-ATT-PROFILE', $this->codes($this->validator->validate($document)));
    }

    public function test_minimum_profile_warns_about_the_invoicing_period(): void {
        $document = $this->baseDocument(ERechnungProfile::MINIMUM)
            ->setInvoicingPeriod(Period::day(new DateTimeImmutable('2026-09-01')));

        $result = $this->validator->validate($document);

        $this->assertSame(['TK-PERIOD-PROFILE'], $this->codes($result));
        $this->assertTrue($result->isValid(), 'A dropped period is a warning, not an error');
    }

    public function test_invoicing_period_rules(): void {
        $empty = $this->baseDocument(ERechnungProfile::XRECHNUNG)->setInvoicingPeriod(new Period);
        $reversed = $this->baseDocument(ERechnungProfile::XRECHNUNG)
            ->setInvoicingPeriod(Period::between(new DateTimeImmutable('2026-09-30'), new DateTimeImmutable('2026-09-01')));

        $this->assertSame(['BR-CO-19'], $this->codes($this->validator->validate($empty)));
        $this->assertSame(['BR-29'], $this->codes($this->validator->validate($reversed)));
    }

    public function test_line_period_rules(): void {
        $period = Period::between(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30'));

        $this->assertSame(['BR-CO-20'], $this->codes($this->validator->validate($this->documentWithLinePeriod(new Period, $period))));
        $this->assertSame(['BR-30'], $this->codes($this->validator->validate(
            $this->documentWithLinePeriod(Period::between(new DateTimeImmutable('2026-09-09'), new DateTimeImmutable('2026-09-02')), $period)
        )));
        $this->assertSame(['PEPPOL-EN16931-R110', 'PEPPOL-EN16931-R111'], $this->codes($this->validator->validate(
            $this->documentWithLinePeriod(Period::between(new DateTimeImmutable('2026-08-31'), new DateTimeImmutable('2026-10-01')), $period)
        )));
        $this->assertSame([], $this->codes($this->validator->validate(
            $this->documentWithLinePeriod(Period::day(new DateTimeImmutable('2026-09-30')), $period)
        )), 'The last day of the invoicing period is inside');
    }

    private function documentWithLinePeriod(Period $linePeriod, Period $invoicingPeriod): Document {
        $euro = CurrencyCode::Euro;

        return ERechnungDocumentBuilder::xrechnung('XR-1', $this->leitwegId)
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withBuyer('Öffentliche Verwaltung')
            ->addInvoiceLine(new InvoiceLine(
                id: '1',
                quantity: 1.0,
                unitCode: UnitCode::HOUR,
                netAmount: Money::of('100.00', $euro),
                itemName: 'Beratung',
                unitPrice: Money::of('100.00', $euro),
                taxCategory: TaxCategory::STANDARD,
                taxPercent: 19.0,
                period: $linePeriod,
            ))
            ->build()
            ->setInvoicingPeriod($invoicingPeriod);
    }

    private function baseDocument(ERechnungProfile $profile): Document {
        return ERechnungDocumentBuilder::create('RE-1')
            ->withProfile($profile)
            ->withIssueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withBuyer('Kunde AG')
            ->addLine('Beratung', 1, 100.00)
            ->build();
    }

    /**
     * @return list<string>
     */
    private function codes(ValidationResult $result): array {
        return array_values(array_map(static fn (ValidationMessage $m): string => $m->getCode(), $result->getMessages()));
    }
}
