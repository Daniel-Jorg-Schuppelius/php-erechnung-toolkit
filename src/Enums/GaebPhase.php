<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebPhase.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * GAEB data exchange phase (GAEB DA XML 3.3).
 *
 * The value is the DA code as it appears in the file extension and in the `DP`
 * element, e.g. `83` for a request for bid (`.x83`, `.d83`, `.p83`). Codes are
 * not always numeric: the invoice attachment is `89B`, the framework phases
 * carry a `Z`.
 *
 * The beta phases of 3.3 (X61 room book, X84P price comparison, X98/X99 trade)
 * are deliberately absent until the committee releases them.
 */
enum GaebPhase: string {
    case QuantitySurvey = '31';          // Mengenermittlung (REB-VB 23.003)
    case CostCatalogue = '50';           // Baukostenkatalog
    case CostEstimate = '51';            // Kostenermittlung
    case CalculationData = '52';         // Kalkulationsdaten
    case Universal = '80';               // Universelle LV-Daten
    case Lv = '81';                      // Leistungsbeschreibung
    case Estimate = '82';                // Kostenansatz
    case RequestForBid = '83';           // Angebotsaufforderung
    case Bid = '84';                     // Angebotsabgabe
    case SideBid = '85';                 // Nebenangebot
    case Award = '86';                   // Auftragserteilung
    case AwardConfirmation = '87';       // Auftragsbestätigung
    case Invoice = '89';                 // Rechnung
    case InvoiceAttachment = '89B';      // Rechnungsbegründende Unterlage
    case FrameworkRequestForBid = '83Z'; // Zeitvertrag: Angebotsaufforderung
    case FrameworkBid = '84Z';           // Zeitvertrag: Angebotsabgabe
    case FrameworkCallOff = '86ZE';      // Zeitvertrag: Einzelauftrag
    case FrameworkAgreement = '86ZR';    // Zeitvertrag: Rahmenauftrag
    case PriceInquiry = '93';            // Handel: Preisanfrage
    case PriceOffer = '94';              // Handel: Preisangebot
    case Order = '96';                   // Handel: Bestellung
    case OrderConfirmation = '97';       // Handel: Auftragsbestätigung

    public function label(): string {
        return match ($this) {
            self::QuantitySurvey => 'Mengenermittlung',
            self::CostCatalogue => 'Baukostenkatalog',
            self::CostEstimate => 'Kostenermittlung',
            self::CalculationData => 'Kalkulationsdaten',
            self::Universal => 'Universelle LV-Daten',
            self::Lv => 'Leistungsbeschreibung',
            self::Estimate => 'Kostenansatz',
            self::RequestForBid => 'Angebotsaufforderung',
            self::Bid => 'Angebotsabgabe',
            self::SideBid => 'Nebenangebot',
            self::Award => 'Auftragserteilung',
            self::AwardConfirmation => 'Auftragsbestätigung',
            self::Invoice => 'Rechnung',
            self::InvoiceAttachment => 'Rechnungsbegründende Unterlage',
            self::FrameworkRequestForBid => 'Zeitvertrag: Angebotsaufforderung',
            self::FrameworkBid => 'Zeitvertrag: Angebotsabgabe',
            self::FrameworkCallOff => 'Zeitvertrag: Einzelauftrag',
            self::FrameworkAgreement => 'Zeitvertrag: Rahmenauftrag',
            self::PriceInquiry => 'Preisanfrage',
            self::PriceOffer => 'Preisangebot',
            self::Order => 'Bestellung',
            self::OrderConfirmation => 'Auftragsbestätigung (Handel)',
        };
    }

    /** Is this one of the bill of quantity phases (X80 to X87)? */
    public function isBillOfQuantity(): bool {
        return match ($this) {
            self::Universal, self::Lv, self::Estimate, self::RequestForBid,
            self::Bid, self::SideBid, self::Award, self::AwardConfirmation => true,
            default => false,
        };
    }

    /** Does this phase carry binding unit and total prices? */
    public function carriesPrices(): bool {
        return match ($this) {
            self::Lv, self::RequestForBid, self::FrameworkRequestForBid,
            self::QuantitySurvey, self::PriceInquiry => false,
            default => true,
        };
    }

    /**
     * Does this phase carry the texts of the bill of quantity? The bid returns
     * prices for an already known document: labels, short and long texts have no
     * place there, only the text complements the bidder filled in (GAEB DA XML
     * 3.3, X84 schema).
     */
    public function carriesTexts(): bool {
        return match ($this) {
            self::Bid, self::FrameworkBid => false,
            default => true,
        };
    }

    /**
     * Must quantity and unit be present on an item? Bid submission is the only
     * phase where they may be omitted: the bidder returns prices and text
     * complements for an already known bill of quantity (GAEB DA XML 3.3,
     * rules for X80 to X86, object Item, rule 7). The framework bid follows the
     * same idea.
     */
    public function carriesQuantities(): bool {
        return match ($this) {
            self::Bid, self::FrameworkBid => false,
            default => true,
        };
    }

    /**
     * Tolerant lookup from a DA code such as "84", "X84", "x84" or "89B". Only
     * the leading format letter is stripped - the code itself may end in a
     * letter and must survive.
     */
    public static function fromCode(?string $code): ?self {
        if ($code === null) {
            return null;
        }

        $normalised = strtoupper(trim($code));
        if (preg_match('/^[XDP](\d.*)$/', $normalised, $matches) === 1) {
            $normalised = $matches[1];
        }

        return self::tryFrom($normalised);
    }
}
