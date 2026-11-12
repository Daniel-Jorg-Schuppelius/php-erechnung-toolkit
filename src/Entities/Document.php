<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Document.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Contracts\Abstracts\DocumentAbstract;
use ERechnungToolkit\Enums\{ERechnungProfile, InvoiceType, NoteSubjectCode, PaymentMeansCode, TaxCategory};
use ERechnungToolkit\Generators\ERechnungGenerator;
use InvalidArgumentException;

/**
 * E-Rechnung Document (EN 16931 / XRechnung / ZUGFeRD).
 *
 * Represents a complete electronic invoice according to European standards.
 *
 * Structure:
 * - Invoice metadata (ID, dates, type, profile)
 * - Seller and Buyer parties
 * - Invoice lines with items
 * - Tax breakdown
 * - Monetary totals
 * - Payment information
 */
final class Document extends DocumentAbstract {
    /** @var InvoiceLine[] */
    private array $lines = [];

    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    /** @var string[] */
    private array $notes = [];

    private ?TaxTotal $taxTotal = null;
    private ?MonetaryTotal $monetaryTotal = null;

    private ?string $taxExemptionReason = null;
    private ?string $taxExemptionReasonCode = null;

    /** @var array<string, array{reason: string, code: ?string}> keyed by UNCL5305 code */
    private array $categoryTaxExemptions = [];

    private ?string $remittanceInformation = null;

    /** @var SupportingDocument[] */
    private array $supportingDocuments = [];

    private ?Period $invoicingPeriod = null;
    private ?string $invoicedObjectIdentifier = null;
    private ?string $invoicedObjectScheme = null;

    public function __construct(
        private string $id,
        private DateTimeImmutable $issueDate,
        private InvoiceType $invoiceType,
        private Party $seller,
        private Party $buyer,
        private CurrencyCode $currency,
        private ERechnungProfile $profile = ERechnungProfile::EN16931,
        private ?DateTimeImmutable $dueDate = null,
        private ?DateTimeImmutable $taxPointDate = null,
        private ?string $buyerReference = null,
        private ?string $orderReference = null,
        private ?string $contractReference = null,
        private ?string $projectReference = null,
        private ?PaymentMeansCode $paymentMeansCode = null,
        private ?PaymentTerms $paymentTerms = null,
        private ?Party $payee = null,
        private ?Party $deliveryParty = null,
        private ?DateTimeImmutable $deliveryDate = null,
        private ?string $precedingInvoiceReference = null
    ) {}

    public function getId(): string {
        return $this->id;
    }

    public function getIssueDate(): DateTimeImmutable {
        return $this->issueDate;
    }

    public function getInvoiceType(): InvoiceType {
        return $this->invoiceType;
    }

    public function getSeller(): Party {
        return $this->seller;
    }

