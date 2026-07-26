<?php
/*
 * Created on   : Sat Jul 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : MoneyPrecisionTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Entities;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\{Document, InvoiceLine, Party, PostalAddress, TaxSubtotal, TaxTotal};
use Tests\Contracts\BaseTestCase;

/**
 * Sichert die Präzisionszusagen ab, die den Umstieg auf {@see Money} tragen:
 * Positions-/Steuersummen müssen exakt aufgehen (EN 16931 BR-CO-10/13/14/15),
 * und die XML-Ausgabe darf keine float-Artefakte enthalten.
 */
class MoneyPrecisionTest extends BaseTestCase {
    private function eur(string $amount): Money {
        return Money::of($amount, CurrencyCode::Euro);
    }

    private function document(): Document {
        return Document::create(
            id: 'R-2026-001',
            issueDate: new DateTimeImmutable('2026-07-25'),
            seller: new Party(
                name: 'Muster GmbH',
                postalAddress: PostalAddress::german('Musterstraße 1', '12345', 'Berlin'),
                vatId: 'DE123456789'
            ),
            buyer: new Party(
                name: 'Kunde AG',
                postalAddress: PostalAddress::german('Kundenweg 2', '54321', 'Hamburg')
            )
        );
    }

    public function test_line_totals_stay_exact_for_amounts_that_break_float(): void {
        $document = $this->document();
        // 0,1 + 0,2 ist als float nicht 0,3 — über Money muss die Summe exakt sein.
        $document->addLine(InvoiceLine::create('1', 'Kleinteil A', 1, $this->eur('0.10'), 19.0));
        $document->addLine(InvoiceLine::create('2', 'Kleinteil B', 1, $this->eur('0.20'), 19.0));
        $document->recalculateTotals();

        $this->assertSame('0.30', $document->getNetAmount()->getAmount());
    }

    public function test_tax_is_rounded_commercially_per_rate(): void {
        // 8,15 € * 19 % = 1,5485 -> kaufmännisch 1,55 (float ergibt 1,54)
        $subtotal = TaxSubtotal::standard($this->eur('8.15'), 19.0);

        $this->assertSame('1.55', $subtotal->getTaxAmount()->getAmount());
        $this->assertSame('8.15', $subtotal->getTaxableAmount()->getAmount());
    }

    public function test_tax_total_equals_sum_of_subtotals(): void {
        $subtotals = [
            TaxSubtotal::standard($this->eur('100.01'), 19.0),
            TaxSubtotal::reduced($this->eur('49.99'), 7.0),
        ];
        $taxTotal = TaxTotal::fromSubtotals($subtotals, CurrencyCode::Euro);

        $expected = Money::sum(array_map(fn (TaxSubtotal $s): Money => $s->getTaxAmount(), $subtotals), CurrencyCode::Euro);
        $this->assertTrue($taxTotal->getTaxAmount()->equals($expected), 'BR-CO-14: Steuersumme muss der Summe der Aufschlüsselung entsprechen.');
        $this->assertSame('22.50', $taxTotal->getTaxAmount()->getAmount());
    }

    public function test_document_totals_are_consistent_across_many_lines(): void {
        $document = $this->document();
        // Beträge mit „krummen" Cent-Werten: die Summe der Positionen muss exakt
        // dem Belegnetto entsprechen, Netto + Steuer exakt dem Brutto.
        foreach (['19.99', '0.07', '1234.56', '0.01', '99.95'] as $index => $amount) {
            $document->addLine(InvoiceLine::create((string) ($index + 1), "Position $index", 3, $this->eur($amount), 19.0));
        }
        $document->recalculateTotals();

        $lineSum = Money::sum(
            array_map(fn (InvoiceLine $line): Money => $line->getNetAmount(), $document->getLines()),
            CurrencyCode::Euro
        );

        $this->assertTrue($lineSum->equals($document->getNetAmount()), 'BR-CO-10: Positionssumme muss dem Belegnetto entsprechen.');
        $this->assertTrue(
            $document->getNetAmount()->plus($document->getTaxAmount())->equals($document->getGrossAmount()),
            'BR-CO-15: Netto + Steuer muss exakt dem Bruttobetrag entsprechen.'
        );
    }

    public function test_xml_amounts_carry_the_currency_scale_without_float_artifacts(): void {
        $document = $this->document();
        $document->addLine(InvoiceLine::create('1', 'Dienstleistung', 3, $this->eur('33.33'), 19.0));
        $document->recalculateTotals();

        $xml = $document->toUblXml();

        $this->assertMatchesRegularExpression('/<cbc:LineExtensionAmount[^>]*>99\.99</', $xml);
        $this->assertMatchesRegularExpression('/<cbc:TaxAmount[^>]*>19\.00</', $xml);
        $this->assertDoesNotMatchRegularExpression('/>\d+\.\d{3,}</', $xml, 'Beträge dürfen nie mehr Nachkommastellen als die Währung haben.');
    }

    public function test_zero_decimal_currency_uses_its_own_scale(): void {
        $jpy = CurrencyCode::from('JPY');
        $line = InvoiceLine::create('1', 'Artikel', 3, Money::of('1000', $jpy), 10.0);

        $this->assertSame('3000', $line->getNetAmount()->getAmount());
        $this->assertSame('300', $line->getTaxAmount()->getAmount());
        $this->assertSame($jpy, $line->getCurrency());
    }

    public function test_exempt_lines_produce_zero_tax(): void {
        $subtotal = TaxSubtotal::exempt($this->eur('500.00'), 'Kleinunternehmer §19 UStG');

        $this->assertTrue($subtotal->getTaxAmount()->isZero());
        $this->assertTrue($subtotal->isExempt());
        $this->assertSame(CurrencyCode::Euro, $subtotal->getCurrency());
    }
}
