<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PaymentMeansCode.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Payment Means Code according to UNTDID 4461 (EN 16931).
 * 
 * Defines the payment method for the invoice.
 * 
 * @see https://docs.peppol.eu/poacc/billing/3.0/codelist/UNCL4461/
 * @package ERechnungToolkit\Enums
 */
enum PaymentMeansCode: string {
    /** Instrument not defined */
    case NOT_DEFINED = '1';

    /** Automated clearing house credit (ACH) */
    case ACH_CREDIT = '2';

    /** Automated clearing house debit (ACH) */
    case ACH_DEBIT = '3';

    /** Cash (Barzahlung) */
    case CASH = '10';

    /** Cheque (Scheck) */
    case CHEQUE = '20';

    /** Credit transfer (Überweisung) */
    case CREDIT_TRANSFER = '30';

    /** Debit transfer (Lastschrift) */
    case DEBIT_TRANSFER = '31';

    /** Payment to bank account (Banküberweisung) */
    case BANK_ACCOUNT = '42';

    /** Bank card (Bankkarte) */
    case BANK_CARD = '48';

    /** Direct debit (SEPA-Lastschrift) */
    case DIRECT_DEBIT = '49';

    /** Standing agreement (Dauerauftrag) */
    case STANDING_AGREEMENT = '57';

    /** SEPA credit transfer (SEPA-Überweisung) */
    case SEPA_CREDIT_TRANSFER = '58';

    /** SEPA direct debit (SEPA-Lastschrift) */
    case SEPA_DIRECT_DEBIT = '59';

    /** Online payment service */
    case ONLINE_PAYMENT = 'ZZZ';

    /**
     * Returns the German label.
     */
    public function label(): string {
        return match ($this) {
            self::NOT_DEFINED => 'Nicht definiert',
            self::ACH_CREDIT => 'ACH-Gutschrift',
            self::ACH_DEBIT => 'ACH-Lastschrift',
            self::CASH => 'Barzahlung',
            self::CHEQUE => 'Scheck',
            self::CREDIT_TRANSFER => 'Überweisung',
            self::DEBIT_TRANSFER => 'Lastschrift',
            self::BANK_ACCOUNT => 'Banküberweisung',
            self::BANK_CARD => 'Bankkarte',
            self::DIRECT_DEBIT => 'Lastschrift',
            self::STANDING_AGREEMENT => 'Dauerauftrag',
            self::SEPA_CREDIT_TRANSFER => 'SEPA-Überweisung',
            self::SEPA_DIRECT_DEBIT => 'SEPA-Lastschrift',
            self::ONLINE_PAYMENT => 'Online-Zahlung',
        };
    }

    /**
     * Checks if this is a SEPA payment method.
     */
    public function isSepa(): bool {
        return $this === self::SEPA_CREDIT_TRANSFER || $this === self::SEPA_DIRECT_DEBIT;
    }

    /**
     * Checks if this is a direct debit method.
     */
    public function isDirectDebit(): bool {
        return in_array($this, [
            self::ACH_DEBIT,
            self::DEBIT_TRANSFER,
            self::DIRECT_DEBIT,
            self::SEPA_DIRECT_DEBIT,
        ], true);
    }

    /**
     * Creates from UNTDID 4461 code string.
     */
    public static function fromCode(string $code): ?self {
        return self::tryFrom($code);
    }
}
