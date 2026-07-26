<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UglGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Builders\OrderBuilder;
use ERechnungToolkit\Entities\{AllowanceCharge, Order, OrderLine};
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Generators\UglGenerator;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the UGL 5.0 order generator (GC-Gruppe / SHK trade, fixed-width).
 */
class UglGeneratorTest extends BaseTestCase {
    private UglGenerator $generator;
    private Order $order;

    protected function setUp(): void {
        parent::setUp();

        $this->generator = new UglGenerator;
        $this->order = OrderBuilder::xbestellung('ORD-2026-001')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Mueller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->withRequestedDeliveryPeriod(new DateTimeImmutable('2026-07-01'))
            ->addLine('Heizungspumpe', 5, 120.00, UnitCode::PIECE, 'ART-4711')
            ->addLine('Kugelhahn', 2, 12.50, UnitCode::PIECE, 'ART-4712')
            ->build();
    }

    /** @return list<string> raw (single-byte) records without the CR/LF terminator */
    private function records(string $ugl): array {
        return array_values(array_filter(preg_split('/\r\n/', $ugl) ?: [], fn (string $r) => $r !== ''));
    }

    private function field(string $record, int $from, int $to): string {
        $raw = substr($record, $from - 1, $to - $from + 1);

        return rtrim((string) iconv('ISO-8859-1', 'UTF-8', $raw));
    }

    public function test_record_structure_and_fixed_length(): void {
        $records = $this->records($this->generator->generateOrder($this->order));

        // KOP + 2 POA + END
        $this->assertCount(4, $records);
        $this->assertSame('KOP', substr($records[0], 0, 3));
        $this->assertSame('POA', substr($records[1], 0, 3));
        $this->assertSame('POA', substr($records[2], 0, 3));
        $this->assertSame('END', substr($records[3], 0, 3));

        // Jeder Satz ist exakt 350 Bytes.
        foreach ($records as $record) {
            $this->assertSame(350, strlen($record), 'Each UGL record must be 350 bytes');
        }
    }

    public function test_kop_header_fields(): void {
        $kop = $this->records($this->generator->generateOrder($this->order))[0];

        $this->assertSame('BE', $this->field($kop, 24, 25));           // Anfrageart = Bestellung
        $this->assertSame('ORD-2026-001', $this->field($kop, 26, 40)); // Kundenauftragsnummer HW
        $this->assertSame('20260701', $this->field($kop, 106, 113));   // gewünschtes Lieferdatum
        $this->assertSame('EUR', $this->field($kop, 114, 116));        // Währung
        $this->assertSame('05.00', $this->field($kop, 117, 121));      // Version
        $this->assertSame('20260625', $this->field($kop, 162, 169));   // Dokumentendatum
    }

    public function test_poa_position_fields_and_numeric_encoding(): void {
        $poa = $this->records($this->generator->generateOrder($this->order))[1];

        $this->assertSame('0000000001', substr($poa, 3, 10));   // Positionsnummer HW (10,0)
        $this->assertSame('ART-4711', $this->field($poa, 24, 38)); // Artikelnummer
        $this->assertSame('00000005000', substr($poa, 38, 11)); // Menge 5 (11,3)
        $this->assertSame('Heizungspumpe', $this->field($poa, 50, 89));
        $this->assertSame('00000012000', substr($poa, 129, 11)); // Brutto je PE 120,00 (11,2)
        $this->assertSame('00000060000', substr($poa, 141, 11)); // Netto-Positionswert 600,00 (11,2)
        $this->assertSame('ST', $this->field($poa, 184, 186));   // Mengeneinheit
    }

    public function test_emits_adr_record_for_deviating_delivery_address(): void {
        $order = OrderBuilder::xbestellung('ORD-ADR-1')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Mueller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->withDeliveryAddress('Baustelle 7', '20095', 'Hamburg', 'DE', 'Bauvorhaben Hafencity', null, 'Anlieferung Tor 3')
            ->addLine('Heizungspumpe', 1, 120.00, UnitCode::PIECE, 'ART-4711')
            ->build();

        $records = $this->records($this->generator->generateOrder($order));

        // Reihenfolge: KOP, ADR, POA, END
        $this->assertSame('KOP', substr($records[0], 0, 3));
        $this->assertSame('ADR', substr($records[1], 0, 3));
        $this->assertSame('POA', substr($records[2], 0, 3));
        $this->assertSame('END', substr($records[3], 0, 3));

        $adr = $records[1];
        $this->assertSame(350, strlen($adr));
        $this->assertSame('Bauvorhaben Hafencity', $this->field($adr, 4, 33));   // Name 1
        $this->assertSame('Baustelle 7', $this->field($adr, 94, 123));           // Straße
        $this->assertSame('20095', $this->field($adr, 127, 132));               // PLZ
        $this->assertSame('Hamburg', $this->field($adr, 133, 162));             // Ort
        $this->assertSame('Anlieferung Tor 3', $this->field($adr, 295, 344));    // Lieferhinweis
        $this->assertSame('', $this->field($adr, 124, 126));                    // Land leer = DE
    }

