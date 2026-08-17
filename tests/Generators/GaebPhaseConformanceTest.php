<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebPhaseConformanceTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebChangeOrder, GaebCostElement, GaebCosting, GaebFrameworkAgreement, GaebInvoice, GaebInvoiceShare, GaebItem, GaebOrder, GaebOrderItem, GaebParty, GaebSection};
use ERechnungToolkit\Enums\{GaebAwardCategory, GaebChangeOrderInitiator, GaebChangeOrderPhase, GaebChangeOrderStatus, GaebCostingMethod, GaebCostingType, GaebInvoiceShareType, GaebInvoiceType, GaebItemType, GaebMarkupType, GaebPhase};
use ERechnungToolkit\Generators\GaebDaXmlGenerator;
use ERechnungToolkit\Helper\Gaeb\GaebCalculator;
use ERechnungToolkit\Parsers\GaebDaXmlParser;
use ERechnungToolkit\Validators\GaebSchemaValidator;
use Tests\Contracts\BaseTestCase;

/**
 * Jede Austauschphase hat ihr eigenes Schema, und die unterscheiden sich nicht
 * nur in Kleinigkeiten: Was in der einen Pflicht ist, gibt es in der anderen
 * gar nicht. Diese Tests halten fest, was womit geschrieben werden darf.
 */
class GaebPhaseConformanceTest extends BaseTestCase {
    private function party(string $name): GaebParty {
        return new GaebParty(name: $name, street: 'Weg 1', postalCode: '53111', city: 'Bonn');
    }

    private function document(): GaebBoq {
        return new GaebBoq(
            projectName: 'Konformität',
            sections: [new GaebSection(reference: '001', label: 'Erdarbeiten')],
            items: [
                new GaebItem(
                    reference: '001.0010',
                    sectionReference: '001',
                    shortText: 'Aushub',
                    quantity: '10.000',
                    unit: 'm3',
                    unitPrice: Money::of('12.50', CurrencyCode::Euro),
                    totalPrice: Money::of('125.00', CurrencyCode::Euro),
                    changeOrderNo: '1',
                    changeOrderStatus: GaebChangeOrderStatus::Offered,
                ),
                new GaebItem(
                    reference: '001.0020',
                    sectionReference: '001',
                    shortText: 'Baustellengemeinkosten',
                    type: GaebItemType::Markup,
                    markupType: GaebMarkupType::AllInCategory,
                    unitPrice: Money::of('5.00', CurrencyCode::Euro),
                ),
            ],
            changeOrders: [new GaebChangeOrder(
                number: '1',
                phase: GaebChangeOrderPhase::SupplementaryBid,
                status: GaebChangeOrderStatus::Offered,
                initiator: GaebChangeOrderInitiator::Contractor,
                reason: 'Baugrund weicht vom Gutachten ab',
                date: '2026-08-17',
            )],
        );
    }

    /**
     * Der Regressionstest der Formatschicht: Dasselbe Dokument muss in jeder
     * LV-Phase gegen deren eigenes Schema validieren.
     *
     * @return list<array{GaebPhase}>
     */
    public static function phases(): array {
        return [
            [GaebPhase::Universal], [GaebPhase::Lv], [GaebPhase::Estimate],
            [GaebPhase::RequestForBid], [GaebPhase::Bid], [GaebPhase::SideBid],
            [GaebPhase::Award], [GaebPhase::AwardConfirmation],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phases')]
    public function test_every_phase_writes_a_valid_document(GaebPhase $phase): void {
        $xml = (new GaebDaXmlGenerator)->generate(
            $this->document(),
            $phase,
            contractor: $this->party('Bau GmbH'),
            client: $this->party('Stadt Bonn'),
        );

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml), "X{$phase->value} ist nicht schemavalide.");
    }

    /**
     * Vor der Angebotsabgabe ist der Bieter unbekannt - die Schemata von X81
     * bis X83 kennen das Element gar nicht. Der Auftraggeber ist umgekehrt in
     * der Auftragserteilung Pflicht.
     */
    public function test_parties_appear_only_where_the_phase_knows_them(): void {
        $generator = new GaebDaXmlGenerator;
        $args = ['contractor' => $this->party('Bau GmbH'), 'client' => $this->party('Stadt Bonn')];

        $request = $generator->generate($this->document(), GaebPhase::RequestForBid, ...$args);
        $this->assertStringNotContainsString('<CTR>', $request);
        $this->assertStringContainsString('<OWN>', $request);

        $bid = $generator->generate($this->document(), GaebPhase::Bid, ...$args);
        $this->assertStringContainsString('<CTR>', $bid);
        $this->assertStringNotContainsString('<OWN>', $bid);
    }

