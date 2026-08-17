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
use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebChangeOrder, GaebItem, GaebParty, GaebSection};
use ERechnungToolkit\Enums\{GaebAwardCategory, GaebChangeOrderInitiator, GaebChangeOrderPhase, GaebChangeOrderStatus, GaebItemType, GaebMarkupType, GaebPhase};
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

    /**
     * Gelesen wird jede Phase, geschrieben nicht: Zeitvertrag, Rechnung und
     * Handel hängen unter eigenen Wurzelblöcken mit eigener Struktur. Das sagt
     * der Schreiber deutlich, statt eine halb richtige Vergabedatei abzuliefern.
     */
    public function test_framework_phases_refuse_to_be_written(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Phase X83Z/');

        (new GaebDaXmlGenerator)->generate($this->document(), GaebPhase::FrameworkRequestForBid);
    }

    /** Was geschrieben werden kann, ist im Enum festgehalten - nicht im Generator. */
    public function test_enum_states_which_phases_can_be_written(): void {
        $this->assertTrue(GaebPhase::Lv->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::Award->isWritableAsDaXml());
        $this->assertTrue(GaebPhase::QuantitySurvey->isWritableAsDaXml());

        $this->assertFalse(GaebPhase::FrameworkBid->isWritableAsDaXml());
        $this->assertFalse(GaebPhase::Invoice->isWritableAsDaXml());
        $this->assertFalse(GaebPhase::Order->isWritableAsDaXml());
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
