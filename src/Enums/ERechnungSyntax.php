<?php
/*
 * Created on   : Sat Nov 07 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungSyntax.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Syntax einer XML-Rechnung, erkannt am Wurzelelement. ZUGFeRD 1.0
 * (CrossIndustryDocument) gehört nicht dazu: es wird nur gelesen, nie ergänzt.
 */
enum ERechnungSyntax: string {
    case UBL_INVOICE = 'ubl-invoice';
    case UBL_CREDIT_NOTE = 'ubl-creditnote';
    case CII = 'cii';

    public static function fromRoot(?string $namespace, ?string $localName): ?self {
        foreach (self::cases() as $case) {
            if ($case->namespace() === $namespace && $case->rootName() === $localName) {
                return $case;
            }
        }

        return null;
    }

    public function namespace(): string {
        return match ($this) {
            self::UBL_INVOICE => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            self::UBL_CREDIT_NOTE => 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
            self::CII => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
        };
    }

    public function rootName(): string {
        return match ($this) {
            self::UBL_INVOICE => 'Invoice',
            self::UBL_CREDIT_NOTE => 'CreditNote',
            self::CII => 'CrossIndustryInvoice',
        };
    }

    public function isUbl(): bool {
        return $this !== self::CII;
    }
}
