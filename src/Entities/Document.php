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
use ERechnungToolkit\Contracts\Abstracts\DocumentAbstract;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Generators\ERechnungGenerator;
use DateTimeImmutable;

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
 * 
 * @package ERechnungToolkit\Entities
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
    ) {
    }

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
     * @return string[]
     */
    public function getNotes(): array {
        return $this->notes;
    }

    public function getTaxTotal(): ?TaxTotal {
        return $this->taxTotal;
    }

    public function getMonetaryTotal(): ?MonetaryTotal {
        return $this->monetaryTotal;
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
     */
    public function addNote(string $note): self {
        $this->notes[] = $note;
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

        // Group lines by tax category and rate
        $taxGroups = [];
        foreach ($this->lines as $line) {
            $key = $line->getTaxCategory()->value . '_' . $line->getTaxPercent();
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = [
                    'category' => $line->getTaxCategory(),
                    'percent' => $line->getTaxPercent(),
                    'amount' => 0.0,
                ];
            }
            $taxGroups[$key]['amount'] += $line->getNetAmount();
        }

        // Add document level allowances/charges to tax groups
        foreach ($this->allowanceCharges as $ac) {
            if ($ac->getTaxCategory() !== null && $ac->getTaxPercent() !== null) {
                $key = $ac->getTaxCategory()->value . '_' . $ac->getTaxPercent();
                if (!isset($taxGroups[$key])) {
                    $taxGroups[$key] = [
                        'category' => $ac->getTaxCategory(),
                        'percent' => $ac->getTaxPercent(),
                        'amount' => 0.0,
                    ];
                }
                $amount = $ac->isCharge() ? $ac->getAmount() : -$ac->getAmount();
                $taxGroups[$key]['amount'] += $amount;
            }
        }

        // Create tax subtotals
        $subtotals = [];
        foreach ($taxGroups as $group) {
            $taxableAmount = round($group['amount'], 2);
            $taxAmount = round($taxableAmount * $group['percent'] / 100, 2);
            $subtotals[] = new TaxSubtotal(
                $taxableAmount,
                $taxAmount,
                $group['category'],
                $group['percent']
            );
        }

        $this->taxTotal = TaxTotal::fromSubtotals($subtotals, $this->currency);

        // Calculate monetary totals
        $this->monetaryTotal = MonetaryTotal::calculate(
            $this->lines,
            $this->allowanceCharges,
            $this->taxTotal,
            $this->currency,
            0.0
        );
    }

    /**
     * Returns the total net amount.
     */
    public function getNetAmount(): float {
        return $this->monetaryTotal?->getTaxExclusiveAmount() ?? 0.0;
    }

    /**
     * Returns the total tax amount.
     */
    public function getTaxAmount(): float {
        return $this->taxTotal?->getTaxAmount() ?? 0.0;
    }

    /**
     * Returns the total gross amount.
     */
    public function getGrossAmount(): float {
        return $this->monetaryTotal?->getTaxInclusiveAmount() ?? 0.0;
    }

    /**
     * Returns the payable amount.
     */
    public function getPayableAmount(): float {
        return $this->monetaryTotal?->getPayableAmount() ?? 0.0;
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
        return (new ERechnungGenerator())->generateUbl($this);
    }

    /**
     * Generates UN/CEFACT CII XML output.
     */
    public function toCiiXml(): string {
        return (new ERechnungGenerator())->generateCii($this);
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
            buyerReference: $leitwegId
        );

        return $doc;
    }
}