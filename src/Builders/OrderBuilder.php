<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderBuilder.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Builders;

use CommonToolkit\Enums\{CountryCode, CurrencyCode};
use DateTimeImmutable;
use ERechnungToolkit\Entities\{AllowanceCharge, Order, OrderLine, Party, PostalAddress};
use ERechnungToolkit\Enums\{OrderProfile, TaxCategory, UnitCode};
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;

/**
 * Fluent builder for UBL Order documents (Peppol BIS Order / XBestellung).
 *
 * Example:
 * ```php
 * $order = OrderBuilder::xbestellung('ORD-2026-001')
 *     ->withIssueDate(new DateTimeImmutable())
 *     ->withBuyer('Stadt Musterstadt')
 *     ->withBuyerAddress('Rathausplatz 1', '12345', 'Musterstadt')
 *     ->withBuyerEndpoint('04011000-12345-67', '0204')
 *     ->withSeller('Lieferant GmbH', 'DE123456789')
 *     ->withSellerAddress('Lieferweg 2', '54321', 'Lieferstadt')
 *     ->withSellerEndpoint('DE123456789', '9930')
 *     ->addLine('Bürostuhl', 5, 120.00, UnitCode::PIECE, 'ART-4711')
 *     ->build();
 * ```
 */
final class OrderBuilder {
    use ErrorLog;

    private DateTimeImmutable $issueDate;
    private CurrencyCode $currency = CurrencyCode::Euro;
    private OrderProfile $profile = OrderProfile::XBESTELLUNG;

    private ?string $buyerName = null;
    private ?string $buyerVatId = null;
    private ?PostalAddress $buyerAddress = null;
    private ?string $buyerEndpointId = null;
    private ?string $buyerEndpointScheme = null;
    private ?string $buyerContactName = null;
    private ?string $buyerContactPhone = null;
    private ?string $buyerContactEmail = null;

    private ?string $sellerName = null;
    private ?string $sellerVatId = null;
    private ?string $sellerTaxId = null;
    private ?PostalAddress $sellerAddress = null;
    private ?string $sellerEndpointId = null;
    private ?string $sellerEndpointScheme = null;

    private ?string $buyerReference = null;
    private ?string $salesOrderId = null;
    private ?string $contractReference = null;
    private ?string $originatorDocumentReference = null;
    private ?DateTimeImmutable $requestedDeliveryStartDate = null;
    private ?DateTimeImmutable $requestedDeliveryEndDate = null;

    /** @var OrderLine[] */
    private array $lines = [];

    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    /** @var string[] */
    private array $notes = [];

    private int $lineCounter = 0;

    private function __construct(private string $id) {
        $this->issueDate = new DateTimeImmutable;
    }

    /**
     * Creates a new order builder (Peppol BIS Order only by default).
     */
    public static function create(string $id): self {
        return (new self($id))->withProfile(OrderProfile::PEPPOL_ORDER_ONLY);
    }

    /**
     * Creates a builder for a German XBestellung order.
     */
    public static function xbestellung(string $id): self {
        return (new self($id))->withProfile(OrderProfile::XBESTELLUNG);
    }

    // === Metadata ===

    public function withIssueDate(DateTimeImmutable $date): self {
        $this->issueDate = $date;
        return $this;
    }

    public function withCurrency(CurrencyCode $currency): self {
        $this->currency = $currency;
        return $this;
    }

    public function withProfile(OrderProfile $profile): self {
        $this->profile = $profile;
        return $this;
    }

    public function withBuyerReference(string $reference): self {
        $this->buyerReference = $reference;
        return $this;
    }

    public function withSalesOrderId(string $salesOrderId): self {
        $this->salesOrderId = $salesOrderId;
        return $this;
    }

    public function withContractReference(string $reference): self {
        $this->contractReference = $reference;
        return $this;
    }

    public function withOriginatorDocumentReference(string $reference): self {
        $this->originatorDocumentReference = $reference;
        return $this;
    }

    public function withRequestedDeliveryPeriod(DateTimeImmutable $start, ?DateTimeImmutable $end = null): self {
        $this->requestedDeliveryStartDate = $start;
        $this->requestedDeliveryEndDate = $end;
        return $this;
    }

    // === Buyer (ordering party) ===

    public function withBuyer(string $name, ?string $vatId = null): self {
        $this->buyerName = $name;
        $this->buyerVatId = $vatId;
        return $this;
    }

