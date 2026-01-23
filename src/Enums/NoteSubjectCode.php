<?php
/*
 * Created on   : Fri Jan 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : NoteSubjectCode.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Note Subject Code according to UNTDID 4451.
 * 
 * Used for categorizing invoice notes (BT-21/BT-22) in EN 16931.
 * 
 * @package ERechnungToolkit\Enums
 */
enum NoteSubjectCode: string {
    // General
    case AAA = 'AAA'; // Terms of delivery
    case AAB = 'AAB'; // Terms of payment
    case AAC = 'AAC'; // Additional terms
    case AAD = 'AAD'; // Price conditions
    case AAE = 'AAE'; // Additional conditions
    case AAF = 'AAF'; // Payment discount terms
    case AAG = 'AAG'; // Price change reason
    case AAI = 'AAI'; // Payment information
    case AAJ = 'AAJ'; // Free text
    case AAK = 'AAK'; // Party instructions
    case AAL = 'AAL'; // Accounting information
    case AAM = 'AAM'; // Consignment information
    case AAN = 'AAN'; // Delivery conditions
    case AAO = 'AAO'; // Customs information
    case AAP = 'AAP'; // Special instructions
    case AAQ = 'AAQ'; // Terms of sale
    case AAR = 'AAR'; // Terms and conditions
    case AAS = 'AAS'; // Terms of transport
    case AAT = 'AAT'; // Packaging information
    case AAU = 'AAU'; // Allowance/charge information
    case AAV = 'AAV'; // Handling instructions
    case AAW = 'AAW'; // Hazardous material information
    case AAX = 'AAX'; // License information
    case AAY = 'AAY'; // Certification statements
    case AAZ = 'AAZ'; // Clean on board statements

        // Business specific
    case ABL = 'ABL'; // Legal information
    case ABN = 'ABN'; // Contract information
    case ABO = 'ABO'; // Parties to transaction
    case ABP = 'ABP'; // Payer information
    case ABQ = 'ABQ'; // Quotation instruction
    case ABR = 'ABR'; // Regulatory information (superseded by REG)
    case ABS = 'ABS'; // Special handling
    case ABT = 'ABT'; // Shipment description
    case ABU = 'ABU'; // Transaction reference number description
    case ABV = 'ABV'; // Transport contract references

        // Common codes for E-Invoicing
    case ACB = 'ACB'; // Additional information
    case ACC = 'ACC'; // Clause on copyright
    case ACD = 'ACD'; // Container remarks
    case ACE = 'ACE'; // Contract clause
    case ACF = 'ACF'; // Contract description

    case ADU = 'ADU'; // General information (most common for general notes)

    case CUS = 'CUS'; // Customs information

    case PUR = 'PUR'; // Purchasing information

    case REG = 'REG'; // Regulatory information

    case SUR = 'SUR'; // Surety/bond requirement

    case TAX = 'TAX'; // Tax declaration
    case TXD = 'TXD'; // Tax declaration (alternative)

    case WHI = 'WHI'; // Warehouse instructions
    case ZZZ = 'ZZZ'; // Mutually defined

