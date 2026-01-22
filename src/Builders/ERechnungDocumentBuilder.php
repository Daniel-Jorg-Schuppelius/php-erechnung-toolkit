<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungDocumentBuilder.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Builders;

use CommonToolkit\Enums\CountryCode;
use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Entities\AllowanceCharge;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PaymentTerms;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Enums\UnitCode;
use DateTimeImmutable;

/**
 * Fluent builder for E-Rechnung documents.
 * 
 * Provides a convenient API for creating XRechnung/ZUGFeRD invoices.
 * 
 * Example:
 * ```php
 * $invoice = ERechnungDocumentBuilder::create('INV-2026-001')
 *     ->withIssueDate(new DateTimeImmutable())
 *     ->withSeller('Muster GmbH', 'DE123456789')
 *     ->withSellerAddress('Musterstraße 1', '12345', 'Berlin')
 *     ->withBuyer('Kunde AG')
 *     ->withBuyerAddress('Kundenweg 2', '54321', 'München')
 *     ->addLine('Beratungsleistung', 10, 150.00, 19.0)
 *     ->build();
 * ```
 * 
 * @package ERechnungToolkit\Builders
 */
final class ERechnungDocumentBuilder {
    private string $id;
    private DateTimeImmutable $issueDate;
    private InvoiceType $invoiceType = InvoiceType::INVOICE;
    private CurrencyCode $currency = CurrencyCode::Euro;
    private ERechnungProfile $profile = ERechnungProfile::EN16931;

    private ?string $sellerName = null;
    private ?string $sellerVatId = null;
    private ?string $sellerTaxId = null;
    private ?PostalAddress $sellerAddress = null;
    private ?string $sellerContactName = null;
    private ?string $sellerContactPhone = null;
    private ?string $sellerContactEmail = null;
    private ?string $sellerIban = null;
    private ?string $sellerBic = null;
    private ?string $sellerBankName = null;
    private ?string $sellerEndpointId = null;
    private ?string $sellerEndpointScheme = null;

    private ?string $buyerName = null;
    private ?string $buyerVatId = null;
    private ?PostalAddress $buyerAddress = null;
    private ?string $buyerEndpointId = null;
    private ?string $buyerEndpointScheme = null;

    private ?DateTimeImmutable $dueDate = null;
    private ?DateTimeImmutable $taxPointDate = null;
    private ?DateTimeImmutable $deliveryDate = null;
    private ?string $buyerReference = null;
    private ?string $orderReference = null;
    private ?string $contractReference = null;
    private ?string $projectReference = null;
    private ?PaymentMeansCode $paymentMeansCode = null;
    private ?PaymentTerms $paymentTerms = null;
    private ?string $precedingInvoiceReference = null;

    /** @var InvoiceLine[] */
    private array $lines = [];

    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    /** @var string[] */
    private array $notes = [];

    private int $lineCounter = 0;

    private function __construct(string $id) {
        $this->id = $id;
        $this->issueDate = new DateTimeImmutable();
    }

    /**
     * Creates a new builder for an invoice.
     */
    public static function create(string $id): self {
        return new self($id);
    }

    /**
     * Creates a builder for an XRechnung invoice.
     */
    public static function xrechnung(string $id, string $leitwegId): self {
        return (new self($id))
            ->withProfile(ERechnungProfile::XRECHNUNG)
            ->withBuyerReference($leitwegId);
    }

    /**
     * Creates a builder for a ZUGFeRD invoice.
     */
    public static function zugferd(string $id, ERechnungProfile $profile = ERechnungProfile::EN16931): self {
        return (new self($id))->withProfile($profile);
    }

    /**
     * Creates a builder for a credit note.
     */
    public static function creditNote(string $id, string $precedingInvoiceReference): self {
        return (new self($id))
            ->withInvoiceType(InvoiceType::CREDIT_NOTE)
            ->withPrecedingInvoiceReference($precedingInvoiceReference);
    }

    // === Invoice metadata ===

    public function withIssueDate(DateTimeImmutable $date): self {
        $this->issueDate = $date;
        return $this;
    }

    public function withInvoiceType(InvoiceType $type): self {
        $this->invoiceType = $type;
        return $this;
    }

    public function withCurrency(CurrencyCode $currency): self {
        $this->currency = $currency;
        return $this;
    }

    public function withProfile(ERechnungProfile $profile): self {
        $this->profile = $profile;
        return $this;
    }

    public function withDueDate(DateTimeImmutable $date): self {
        $this->dueDate = $date;
        return $this;
    }

    public function withTaxPointDate(DateTimeImmutable $date): self {
        $this->taxPointDate = $date;
        return $this;
    }

