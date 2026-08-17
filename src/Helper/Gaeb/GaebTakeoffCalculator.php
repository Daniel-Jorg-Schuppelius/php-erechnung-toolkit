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

use ERechnungToolkit\Entities\Gaeb\{GaebBoq, GaebItem, GaebTakeoffLine};
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
        '07' => 'Kreissektor, mit Höhe Zylindersektor',
        '08' => 'Kreisringsektor, mit Höhe Hohlzylindersektor',
        '09' => 'Parabelsegment, mit Höhe Parabelsegmentkörper',
        '10' => 'Tangenteneck, mit Höhe Tangenteneckkörper',
        '11' => 'Kegelstumpfsektormantel',
        '12' => 'Kegelstumpfsektor',
        '13' => 'Prisma über Dreieck mit drei Höhen',
        '14' => 'Dreieckspyramidenstumpf',
        '15' => 'Rechteckpyramidenstumpf',
        '20' => 'Pythagoras',
        '21' => 'Geraden aus Koordinaten (Polygonlänge)',
        '22' => 'Unregelmäßiges Vieleck aus Koordinaten (Gauß)',
        '23' => 'Flächen-/Mengenermittlung aus Querprofilen',
        '25' => 'Stationierte Trapezprofile',
        '30' => 'Quadratwurzel',
        '31' => 'Arithmetisches Mittel',
        '32' => 'Quadratisches Mittel',
        '91' => 'Freie Formel',
    ];

    /**
     * Surveys of every item of a document, in file order.
     *
     * The order matters: a line takes over a subtotal by its address, and that
     * address may well have been computed under an earlier ordinal number. Item
     * by item on their own, such a reference would come up empty.
     *
     * @return array<string, array{quantity: float, lines: int, skipped: list<string>, results: array<string, float>}>
     */
    public function document(GaebBoq $boq): array {
        $results = [];
        $surveys = [];

        foreach ($boq->getItems() as $item) {
            $survey = $this->total($item, $results);
            $results = $survey['results'];
            $surveys[$item->getReference()] = $survey;
        }

        return $surveys;
    }

    /**
     * Sum of an item's survey, and what could not be evaluated.
     *
     * Addresses are valid across the whole document, not just within the item:
     * a line may take over a subtotal computed under another ordinal number.
     * Pass the `results` of the previous items to make those references work -
     * or use {@see document()}, which does it for a whole bill of quantities.
     *
     * @param  array<string, float> $carry results of earlier addressed lines
     * @return array{quantity: float, lines: int, skipped: list<string>, results: array<string, float>}
     */
    public function total(GaebItem $item, array $carry = []): array {
        $sum = 0.0;
        $counted = 0;
        $skipped = [];
        /** @var array<string, float> $results result of every addressed line */
        $results = $carry;

        /** @var list<GaebTakeoffLine> $group lines of an open multi-line formula */
        $group = [];

        foreach ($item->getTakeoffLines() as $line) {
            if ($line->getFormula() === null) {
                continue;
            }

            // Coordinate formulas run across records until a line closes them
            // with `=`; evaluating each record on its own would be nonsense.
            if ($this->isMultiLine($line->getFormula())) {
                $group[] = $line;
                if (!$this->closesGroup($line)) {
                    continue;
                }
                $value = $this->evaluateGroup($group, $results);
                $last = $group[count($group) - 1];
                $group = [];

                if ($value === null) {
                    $skipped[] = $this->reason($last);

                    continue;
                }
                if ($last->getAddress() !== null) {
                    $results[$last->getAddress()] = $value;
                }
                if ($last->countsTowardsQuantity()) {
                    $sum += $value;
                    $counted++;
                }

                continue;
            }

            $value = $this->line($line, $results);
            if ($value === null) {
                if ($line->countsTowardsQuantity()) {
                    $skipped[] = $this->reason($line);
                }

                continue;
            }

            // Auch Hilfswerte werden abgelegt: sie zählen nicht zur Menge, aber
            // spätere Zeilen greifen sie über ihre Adresse wieder auf.
            if ($line->getAddress() !== null) {
                $results[$line->getAddress()] = $value;
            }

            if (!$line->countsTowardsQuantity()) {
                continue;
            }

            $sum += $value;
            $counted++;
        }

        // Eine Gruppe ohne abschließendes `=` ist unvollständig - melden,
        // nicht stillschweigend fallen lassen.
        if ($group !== []) {
            $skipped[] = $this->reason($group[count($group) - 1]) . ' (Gruppe ohne Abschluss)';
        }

        return ['quantity' => round($sum, 4), 'lines' => $counted, 'skipped' => $skipped, 'results' => $results];
    }

    /**
     * Result of a single line including its factor, or null when unsupported.
     *
     * @param array<string, float> $results results of earlier addressed lines
     */
    public function line(GaebTakeoffLine $line, array $results = []): ?float {
        $formula = $line->getFormula();
        if ($formula === null || !isset(self::SUPPORTED[$formula])) {
            return null;
        }

        $raw = $line->getValues();
        $values = array_map(fn (string $value): float => $this->value($value, $results), $raw);
        $result = $formula === '91'
            ? $this->freeFormula($raw[0] ?? '', $results)
            : $this->apply($formula, $values, $raw);

        if ($result === null) {
            return null;
        }

        return $result * $this->factor($line);
    }

    /**
     * Does this line close its group? Beside the flag the parser sets from the
     * record, the free formula carries its `=` inside the expression - which
     * the REB demands, so it is enough on its own.
     */
    private function closesGroup(GaebTakeoffLine $line): bool {
        if ($line->closesResult()) {
            return true;
        }

        return $line->getFormula() === '91'
            && str_ends_with(rtrim($line->getValues()[0] ?? ''), '=');
    }

    /**
     * Formulas whose values run across several records, closed by `=`. Four of
     * them do: the plain sum (00), both coordinate formulas (21/22) and the
     * free formula (91), whose expression may simply be too long for one line.
     */
    private function isMultiLine(string $formula): bool {
        return in_array($formula, ['00', '21', '22', '23', '25', '91'], true);
    }

    /**
     * Result of a group of lines that belong to one formula.
     *
     * @param  list<GaebTakeoffLine>  $group
     * @param  array<string, float>   $results
     */
    private function evaluateGroup(array $group, array $results): ?float {
        $formula = $group[0]->getFormula();

        // Der Rechenansatz addiert seine Werte samt Vorzeichen - über alle
        // Zeilen der Gruppe hinweg, der Übertrag zählt mit.
        if ($formula === '00') {
            $raw = [];
            foreach ($group as $line) {
                foreach ($line->getValues() as $value) {
                    $raw[] = $value;
                }
            }
            $sum = $this->plainSum($raw);

            return $sum === null ? null : $sum * $this->factor($group[0]);
        }

        // Die freie Formel setzt ihren Ausdruck aus den Zeilen zusammen; für
        // sich genommen ist keine Teilzeile ein gültiger Ausdruck.
        if ($formula === '91') {
            $expression = '';
            foreach ($group as $line) {
                $expression .= $line->getValues()[0] ?? '';
            }
            $result = $this->freeFormula($expression, $results);

            return $result === null ? null : $result * $this->factor($group[0]);
        }

        if ($formula === '23' || $formula === '25') {
            $result = $this->stationProfile($group, $results, $formula);

            return $result === null ? null : $result * $this->factor($group[0]);
        }

        return $this->coordinates($group, $results);
    }

    /**
     * Cross sections (23) and stationed trapezoidal profiles (25). Both walk a
     * chain of stations and apply the trapezoidal rule between neighbours:
     * `R = |St_i - St_i-1| * (F_i + F_i-1) / 2`, summed over all intervals.
     *
     * They differ only in how the area of one station is obtained. Formula 23
     * takes it ready-made - up to four part areas per station, usually computed
     * by formula 21/22 before and referred to by their address. Formula 25
     * derives it from the trapezium itself: `F = (a + b) / 2 * h`.
     *
     * Whether the result is a volume or a surface follows from what was fed in
     * (areas or lengths); the arithmetic is the same, which is why the REB puts
     * both under one formula number.
     *
     * @param  list<GaebTakeoffLine> $group
     * @param  array<string, float>  $results
     */
    private function stationProfile(array $group, array $results, string $formula): ?float {
        /** @var list<array{float, float}> $stations station and its area */
        $stations = [];

        foreach ($group as $line) {
            $v = array_map(fn (string $value): float => $this->value($value, $results), $line->getValues());
            if (count($v) < 2) {
                continue;
            }

            $area = $formula === '25'
                // hi, ai, bi - the trapezium of this station
                ? (isset($v[3]) ? ($v[2] + $v[3]) / 2 * $v[1] : null)
                // up to four part areas, already computed
                : array_sum(array_slice($v, 1));

            if ($area === null) {
                return null;
            }
            $stations[] = [$v[0], $area];
        }

        if (count($stations) < 2) {
            return null;
        }

        $total = 0.0;
        for ($i = 1; $i < count($stations); $i++) {
            $length = abs($stations[$i][0] - $stations[$i - 1][0]);
            $total += $length * ($stations[$i][1] + $stations[$i - 1][1]) / 2;
        }

        return $total;
    }

    /**
     * Polygon length (21) or the area of an irregular polygon after Gauss (22).
     * Both take an unlimited list of y/z pairs; an odd trailing value is the
     * thickness `D`, which turns the length into an area and the area into a
     * volume.
     *
     * @param  list<GaebTakeoffLine>  $group
     * @param  array<string, float>   $results
     */
    private function coordinates(array $group, array $results): ?float {
        $formula = $group[0]->getFormula();
        $values = [];
        foreach ($group as $line) {
            foreach ($line->getValues() as $value) {
                $values[] = $this->value($value, $results);
            }
        }

        $thickness = null;
        if (count($values) % 2 === 1) {
            $thickness = array_pop($values);
        }
        if (count($values) < 4) {
            return null;
        }

        $points = array_chunk($values, 2);
        $result = $formula === '21' ? $this->polygonLength($points) : $this->gaussArea($points);

        // Der Faktor der ersten Zeile gilt für die ganze Gruppe.
        return $result * ($thickness ?? 1.0) * $this->factor($group[0]);
    }

    /** @param list<list<float>> $points */
    private function polygonLength(array $points): float {
        $length = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $length += sqrt(($points[$i][0] - $points[$i - 1][0]) ** 2 + ($points[$i][1] - $points[$i - 1][1]) ** 2);
        }

        return $length;
    }

    /**
     * Gauss's area formula. The polygon closes on itself, so the last point is
     * followed by the first again.
     *
     * @param list<list<float>> $points
     */
    private function gaussArea(array $points): float {
        $sum = 0.0;
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $next = $points[($i + 1) % $count];
            $sum += ($points[$i][0] - $next[0]) * ($points[$i][1] + $next[1]);
        }

        return abs($sum) / 2;
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
            // Every area formula becomes a volume once a height follows it -
            // the prism over the same base. The extra value is always the last.
            '01' => isset($v[1]) ? $this->withHeight($v[0] * $v[1] / 2, $v, 2) : null,
            '02' => isset($v[2]) ? $this->withHeight($v[0] * $v[1] * sin($this->gonToRadians($v[2])) / 2, $v, 3) : null,
            '03' => isset($v[2]) ? $this->withHeight($this->heron($v[0], $v[1], $v[2]), $v, 3) : null,
            '04' => isset($v[1]) ? $this->withHeight($v[0] * $v[1], $v, 2) : null,
            '05' => isset($v[2]) ? $this->withHeight(($v[0] + $v[1]) / 2 * $v[2], $v, 3) : null,
            // Circle sector; a full circle is 400 gon.
            '07' => isset($v[1]) ? $this->withHeight($v[0] ** 2 * M_PI * $v[1] / 400, $v, 2) : null,
            '08' => isset($v[2]) ? $this->withHeight(($v[0] ** 2 - $v[1] ** 2) * M_PI * $v[2] / 400, $v, 3) : null,
            // Parabolic segment: two thirds of the enclosing rectangle.
            '09' => isset($v[1]) ? $this->withHeight(2 * $v[0] * $v[1] / 3, $v, 2) : null,
            '10' => isset($v[1]) ? $this->withHeight($v[0] ** 2 * tan($this->gonToRadians($v[1] / 2)), $v, 2) : null,
            // Lateral surface of a truncated cone sector (a cone has r = 0).
            '11' => isset($v[3])
                ? ($v[0] + $v[1]) * sqrt(($v[0] - $v[1]) ** 2 + $v[3] ** 2) * M_PI * $v[2] / 400
                : null,
            '12' => isset($v[3])
                ? ($v[0] ** 2 + $v[0] * $v[1] + $v[1] ** 2) * $v[3] * M_PI * $v[2] / (3 * 400)
                : null,
            // Prisma über einem Dreieck, dessen drei Kanten unterschiedlich
            // hoch sind: die mittlere Höhe ist ein Drittel ihrer Summe.
            '13' => isset($v[4]) ? $v[0] * $v[1] * ($v[2] + $v[3] + $v[4]) / 6 : null,
            // Pyramidenstümpfe: A/B ist die Grundfläche, a/b die Deckfläche.
            // Für eine volle Pyramide werden a und b als 0 eingetragen.
            '14' => isset($v[4]) ? $this->frustum($v) / 12 : null,
            '15' => isset($v[4]) ? $this->frustum($v) / 6 : null,
            '20' => isset($v[1]) ? sqrt($v[0] ** 2 + $v[1] ** 2) : null,
            '30' => isset($v[0]) && $v[0] >= 0 ? sqrt($v[0]) : null,
            '31' => $v === [] ? null : array_sum($v) / count($v),
            '32' => $v === [] ? null : sqrt(array_sum(array_map(static fn (float $x): float => $x ** 2, $v)) / count($v)),
            default => null,
        };
    }

    /**
     * Formula 00 adds up its values. The sign belongs **in front of** its value
     * and, because the fields are right aligned, is separated from the digits by
     * blanks (`+ 24300`). The sign of the first value on a continuation line
     * still sits behind the last field of the line before, where the parser
     * keeps it as an entry of its own.
     *
     * @param list<string> $raw
     */
    private function plainSum(array $raw): ?float {
        $sum = 0.0;
        $seen = false;
        $carried = 1.0;

        foreach ($raw as $entry) {
            $entry = str_replace([' ', '='], '', $entry);
            if ($entry === '') {
                continue;
            }
            // Ein Eintrag, der nur aus dem Vorzeichen besteht, gilt dem
            // nächsten Wert.
            if ($entry === '+' || $entry === '-') {
                $carried = $entry === '-' ? -1.0 : 1.0;

                continue;
            }

            $sign = $carried;
            $carried = 1.0;
            if ($entry[0] === '+' || $entry[0] === '-') {
                $sign = $entry[0] === '-' ? -1.0 : 1.0;
                $entry = substr($entry, 1);
            } elseif (str_ends_with($entry, '-')) {
                // Toleranz gegenüber Schreibern, die es nachstellen.
                $sign = -1.0;
                $entry = rtrim($entry, '-');
            } else {
                $entry = rtrim($entry, '+');
            }
            if (!is_numeric($entry)) {
                continue;
            }
            $sum += $sign * $this->value($entry);
            $seen = true;
        }

        return $seen ? $sum : null;
    }

    /**
     * Multiplies an area by the height that follows it, if one was given. The
     * REB catalogue writes each area formula twice: once as a face, once as the
     * prism over it - distinguished solely by the extra value.
     *
     * @param list<float> $v
     */
    private function withHeight(?float $area, array $v, int $heightIndex): ?float {
        if ($area === null) {
            return null;
        }

        return isset($v[$heightIndex]) ? $area * $v[$heightIndex] : $area;
    }

    /**
     * Shared body of the two frustum formulas: base A/B, top a/b and height H.
     * Only the divisor differs - twelve for the triangular, six for the
     * rectangular one.
     *
     * @param list<float> $v A, B, H, a, b
     */
    private function frustum(array $v): float {
        return (2 * $v[0] * $v[1] + 2 * $v[3] * $v[4] + $v[0] * $v[4] + $v[3] * $v[1]) * $v[2];
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
     *
     * @param array<string, float> $results
     */
    private function freeFormula(string $expression, array $results = []): ?float {
        $expression = rtrim(trim($expression), '=');
        if ($expression === '') {
            return null;
        }

        // Ein reiner Adressverweis übernimmt die Zwischensumme jener Zeile.
        if ($this->isAddress($expression)) {
            return $results[$expression] ?? null;
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

    /**
     * Value field: an integer with three implied decimals - or the address of an
     * earlier line, whose result is taken instead. A survey refers back to what
     * it has already computed rather than repeating it.
     *
     * @param array<string, float> $results
     */
    private function value(string $raw, array $results = []): float {
        $raw = rtrim(trim($raw), '+-=');
        if ($raw === '') {
            return 0.0;
        }

        if ($this->isAddress($raw)) {
            return $results[$raw] ?? 0.0;
        }

        return is_numeric($raw) ? ((float) $raw) / self::VALUE_SCALE : 0.0;
    }

    /**
     * Does the field look like a line address? Four digits, a letter for the
     * line and one more digit - that is how the survey numbers its sheets.
     */
    private function isAddress(string $value): bool {
        return preg_match('/^\d{4}[A-Za-z]\d$/', $value) === 1;
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