    public function getBuyer(): Party {
        return $this->buyer;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    public function getProfile(): ERechnungProfile {
        return $this->profile;
    }

    public function getDueDate(): ?DateTimeImmutable {
        return $this->dueDate;
    }

    public function getTaxPointDate(): ?DateTimeImmutable {
        return $this->taxPointDate;
    }

    public function getBuyerReference(): ?string {
        return $this->buyerReference;
    }

    public function getOrderReference(): ?string {
        return $this->orderReference;
    }

    public function getContractReference(): ?string {
        return $this->contractReference;
    }

    public function getProjectReference(): ?string {
        return $this->projectReference;
    }

    public function getPaymentMeansCode(): ?PaymentMeansCode {
        return $this->paymentMeansCode;
    }

    public function getPaymentTerms(): ?PaymentTerms {
        return $this->paymentTerms;
    }

    public function getPayee(): ?Party {
        return $this->payee;
    }

    public function getDeliveryParty(): ?Party {
        return $this->deliveryParty;
    }

    public function getDeliveryDate(): ?DateTimeImmutable {
        return $this->deliveryDate;
    }

    public function getPrecedingInvoiceReference(): ?string {
        return $this->precedingInvoiceReference;
    }

    /**
     * @return InvoiceLine[]
     */
    public function getLines(): array {
        return $this->lines;
    }

    /**
     * @return AllowanceCharge[]
     */
    public function getAllowanceCharges(): array {
        return $this->allowanceCharges;
    }

    /**
     * Returns all notes as formatted strings (with #XXX# prefix if subject code was set).
     *
     * @return string[]
     */
    public function getNotes(): array {
        return $this->notes;
    }

    /**
     * Returns all notes as structured array with parsed subject codes.
     *
     * @return array<int, array{code: NoteSubjectCode|null, text: string}>
     */
    public function getNotesStructured(): array {
        return array_map(
            fn (string $note) => NoteSubjectCode::parseNote($note),
            $this->notes
        );
    }

    /**
     * Returns notes filtered by subject code.
     *
     * @return string[] Note texts (without prefix)
     */
    public function getNotesBySubjectCode(NoteSubjectCode $subjectCode): array {
        $result = [];
        foreach ($this->notes as $note) {
            $parsed = NoteSubjectCode::parseNote($note);
            if ($parsed['code'] === $subjectCode) {
                $result[] = $parsed['text'];
            }
        }
        return $result;
    }

    public function getTaxTotal(): ?TaxTotal {
        return $this->taxTotal;
    }

    public function getMonetaryTotal(): ?MonetaryTotal {
        return $this->monetaryTotal;
    }

    /**
     * Returns the VAT exemption reason text (BT-120) applied to exempt/
     * reverse-charge/zero-rated tax categories.
     */
    public function getTaxExemptionReason(): ?string {
        return $this->taxExemptionReason;
    }

    /**
     * Returns the VAT exemption reason code (BT-121), e.g. a VATEX-* code.
     */
    public function getTaxExemptionReasonCode(): ?string {
        return $this->taxExemptionReasonCode;
    }

    /**
     * Sets the VAT exemption reason (BT-120) and optional code (BT-121).
     *
     * The reason is propagated to every tax subtotal whose category requires
     * one ({@see TaxCategory::requiresExemptionReason()}) and has no reason of
     * its own ({@see setTaxExemptionFor()}); zero rated and standard rated
     * subtotals stay without (BR-Z-10, BR-S-10).
     */
    public function setTaxExemption(string $reason, ?string $code = null): self {
        $this->taxExemptionReason = $reason;
        $this->taxExemptionReasonCode = $code;
        $this->recalculateTotals();
        return $this;
    }

    /**
     * Sets the VAT exemption reason (BT-120) and optional code (BT-121) of one
     * VAT category; it takes precedence over {@see setTaxExemption()}.
     *
     * @throws InvalidArgumentException for categories that must not carry a reason (S, Z, L, M)
     */
    public function setTaxExemptionFor(TaxCategory $category, string $reason, ?string $code = null): self {
        if (!$category->requiresExemptionReason()) {
            throw new InvalidArgumentException("VAT category {$category->value} carries no exemption reason (BR-S-10, BR-Z-10, BR-AF-10, BR-AG-10)");
        }
        $this->categoryTaxExemptions[$category->value] = ['reason' => $reason, 'code' => $code];
        $this->recalculateTotals();
        return $this;
    }

    /**
     * Exemption reason text (BT-120) the VAT breakdown of this category carries.
     */
    public function getTaxExemptionReasonFor(TaxCategory $category): ?string {
        return $this->exemptionFor($category)['reason'];
    }

    /**
     * Exemption reason code (BT-121) the VAT breakdown of this category carries.
     */
    public function getTaxExemptionReasonCodeFor(TaxCategory $category): ?string {
        return $this->exemptionFor($category)['code'];
    }

    /**
     * @return array{reason: ?string, code: ?string}
     */
    private function exemptionFor(TaxCategory $category): array {
        if (!$category->requiresExemptionReason()) {
            return ['reason' => null, 'code' => null];
        }

        return $this->categoryTaxExemptions[$category->value]
            ?? ['reason' => $this->taxExemptionReason, 'code' => $this->taxExemptionReasonCode];
    }

    /**
     * Returns the remittance information / Verwendungszweck (BT-83).
     */
    public function getRemittanceInformation(): ?string {
        return $this->remittanceInformation;
    }

    /**
     * Sets the remittance information / Verwendungszweck (BT-83).
     */
    public function setRemittanceInformation(string $reference): self {
        $this->remittanceInformation = $reference;
        return $this;
    }

    /**
     * Adds an additional supporting document (BG-24).
     */
    public function addSupportingDocument(SupportingDocument $document): self {
        $this->supportingDocuments[] = $document;
        return $this;
    }

    /**
     * @return SupportingDocument[]
     */
    public function getSupportingDocuments(): array {
        return $this->supportingDocuments;
    }

    /**
     * Sets the invoicing period (BG-14); null removes it.
     */
    public function setInvoicingPeriod(?Period $period): self {
        $this->invoicingPeriod = $period;
        return $this;
    }

    public function getInvoicingPeriod(): ?Period {
        return $this->invoicingPeriod;
    }

    /**
     * Sets the invoiced object identifier (BT-18) with optional scheme (BT-18-1, UNTDID 1153).
     */
    public function setInvoicedObject(string $identifier, ?string $scheme = null): self {
        $this->invoicedObjectIdentifier = $identifier;
        $this->invoicedObjectScheme = $scheme;
        return $this;
    }

    public function getInvoicedObjectIdentifier(): ?string {
        return $this->invoicedObjectIdentifier;
    }

    public function getInvoicedObjectScheme(): ?string {
        return $this->invoicedObjectScheme;
    }

    /**
     * Adds an invoice line.
     */
    public function addLine(InvoiceLine $line): self {
        $this->lines[] = $line;
        $this->recalculateTotals();
        return $this;
    }

    /**
     * Adds a document-level allowance or charge.
     */
    public function addAllowanceCharge(AllowanceCharge $allowanceCharge): self {
        $this->allowanceCharges[] = $allowanceCharge;
        $this->recalculateTotals();
        return $this;
    }

    /**
     * Adds a note to the invoice.
     *
     * @param string $note The note text
     * @param NoteSubjectCode|null $subjectCode Optional subject code (UNTDID 4451) for categorization
     */
    public function addNote(string $note, ?NoteSubjectCode $subjectCode = null): self {
        if ($subjectCode !== null) {
            $this->notes[] = NoteSubjectCode::formatNote($note, $subjectCode);
        } else {
            $this->notes[] = $note;
        }
        return $this;
    }

    /**
     * Sets the tax total manually.
     */
    public function setTaxTotal(TaxTotal $taxTotal): self {
        $this->taxTotal = $taxTotal;
        return $this;
    }

    /**
     * Sets the monetary total manually.
     */
    public function setMonetaryTotal(MonetaryTotal $monetaryTotal): self {
        $this->monetaryTotal = $monetaryTotal;
        return $this;
    }

    /**
     * Recalculates tax and monetary totals from lines.
     */
    public function recalculateTotals(): void {
        if (empty($this->lines)) {
            return;
        }

        // Group lines by tax category and rate; "not subject to VAT" has no rate (BR-O-05) and no tax (BR-O-09).
        $zero = Money::zero($this->currency);
        $taxGroups = [];
        foreach ($this->lines as $line) {
            $category = $line->getTaxCategory();
            $percent = $category->hasRate() ? $line->getTaxPercent() : 0.0;
            $key = $category->value . '_' . $percent;
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'category' => $category,
                    'percent' => $percent,
                    'amount' => $zero,
                ];
            }
            $taxGroups[$key]['amount'] = $taxGroups[$key]['amount']->plus($line->getNetAmount());
        }

