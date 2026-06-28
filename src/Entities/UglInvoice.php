<?php
/*
 * Created on   : Sun Jun 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglInvoice.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;

/**
 * Inbound UGL 5.0 invoice (Satzart RGD) — wholesaler → craftsman.
 *
 * A lightweight value object for *reading* a UGL invoice for reconciliation
 * against a purchase order. The toolkit deliberately does NOT generate UGL
 * invoices (the leading invoicing system keeps invoice sovereignty); only the
 * header totals and the article positions are reconstructed.
 *
 * @see \ERechnungToolkit\Parsers\UglInvoiceParser
 */
final class UglInvoice {
    /** Belegart RG = Rechnung (invoice). */
    public const TYPE_INVOICE = 'RG';

    /** Belegart GS = Gutschrift (credit note). */
    public const TYPE_CREDIT = 'GS';

    /**
     * @param  OrderLine[]  $lines
     */
    public function __construct(
        private string $number,
        private string $documentType,
        private DateTimeImmutable $date,
        private CurrencyCode $currency,
        private float $grossTotal,
        private float $vatAmount,
        private float $netTotal,
        private ?DateTimeImmutable $dueDate = null,
        private array $lines = []
    ) {}

    public function getNumber(): string {
        return $this->number;
    }

    /** RG (invoice) or GS (credit note). */
    public function getDocumentType(): string {
        return $this->documentType;
    }

    public function isCreditNote(): bool {
        return $this->documentType === self::TYPE_CREDIT;
    }

    public function getDate(): DateTimeImmutable {
        return $this->date;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    public function getGrossTotal(): float {
        return $this->grossTotal;
    }

    public function getVatAmount(): float {
        return $this->vatAmount;
    }

    public function getNetTotal(): float {
        return $this->netTotal;
    }

    public function getDueDate(): ?DateTimeImmutable {
        return $this->dueDate;
    }

    /**
     * @return OrderLine[]
     */
    public function getLines(): array {
        return $this->lines;
    }

    public function countLines(): int {
        return count($this->lines);
    }
}
