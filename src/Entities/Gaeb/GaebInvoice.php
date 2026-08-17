<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebInvoice.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\GaebInvoiceType;

/**
 * Head of a GAEB invoice (X89).
 *
 * The invoice phase differs from every other one in two respects (technical
 * documentation 8.1.1): it has to satisfy the statutory requirements for
 * invoices, and its layout is not fixed by GAEB but by the client, who passes
 * the frame down with the award (X86). What stays constant is this head -
 * number, date, kind, the period the work was done in - plus creator, recipient
 * and the gross total.
 */
final class GaebInvoice {
    /**
     * @param list<GaebInvoiceShare> $shares components in computation order
     */
    public function __construct(
        private readonly string $number,
        private readonly string $date,
        private readonly GaebInvoiceType $type,
        private readonly string $serviceStart,
        private readonly string $serviceEnd,
        private readonly ?GaebParty $creator = null,
        private readonly ?string $creatorTaxNumber = null,
        private readonly ?GaebParty $recipient = null,
        private readonly array $shares = [],
        private readonly ?Money $totalGross = null,
        private readonly ?string $sequentialNo = null,
        private readonly bool $creditNote = false,
    ) {}

    public function getNumber(): string {
        return $this->number;
    }

    public function getDate(): string {
        return $this->date;
    }

    public function getType(): GaebInvoiceType {
        return $this->type;
    }

    /** Beginn des Leistungszeitraums (ISO). */
    public function getServiceStart(): string {
        return $this->serviceStart;
    }

    /** Ende des Leistungszeitraums (ISO). */
    public function getServiceEnd(): string {
        return $this->serviceEnd;
    }

    public function getCreator(): ?GaebParty {
        return $this->creator;
    }

    /** Steuernummer des Rechnungserstellers - Pflichtangabe des Steuerrechts. */
    public function getCreatorTaxNumber(): ?string {
        return $this->creatorTaxNumber;
    }

    public function getRecipient(): ?GaebParty {
        return $this->recipient;
    }

    /** @return list<GaebInvoiceShare> */
    public function getShares(): array {
        return $this->shares;
    }

    public function getTotalGross(): ?Money {
        return $this->totalGross;
    }

    /** Laufende Nummer innerhalb der Abschlagsrechnungen. */
    public function getSequentialNo(): ?string {
        return $this->sequentialNo;
    }

    public function isCreditNote(): bool {
        return $this->creditNote;
    }
}