    /**
     * Nummer und Status des Nachtrags sind im Schema eine Pflichtgruppe. Fehlt
     * der Status, gilt „erkannt" - sonst wäre die Datei unlesbar für die
     * Gegenseite.
     */
    public function test_addendum_number_never_travels_without_a_status(): void {
        $boq = new GaebBoq(
            sections: [new GaebSection(reference: '001', label: 'E')],
            items: [new GaebItem(reference: '001.0010', sectionReference: '001', shortText: 'A', quantity: '1.000', unit: 'St', changeOrderNo: '2')],
        );

        $xml = (new GaebDaXmlGenerator)->generate($boq, GaebPhase::Lv);

        $this->assertStringContainsString('<CONo>2</CONo>', $xml);
        $this->assertStringContainsString('<COStatus>Recog</COStatus>', $xml);
        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
    }

    /** Der Nachtragskopf überlebt den Weg durch die Datei. */
    public function test_addendum_head_survives_the_round_trip(): void {
        $xml = (new GaebDaXmlGenerator)->generate($this->document(), GaebPhase::Award, client: $this->party('Stadt Bonn'));
        $order = (new GaebDaXmlParser)->parse($xml)->getChangeOrder('1');

        $this->assertNotNull($order);
        $this->assertSame(GaebChangeOrderPhase::SupplementaryBid, $order->getPhase());
        $this->assertSame(GaebChangeOrderInitiator::Contractor, $order->getInitiator());
        $this->assertSame('Baugrund weicht vom Gutachten ab', $order->getReason());
        $this->assertSame('2026-08-17', $order->getDate());
    }

    /**
     * Die Zuschlagsposition trägt einen Satz, keinen Einheitspreis - und davor
     * die Bemessungsgrundlage, ohne die der Satz nichts aussagt: 5 % auf die
     * 125,00 € der Gruppe sind 6,25 €.
     */
    public function test_markup_is_computed_on_the_items_it_applies_to(): void {
        $boq = $this->document();
        $markup = $boq->getItems()[1];
        $calculator = new GaebCalculator;

        $this->assertSame('125,00 €', $calculator->markupBase($boq, $markup)->format());
        $this->assertSame('6,25 €', $calculator->markupAmount($boq, $markup)->format());

        $xml = (new GaebDaXmlGenerator)->generate($boq, GaebPhase::Award, client: $this->party('Stadt Bonn'));
        $this->assertStringContainsString('<ITMarkup>125</ITMarkup>', $xml);
        $this->assertStringContainsString('<Markup>5</Markup>', $xml);
        $this->assertStringContainsString('<IT>6.25</IT>', $xml);
    }

    /**
     * Die Vergabeart trennt Verfahren, die gleich klingen: „mit" und „ohne
     * Teilnahmewettbewerb" sind eigene Werte, weil sie Fristen und Bieterkreis
     * ändern. Sie zusammenzulegen wäre ein Rechtsfehler.
     */
    public function test_award_categories_keep_the_call_for_participation_apart(): void {
        $this->assertFalse(GaebAwardCategory::RestrictedInvitation->hasCallForParticipation());
        $this->assertTrue(GaebAwardCategory::RestrictedInvitationWithCall->hasCallForParticipation());
        $this->assertFalse(GaebAwardCategory::NegotiatedProcedure->hasCallForParticipation());
        $this->assertTrue(GaebAwardCategory::NegotiatedProcedureWithCall->hasCallForParticipation());

        // Die Innovationspartnerschaft kam erst mit 3.3.
        $this->assertFalse(GaebAwardCategory::InnovationPartnership->existsIn('3.2'));
        $this->assertTrue(GaebAwardCategory::InnovationPartnership->existsIn('3.3'));
        $this->assertTrue(GaebAwardCategory::OpenProcedure->existsIn('3.2'));

        // Ein Zeitvertrag wird nicht im offenen Verfahren vergeben.
        $this->assertFalse(GaebAwardCategory::OpenProcedure->allowedInFrameworkAgreement());
        $this->assertTrue(GaebAwardCategory::PublicInvitation->allowedInFrameworkAgreement());
    }

