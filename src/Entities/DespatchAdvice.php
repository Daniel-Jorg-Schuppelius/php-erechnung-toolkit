<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdvice.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Contracts\Abstracts\XML\DomainXmlDocumentAbstract;
use DateTimeImmutable;
use ERechnungToolkit\Enums\DespatchAdviceProfile;
use ERechnungToolkit\Generators\DespatchAdviceGenerator;

/**
 * Despatch advice document (UBL DespatchAdvice — Peppol BIS Despatch Advice).
 *
 * The "Lieferschein" of the procurement chain (order → despatch advice →
 * invoice). The **supplier** is the document sender (cac:DespatchSupplierParty),
 * the **customer** is the recipient (cac:DeliveryCustomerParty). Lines reference
 * the originating order line, which makes it the natural basis for a goods
 * receipt reconciliation.
 *
 * @see https://docs.peppol.eu/poacc/upgrade-3/syntax/DespatchAdvice/
 */
final class DespatchAdvice extends DomainXmlDocumentAbstract {
    /** @var DespatchLine[] */
    private array $lines = [];

    /** @var string[] */
    private array $notes = [];

    public function __construct(
        private string $id,
        private DateTimeImmutable $issueDate,
        private Party $despatchSupplierParty,
        private Party $deliveryCustomerParty,
        private DespatchAdviceProfile $profile = DespatchAdviceProfile::PEPPOL_DESPATCH_ADVICE,
        private ?string $orderReference = null,
        private ?string $salesOrderId = null,
        private string $shipmentId = '1',
        private ?DateTimeImmutable $actualDeliveryDate = null,
        private ?PostalAddress $deliveryAddress = null
    ) {}

    public function getId(): string {
        return $this->id;
    }

    public function getIssueDate(): DateTimeImmutable {
        return $this->issueDate;
    }

    /** The supplier sending the goods (document sender). */
    public function getDespatchSupplierParty(): Party {
        return $this->despatchSupplierParty;
    }

    /** The customer receiving the goods (document recipient). */
    public function getDeliveryCustomerParty(): Party {
        return $this->deliveryCustomerParty;
    }

    public function getProfile(): DespatchAdviceProfile {
        return $this->profile;
    }

    public function getOrderReference(): ?string {
        return $this->orderReference;
    }

    public function getSalesOrderId(): ?string {
        return $this->salesOrderId;
    }

    public function getShipmentId(): string {
        return $this->shipmentId;
    }

    public function getActualDeliveryDate(): ?DateTimeImmutable {
        return $this->actualDeliveryDate;
    }

    public function getDeliveryAddress(): ?PostalAddress {
        return $this->deliveryAddress;
    }

    /**
     * @return DespatchLine[]
     */
    public function getLines(): array {
        return $this->lines;
    }

    /**
     * @return string[]
     */
    public function getNotes(): array {
        return $this->notes;
    }

    public function addLine(DespatchLine $line): self {
        $this->lines[] = $line;
        return $this;
    }

    public function addNote(string $note): self {
        $this->notes[] = $note;
        return $this;
    }

    public function countLines(): int {
        return count($this->lines);
    }

    /**
     * Validates mandatory despatch advice content.
     *
     * @return string[]
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->id)) {
            $errors[] = 'Despatch advice number is mandatory';
        }
        if (empty($this->despatchSupplierParty->getName())) {
            $errors[] = 'Despatch supplier name is mandatory';
        }
        if (empty($this->deliveryCustomerParty->getName())) {
            $errors[] = 'Delivery customer name is mandatory';
        }
        if (empty($this->lines)) {
            $errors[] = 'At least one despatch line is required';
        }

        return $errors;
    }

    public function isValid(): bool {
        return empty($this->validate());
    }

    /**
     * Generates UBL Despatch Advice XML output.
     */
    public function toUblXml(): string {
        return (new DespatchAdviceGenerator)->generateUbl($this);
    }

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
     * Creates a new despatch advice document.
     */
    public static function create(
        string $id,
        DateTimeImmutable $issueDate,
        Party $despatchSupplierParty,
        Party $deliveryCustomerParty,
        ?string $orderReference = null
    ): self {
        return new self(
            id: $id,
            issueDate: $issueDate,
            despatchSupplierParty: $despatchSupplierParty,
            deliveryCustomerParty: $deliveryCustomerParty,
            orderReference: $orderReference
        );
    }
}