    public function withBuyerAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string|null $country = null,
        ?string $additionalLine = null
    ): self {
        $this->buyerAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country ?? CountryCode::Germany
        );
        return $this;
    }

    public function withBuyerEndpoint(string $endpointId, string $scheme = '0204'): self {
        $this->buyerEndpointId = $endpointId;
        $this->buyerEndpointScheme = $scheme;
        return $this;
    }

    public function withBuyerContact(string $name, ?string $phone = null, ?string $email = null): self {
        $this->buyerContactName = $name;
        $this->buyerContactPhone = $phone;
        $this->buyerContactEmail = $email;
        return $this;
    }

    // === Seller (supplier) ===

    public function withSeller(string $name, ?string $vatId = null, ?string $taxId = null): self {
        $this->sellerName = $name;
        $this->sellerVatId = $vatId;
        $this->sellerTaxId = $taxId;
        return $this;
    }

    public function withSellerAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string|null $country = null,
        ?string $additionalLine = null
    ): self {
        $this->sellerAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country ?? CountryCode::Germany
        );
        return $this;
    }

    public function withSellerEndpoint(string $endpointId, string $scheme = '9930'): self {
        $this->sellerEndpointId = $endpointId;
        $this->sellerEndpointScheme = $scheme;
        return $this;
    }

    // === Lines ===

    /**
     * Adds an order line; the net amount is derived from quantity and unit price.
     */
    public function addLine(
        string $itemName,
        float $quantity,
        float $unitPrice,
        ?UnitCode $unitCode = null,
        ?string $sellersItemId = null,
        ?float $taxPercent = null,
        ?TaxCategory $taxCategory = null,
        ?string $itemDescription = null
    ): self {
        $this->lineCounter++;
        $this->lines[] = new OrderLine(
            id: (string) $this->lineCounter,
            quantity: $quantity,
            unitCode: $unitCode ?? UnitCode::PIECE,
            netAmount: round($quantity * $unitPrice, 2),
            itemName: $itemName,
            unitPrice: $unitPrice,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId,
            taxCategory: $taxCategory,
            taxPercent: $taxPercent
        );
        return $this;
    }

    /**
     * Adds an existing order line.
     */
    public function addOrderLine(OrderLine $line): self {
        $this->lines[] = $line;
        return $this;
    }

    // === Allowances and charges ===

    public function addDiscount(float $amount, string $reason = 'Rabatt'): self {
        $this->allowanceCharges[] = AllowanceCharge::discount($amount, $reason, null, null, null);
        return $this;
    }

    public function addCharge(float $amount, string $reason): self {
        $this->allowanceCharges[] = AllowanceCharge::surcharge($amount, $reason, null, null, null);
        return $this;
    }

    public function addNote(string $note): self {
        $this->notes[] = $note;
        return $this;
    }

    // === Build ===

    /**
     * Builds the order document.
     *
     * @throws InvalidArgumentException If required parties are missing.
     */
    public function build(): Order {
        if ($this->buyerName === null) {
            $this->logError('Buyer name is required');
            throw new InvalidArgumentException('Buyer name is required');
        }
        if ($this->sellerName === null) {
            $this->logError('Seller name is required');
            throw new InvalidArgumentException('Seller name is required');
        }

        $buyer = new Party(
            name: $this->buyerName,
            postalAddress: $this->buyerAddress,
            vatId: $this->buyerVatId,
            endpointId: $this->buyerEndpointId,
            endpointScheme: $this->buyerEndpointScheme,
            contactName: $this->buyerContactName,
            contactPhone: $this->buyerContactPhone,
            contactEmail: $this->buyerContactEmail
        );

        $seller = new Party(
            name: $this->sellerName,
            postalAddress: $this->sellerAddress,
            vatId: $this->sellerVatId,
            taxRegistrationId: $this->sellerTaxId,
            endpointId: $this->sellerEndpointId,
            endpointScheme: $this->sellerEndpointScheme
        );

        $order = new Order(
            id: $this->id,
            issueDate: $this->issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $this->currency,
            profile: $this->profile,
            buyerReference: $this->buyerReference,
            salesOrderId: $this->salesOrderId,
            contractReference: $this->contractReference,
            originatorDocumentReference: $this->originatorDocumentReference,
            requestedDeliveryStartDate: $this->requestedDeliveryStartDate,
            requestedDeliveryEndDate: $this->requestedDeliveryEndDate
        );

        foreach ($this->lines as $line) {
            $order->addLine($line);
        }
        foreach ($this->allowanceCharges as $ac) {
            $order->addAllowanceCharge($ac);
        }
        foreach ($this->notes as $note) {
            $order->addNote($note);
        }

        return $order;
    }
}