    /** Seit dem Zeitvertrag lässt sich jede Phase schreiben, nicht nur lesen. */
    public function test_enum_states_which_phases_can_be_written(): void {
        $this->assertTrue(GaebPhase::Lv->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::Award->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::QuantitySurvey->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::Invoice->isWritableAsDaXml());

        $this->assertTrue(GaebPhase::Order->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::FrameworkBid->isWritableAsDaXml());
    }

    private function order(): GaebOrder {
        return new GaebOrder(
            deliveryDate: '2026-09-01',
            items: [
                new GaebOrderItem(
                    catalogArticleNo: 'BET-C25-30',
                    description: 'Transportbeton C25/30',
                    quantity: '12.000',
                    unit: 'm3',
                    price: Money::of('98.50', CurrencyCode::Euro),
                    weight: '28800',
                    weightUnit: 'kg',
                    vatRate: '19',
                ),
            ],
            supplier: $this->party('Baustoff Meier'),
            supplierTaxNo: '205/5711/0041',
            supplierRegisterNo: 'HRB 4711',
            customer: $this->party('Bau GmbH'),
            inquiryNo: 'ANF-2026-88',
            vatRate: '19',
        );
    }

    /**
     * Der Handel läuft neben der Vergabe: Er kauft Artikel statt Positionen und
     * hängt deshalb unter `Order` statt unter `Award`.
     *
     * @return list<array{GaebPhase}>
     */
    public static function tradePhases(): array {
        return [[GaebPhase::PriceInquiry], [GaebPhase::PriceOffer], [GaebPhase::Order], [GaebPhase::OrderConfirmation]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tradePhases')]
    public function test_trade_phases_write_valid_documents(GaebPhase $phase): void {
        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, $phase, order: $this->order());

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml), "X{$phase->value} ist nicht schemavalide.");
        $this->assertStringContainsString('<Order>', $xml);
        $this->assertStringNotContainsString('<Award>', $xml);
        $this->assertStringContainsString('<CatalogArtNo>BET-C25-30</CatalogArtNo>', $xml);
        // Handelsrecht: Steuer- und Handelsregisternummer des Lieferanten.
        $this->assertStringContainsString('<TaxNo>205/5711/0041</TaxNo>', $xml);
        $this->assertStringContainsString('<RegNo>HRB 4711</RegNo>', $xml);
    }

    /**
     * Ein Einzelunternehmer hat keine Handelsregisternummer - er ist nicht
     * eingetragen. Das Schema verlangt das **Element**, nicht seinen Inhalt:
     * `<RegNo/>` leer ist korrekt, das Weglassen wäre der Fehler.
     */
    public function test_supplier_without_register_entry_stays_valid(): void {
        $order = new GaebOrder(
            deliveryDate: '2026-09-01',
            items: [new GaebOrderItem(catalogArticleNo: 'BET', quantity: '1.000', unit: 'm3')],
            supplier: $this->party('Maurermeister Krause'),
            supplierTaxNo: '205/5711/0041',
            // kein Handelsregistereintrag
            customer: $this->party('Bau GmbH'),
        );

        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::Order, order: $order);

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
        $this->assertStringContainsString('<RegNo/>', $xml);
        // Der Kunde nennt nur seine Anschrift, keine Steuernummer.
        $this->assertMatchesRegularExpression('#<CustomerInfo>\s*<Address>.*?</Address>\s*</CustomerInfo>#s', $xml);
    }

    private function framework(): GaebFrameworkAgreement {
        return new GaebFrameworkAgreement(
            label: 'Winterdienst 2026',
            description: 'Räumen und Streuen der Verkehrsflächen',
            begin: '2026-01-01',
            end: '2026-12-31',
            number: 'RV-7',
        );
    }

    private function frameworkDocument(): GaebBoq {
        return new GaebBoq(
            projectName: 'Winterdienst',
            sections: [new GaebSection(reference: '001', label: 'Räumen')],
            items: [new GaebItem(
                reference: '001.0010',
                sectionReference: '001',
                shortText: 'Räumen je m²',
                longText: 'Räumen und Streuen der Verkehrsflächen nach Bedarf.',
                quantity: '25.000',
                unit: 'm2',
                unitPrice: Money::of('7.80', CurrencyCode::Euro),
                bidUpDownRequired: true,
                bidUpDownPercent: '-5.00',
            )],
        );
    }

    /** @return list<array{GaebPhase}> */
    public static function frameworkPhases(): array {
        return [
            [GaebPhase::FrameworkRequestForBid], [GaebPhase::FrameworkBid],
            [GaebPhase::FrameworkAgreement], [GaebPhase::FrameworkCallOff],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworkPhases')]
    public function test_framework_phases_write_valid_documents(GaebPhase $phase): void {
        $xml = (new GaebDaXmlGenerator)->generate(
            $this->frameworkDocument(),
            $phase,
            contractor: $this->party('Bau GmbH'),
            client: $this->party('Stadt Bonn'),
            frameworkAgreement: $this->framework(),
            site: $this->party('Baustelle Nordstraße'),
        );

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml), "X{$phase->value} ist nicht schemavalide.");
    }

    /**
     * Der Zeitvertrag dreht die gewohnte Reihenfolge um: Die Aufforderung nennt
     * bereits die Listenpreise und fragt ein Auf-/Abgebot ab, der Bieter
     * antwortet allein mit dem Prozentsatz.
     */
    public function test_framework_bid_answers_with_a_percentage_only(): void {
        $generator = new GaebDaXmlGenerator;
        $args = [
            'contractor' => $this->party('Bau GmbH'),
            'client' => $this->party('Stadt Bonn'),
            'frameworkAgreement' => $this->framework(),
            'site' => $this->party('Baustelle Nordstraße'),
        ];

        $request = $generator->generate($this->frameworkDocument(), GaebPhase::FrameworkRequestForBid, ...$args);
        $this->assertStringContainsString('<UP>7.8</UP>', $request);
        $this->assertStringContainsString('<BidUpDownReq>Yes</BidUpDownReq>', $request);

        $bid = $generator->generate($this->frameworkDocument(), GaebPhase::FrameworkBid, ...$args);
        $this->assertStringContainsString('<BidUpDownPct>-5</BidUpDownPct>', $bid);
        $this->assertStringNotContainsString('<UP>', $bid);
    }

    /**
     * Die Menge steht erst im Einzelabruf fest; bis dahin werden Leistungen
     * ohne Menge bepreist. Der Abruf beruft sich dafür auf die Position im
     * Rahmenvertrag, statt ihren Text zu wiederholen.
     */
    public function test_quantity_appears_only_in_the_single_call_off(): void {
        $generator = new GaebDaXmlGenerator;
        $args = [
            'client' => $this->party('Stadt Bonn'),
            'frameworkAgreement' => $this->framework(),
            'site' => $this->party('Baustelle Nordstraße'),
        ];

        $agreement = $generator->generate($this->frameworkDocument(), GaebPhase::FrameworkAgreement, ...$args);
        $this->assertStringNotContainsString('<Qty>', $agreement);

        $callOff = $generator->generate($this->frameworkDocument(), GaebPhase::FrameworkCallOff, ...$args);
        $this->assertStringContainsString('<Qty>25</Qty>', $callOff);
        $this->assertStringContainsString('<WICNo>001.0010</WICNo>', $callOff);
        $this->assertStringNotContainsString('<CompleteText>', $callOff);
    }

    private function costing(bool $fullNumbers = true): GaebCosting {
        return new GaebCosting(
            name: 'KB-2026',
            elements: [new GaebCostElement(
                description: 'Baukonstruktion',
                unit: 'm2',
                number: '300',
                quantity: '1200.000',
                unitPrice: Money::of('1450.00', CurrencyCode::Euro),
                unitPriceFrom: Money::of('1300.00', CurrencyCode::Euro),
                unitPriceAverage: Money::of('1450.00', CurrencyCode::Euro),
                unitPriceTo: Money::of('1600.00', CurrencyCode::Euro),
                children: [new GaebCostElement(description: 'Gründung', unit: 'm2', number: '320')],
            )],
            label: 'Kostenberechnung Neubau',
            type: GaebCostingType::Calculation,
            method: GaebCostingMethod::ByElements,
            date: '2026-08-17',
            fullElementNumbers: $fullNumbers,
        );
    }

    /**
     * Die Kostenermittlung beschreibt nicht, was zu tun ist, sondern was es
     * kosten soll - gegliedert nach Kostengruppen statt nach Gewerken, und
     * verschachtelt.
     */
    public function test_costing_is_written_under_its_own_root(): void {
        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $this->costing());

        $this->assertStringContainsString('<ElementalCosting>', $xml);
        $this->assertStringNotContainsString('<Award>', $xml);
        $this->assertStringContainsString('<ECType>cost determination</ECType>', $xml);
        $this->assertStringContainsString('<EleNo>300</EleNo>', $xml);
        // Das Unterelement steckt im übergeordneten, nicht daneben.
        $this->assertMatchesRegularExpression('#<CostElement>.*<CostElement>.*<EleNo>320</EleNo>#s', $xml);
    }

    /**
     * Ein früher Kennwert ist eine Spanne, keine Zahl - und das Format hält das
     * aus. Die Ehrlichkeit gehört mit übertragen.
     */
    public function test_costing_carries_price_ranges(): void {
        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $this->costing());

        $this->assertStringContainsString('<UPFrom>1300</UPFrom>', $xml);
        $this->assertStringContainsString('<UPAvg>1450</UPAvg>', $xml);
        $this->assertStringContainsString('<UPTo>1600</UPTo>', $xml);
        $this->assertTrue($this->costing()->getElements()[0]->hasPriceRange());
    }

    /**
     * Zwei Bauformen: `.2` schreibt den Elementbezeichner voll aus (`314` — die
     * übliche Form für DIN 276), `.1` nur den Teil der eigenen Ebene.
     */
    public function test_costing_shapes_differ_in_the_element_number(): void {
        $generator = new GaebDaXmlGenerator;

        $full = $generator->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $this->costing(true));
        $this->assertStringContainsString('<EleNo>300</EleNo>', $full);
        $this->assertStringNotContainsString('<ElePart>', $full);

        $partial = $generator->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $this->costing(false));
        $this->assertStringContainsString('<ElePart>300</ElePart>', $partial);
        $this->assertStringNotContainsString('<EleNo>', $partial);
    }

    /**
     * Die Kostenschemata stapeln zwei `xs:redefine` übereinander, was libxml
     * nicht auflöst. Das sagt der Validator deutlich, statt einen Folgefehler
     * zu melden, der wie ein Dokumentfehler aussieht.
     */
    public function test_costing_schema_limitation_is_named(): void {
        $xml = (new GaebDaXmlGenerator)->generate(new GaebBoq, GaebPhase::CostEstimate, costing: $this->costing());

        $errors = (new GaebSchemaValidator)->validate($xml);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('libxml', $errors[0]);
        $this->assertStringContainsString('xs:redefine', $errors[0]);
    }

    /** Die Preisanfrage fragt ohne Preis; erst das Angebot nennt einen. */
    public function test_price_inquiry_asks_without_prices(): void {
        $generator = new GaebDaXmlGenerator;

        $inquiry = $generator->generate(new GaebBoq, GaebPhase::PriceInquiry, order: $this->order());
        $this->assertStringNotContainsString('<NetPrice>', $inquiry);

        $offer = $generator->generate(new GaebBoq, GaebPhase::PriceOffer, order: $this->order());
        $this->assertStringContainsString('<NetPrice>98.5</NetPrice>', $offer);
    }

    private function invoice(): GaebInvoice {
        return new GaebInvoice(
            number: '2026-0042',
            date: '2026-08-17',
            type: GaebInvoiceType::Deduction,
            serviceStart: '2026-07-01',
            serviceEnd: '2026-07-31',
            creator: $this->party('Bau GmbH'),
            creatorTaxNumber: '205/5711/0041',
            recipient: $this->party('Stadt Bonn'),
            shares: [
                new GaebInvoiceShare(GaebInvoiceShareType::BasicAmount, 'Leistung Juli', Money::of('125.00', CurrencyCode::Euro)),
                new GaebInvoiceShare(GaebInvoiceShareType::SecurityForFulfilment, 'Sicherheit 5 %', percent: '5'),
                new GaebInvoiceShare(GaebInvoiceShareType::Vat, '19 % USt', Money::of('23.75', CurrencyCode::Euro)),
            ],
            totalGross: Money::of('148.75', CurrencyCode::Euro),
        );
    }

    /** Die Rechnung hängt unter `Invoice`, nicht unter `Award`. */
    public function test_invoice_is_written_under_its_own_root(): void {
        $xml = (new GaebDaXmlGenerator)->generate(
            $this->document(),
            GaebPhase::Invoice,
            contractor: $this->party('Bau GmbH'),
            client: $this->party('Stadt Bonn'),
            invoice: $this->invoice(),
        );

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
        $this->assertStringContainsString('<Invoice>', $xml);
        $this->assertStringNotContainsString('<Award>', $xml);
        $this->assertStringContainsString('<InvoiceNo>2026-0042</InvoiceNo>', $xml);
        $this->assertStringContainsString('<InvoiceType>deduction</InvoiceType>', $xml);
        $this->assertStringContainsString('<TaxNo>205/5711/0041</TaxNo>', $xml);
        // Die Rechnung nennt die abgerechnete Menge, nicht die beauftragte.
        $this->assertStringContainsString('<BillQty>10</BillQty>', $xml);
        $this->assertStringNotContainsString('<Qty>', $xml);
    }

    /**
     * Betrag und Prozentsatz stehen im Schema zur Wahl: Ein Rechnungsanteil ist
     * auf genau eine Art definiert.
     */
    public function test_invoice_share_carries_either_amount_or_percentage(): void {
        $xml = (new GaebDaXmlGenerator)->generate(
            $this->document(),
            GaebPhase::Invoice,
            client: $this->party('Stadt Bonn'),
            invoice: $this->invoice(),
        );

        // Der prozentuale Anteil trägt keinen Betrag daneben.
        $this->assertMatchesRegularExpression(
            '#<InvoiceShare>\s*<InvoiceShareType>security deposit for fulfillment of a contract</InvoiceShareType>\s*<Description>[^<]*</Description>\s*<Percent>5</Percent>\s*</InvoiceShare>#',
            $xml
        );
        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
    }

    /**
     * Die rechnungsbegründende Unterlage ist keine Rechnung im Sinne des
     * Handelsrechts - sie verweist auf die Nummer der Rechnung, zu der sie
     * gehört, statt eine eigene zu führen.
     */
    public function test_invoice_attachment_refers_to_its_invoice(): void {
        $xml = (new GaebDaXmlGenerator)->generate(
            $this->document(),
            GaebPhase::InvoiceAttachment,
            client: $this->party('Stadt Bonn'),
            invoice: $this->invoice(),
        );

        $this->assertSame([], (new GaebSchemaValidator)->validate($xml));
        $this->assertStringContainsString('<RefInvoiceNo>2026-0042</RefInvoiceNo>', $xml);
        $this->assertStringNotContainsString('<InvoiceNo>', $xml);
    }

    /** Abzüge tragen ihr Vorzeichen im Typ, nicht in der Zahl. */
    public function test_share_types_know_whether_they_lower_the_sum(): void {
        $this->assertFalse(GaebInvoiceShareType::BasicAmount->reducesAmount());
        $this->assertTrue(GaebInvoiceShareType::SecurityForDefects->reducesAmount());
        $this->assertTrue(GaebInvoiceShareType::PaymentsReceived->reducesAmount());
        $this->assertTrue(GaebInvoiceShareType::Subtotal->isIntermediate());

        // Eine Gegenforderung kehrt das Vorzeichen um.
        $claim = new GaebInvoiceShare(GaebInvoiceShareType::BasicAmount, 'Gegenforderung', counterClaim: true);
        $this->assertTrue($claim->lowersTotal());

        // Eine Pro-forma-Rechnung fordert kein Geld.
        $this->assertFalse(GaebInvoiceType::ProForma->demandsPayment());
        $this->assertTrue(GaebInvoiceType::FinalAccount->closesContract());
    }

    /** Ein Zuschlag auf einen Zuschlag würde sich aufschaukeln - er zählt nicht mit. */
    public function test_markup_does_not_apply_to_other_markups(): void {
        $boq = new GaebBoq(
            sections: [new GaebSection(reference: '001', label: 'E')],
            items: [
                new GaebItem(reference: '001.0010', sectionReference: '001', quantity: '1.000', unit: 'St', unitPrice: Money::of('100.00', CurrencyCode::Euro)),
                new GaebItem(reference: '001.0020', sectionReference: '001', type: GaebItemType::Markup, markupType: GaebMarkupType::AllInCategory, unitPrice: Money::of('10.00', CurrencyCode::Euro)),
                new GaebItem(reference: '001.0030', sectionReference: '001', type: GaebItemType::Markup, markupType: GaebMarkupType::AllInCategory, unitPrice: Money::of('10.00', CurrencyCode::Euro)),
            ],
        );

        // Beide Zuschläge rechnen auf 100,00 €, nicht der zweite auf 110,00 €.
        $this->assertSame('10,00 €', (new GaebCalculator)->markupAmount($boq, $boq->getItems()[2])->format());
    }
}
