<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTakeoffCalculatorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Helper;

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebTakeoffLine};
use ERechnungToolkit\Helper\Gaeb\{GaebExpression, GaebTakeoffCalculator};
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the REB arithmetic. Every expected figure is computed by hand:
 * values carry three decimals, angles are gon.
 */
class GaebTakeoffCalculatorTest extends BaseTestCase {
    private GaebTakeoffCalculator $calculator;

    protected function setUp(): void {
        parent::setUp();
        $this->calculator = new GaebTakeoffCalculator;
    }

    /** @param list<string> $values */
    private function line(string $formula, array $values, string $kind = ' ', ?string $factor = null): GaebTakeoffLine {
        return new GaebTakeoffLine(kind: $kind, factor: $factor, formula: $formula, values: $values, address: '0001B0');
    }

    /** Rechteck: 3,250 m × 1,250 m = 4,0625 m². */
    public function test_rectangle_and_volume(): void {
        $this->assertEqualsWithDelta(4.0625, (float) $this->calculator->line($this->line('04', ['3250', '1250'])), 0.0001);
        $this->assertEqualsWithDelta(8.125, (float) $this->calculator->line($this->line('04', ['3250', '1250', '2000'])), 0.0001);
    }

    /** Dreieck aus Grundseite und Höhe: 12,330 × 4,560 / 2. */
    public function test_triangle_from_base_and_height(): void {
        $this->assertEqualsWithDelta(28.1124, (float) $this->calculator->line($this->line('01', ['12330', '4560'])), 0.0001);
    }

    /** Trapez: (2,330 + 2,850) / 2 × 1,250. */
    public function test_trapezium(): void {
        $this->assertEqualsWithDelta(3.2375, (float) $this->calculator->line($this->line('05', ['2330', '2850', '1250'])), 0.0001);
    }

    /** Prisma mit drei Höhen: 4 × 3 × (2+2+2) / 6. */
    public function test_prism_with_three_heights(): void {
        $line = $this->line('13', ['4000', '3000', '2000', '2000', '2000']);
        $this->assertEqualsWithDelta(12.0, (float) $this->calculator->line($line), 0.0001);
    }

    /**
     * Pyramidenstümpfe teilen den Zähler (2AB + 2ab + Ab + aB) · H und
     * unterscheiden sich nur im Nenner: 12 beim Dreieck, 6 beim Rechteck.
     * (2·12 + 2·2 + 4 + 6) · 6 = 228.
     */
    public function test_truncated_pyramids(): void {
        $values = ['4000', '3000', '6000', '2000', '1000'];
        $this->assertEqualsWithDelta(19.0, (float) $this->calculator->line($this->line('14', $values)), 0.0001);
        $this->assertEqualsWithDelta(38.0, (float) $this->calculator->line($this->line('15', $values)), 0.0001);
    }

    /** Deckfläche 0 macht den Stumpf zur vollen Pyramide: 12 × 6 / 3 = 24. */
    public function test_truncated_pyramid_without_top_is_a_full_pyramid(): void {
        $line = $this->line('15', ['4000', '3000', '6000', '0', '0']);
        $this->assertEqualsWithDelta(24.0, (float) $this->calculator->line($line), 0.0001);
    }

