<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : AllowanceChargeReasonCode.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Allowance/Charge Reason Code according to UNTDID 5189/7161 (EN 16931).
 * 
 * Defines the reason for document level allowances and charges.
 * 
 * @package ERechnungToolkit\Enums
 */
enum AllowanceChargeReasonCode: string {
    // Allowance codes (UNTDID 5189)
    /** Bonus for works ahead of schedule */
    case BONUS_AHEAD_SCHEDULE = '41';

    /** Other bonus (Sonstiger Bonus) */
    case OTHER_BONUS = '42';

    /** Manufacturer's consumer discount */
    case MANUFACTURER_DISCOUNT = '60';

    /** Due to military status */
    case MILITARY_DISCOUNT = '62';

    /** Due to work accident */
    case WORK_ACCIDENT_DISCOUNT = '63';

    /** Special agreement (Sondervereinbarung) */
    case SPECIAL_AGREEMENT = '64';

    /** Production error discount */
    case PRODUCTION_ERROR = '65';

    /** New outlet discount */
    case NEW_OUTLET = '66';

    /** Sample discount */
    case SAMPLE = '67';

    /** End-of-range discount */
    case END_OF_RANGE = '68';

    /** Incoterm discount */
    case INCOTERM = '70';

    /** Point of sales threshold allowance */
    case POS_THRESHOLD = '71';

    /** Material surcharge/deduction */
    case MATERIAL_SURCHARGE = '88';

    /** Discount (Rabatt) */
    case DISCOUNT = '95';

    /** Special rebate (Sonderrabatt) */
    case SPECIAL_REBATE = '100';

    /** Fixed long term (Festrabatt) */
    case FIXED_LONG_TERM = '102';

    /** Temporary (Zeitrabatt) */
    case TEMPORARY = '103';

    /** Standard (Standardrabatt) */
    case STANDARD = '104';

    /** Yearly turnover (Jahresumsatzrabatt) */
    case YEARLY_TURNOVER = '105';
    
    // Charge codes (UNTDID 7161)
    /** Freight charge (Frachtkosten) */
    case FREIGHT = 'FC';

    /** Insurance (Versicherung) */
    case INSURANCE = 'INS';

    /** Packing (Verpackung) */
    case PACKING = 'PC';

    /** Handling (Bearbeitung) */
    case HANDLING = 'HD';

    /** Pickup (Abholung) */
    case PICKUP = 'PI';

    /** Delivery (Lieferung) */
    case DELIVERY = 'DL';

    /** Testing (Prüfung) */
    case TESTING = 'TST';

    /** Advertising (Werbung) */
    case ADVERTISING = 'ADR';

    /** Customs duties (Zollgebühren) */
    case CUSTOMS = 'AAA';

    /** Environmental protection service */
    case ENVIRONMENTAL = 'ABL';

    /**
     * Returns the German label.
     */
    public function label(): string {
        return match ($this) {
            self::BONUS_AHEAD_SCHEDULE => 'Bonus für vorzeitige Fertigstellung',
            self::OTHER_BONUS => 'Sonstiger Bonus',
            self::MANUFACTURER_DISCOUNT => 'Herstellerrabatt',
            self::MILITARY_DISCOUNT => 'Militärrabatt',
            self::WORK_ACCIDENT_DISCOUNT => 'Arbeitsunfallrabatt',
            self::SPECIAL_AGREEMENT => 'Sondervereinbarung',
            self::PRODUCTION_ERROR => 'Produktionsfehlerrabatt',
            self::NEW_OUTLET => 'Neueröffnungsrabatt',
            self::SAMPLE => 'Musterrabatt',
            self::END_OF_RANGE => 'Auslaufrabatt',
            self::INCOTERM => 'Incoterm-Rabatt',
            self::POS_THRESHOLD => 'POS-Schwellenwertrabatt',
            self::MATERIAL_SURCHARGE => 'Materialzuschlag/-abzug',
            self::DISCOUNT => 'Rabatt',
            self::SPECIAL_REBATE => 'Sonderrabatt',
            self::FIXED_LONG_TERM => 'Festrabatt',
            self::TEMPORARY => 'Zeitrabatt',
            self::STANDARD => 'Standardrabatt',
            self::YEARLY_TURNOVER => 'Jahresumsatzrabatt',
            self::FREIGHT => 'Frachtkosten',
            self::INSURANCE => 'Versicherung',
            self::PACKING => 'Verpackung',
            self::HANDLING => 'Bearbeitung',
            self::PICKUP => 'Abholung',
            self::DELIVERY => 'Lieferung',
            self::TESTING => 'Prüfung',
            self::ADVERTISING => 'Werbung',
            self::CUSTOMS => 'Zollgebühren',
            self::ENVIRONMENTAL => 'Umweltschutzservice',
        };
    }

    /**
     * Checks if this is an allowance (Rabatt/Abzug).
     */
    public function isAllowance(): bool {
        return is_numeric($this->value);
    }

    /**
     * Checks if this is a charge (Zuschlag).
     */
    public function isCharge(): bool {
        return !$this->isAllowance();
    }
}
