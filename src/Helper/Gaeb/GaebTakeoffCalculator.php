<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTakeoffCalculator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\Gaeb;

use ERechnungToolkit\Entities\Gaeb\{GaebItem, GaebTakeoffLine};
use InvalidArgumentException;

/**
 * Evaluates a quantity survey after REB-VB 23.003.
 *
 * GAEB puts the arithmetic of the reading side above the delivered figure: who
 * can compute, computes, and the own result counts. This class does that for
 * the formulas whose rule is unambiguous - areas, volumes, means, the free
 * formula and the plain calculation - and refuses the rest by name instead of
 * guessing. A survey that silently returns a wrong quantity is worse than one
 * that says which line it could not evaluate.
 *
 * Two conventions of the format decide the result:
 *
 * - **Values carry three decimals.** `12330` is 12,330 - metres, not millimetres.
 * - **Angles are given in gon**, not degrees: 400 gon make a full circle.
 *
 * A line marked as a comment carries no arithmetic, a helper value (`H`) is
 * addressable but stays out of the total, and the factor multiplies its line -
 * a negative one subtracts, which is how a door is taken out of a wall.
 */
final class GaebTakeoffCalculator {
    /** Values are integers with three implied decimals. */
    private const VALUE_SCALE = 1000;

    /** Factors follow the same scale; an empty factor means one. */
    private const FACTOR_SCALE = 1000;

    /** Formulas this class evaluates, with the name the REB catalogue gives them. */
    private const SUPPORTED = [
        '00' => 'Rechenansatz',
        '01' => 'Dreieck aus Grundseite und Höhe',
        '02' => 'Dreieck aus zwei Seiten und eingeschlossenem Winkel',
        '03' => 'Dreieck aus drei Seiten',
        '04' => 'Rechteck oder Quader',
        '05' => 'Trapez',
        '20' => 'Pythagoras',
        '30' => 'Quadratwurzel',
        '31' => 'Arithmetisches Mittel',
        '32' => 'Quadratisches Mittel',
        '91' => 'Freie Formel',
    ];

    /**
     * Sum of an item's survey, and what could not be evaluated.
     *
     * @return array{quantity: float, lines: int, skipped: list<string>}
     */
    public function total(GaebItem $item): array {
        $sum = 0.0;
        $counted = 0;
        $skipped = [];

        foreach ($item->getTakeoffLines() as $line) {
            if (!$line->countsTowardsQuantity() || $line->getFormula() === null) {
                continue;
            }

            $value = $this->line($line);
            if ($value === null) {
                $skipped[] = $this->reason($line);

                continue;
            }

            $sum += $value;
            $counted++;
        }

        return ['quantity' => round($sum, 4), 'lines' => $counted, 'skipped' => $skipped];
    }

    /** Result of a single line including its factor, or null when unsupported. */
    public function line(GaebTakeoffLine $line): ?float {
        $formula = $line->getFormula();
        if ($formula === null || !isset(self::SUPPORTED[$formula])) {
            return null;
        }

        $values = array_map(fn (string $raw): float => $this->value($raw), $line->getValues());
        $result = $formula === '91'
            ? $this->freeFormula($line->getValues()[0] ?? '')
            : $this->apply($formula, $values, $line->getValues());

        if ($result === null) {
            return null;
        }

        return $result * $this->factor($line);
    }

    /** Is the formula of this line one the calculator can evaluate? */
    public function supports(GaebTakeoffLine $line): bool {
        $formula = $line->getFormula();

        return $formula !== null && isset(self::SUPPORTED[$formula]);
    }

    /**
     * @param  list<float>   $v scaled values
     * @param  list<string>  $raw untouched values, needed where signs matter
     */
    private function apply(string $formula, array $v, array $raw): ?float {
        return match ($formula) {
            // Plain calculation: the sign travels behind each value.
            '00' => $this->plainSum($raw),
            '01' => isset($v[1]) ? $v[0] * $v[1] / 2 : null,
            '02' => isset($v[2]) ? $v[0] * $v[1] * sin($this->gonToRadians($v[2])) / 2 : null,
            '03' => isset($v[2]) ? $this->heron($v[0], $v[1], $v[2]) : null,
            // Two values give an area, three a volume.
            '04' => match (count($v)) {
                2 => $v[0] * $v[1],
                3 => $v[0] * $v[1] * $v[2],
                default => null,
            },
            '05' => isset($v[2]) ? ($v[0] + $v[1]) / 2 * $v[2] : null,
            '20' => isset($v[1]) ? sqrt($v[0] ** 2 + $v[1] ** 2) : null,
            '30' => isset($v[0]) && $v[0] >= 0 ? sqrt($v[0]) : null,
            '31' => $v === [] ? null : array_sum($v) / count($v),
            '32' => $v === [] ? null : sqrt(array_sum(array_map(static fn (float $x): float => $x ** 2, $v)) / count($v)),
            default => null,
        };
    }

    /**
     * Formula 00 adds its values, each with the sign written behind it.
     *
     * @param list<string> $raw
     */
    private function plainSum(array $raw): ?float {
        $sum = 0.0;
        $seen = false;

        foreach ($raw as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $sign = 1.0;
            if (str_ends_with($entry, '-')) {
                $sign = -1.0;
                $entry = rtrim($entry, '-');
            } else {
                $entry = rtrim($entry, '+=');
            }
            if (!is_numeric($entry)) {
                continue;
            }
            $sum += $sign * $this->value($entry);
            $seen = true;
        }

        return $seen ? $sum : null;
    }

    private function heron(float $a, float $b, float $c): ?float {
        $s = ($a + $b + $c) / 2;
        $area = $s * ($s - $a) * ($s - $b) * ($s - $c);

        return $area <= 0 ? null : sqrt($area);
    }

    /**
     * Free formula: an expression with the comma as decimal separator. It is
     * evaluated by a parser of its own - never by `eval`, because the text
     * comes from a foreign file.
     */
    private function freeFormula(string $expression): ?float {
        $expression = rtrim(trim($expression), '=');
        if ($expression === '') {
            return null;
        }

        try {
            return (new GaebExpression)->evaluate($expression);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function factor(GaebTakeoffLine $line): float {
        $factor = $line->getFactor();
        if ($factor === null || trim($factor) === '') {
            return 1.0;
        }

        $negative = str_starts_with($factor, '-');
        $digits = ltrim($factor, '+-');

        if (!is_numeric($digits)) {
            return 1.0;
        }

        $value = ((float) $digits) / self::FACTOR_SCALE;

        return $negative ? -$value : $value;
    }

    /** Value field: an integer with three implied decimals. */
    private function value(string $raw): float {
        $raw = rtrim(trim($raw), '+-=');
        if ($raw === '' || !is_numeric($raw)) {
            return 0.0;
        }

        return ((float) $raw) / self::VALUE_SCALE;
    }

    /** REB angles are given in gon: 400 gon are a full circle. */
    private function gonToRadians(float $gon): float {
        return $gon * M_PI / 200;
    }

    private function reason(GaebTakeoffLine $line): string {
        $formula = (string) $line->getFormula();

        return "Formel {$formula} wird nicht berechnet"
            . ($line->getAddress() !== null ? " (Zeile {$line->getAddress()})" : '') . '.';
    }
}
