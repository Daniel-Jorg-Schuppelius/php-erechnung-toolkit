<?php
/*
 * Created on   : Thu Sep 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : XRechnungDocuments.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Contracts;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Entities\{AllowanceCharge, Document, InvoiceLine};
use ERechnungToolkit\Enums\{InvoiceType, PaymentMeansCode, TaxCategory, UnitCode};

/**
 * Testdokumente für die Konformitätsprüfungen beider Syntaxen: eine XRechnung,
 * die jede optionale Partei- und Positionsangabe trägt, deren Elementfolge im
 * CII-Schema festgelegt ist.
 */
trait XRechnungDocuments {
    private string $leitwegId = '04011000-12345-67';

    private function fullXRechnung(bool $creditNote = false): Document {
        $euro = CurrencyCode::Euro;
        $consulting = new InvoiceLine(
            id: '1',
            quantity: 3.0,
            unitCode: UnitCode::HOUR,
            netAmount: Money::of('270.00', $euro),
            itemName: 'Beratung',
            unitPrice: Money::of('100.00', $euro),
            taxCategory: TaxCategory::STANDARD,
            taxPercent: 19.0,
            itemDescription: 'Beratung zur Einführung der E-Rechnung',
            sellersItemId: 'B-100',
            buyersItemId: 'K-9',
            standardItemId: '4006381333931',
            standardItemScheme: '0160',
            note: 'Termin vor Ort',
        );
        $consulting->addAllowanceCharge(AllowanceCharge::discount(
            Money::of('30.00', $euro),
            'Rabatt',
            taxCategory: TaxCategory::STANDARD,
            taxPercent: 19.0,
        ));

        $builder = ERechnungDocumentBuilder::xrechnung($creditNote ? 'GS-2026-0007' : 'XR-2026-0816', $this->leitwegId)
            ->withIssueDate(new DateTimeImmutable('2026-09-17'))
            ->withDueDate(new DateTimeImmutable('2026-10-17'))
            ->withDeliveryDate(new DateTimeImmutable('2026-09-10'))
            ->withOrderReference('PO-4711')
            ->withContractReference('V-2026-01')
            ->withSeller('Verkäufer GmbH', 'DE123456789', '201/123/45678')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withSellerEndpoint('rechnung@verkaeufer.de', 'EM')
            ->withSellerContact('Max Müller', '+49 30 123456', 'kontakt@verkaeufer.de')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Öffentliche Verwaltung', 'DE987654321')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId($this->leitwegId)
            ->withPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER)
            ->withPaymentTermsNet30()
            ->withRemittanceInformation('XR-2026-0816')
            ->addNote('Vielen Dank für Ihren Auftrag.')
            ->addInvoiceLine($consulting)
            ->addLine('Kabel NYM-J 3x1,5', 2, 50.00, 19.0, UnitCode::PIECE, null, null, 'NYM-315')
            ->addDiscount(10.00, 'Treuerabatt');

        if ($creditNote) {
            $builder->withInvoiceType(InvoiceType::CREDIT_NOTE)
                ->withPrecedingInvoiceReference('XR-2026-0815');
        }

        return $builder->build();
    }

    /**
     * Kleinunternehmer (§ 19 UStG) ohne USt-IdNr.: Kategorie E mit
     * Befreiungsgrund, Steuernummer als BT-32 **und** als Verkäuferkennung
     * BT-29. Ohne BT-29 weist KoSIT die Rechnung nach BR-CO-26 ab.
     */
    private function smallBusinessXRechnung(): Document {
        return ERechnungDocumentBuilder::xrechnung('XR-2026-0817', $this->leitwegId)
            ->withIssueDate(new DateTimeImmutable('2026-09-17'))
            ->withDueDate(new DateTimeImmutable('2026-10-01'))
            ->withSeller('Kleinbetrieb Anna Schmidt', '', '201/987/65432')
            ->withSellerIdentifier('201/987/65432')
            ->withSellerAddress('Werkstattweg 3', '50667', 'Köln')
            ->withSellerEndpoint('anna@kleinbetrieb.de', 'EM')
            ->withSellerContact('Anna Schmidt', '+49 221 55555', 'anna@kleinbetrieb.de')
            ->withSellerBankAccount('DE89370400440532013000')
            ->withBuyer('Öffentliche Verwaltung')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId($this->leitwegId)
            ->withPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER)
            ->withPaymentTermsNet30()
            ->withTaxExemptionReason('Kein Ausweis von Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG')
            ->addLine('Reparatur', 1, 180.00, 0.0, UnitCode::LUMP_SUM, TaxCategory::EXEMPT)
            ->build();
    }
}
