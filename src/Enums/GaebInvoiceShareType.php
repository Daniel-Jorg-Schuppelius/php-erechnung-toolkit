<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebInvoiceShareType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Component of an invoice (GAEB DA XML 3.3, `InvoiceShareType`).
 *
 * GAEB deliberately prescribes no fixed invoice layout: the client defines the
 * terms and the extent, the standard only offers this list as a frame (see the
 * technical documentation, 8.1.2). A type may therefore appear several times
 * under different captions, and the order of the components is the order of
 * their computation.
 */
enum GaebInvoiceShareType: string {
    case BasicAmount = 'basic amount';
    case SecurityForFulfilment = 'security deposit for fulfillment of a contract';
    case SecurityForDefects = 'security deposit for requirements off defects';
    case SecurityDeposit = 'security deposit';
    case ContractPenalty = 'contract penalty';
    case SiteSign = 'construction sign building-blackboard';
    case PowerOnSite = 'power consumed on site';
    case Water = 'water';
    case Preparation = 'preparation';
    case BuildersRiskInsurance = "insurance against builder's risk";
    case WithholdingTax = 'construction withholding tax';
    case OtherDiscount = 'other type of discount';
    case PaymentsReceived = 'amount payments received';
    case InvoicedAmount = 'amount invoices';
    case OutstandingAmount = 'outstanding amount';
    case Subtotal = 'subtotal';
    case SmallContractExtra = 'small contract extra amount';
    case PercentageAdjustment = 'percentage extra or deduction amount';
    case Discount = 'discount';
    case Vat = 'VAT';

    public function label(): string {
        return match ($this) {
            self::BasicAmount => 'Grundbetrag',
            self::SecurityForFulfilment => 'Sicherheitseinbehalt Vertragserfüllung',
            self::SecurityForDefects => 'Sicherheitseinbehalt Mängelansprüche',
            self::SecurityDeposit => 'Sicherheitseinbehalt',
            self::ContractPenalty => 'Vertragsstrafe',
            self::SiteSign => 'Bauschild',
            self::PowerOnSite => 'Baustrom',
            self::Water => 'Bauwasser',
            self::Preparation => 'Vorhaltung',
            self::BuildersRiskInsurance => 'Bauwesenversicherung',
            self::WithholdingTax => 'Bauabzugsteuer',
            self::OtherDiscount => 'Sonstiger Abzug',
            self::PaymentsReceived => 'Erhaltene Zahlungen',
            self::InvoicedAmount => 'Bereits berechnet',
            self::OutstandingAmount => 'Offener Betrag',
            self::Subtotal => 'Zwischensumme',
            self::SmallContractExtra => 'Kleinauftragszuschlag',
            self::PercentageAdjustment => 'Prozentualer Zu-/Abschlag',
            self::Discount => 'Nachlass',
            self::Vat => 'Umsatzsteuer',
        };
    }

    /**
     * Does this component reduce the amount payable? Such shares are entered as
     * positive figures and subtracted - the sign belongs to the type, not to
     * the number.
     */
    public function reducesAmount(): bool {
        return match ($this) {
            self::SecurityForFulfilment, self::SecurityForDefects, self::SecurityDeposit,
            self::ContractPenalty, self::SiteSign, self::PowerOnSite, self::Water,
            self::Preparation, self::BuildersRiskInsurance, self::WithholdingTax,
            self::OtherDiscount, self::PaymentsReceived, self::InvoicedAmount,
            self::Discount => true,
            default => false,
        };
    }

    /** Is this a computed intermediate result rather than a claim of its own? */
    public function isIntermediate(): bool {
        return $this === self::Subtotal || $this === self::OutstandingAmount;
    }
}
