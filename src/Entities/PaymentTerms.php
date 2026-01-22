<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PaymentTerms.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use DateTimeImmutable;

/**
 * Payment Terms for E-Rechnung (EN 16931).
 * 
 * Represents payment conditions and due date information.
 * 
 * @package ERechnungToolkit\Entities
 */
final class PaymentTerms {
    public function __construct(
        private ?string $note = null,
        private ?DateTimeImmutable $dueDate = null,
        private ?string $mandateReference = null,
        private ?int $netPaymentDays = null,
        private ?float $discountPercent = null,
        private ?int $discountDays = null
    ) {
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function getDueDate(): ?DateTimeImmutable {
        return $this->dueDate;
    }

    public function getMandateReference(): ?string {
        return $this->mandateReference;
    }

    public function getNetPaymentDays(): ?int {
        return $this->netPaymentDays;
    }

    public function getDiscountPercent(): ?float {
        return $this->discountPercent;
    }

    public function getDiscountDays(): ?int {
        return $this->discountDays;
    }

    /**
     * Calculates the due date from invoice date.
     */
    public function calculateDueDate(DateTimeImmutable $invoiceDate): DateTimeImmutable {
        if ($this->dueDate !== null) {
            return $this->dueDate;
        }

        $days = $this->netPaymentDays ?? 30;
        return $invoiceDate->modify("+{$days} days");
    }

    /**
     * Calculates the discount deadline from invoice date.
     */
    public function calculateDiscountDeadline(DateTimeImmutable $invoiceDate): ?DateTimeImmutable {
        if ($this->discountDays === null || $this->discountPercent === null) {
            return null;
        }

        return $invoiceDate->modify("+{$this->discountDays} days");
    }

    /**
     * Calculates the discounted amount.
     */
    public function calculateDiscountedAmount(float $amount): float {
        if ($this->discountPercent === null) {
            return $amount;
        }

        return round($amount * (1 - $this->discountPercent / 100), 2);
    }

    /**
     * Generates a German payment terms note.
     */
    public function generateNote(float $amount, DateTimeImmutable $invoiceDate): string {
        $parts = [];

        if ($this->discountPercent !== null && $this->discountDays !== null) {
            $discountDeadline = $this->calculateDiscountDeadline($invoiceDate);
            $discountedAmount = $this->calculateDiscountedAmount($amount);
            $parts[] = sprintf(
                "Bei Zahlung bis zum %s: %.2f%% Skonto (%.2f EUR)",
                $discountDeadline->format('d.m.Y'),
                $this->discountPercent,
                $discountedAmount
            );
        }

        $dueDate = $this->calculateDueDate($invoiceDate);
        $parts[] = sprintf(
            "Zahlbar bis zum %s (%.2f EUR)",
            $dueDate->format('d.m.Y'),
            $amount
        );

        return implode('. ', $parts) . '.';
    }

    /**
     * Creates standard 30-day payment terms.
     */
    public static function net30(): self {
        return new self(
            note: 'Zahlbar innerhalb von 30 Tagen ohne Abzug.',
            netPaymentDays: 30
        );
    }

    /**
     * Creates payment terms with skonto.
     */
    public static function withSkonto(
        int $skontoDays,
        float $skontoPercent,
        int $netDays = 30
    ): self {
        return new self(
            note: sprintf(
                '%d Tage %s%% Skonto, %d Tage netto.',
                $skontoDays,
                number_format($skontoPercent, 1, ',', ''),
                $netDays
            ),
            netPaymentDays: $netDays,
            discountPercent: $skontoPercent,
            discountDays: $skontoDays
        );
    }

    /**
     * Creates immediate payment terms.
     */
    public static function immediate(): self {
        return new self(
            note: 'Zahlbar sofort ohne Abzug.',
            netPaymentDays: 0
        );
    }

    /**
     * Creates SEPA direct debit terms.
     */
    public static function sepaDirectDebit(string $mandateReference): self {
        return new self(
            note: 'Der Rechnungsbetrag wird per SEPA-Lastschrift eingezogen.',
            mandateReference: $mandateReference
        );
    }
}
