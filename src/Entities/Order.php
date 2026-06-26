<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Order.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Contracts\Abstracts\XML\DomainXmlDocumentAbstract;
use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;
use ERechnungToolkit\Enums\{OrderProfile, OrderXProfile};
use ERechnungToolkit\Generators\{OrderGenerator, OrderXGenerator};

/**
 * Order document (UBL Order — Peppol BIS Order only / XBestellung).
 *
 * Represents an electronic purchase order. In contrast to an invoice, the
 * **buyer** is the document originator/sender and the **seller** (supplier) is
 * the recipient. Tax is optional per line; the document carries an anticipated
 * monetary total rather than a settled tax breakdown.
 *
 * @see https://docs.peppol.eu/poacc/upgrade-3/syntax/Order/
 */
final class Order extends DomainXmlDocumentAbstract {
    /** @var OrderLine[] */
    private array $lines = [];

    /** @var AllowanceCharge[] */
    private array $allowanceCharges = [];

    /** @var string[] */
    private array $notes = [];

    public function __construct(
        private string $id,
        private DateTimeImmutable $issueDate,
        private Party $buyer,
        private Party $seller,
        private CurrencyCode $currency = CurrencyCode::Euro,
        private OrderProfile $profile = OrderProfile::XBESTELLUNG,
        private ?string $buyerReference = null,
        private ?string $salesOrderId = null,
        private ?string $contractReference = null,
        private ?string $originatorDocumentReference = null,
        private ?DateTimeImmutable $requestedDeliveryStartDate = null,
        private ?DateTimeImmutable $requestedDeliveryEndDate = null
    ) {}

    public function getId(): string {
        return $this->id;
    }

    public function getIssueDate(): DateTimeImmutable {
        return $this->issueDate;
    }

    /** The ordering party (document originator / sender). */
    public function getBuyer(): Party {
        return $this->buyer;
    }

    /** The supplier (document recipient). */
    public function getSeller(): Party {
        return $this->seller;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    public function getProfile(): OrderProfile {
        return $this->profile;
    }

    public function getBuyerReference(): ?string {
        return $this->buyerReference;
    }

    public function getSalesOrderId(): ?string {
        return $this->salesOrderId;
    }

    public function getContractReference(): ?string {
        return $this->contractReference;
    }

    public function getOriginatorDocumentReference(): ?string {
        return $this->originatorDocumentReference;
    }

    public function getRequestedDeliveryStartDate(): ?DateTimeImmutable {
        return $this->requestedDeliveryStartDate;
    }

    public function getRequestedDeliveryEndDate(): ?DateTimeImmutable {
        return $this->requestedDeliveryEndDate;
    }

    /**
     * @return OrderLine[]
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

    public function addLine(OrderLine $line): self {
        $this->lines[] = $line;
        return $this;
    }

    public function addAllowanceCharge(AllowanceCharge $allowanceCharge): self {
        $this->allowanceCharges[] = $allowanceCharge;
        return $this;
    }

    public function addNote(string $note): self {
        $this->notes[] = $note;
        return $this;
    }

    public function setRequestedDeliveryPeriod(
        DateTimeImmutable $start,
        ?DateTimeImmutable $end = null
    ): self {
        $this->requestedDeliveryStartDate = $start;
        $this->requestedDeliveryEndDate = $end;
        return $this;
    }

    /**
     * Sum of all order line net amounts (BT line extension equivalent).
     */
    public function getLineExtensionAmount(): float {
        return round(
            array_reduce(
                $this->lines,
                fn (float $sum, OrderLine $line) => $sum + $line->getNetAmount(),
                0.0
            ),
            2
        );
    }

    /**
     * Sum of document level allowances.
     */
    public function getAllowanceTotalAmount(): float {
        return round(
            array_reduce(
                array_filter($this->allowanceCharges, fn (AllowanceCharge $ac) => $ac->isAllowance()),
                fn (float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
                0.0
            ),
            2
        );
    }

    /**
     * Sum of document level charges.
     */
    public function getChargeTotalAmount(): float {
        return round(
            array_reduce(
                array_filter($this->allowanceCharges, fn (AllowanceCharge $ac) => $ac->isCharge()),
                fn (float $sum, AllowanceCharge $ac) => $sum + $ac->getAmount(),
                0.0
            ),
            2
        );
    }

    /**
     * Anticipated payable amount = line extension - allowances + charges.
     */
    public function getPayableAmount(): float {
        return round(
            $this->getLineExtensionAmount()
                - $this->getAllowanceTotalAmount()
                + $this->getChargeTotalAmount(),
            2
        );
    }

    public function countLines(): int {
        return count($this->lines);
    }

    /**
     * Validates mandatory order content (Peppol BIS Order core).
     *
     * @return string[]
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->id)) {
            $errors[] = 'BT-13: Order number is mandatory';
        }
        if (empty($this->buyer->getName())) {
            $errors[] = 'Buyer name is mandatory';
        }
        if (empty($this->seller->getName())) {
            $errors[] = 'Seller name is mandatory';
        }
        if (empty($this->lines)) {
            $errors[] = 'At least one order line is required';
        }

        return $errors;
    }

    public function isValid(): bool {
        return empty($this->validate());
    }

    /**
     * Generates UBL Order XML output.
     */
    public function toUblXml(): string {
        return (new OrderGenerator)->generateUbl($this);
    }

    /**
     * Generates Order-X CII XML output (UN/CEFACT Cross Industry Order).
     */
    public function toOrderXXml(OrderXProfile $profile = OrderXProfile::COMFORT): string {
        return (new OrderXGenerator)->generateCii($this, $profile);
    }

    /**
     * Generates XML in the default (UBL) format.
     */
    public function toXml(): string {
        return $this->toUblXml();
    }

    public function __toString(): string {
        return $this->toXml();
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultXml(): string {
        return $this->toXml();
    }

    /**
     * Creates a new order document.
     */
    public static function create(
        string $id,
        DateTimeImmutable $issueDate,
        Party $buyer,
        Party $seller,
        CurrencyCode $currency = CurrencyCode::Euro,
        OrderProfile $profile = OrderProfile::XBESTELLUNG
    ): self {
        return new self(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            profile: $profile
        );
    }
}
