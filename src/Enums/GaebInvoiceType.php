<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebInvoiceType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Kind of invoice in the X89 phase (GAEB DA XML 3.3, `InvoiceType`).
 *
 * The kind decides what the amounts mean: a deduction is settled against later,
 * a final account closes the contract, and a pro forma invoice is no invoice in
 * the sense of the German commercial code at all.
 */
enum GaebInvoiceType: string {
    case Deduction = 'deduction';                 // Abschlagsrechnung
    case FinalAccount = 'final account';          // Schlussrechnung
    case PartFinalAccount = 'part final account'; // Teilschlussrechnung
    case AdvancePayment = 'advance payment';      // Vorauszahlung
    case SingleInvoice = 'single invoice';        // Einzelrechnung
    case ProForma = 'pro forma invoice';          // Pro-forma-Rechnung
    case Reviewed = 'reviewed invoice';           // Geprüfte Rechnung

    public function label(): string {
        return match ($this) {
            self::Deduction => 'Abschlagsrechnung',
            self::FinalAccount => 'Schlussrechnung',
            self::PartFinalAccount => 'Teilschlussrechnung',
            self::AdvancePayment => 'Vorauszahlung',
            self::SingleInvoice => 'Einzelrechnung',
            self::ProForma => 'Pro-forma-Rechnung',
            self::Reviewed => 'Geprüfte Rechnung',
        };
    }

    /** Does this kind close the contract? Afterwards only corrections follow. */
    public function closesContract(): bool {
        return $this === self::FinalAccount;
    }

    /**
     * Is a payment claim attached to it? A pro forma invoice states amounts
     * without demanding them - treating it as due would be wrong.
     */
    public function demandsPayment(): bool {
        return $this !== self::ProForma;
    }
}