    /**
     * Returns a human-readable label.
     */
    public function getLabel(): string {
        return match ($this) {
            self::AAA => 'Lieferbedingungen',
            self::AAB => 'Zahlungsbedingungen',
            self::AAC => 'Zusätzliche Bedingungen',
            self::AAD => 'Preisbedingungen',
            self::AAE => 'Weitere Bedingungen',
            self::AAF => 'Skontobedingungen',
            self::AAG => 'Preisänderungsgrund',
            self::AAI => 'Zahlungsinformationen',
            self::AAJ => 'Freitext',
            self::AAK => 'Partei-Anweisungen',
            self::AAL => 'Buchhaltungsinformationen',
            self::AAM => 'Sendungsinformationen',
            self::AAN => 'Lieferbedingungen',
            self::AAO => 'Zollinformationen',
            self::AAP => 'Besondere Anweisungen',
            self::AAQ => 'Verkaufsbedingungen',
            self::AAR => 'Allgemeine Geschäftsbedingungen',
            self::AAS => 'Transportbedingungen',
            self::AAT => 'Verpackungsinformationen',
            self::AAU => 'Zu-/Abschlagsinformationen',
            self::AAV => 'Handhabungsanweisungen',
            self::AAW => 'Gefahrgutinformationen',
            self::AAX => 'Lizenzinformationen',
            self::AAY => 'Zertifizierungsangaben',
            self::AAZ => 'Clean-on-Board-Vermerk',
            self::ABL => 'Rechtliche Hinweise',
            self::ABN => 'Vertragsinformationen',
            self::ABO => 'Transaktionsparteien',
            self::ABP => 'Zahlerpflichtinformationen',
            self::ABQ => 'Angebotsanweisungen',
            self::ABR => 'Regulatorische Informationen',
            self::ABS => 'Besondere Behandlung',
            self::ABT => 'Sendungsbeschreibung',
            self::ABU => 'Transaktionsreferenzbeschreibung',
            self::ABV => 'Transportvertragsreferenzen',
            self::ACB => 'Zusatzinformationen',
            self::ACC => 'Urheberrechtsklausel',
            self::ACD => 'Containeranmerkungen',
            self::ACE => 'Vertragsklausel',
            self::ACF => 'Vertragsbeschreibung',
            self::ADU => 'Allgemeine Informationen',
            self::CUS => 'Zollinformationen',
            self::PUR => 'Einkaufsinformationen',
            self::REG => 'Regulatorische Hinweise',
            self::SUR => 'Bürgschaftsanforderung',
            self::TAX => 'Steuererklärung',
            self::TXD => 'Steuerliche Hinweise',
            self::WHI => 'Lageranweisungen',
            self::ZZZ => 'Benutzerdefiniert',
        };
    }

    /**
     * Returns the prefix format for notes (e.g., "#ADU#").
     */
    public function getPrefix(): string {
        return "#{$this->value}#";
    }

    /**
     * Creates a NoteSubjectCode from a prefix string.
     * 
     * @param string $prefix The prefix (e.g., "#ADU#" or "ADU")
     * @return self|null The matching code or null if not found
     */
    public static function fromPrefix(string $prefix): ?self {
        // Remove # characters if present
        $code = trim($prefix, '#');

        foreach (self::cases() as $case) {
            if ($case->value === $code) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Extracts subject code and text from a note string.
     * 
     * @param string $note The note text (e.g., "#ADU#This is a note")
     * @return array{code: self|null, text: string}
     */
    public static function parseNote(string $note): array {
        // Pattern: #XXX# at the beginning
        if (preg_match('/^#([A-Z]{3})#(.*)$/s', $note, $matches)) {
            $code = self::fromPrefix($matches[1]);
            return [
                'code' => $code,
                'text' => trim($matches[2]),
            ];
        }

        return [
            'code' => null,
            'text' => $note,
        ];
    }

    /**
     * Formats a note with subject code prefix.
     * 
     * @param string $text The note text
     * @param self|null $code The subject code (optional)
     * @return string The formatted note
     */
    public static function formatNote(string $text, ?self $code = null): string {
        if ($code === null) {
            return $text;
        }

        return $code->getPrefix() . $text;
    }

    /**
     * Common codes for specific use cases.
     */
    public static function forGeneralInfo(): self {
        return self::ADU;
    }

    public static function forPaymentInfo(): self {
        return self::AAI;
    }

    public static function forLegalInfo(): self {
        return self::ABL;
    }

    public static function forRegulatory(): self {
        return self::REG;
    }

    public static function forTax(): self {
        return self::TXD;
    }

    public static function forCustoms(): self {
        return self::CUS;
    }

    public static function forTermsAndConditions(): self {
        return self::AAR;
    }

    public static function forDeliveryConditions(): self {
        return self::AAN;
    }

    public static function forPaymentTerms(): self {
        return self::AAB;
    }
}