    public function withDeliveryDate(DateTimeImmutable $date): self {
        $this->deliveryDate = $date;
        return $this;
    }

    public function withBuyerReference(string $reference): self {
        $this->buyerReference = $reference;
        return $this;
    }

    public function withOrderReference(string $reference): self {
        $this->orderReference = $reference;
        return $this;
    }

    public function withContractReference(string $reference): self {
        $this->contractReference = $reference;
        return $this;
    }

    public function withProjectReference(string $reference): self {
        $this->projectReference = $reference;
        return $this;
    }

    public function withPrecedingInvoiceReference(string $reference): self {
        $this->precedingInvoiceReference = $reference;
        return $this;
    }

    // === Seller ===

    public function withSeller(string $name, string $vatId, ?string $taxId = null): self {
        $this->sellerName = $name;
        $this->sellerVatId = $vatId;
        $this->sellerTaxId = $taxId;
        return $this;
    }

    public function withSellerAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string $country = CountryCode::Germany,
        ?string $additionalLine = null
    ): self {
        $this->sellerAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country
        );
        return $this;
    }

    public function withSellerContact(string $name, ?string $phone = null, ?string $email = null): self {
        $this->sellerContactName = $name;
        $this->sellerContactPhone = $phone;
        $this->sellerContactEmail = $email;
        return $this;
    }

    public function withSellerBankAccount(string $iban, ?string $bic = null, ?string $bankName = null): self {
        $this->sellerIban = $iban;
        $this->sellerBic = $bic;
        $this->sellerBankName = $bankName;
        return $this;
    }

    public function withSellerEndpoint(string $endpointId, string $scheme = '0204'): self {
        $this->sellerEndpointId = $endpointId;
        $this->sellerEndpointScheme = $scheme;
        return $this;
    }

    // === Buyer ===

    public function withBuyer(string $name, ?string $vatId = null): self {
        $this->buyerName = $name;
        $this->buyerVatId = $vatId;
        return $this;
    }

    public function withBuyerAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string $country = CountryCode::Germany,
        ?string $additionalLine = null
    ): self {
        $this->buyerAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country
        );
        return $this;
    }

    public function withBuyerEndpoint(string $endpointId, string $scheme = '0204'): self {
        $this->buyerEndpointId = $endpointId;
        $this->buyerEndpointScheme = $scheme;
        return $this;
    }

    /**
     * Sets buyer with Leitweg-ID (for XRechnung).
     */
    public function withBuyerLeitwegId(string $leitwegId): self {
        $this->buyerEndpointId = $leitwegId;
        $this->buyerEndpointScheme = '0204';
        $this->buyerReference = $leitwegId;
        return $this;
    }

    // === Payment ===

    public function withPaymentMeans(PaymentMeansCode $code): self {
        $this->paymentMeansCode = $code;
        return $this;
    }

    public function withPaymentTerms(PaymentTerms $terms): self {
        $this->paymentTerms = $terms;
        return $this;
    }

    public function withPaymentTermsNet30(): self {
        $this->paymentTerms = PaymentTerms::net30();
        return $this;
    }

    public function withPaymentTermsSkonto(int $skontoDays, float $skontoPercent, int $netDays = 30): self {
        $this->paymentTerms = PaymentTerms::withSkonto($skontoDays, $skontoPercent, $netDays);
        return $this;
    }

    public function withSepaDirectDebit(string $mandateReference): self {
        $this->paymentMeansCode = PaymentMeansCode::SEPA_DIRECT_DEBIT;
        $this->paymentTerms = PaymentTerms::sepaDirectDebit($mandateReference);
        return $this;
    }

    public function withSepaCreditTransfer(): self {
        $this->paymentMeansCode = PaymentMeansCode::SEPA_CREDIT_TRANSFER;
        return $this;
    }

    // === Lines ===

    /**
     * Adds an invoice line.
     */
    public function addLine(
        string $itemName,
        float $quantity,
        float $unitPrice,
        float $taxPercent = 19.0,
        UnitCode $unitCode = UnitCode::PIECE,
        TaxCategory $taxCategory = TaxCategory::STANDARD,
        ?string $itemDescription = null,
        ?string $sellersItemId = null
    ): self {
        $this->lineCounter++;
        $line = new InvoiceLine(
            id: (string)$this->lineCounter,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: round($quantity * $unitPrice, 2),
            itemName: $itemName,
            unitPrice: $unitPrice,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId
        );
        $this->lines[] = $line;
        return $this;
    }

    /**
     * Adds a service line (hours-based).
     */
    public function addServiceLine(
        string $description,
        float $hours,
        float $hourlyRate,
        float $taxPercent = 19.0
    ): self {
        return $this->addLine($description, $hours, $hourlyRate, $taxPercent, UnitCode::HOUR);
    }

    /**
     * Adds a lump sum line.
     */
    public function addLumpSumLine(
        string $description,
        float $amount,
        float $taxPercent = 19.0
    ): self {
        return $this->addLine($description, 1.0, $amount, $taxPercent, UnitCode::LUMP_SUM);
    }

    /**
     * Adds an existing invoice line.
     */
    public function addInvoiceLine(InvoiceLine $line): self {
        $this->lines[] = $line;
        return $this;
    }

    // === Allowances and Charges ===

    /**
     * Adds a document-level discount.
     */
    public function addDiscount(
        float $amount,
        string $reason = 'Rabatt',
        float $taxPercent = 19.0
    ): self {
        $this->allowanceCharges[] = AllowanceCharge::discount($amount, $reason, null, TaxCategory::STANDARD, $taxPercent);
        return $this;
    }

    /**
     * Adds a percentage-based discount.
     */
    public function addPercentageDiscount(
        float $percentage,
        string $reason = 'Rabatt',
        float $taxPercent = 19.0
    ): self {
        // Calculate base amount from lines
        $baseAmount = array_reduce(
            $this->lines,
            fn(float $sum, InvoiceLine $line) => $sum + $line->getNetAmount(),
            0.0
        );
        $this->allowanceCharges[] = AllowanceCharge::percentageDiscount(
            $baseAmount,
            $percentage,
            $reason,
            null,
            TaxCategory::STANDARD,
            $taxPercent
        );
        return $this;
    }

    /**
     * Adds shipping/freight charges.
     */
    public function addShipping(float $amount, float $taxPercent = 19.0): self {
        $this->allowanceCharges[] = AllowanceCharge::shipping($amount, TaxCategory::STANDARD, $taxPercent);
        return $this;
    }

    /**
     * Adds packing charges.
     */
    public function addPacking(float $amount, float $taxPercent = 19.0): self {
        $this->allowanceCharges[] = AllowanceCharge::packing($amount, TaxCategory::STANDARD, $taxPercent);
        return $this;
    }

    // === Notes ===

    /**
     * Adds a note to the invoice.
     */
    public function addNote(string $note): self {
        $this->notes[] = $note;
        return $this;
    }

    // === Build ===

    /**
     * Builds the E-Rechnung document.
     * 
     * @throws \InvalidArgumentException If required fields are missing.
     */
    public function build(): Document {
        // Validate required fields
        if ($this->sellerName === null) {
            throw new \InvalidArgumentException('Seller name is required');
        }
        if ($this->buyerName === null) {
            throw new \InvalidArgumentException('Buyer name is required');
        }

        // Build seller party
        $seller = new Party(
            name: $this->sellerName,
            postalAddress: $this->sellerAddress,
            vatId: $this->sellerVatId,
            taxRegistrationId: $this->sellerTaxId,
            endpointId: $this->sellerEndpointId,
            endpointScheme: $this->sellerEndpointScheme,
            contactName: $this->sellerContactName,
            contactPhone: $this->sellerContactPhone,
            contactEmail: $this->sellerContactEmail,
            iban: $this->sellerIban,
            bic: $this->sellerBic,
            bankName: $this->sellerBankName
        );

        // Build buyer party
        $buyer = new Party(
            name: $this->buyerName,
            postalAddress: $this->buyerAddress,
            vatId: $this->buyerVatId,
            endpointId: $this->buyerEndpointId,
            endpointScheme: $this->buyerEndpointScheme
        );

        // Create document
        $document = new Document(
            id: $this->id,
            issueDate: $this->issueDate,
            invoiceType: $this->invoiceType,
            seller: $seller,
            buyer: $buyer,
            currency: $this->currency,
            profile: $this->profile,
            dueDate: $this->dueDate,
            taxPointDate: $this->taxPointDate,
            buyerReference: $this->buyerReference,
            orderReference: $this->orderReference,
            contractReference: $this->contractReference,
            projectReference: $this->projectReference,
            paymentMeansCode: $this->paymentMeansCode,
            paymentTerms: $this->paymentTerms,
            deliveryDate: $this->deliveryDate,
            precedingInvoiceReference: $this->precedingInvoiceReference
        );

        // Add lines
        foreach ($this->lines as $line) {
            $document->addLine($line);
        }

        // Add allowances/charges
        foreach ($this->allowanceCharges as $ac) {
            $document->addAllowanceCharge($ac);
        }

        // Add notes
        foreach ($this->notes as $note) {
            $document->addNote($note);
        }

        return $document;
    }
}