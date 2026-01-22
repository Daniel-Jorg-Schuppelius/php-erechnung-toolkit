<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : InvoiceType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Invoice Type Code according to UNTDID 1001 (EN 16931).
 * 
 * Defines the type of the invoice document (Rechnung, Gutschrift, etc.).
 * 
 * @see https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL1001-inv/
 * @package ERechnungToolkit\Enums
 */
enum InvoiceType: string {
    /** Commercial invoice (Handelsrechnung) */
    case INVOICE = '380';

    /** Credit note (Gutschrift) */
    case CREDIT_NOTE = '381';

    /** Debit note (Belastungsanzeige) */
    case DEBIT_NOTE = '383';

    /** Corrected invoice (Korrekturrechnung) */
    case CORRECTED_INVOICE = '384';

    /** Self-billed invoice (Gutschrift im Eigenbeleg-Verfahren) */
    case SELF_BILLED_INVOICE = '389';

    /** Prepayment invoice (Anzahlungsrechnung) */
    case PREPAYMENT_INVOICE = '386';

    /** Partial invoice (Teilrechnung) */
    case PARTIAL_INVOICE = '326';

    /** Invoice information for accounting purposes (Proforma-Rechnung) */
    case PROFORMA_INVOICE = '325';

    /**
     * Returns the human-readable German name.
     */
    public function label(): string {
        return match ($this) {
            self::INVOICE => 'Rechnung',
            self::CREDIT_NOTE => 'Gutschrift',
            self::DEBIT_NOTE => 'Belastungsanzeige',
            self::CORRECTED_INVOICE => 'Korrekturrechnung',
            self::SELF_BILLED_INVOICE => 'Gutschrift (Eigenbeleg)',
            self::PREPAYMENT_INVOICE => 'Anzahlungsrechnung',
            self::PARTIAL_INVOICE => 'Teilrechnung',
            self::PROFORMA_INVOICE => 'Proforma-Rechnung',
        };
    }

    /**
     * Checks if this is a credit type document.
     */
    public function isCredit(): bool {
        return $this === self::CREDIT_NOTE || $this === self::SELF_BILLED_INVOICE;
    }

    /**
     * Creates from UNTDID 1001 code string.
     */
    public static function fromCode(string $code): ?self {
        return self::tryFrom($code);
    }
}
