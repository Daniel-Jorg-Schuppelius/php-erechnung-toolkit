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

use ERechnungToolkit\Entities\Gaeb\{GaebItem, GaebTakeoffLine};
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
            $this->line('22', ['12000', '106200']),
        ]);

        $result = $this->calculator->total($item);

        $this->assertEqualsWithDelta(6.0, $result['quantity'], 0.0001);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('Formel 22', $result['skipped'][0]);
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
}