        // Add document level allowances/charges to tax groups
        foreach ($this->allowanceCharges as $ac) {
            $category = $ac->getTaxCategory();
            if ($category === null) {
                continue;
            }
            $percent = $category->hasRate() ? $ac->getTaxPercent() : 0.0;
            if ($percent === null) {
                continue;
            }
            $key = $category->value . '_' . $percent;
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'category' => $category,
                    'percent' => $percent,
                    'amount' => $zero,
                ];
            }
            $amount = $ac->isCharge() ? $ac->getAmount() : $ac->getAmount()->negated();
            $taxGroups[$key]['amount'] = $taxGroups[$key]['amount']->plus($amount);
        }

        // Create tax subtotals
        $subtotals = [];
        foreach ($taxGroups as $group) {
            $taxableAmount = $group['amount'];
            $exemption = $this->exemptionFor($group['category']);

            $subtotals[] = new TaxSubtotal(
                $taxableAmount,
                $taxableAmount->percentage($group['percent']),
                $group['category'],
                $group['percent'],
                $exemption['reason'],
                $exemption['code']
            );
        }

        $this->taxTotal = TaxTotal::fromSubtotals($subtotals, $this->currency);

        // Calculate monetary totals
        $this->monetaryTotal = MonetaryTotal::calculate(
            $this->lines,
            $this->allowanceCharges,
            $this->taxTotal,
            $this->currency
        );
    }

    /**
     * Returns the total net amount.
     */
    public function getNetAmount(): Money {
        return $this->monetaryTotal?->getTaxExclusiveAmount() ?? Money::zero($this->currency);
    }

    /**
     * Returns the total tax amount.
     */
    public function getTaxAmount(): Money {
        return $this->taxTotal?->getTaxAmount() ?? Money::zero($this->currency);
    }

    /**
     * Returns the total gross amount.
     */
    public function getGrossAmount(): Money {
        return $this->monetaryTotal?->getTaxInclusiveAmount() ?? Money::zero($this->currency);
    }

    /**
     * Returns the payable amount.
     */
    public function getPayableAmount(): Money {
        return $this->monetaryTotal?->getPayableAmount() ?? Money::zero($this->currency);
    }

    /**
     * Counts the invoice lines.
     */
    public function countLines(): int {
        return count($this->lines);
    }

    /**
     * Validates the document according to the profile.
     *
     * @return string[]
     */
    public function validate(): array {
        $errors = [];

        // BT-1: Invoice number is mandatory
        if (empty($this->id)) {
            $errors[] = 'BT-1: Invoice number is mandatory';
        }

        // BT-2: Invoice issue date is mandatory
        // Already enforced by constructor

        // BT-3: Invoice type code is mandatory
        // Already enforced by constructor

        // BT-5: Invoice currency code is mandatory
        // Already enforced by constructor

        // BT-27: Seller name is mandatory
        if (empty($this->seller->getName())) {
            $errors[] = 'BT-27: Seller name is mandatory';
        }

        // BT-44: Buyer name is mandatory
        if (empty($this->buyer->getName())) {
            $errors[] = 'BT-44: Buyer name is mandatory';
        }

        // BT-31/BT-32: Seller VAT or tax registration is mandatory
        if (!$this->seller->hasVatId() && $this->seller->getTaxRegistrationId() === null) {
            $errors[] = 'BT-31/BT-32: Seller VAT ID or tax registration number is mandatory';
        }

        // BG-25: Invoice lines are mandatory
        if (empty($this->lines)) {
            $errors[] = 'BG-25: At least one invoice line is required';
        }

        // XRechnung specific: Buyer reference or order reference is required
        if ($this->profile->isXRechnung()) {
            if (empty($this->buyerReference) && empty($this->orderReference)) {
                $errors[] = 'BT-10: Buyer reference (Leitweg-ID) is mandatory for XRechnung';
            }

            // Seller must have endpoint for XRechnung
            if (!$this->seller->hasEndpoint()) {
                $errors[] = 'BT-34: Seller electronic address is mandatory for XRechnung';
            }

            // Buyer must have endpoint for XRechnung
            if (!$this->buyer->hasEndpoint()) {
                $errors[] = 'BT-49: Buyer electronic address is mandatory for XRechnung';
            }
        }

        return $errors;
    }

    /**
     * Returns true if the document is valid.
     */
    public function isValid(): bool {
        return empty($this->validate());
    }

    /**
     * Generates XML in the default format (UBL for XRechnung, CII for ZUGFeRD).
     */
    public function toXml(): string {
        if ($this->profile->isXRechnung()) {
            return $this->toUblXml();
        }
        return $this->toCiiXml();
    }

    /**
     * Generates UBL XML output.
     */
    public function toUblXml(): string {
        return (new ERechnungGenerator)->generateUbl($this);
    }

    /**
     * Generates UN/CEFACT CII XML output.
     */
    public function toCiiXml(): string {
        return (new ERechnungGenerator)->generateCii($this);
    }

    /**
     * Returns the document as XML string.
     */
    public function __toString(): string {
        return $this->toXml();
    }

    /**
     * Creates a new invoice document.
     */
    public static function create(
        string $id,
        DateTimeImmutable $issueDate,
        Party $seller,
        Party $buyer,
        CurrencyCode $currency = CurrencyCode::Euro,
        InvoiceType $type = InvoiceType::INVOICE,
        ERechnungProfile $profile = ERechnungProfile::EN16931
    ): self {
        return new self(
            id: $id,
            issueDate: $issueDate,
            invoiceType: $type,
            seller: $seller,
            buyer: $buyer,
            currency: $currency,
            profile: $profile
        );
    }

    /**
     * Creates a credit note document.
     */
    public static function creditNote(
        string $id,
        DateTimeImmutable $issueDate,
        Party $seller,
        Party $buyer,
        string $precedingInvoiceReference,
        CurrencyCode $currency = CurrencyCode::Euro,
        ERechnungProfile $profile = ERechnungProfile::EN16931
    ): self {
        return new self(
            id: $id,
            issueDate: $issueDate,
            invoiceType: InvoiceType::CREDIT_NOTE,
            seller: $seller,
            buyer: $buyer,
            currency: $currency,
            profile: $profile,
            precedingInvoiceReference: $precedingInvoiceReference
        );
    }

    /**
     * Creates an XRechnung document.
     */
    public static function xrechnung(
        string $id,
        DateTimeImmutable $issueDate,
        Party $seller,
        Party $buyer,
        string $leitwegId,
        CurrencyCode $currency = CurrencyCode::Euro
    ): self {
        $doc = new self(
            id: $id,
            issueDate: $issueDate,
            invoiceType: InvoiceType::INVOICE,
            seller: $seller,
            buyer: $buyer->withLeitwegId($leitwegId),
            currency: $currency,
            profile: ERechnungProfile::XRECHNUNG,
            buyerReference: LeitwegId::normalize($leitwegId)
        );

        return $doc;
    }
}