    public function test_emits_pot_text_and_poz_surcharge_records(): void {
        $order = OrderBuilder::xbestellung('ORD-PZT')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Installateur Mueller')
            ->withBuyerAddress('Rohrweg 1', '12345', 'Musterstadt')
            ->withSeller('GC Grosshandel', 'DE123456789')
            ->withSellerAddress('Lagerstr 2', '54321', 'Lieferstadt')
            ->addOrderLine(new OrderLine(
                id: '1', quantity: 2, unitCode: UnitCode::PIECE, netAmount: Money::of('240.00', CurrencyCode::Euro),
                itemName: 'Pumpe', unitPrice: Money::of('120.00', CurrencyCode::Euro), sellersItemId: 'ART-1',
                note: 'Bitte vormontiert liefern'
            ))
            ->build();
        $order->addAllowanceCharge(AllowanceCharge::shipping(Money::of('20.00', CurrencyCode::Euro)));                  // FREIGHT → Typ 07
        $order->addAllowanceCharge(AllowanceCharge::surcharge(Money::of('5.00', CurrencyCode::Euro), 'Mindermengenzuschlag')); // → Typ 99

        $records = $this->records($this->generator->generateOrder($order));

        // KOP, POA, POT, POZ(07), POZ(99), END
        $this->assertSame('POA', substr($records[1], 0, 3));
        $this->assertSame('POT', substr($records[2], 0, 3));
        $this->assertSame('POZ', substr($records[3], 0, 3));
        $this->assertSame('POZ', substr($records[4], 0, 3));
        $this->assertSame('END', substr($records[5], 0, 3));

        // POT: Positionsbezug + Infotext + Textanfang-Kennzeichen.
        $pot = $records[2];
        $this->assertSame('0000000001', substr($pot, 3, 10));
        $this->assertSame('Bitte vormontiert liefern', $this->field($pot, 24, 63));
        $this->assertSame('T', $this->field($pot, 162, 162));

        // POZ Fracht: Typ 07, Wert 20,00.
        $this->assertSame('07', $this->field($records[3], 24, 25));
        $this->assertSame('00000002000', substr($records[3], 116, 11));

        // POZ custom: Typ 99 + Bezeichnung, Wert 5,00.
        $this->assertSame('99', $this->field($records[4], 24, 25));
        $this->assertSame('Mindermengenzuschlag', $this->field($records[4], 26, 105));
        $this->assertSame('00000000500', substr($records[4], 116, 11));
    }

    public function test_no_adr_record_without_delivery_address(): void {
        $records = $this->records($this->generator->generateOrder($this->order));

        foreach ($records as $record) {
            $this->assertNotSame('ADR', substr($record, 0, 3));
        }
    }

    public function test_umlauts_keep_fixed_byte_length_and_field_alignment(): void {
        $order = OrderBuilder::xbestellung('ORD-Ü')
            ->withIssueDate(new DateTimeImmutable('2026-06-25'))
            ->withBuyer('Müller & Söhne')
            ->withBuyerAddress('Straße 1', '12345', 'Köln')
            ->withSeller('Großhandel', 'DE1')
            ->withSellerAddress('Weg 2', '54321', 'Ort')
            ->addLine('Übergangsstück', 1, 9.90, UnitCode::PIECE, 'ART-Ä')
            ->build();

        $records = $this->records($this->generator->generateOrder($order));

        foreach ($records as $record) {
            $this->assertSame(350, strlen($record), 'Umlauts must not break the 350-byte width');
        }

        // Felder NACH dem Umlaut-Artikelnamen (50-89) müssen byte-genau ausgerichtet bleiben.
        $poa = $records[1];
        $this->assertSame('Übergangsstück', $this->field($poa, 50, 89));
        $this->assertSame('00000000990', substr($poa, 129, 11)); // Brutto je PE 9,90
        $this->assertSame('00000000990', substr($poa, 141, 11)); // Netto-Positionswert 9,90
        $this->assertSame('ST', $this->field($poa, 184, 186));    // Mengeneinheit
    }
}