    /**
     * Koordinatenformeln laufen über mehrere Sätze bis zum abschließenden `=`;
     * die offene Kette (0|0) → (10|0) → (10|10) → (0|10) misst 30 m.
     */
    public function test_polygon_length_spans_several_lines(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '21', values: ['0', '0', '10000', '0']),
            new GaebTakeoffLine(formula: '21', values: ['10000', '10000', '0', '10000'], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(30.0, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /** Dieselben vier Punkte als geschlossenes Vieleck: 10 × 10 = 100 m². */
    public function test_gauss_area_over_coordinates(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '22', values: ['0', '0', '10000', '0']),
            new GaebTakeoffLine(formula: '22', values: ['10000', '10000', '0', '10000'], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(100.0, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /** Ein einzelner Wert am Ende ist die Dicke und macht die Fläche zum Körper. */
    public function test_gauss_area_with_thickness_becomes_a_volume(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '22', values: ['0', '0', '10000', '0']),
            new GaebTakeoffLine(formula: '22', values: ['10000', '10000', '0', '10000', '500'], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(50.0, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /**
     * Formel 25 nach REB: je Station ein Trapez `F = (a+b)/2 · h`, zwischen den
     * Stationen die Trapezregel. Werte der BVBS-Prüfdatei:
     * F = 5,0625 / 4,2435 / 5,109 bei 750, 760 und 770 m
     * → 10·(5,0625+4,2435)/2 + 10·(4,2435+5,109)/2 = 93,2925.
     */
    public function test_stationed_trapezoidal_profiles(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '25', values: ['750000', '4500', '1250', '1000']),
            new GaebTakeoffLine(formula: '25', values: ['760000', '4100', '1120', '0950']),
            new GaebTakeoffLine(formula: '25', values: ['770000', '3900', '1400', '1220'], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(93.2925, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /** Formel 23 nimmt die Flächen fertig entgegen: 25 m × (100 + 60) / 2. */
    public function test_cross_section_profiles(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '23', values: ['12000', '100000']),
            new GaebTakeoffLine(formula: '23', values: ['37000', '60000'], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(2000.0, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /**
     * Der Rechenansatz trägt sein Vorzeichen **vor** dem Wert; weil die Felder
     * rechtsbündig stehen, liegen Leerzeichen dazwischen. Das Vorzeichen des
     * ersten Wertes einer Folgezeile steht hinter dem letzten Feld der Zeile
     * davor und kommt als eigener Eintrag an.
     */
    public function test_plain_sum_carries_its_signs_across_lines(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '00', values: ['22700', '+ 24300', '- 31400', '+']),
            new GaebTakeoffLine(formula: '00', values: ['29900', '- 24700'], closesResult: true),
        ]);

        // 22,7 + 24,3 - 31,4 + 29,9 - 24,7 = 20,8
        $this->assertEqualsWithDelta(20.8, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /** Die freie Formel darf sich über mehrere Zeilen erstrecken (REB 2009: bis 20). */
    public function test_free_formula_spans_several_lines(): void {
        $item = new GaebItem(reference: '1', takeoffLines: [
            new GaebTakeoffLine(formula: '91', values: ['(4,55 + 4,65 + 4,48 +']),
            new GaebTakeoffLine(formula: '91', values: ['4,52) / 2='], closesResult: true),
        ]);

        $this->assertEqualsWithDelta(9.1, $this->calculator->total($item)['quantity'], 0.0001);
    }

    /**
     * Adressen gelten dokumentweit: Eine Position übernimmt die Zwischensumme
     * einer anderen (REB 23.003 Ausgabe 2009 - Verweise auf höhere Ordnungs-
     * zahlen sind ausdrücklich erlaubt).
     */
    public function test_addresses_reach_across_items(): void {
        $first = new GaebItem(reference: '001.0010', takeoffLines: [
            new GaebTakeoffLine(kind: GaebTakeoffLine::KIND_SUBTOTAL, formula: '04', values: ['35000', '46000'], address: '0004K0'),
        ]);
        $second = new GaebItem(reference: '001.0020', takeoffLines: [
            new GaebTakeoffLine(formula: '91', values: ['0004K0='], address: '0005D0'),
        ]);
        $boq = new GaebBoq(items: [$first, $second]);

        $surveys = $this->calculator->document($boq);

        $this->assertEqualsWithDelta(1610.0, $surveys['001.0010']['quantity'], 0.0001);
        $this->assertEqualsWithDelta(1610.0, $surveys['001.0020']['quantity'], 0.0001);
    }

    /** Der Winkel steht in Gon: 100 Gon sind ein rechter Winkel. */
    public function test_triangle_with_angle_in_gon(): void {
        // 4 × 3 / 2 × sin(100 gon) = 6
        $this->assertEqualsWithDelta(6.0, (float) $this->calculator->line($this->line('02', ['4000', '3000', '100000'])), 0.0001);
    }

    /** Heron: 3-4-5 ergibt 6. */
    public function test_triangle_from_three_sides(): void {
        $this->assertEqualsWithDelta(6.0, (float) $this->calculator->line($this->line('03', ['3000', '4000', '5000'])), 0.0001);
    }

    public function test_pythagoras_root_and_means(): void {
        $this->assertEqualsWithDelta(5.0, (float) $this->calculator->line($this->line('20', ['3000', '4000'])), 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $this->calculator->line($this->line('30', ['9000'])), 0.0001);
        $this->assertEqualsWithDelta(3.0, (float) $this->calculator->line($this->line('31', ['2000', '3000', '4000'])), 0.0001);
        // Quadratisches Mittel von 3 und 4: sqrt((9+16)/2)
        $this->assertEqualsWithDelta(3.5355, (float) $this->calculator->line($this->line('32', ['3000', '4000'])), 0.0001);
    }

    /** Formel 00 addiert mit dem Vorzeichen, das hinter dem Wert steht. */
    public function test_plain_calculation_respects_the_trailing_sign(): void {
        $line = $this->line('00', ['22700+', '24300-', '31400+']);

        $this->assertEqualsWithDelta(29.8, (float) $this->calculator->line($line), 0.0001);
    }

    /** Ein negativer Faktor zieht ab - so verschwindet eine Tür aus der Wand. */
    public function test_negative_factor_subtracts(): void {
        $door = $this->line('04', ['2010', '0885'], ' ', '-2000');

        $this->assertEqualsWithDelta(-3.5577, (float) $this->calculator->line($door), 0.0001);
    }

    /** Kommentare und Hilfswerte bleiben aus der Menge heraus. */
    public function test_total_skips_comments_and_helpers(): void {
        $item = new GaebItem(reference: '001.0010', takeoffLines: [
            $this->line('', [], GaebTakeoffLine::KIND_COMMENT),
            $this->line('04', ['2000', '3000'], GaebTakeoffLine::KIND_HELPER),
            $this->line('04', ['2000', '3000']),
            $this->line('04', ['1000', '1000']),
        ]);

        $result = $this->calculator->total($item);

        $this->assertEqualsWithDelta(7.0, $result['quantity'], 0.0001);
        $this->assertSame(2, $result['lines']);
        $this->assertSame([], $result['skipped']);
    }

    /** Unbekannte Formeln werden benannt, nicht geraten. */
    public function test_unsupported_formulas_are_reported(): void {
        $item = new GaebItem(reference: '001.0010', takeoffLines: [
            $this->line('04', ['2000', '3000']),
            $this->line('23', ['12000', '106200']),
        ]);

        $result = $this->calculator->total($item);

        $this->assertEqualsWithDelta(6.0, $result['quantity'], 0.0001);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Formel 23', $result['skipped'][0]);
    }

    /** Eine Koordinatengruppe ohne abschließendes `=` ist unvollständig. */
    public function test_unfinished_coordinate_group_is_reported(): void {
        $item = new GaebItem(reference: '001.0010', takeoffLines: [
            $this->line('04', ['2000', '3000']),
            new GaebTakeoffLine(formula: '22', values: ['0', '0', '10000', '0']),
        ]);

        $result = $this->calculator->total($item);

        $this->assertEqualsWithDelta(6.0, $result['quantity'], 0.0001);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Abschluss', $result['skipped'][0]);
    }

    /** Die freie Formel rechnet einen Ausdruck mit Komma als Trennzeichen. */
    public function test_free_formula(): void {
        $line = $this->line('91', ['(4,55 + 5,12 + 5,12) * 2=']);

        $this->assertEqualsWithDelta(29.58, (float) $this->calculator->line($line), 0.0001);
    }

    /** Der Ausdruck wird geparst, nicht ausgeführt. */
    public function test_expression_parser_handles_precedence_and_rejects_nonsense(): void {
        $parser = new GaebExpression;

        $this->assertEqualsWithDelta(14.0, $parser->evaluate('2 + 3 * 4'), 0.0001);
        $this->assertEqualsWithDelta(20.0, $parser->evaluate('(2 + 3) * 4'), 0.0001);
        $this->assertEqualsWithDelta(2.5, $parser->evaluate('5 / 2'), 0.0001);
        $this->assertEqualsWithDelta(9.0, $parser->evaluate('3 ^ 2'), 0.0001);
        $this->assertEqualsWithDelta(-1.5, $parser->evaluate('-1,5'), 0.0001);

        $this->expectException(InvalidArgumentException::class);
        $parser->evaluate('phpinfo()');
    }

    /**
     * Eine Aufmaßzeile greift auf das Ergebnis einer früheren zu — über deren
     * Adresse. So wird ein Hilfswert einmal gerechnet und mehrfach benutzt.
     */
    public function test_lines_refer_back_to_earlier_results(): void {
        $helper = new GaebTakeoffLine(
            kind: GaebTakeoffLine::KIND_HELPER,
            formula: '04',
            values: ['2000', '3000'],
            address: '0004F0',
        );
        // Höhe aus dem Hilfswert (6,0) mal Länge 2,0 mal Breite 1,0 = 12,0
        $uses = new GaebTakeoffLine(formula: '04', values: ['2000', '1000', '0004F0'], address: '0004G0');
        $item = new GaebItem(reference: '001.0010', takeoffLines: [$helper, $uses]);

        $result = $this->calculator->total($item);

        // Der Hilfswert selbst zählt nicht mit, nur die Zeile, die ihn nutzt.
        $this->assertEqualsWithDelta(12.0, $result['quantity'], 0.0001);
        $this->assertSame(1, $result['lines']);
    }

    /** Formel 91 kann eine Zwischensumme allein über ihre Adresse übernehmen. */
    public function test_free_formula_takes_over_a_subtotal(): void {
        $subtotal = new GaebTakeoffLine(
            kind: GaebTakeoffLine::KIND_SUBTOTAL,
            formula: '04',
            values: ['35000', '46000'],
            address: '0004K0',
        );
        $carry = new GaebTakeoffLine(formula: '91', values: ['0004K0='], address: '0005D0');
        $item = new GaebItem(reference: '001.0010', takeoffLines: [$subtotal, $carry]);

        $result = $this->calculator->total($item);

        // 35,0 × 46,0 = 1610,0 — einmal als Zwischensumme, einmal übernommen.
        $this->assertEqualsWithDelta(3220.0, $result['quantity'], 0.0001);
    }

    /**
     * Die Formelsammlung schreibt jede Flächenformel doppelt: einmal als
     * Fläche, einmal als Körper darüber. Unterschieden wird allein durch den
     * zusätzlichen Wert am Ende.
     */
    public function test_area_formulas_become_volumes_with_a_height(): void {
        // Dreieck 12,330 × 4,560 / 2 = 28,1124 m², mal Höhe 2,000 m
        $this->assertEqualsWithDelta(56.2248, (float) $this->calculator->line($this->line('01', ['12330', '4560', '2000'])), 0.0001);
        // Trapez mal Höhe
        $this->assertEqualsWithDelta(6.475, (float) $this->calculator->line($this->line('05', ['2330', '2850', '1250', '2000'])), 0.0001);
    }

    /** Kreissektor und Zylindersektor: der Vollkreis misst 400 Gon. */
    public function test_circle_sector_and_cylinder(): void {
        // r = 4,220 m, 45 gon → r²·π·45/400
        $this->assertEqualsWithDelta(6.2940, (float) $this->calculator->line($this->line('07', ['4220', '45000'])), 0.0001);
        $this->assertEqualsWithDelta(12.5880, (float) $this->calculator->line($this->line('07', ['4220', '45000', '2000'])), 0.0001);
        // Voller Kreis: 400 gon ergeben π·r²
        $this->assertEqualsWithDelta(M_PI, (float) $this->calculator->line($this->line('07', ['1000', '400000'])), 0.0001);
    }

    /** Kreisringsektor zieht den inneren Kreis ab. */
    public function test_annulus_sector(): void {
        $this->assertEqualsWithDelta(6.6039, (float) $this->calculator->line($this->line('08', ['6870', '5870', '66000'])), 0.0001);
    }

    /** Parabelsegment: zwei Drittel des umschließenden Rechtecks. */
    public function test_parabolic_segment(): void {
        $this->assertEqualsWithDelta(7.7667, (float) $this->calculator->line($this->line('09', ['1250', '9320'])), 0.0001);
    }

    /** Kegelstumpfsektor nach der Formel der REB-VB 23.003. */
    public function test_truncated_cone_sector(): void {
        $this->assertEqualsWithDelta(2492.7124, (float) $this->calculator->line($this->line('12', ['45000', '28000', '52000', '4500'])), 0.0001);
    }
}
