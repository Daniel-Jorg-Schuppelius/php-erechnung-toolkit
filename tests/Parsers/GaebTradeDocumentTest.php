<?php
/*
 * Created on   : Thu Oct 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTradeDocumentTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebOrder, GaebOrderItem, GaebParty};
use ERechnungToolkit\Enums\GaebPhase;
use ERechnungToolkit\Generators\{Gaeb90Generator, GaebDaXmlGenerator};
use ERechnungToolkit\Parsers\GaebReader;
use ERechnungToolkit\Validators\GaebSchemaValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contracts\BaseTestCase;

/**
 * Rücklauf aus dem Handel (Fachdokumentation 3.3, Kap. 9): Der Händler
 * antwortet auf die Preisanfrage X93 mit dem Preisangebot X94 und auf die
 * Bestellung X96 mit der Auftragsbestätigung X97. Die Beispieldateien sind
 * selbst erstellt und gegen die Schemata DA94/DA97 3.3 geprüft.
 */
final class GaebTradeDocumentTest extends BaseTestCase {
    private GaebReader $reader;

    protected function setUp(): void {
        parent::setUp();
        $this->reader = new GaebReader;
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array {
        return ['X94' => ['preisangebot.X94'], 'X97' => ['auftragsbestaetigung.X97']];
    }

    #[DataProvider('fixtures')]
    public function test_fixtures_are_schema_valid(string $name): void {
        $this->assertSame([], (new GaebSchemaValidator)->validate($this->fixture($name)));
    }

    public function test_reads_price_offer(): void {
        $offer = $this->reader->readOrder($this->fixture('preisangebot.X94'), 'preisangebot.X94');

        $this->assertSame(GaebPhase::PriceOffer, $offer->getPhase());
        $this->assertSame('AG-26-7731', $offer->getOfferNo());
        // Bezug auf die eigene Preisanfrage (X93)
        $this->assertSame('ANF-2026-0042', $offer->getInquiryNo());
        $this->assertNull($offer->getOrderNo());
        $this->assertSame('2026-10-20', $offer->getDate());
        $this->assertSame('2026-11-09', $offer->getDeliveryDate());
        $this->assertSame('19', $offer->getVatRate());
        $supplier = $offer->getSupplier();
        $this->assertNotNull($supplier);
        $this->assertSame('Baustoffhandel Rhein-Main GmbH & Co. KG', $supplier->getName());
        $this->assertSame('60327', $supplier->getPostalCode());
        $this->assertSame('045 228 30017', $offer->getSupplierTaxNo());
        $this->assertSame('HRA 51123', $offer->getSupplierRegisterNo());
        $this->assertSame('Musterbau GmbH', $offer->getCustomer()?->getName());

        [$concrete, $screws] = $offer->getItems();
        $this->assertSame('BET-C25-30', $concrete->getCatalogArticleNo());
        $this->assertSame('MB-1001', $concrete->getCustomerArticleNo());
        $this->assertSame('Transportbeton C25/30 XC2 F3', $concrete->getDescription());
        $this->assertSame('12.00', $concrete->getQuantity());
        $this->assertSame('m3', $concrete->getUnit());
        $this->assertTrue(Money::of('98.50', CurrencyCode::Euro, 4)->equals($concrete->getPrice() ?? Money::zero(CurrencyCode::Euro, 4)));
        $this->assertSame('28800', $concrete->getWeight());
        $this->assertSame('kg', $concrete->getWeightUnit());

        // Der Preis gilt je 100 Stück, nicht je Stück
        $this->assertSame('14.9000', $screws->getPrice()?->getAmount());
        $this->assertSame('100', $screws->getPriceBasis());
        $this->assertSame('4006381333931', $screws->getEan());
        $this->assertSame('2026-11-02', $screws->getDeliveryDate());
        $this->assertSame('Holzbauschraube 6x60', $screws->getDescription());
    }

    public function test_reads_order_confirmation(): void {
        $confirmation = $this->reader->readOrder($this->fixture('auftragsbestaetigung.X97'));

        $this->assertSame(GaebPhase::OrderConfirmation, $confirmation->getPhase());
        $this->assertSame('AB-26-10544', $confirmation->getOrderConfirmationNo());
        // Bezug auf die eigene Bestellung (X96) und das Angebot davor
        $this->assertSame('B-2026-0815', $confirmation->getOrderNo());
        $this->assertSame('AG-26-7731', $confirmation->getOfferNo());
        $this->assertSame('2026-10-22', $confirmation->getDate());
        // <RegNo/> ohne Eintrag ist zulässig und bleibt leer
        $this->assertNull($confirmation->getSupplierRegisterNo());

        [$concrete, $screws] = $confirmation->getItems();
        // Die Zeilenkennung der Bestellung kommt zurück
        $this->assertSame('A-BET-C25-30', $concrete->getExternalId());
        $this->assertSame('2026-11-09', $concrete->getDeliveryDate());
        // Ohne CatalogArtNo kennzeichnet die Artikelnummer des Lieferanten die Zeile
        $this->assertSame('SCHR-6X60', $screws->getCatalogArticleNo());
        $this->assertSame('W-88216', $screws->getSupplierArticleNo());
        $this->assertSame('400', $screws->getQuantity());
        $this->assertSame('2026-11-16', $screws->getDeliveryDate());
    }

    /** Die eigene Bestellung (X96) übersteht Schreiben und Lesen samt Bestellnummer. */
    public function test_order_round_trip_through_generator(): void {
        $order = new GaebOrder(
            deliveryDate: '2026-11-09',
            items: [
                new GaebOrderItem(
                    catalogArticleNo: 'SCHR-6X60',
                    description: 'Holzbauschraube 6x60',
                    quantity: '500',
                    unit: 'St',
                    price: Money::of('14.90', CurrencyCode::Euro),
                    priceBasis: '100',
                    externalId: 'POS-1',
                ),
            ],
            supplier: new GaebParty('Baustoffhandel Rhein-Main GmbH & Co. KG', 'Hafenstraße 12', '60327', 'Frankfurt am Main'),
            supplierTaxNo: '045 228 30017',
            customer: new GaebParty('Musterbau GmbH', 'Werkstraße 5', '12345', 'Musterstadt'),
            offerNo: 'AG-26-7731',
            orderNo: 'B-2026-0815',
        );

        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::Order, date: '2026-10-21', order: $order);
        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
        $this->assertStringContainsString('<PartNo>B-2026-0815</PartNo>', $xml);

        $read = $this->reader->readOrder($xml);
        $this->assertSame(GaebPhase::Order, $read->getPhase());
        $this->assertSame('B-2026-0815', $read->getOrderNo());
        $this->assertSame('AG-26-7731', $read->getOfferNo());
        $this->assertSame('2026-10-21', $read->getDate());
        $this->assertSame('POS-1', $read->getItems()[0]->getExternalId());
        $this->assertSame('100', $read->getItems()[0]->getPriceBasis());
        $this->assertSame('Holzbauschraube 6x60', $read->getItems()[0]->getDescription());
    }

    public function test_bill_of_quantity_is_not_a_trade_document(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->reader->readOrder($this->fixture('vorbemerkungen.X83'), 'vorbemerkungen.X83');
    }

    public function test_gaeb90_is_not_a_trade_document(): void {
        $d83 = (new Gaeb90Generator)->generate($this->reader->read($this->fixture('vorbemerkungen.X83')), GaebPhase::RequestForBid);

        $this->expectException(InvalidArgumentException::class);
        $this->reader->readOrder($d83);
    }

    /** `read()` liefert für ein Handelsdokument nur Kopf und Phase - die Zeilen liest `readOrder()`. */
    public function test_read_keeps_phase_of_trade_document(): void {
        $boq = $this->reader->read($this->fixture('auftragsbestaetigung.X97'));

        $this->assertSame(GaebPhase::OrderConfirmation, $boq->getPhase());
        $this->assertSame(0, $boq->countItems());
    }

    private function fixture(string $name): string {
        $content = file_get_contents(__DIR__ . '/../resources/gaeb/' . $name);
        $this->assertIsString($content);

        return $content;
    }
}
