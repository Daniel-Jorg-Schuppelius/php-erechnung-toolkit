<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceBuilder.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Builders;

use CommonToolkit\Enums\CountryCode;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine, Party, PostalAddress};
use ERechnungToolkit\Enums\UnitCode;
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;

/**
 * Fluent builder for UBL Despatch Advice documents (Peppol BIS Despatch Advice).
 *
 * Example:
 * ```php
 * $advice = DespatchAdviceBuilder::create('DA-2026-001')
 *     ->withOrderReference('ORD-2026-001')
 *     ->withSupplier('Lieferant GmbH', 'DE123456789')
 *     ->withSupplierAddress('Lieferweg 2', '54321', 'Lieferstadt')
 *     ->withCustomer('Besteller AG')
 *     ->withCustomerAddress('Bestellweg 1', '12345', 'Bestellstadt')
 *     ->withActualDeliveryDate(new DateTimeImmutable())
 *     ->addLine('Bürostuhl', 5, UnitCode::PIECE, '1', 'ART-4711')
 *     ->build();
 * ```
 */
final class DespatchAdviceBuilder {
    use ErrorLog;

    private DateTimeImmutable $issueDate;

    private ?string $supplierName = null;
    private ?string $supplierVatId = null;
    private ?string $supplierTaxId = null;
    private ?PostalAddress $supplierAddress = null;
    private ?string $supplierEndpointId = null;
    private ?string $supplierEndpointScheme = null;

    private ?string $customerName = null;
    private ?string $customerVatId = null;
    private ?PostalAddress $customerAddress = null;
    private ?string $customerEndpointId = null;
    private ?string $customerEndpointScheme = null;

    private ?string $orderReference = null;
    private ?string $salesOrderId = null;
    private string $shipmentId = '1';
    private ?DateTimeImmutable $actualDeliveryDate = null;
    private ?PostalAddress $deliveryAddress = null;

    /** @var DespatchLine[] */
    private array $lines = [];

    /** @var string[] */
    private array $notes = [];

    private int $lineCounter = 0;

    private function __construct(private string $id) {
        $this->issueDate = new DateTimeImmutable;
    }

    public static function create(string $id): self {
        return new self($id);
    }

    public function withIssueDate(DateTimeImmutable $date): self {
        $this->issueDate = $date;
        return $this;
    }

    public function withOrderReference(string $orderReference, ?string $salesOrderId = null): self {
        $this->orderReference = $orderReference;
        $this->salesOrderId = $salesOrderId;
        return $this;
    }

    public function withShipmentId(string $shipmentId): self {
        $this->shipmentId = $shipmentId;
        return $this;
    }

    public function withActualDeliveryDate(DateTimeImmutable $date): self {
        $this->actualDeliveryDate = $date;
        return $this;
    }

    public function withDeliveryAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string|null $country = null
    ): self {
        $this->deliveryAddress = new PostalAddress(
            streetName: $street,
            postalCode: $postalCode,
            city: $city,
            country: $country ?? CountryCode::Germany
        );
        return $this;
    }

    // === Supplier (sender of the goods) ===

    public function withSupplier(string $name, ?string $vatId = null, ?string $taxId = null): self {
        $this->supplierName = $name;
        $this->supplierVatId = $vatId;
        $this->supplierTaxId = $taxId;
        return $this;
    }

    public function withSupplierAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string|null $country = null,
        ?string $additionalLine = null
    ): self {
        $this->supplierAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country ?? CountryCode::Germany
        );
        return $this;
    }

    public function withSupplierEndpoint(string $endpointId, string $scheme = '9930'): self {
        $this->supplierEndpointId = $endpointId;
        $this->supplierEndpointScheme = $scheme;
        return $this;
    }

    // === Customer (recipient of the goods) ===

    public function withCustomer(string $name, ?string $vatId = null): self {
        $this->customerName = $name;
        $this->customerVatId = $vatId;
        return $this;
    }

    public function withCustomerAddress(
        string $street,
        string $postalCode,
        string $city,
        CountryCode|string|null $country = null,
        ?string $additionalLine = null
    ): self {
        $this->customerAddress = new PostalAddress(
            streetName: $street,
            additionalStreetName: $additionalLine,
            postalCode: $postalCode,
            city: $city,
            country: $country ?? CountryCode::Germany
        );
        return $this;
    }

    public function withCustomerEndpoint(string $endpointId, string $scheme = '0204'): self {
        $this->customerEndpointId = $endpointId;
        $this->customerEndpointScheme = $scheme;
        return $this;
    }

    // === Lines ===

    /**
     * Adds a despatch line.
     */
    public function addLine(
        string $itemName,
        float $deliveredQuantity,
        ?UnitCode $unitCode = null,
        ?string $orderLineId = null,
        ?string $sellersItemId = null,
        ?string $itemDescription = null
    ): self {
        $this->lineCounter++;
        $this->lines[] = new DespatchLine(
            id: (string) $this->lineCounter,
            deliveredQuantity: $deliveredQuantity,
            unitCode: $unitCode ?? UnitCode::PIECE,
            itemName: $itemName,
            orderLineId: $orderLineId,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId
        );
        return $this;
    }

    public function addDespatchLine(DespatchLine $line): self {
        $this->lines[] = $line;
        return $this;
    }

    public function addNote(string $note): self {
        $this->notes[] = $note;
        return $this;
    }

    /**
     * Builds the despatch advice document.
     *
     * @throws InvalidArgumentException If required parties are missing.
     */
    public function build(): DespatchAdvice {
        if ($this->supplierName === null) {
            $this->logError('Supplier name is required');
            throw new InvalidArgumentException('Supplier name is required');
        }
        if ($this->customerName === null) {
            $this->logError('Customer name is required');
            throw new InvalidArgumentException('Customer name is required');
        }

        $supplier = new Party(
            name: $this->supplierName,
            postalAddress: $this->supplierAddress,
            vatId: $this->supplierVatId,
            taxRegistrationId: $this->supplierTaxId,
            endpointId: $this->supplierEndpointId,
            endpointScheme: $this->supplierEndpointScheme
        );

        $customer = new Party(
            name: $this->customerName,
            postalAddress: $this->customerAddress,
            vatId: $this->customerVatId,
            endpointId: $this->customerEndpointId,
            endpointScheme: $this->customerEndpointScheme
        );

        $advice = new DespatchAdvice(
            id: $this->id,
            issueDate: $this->issueDate,
            despatchSupplierParty: $supplier,
            deliveryCustomerParty: $customer,
            orderReference: $this->orderReference,
            salesOrderId: $this->salesOrderId,
            shipmentId: $this->shipmentId,
            actualDeliveryDate: $this->actualDeliveryDate,
            deliveryAddress: $this->deliveryAddress
        );

        foreach ($this->lines as $line) {
            $advice->addLine($line);
        }
        foreach ($this->notes as $note) {
            $advice->addNote($note);
        }

        return $advice;
    }
}
