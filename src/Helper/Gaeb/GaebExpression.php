<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebExpression.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Helper\Gaeb;

use InvalidArgumentException;

/**
 * Evaluates the expression of the free REB formula (91).
 *
 * The text comes from a file someone else wrote, so it is parsed rather than
 * executed: a recursive descent over numbers, the four operations, powers and
 * parentheses. `eval` would hand a foreign file the keys to the process.
 *
 * The reading head travels as a parameter, not as state on the object - one
 * instance can therefore be reused and even nested without the two runs
 * treading on each other.
 *
 * The decimal separator is the comma, as in the files; a point is accepted too,
 * because writers differ.
 */
final class GaebExpression {
    /** @throws InvalidArgumentException when the expression cannot be read */
    public function evaluate(string $expression): float {
        $input = str_replace([' ', "\t", "\n", "\r"], '', $expression);
        if ($input === '') {
            throw new InvalidArgumentException('Leerer Ausdruck.');
        }

        $position = 0;
        $value = $this->sum($input, $position);

        if ($position < strlen($input)) {
            throw new InvalidArgumentException("Unerwartetes Zeichen an Stelle {$position}.");
        }

        return $value;
    }

    private function sum(string $input, int &$position): float {
        $value = $this->product($input, $position);

        while ($position < strlen($input)) {
            $operator = $input[$position];
            if ($operator !== '+' && $operator !== '-') {
                break;
            }
            $position++;
            $right = $this->product($input, $position);
            $value = $operator === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function product(string $input, int &$position): float {
        $value = $this->power($input, $position);

        while ($position < strlen($input)) {
            $operator = $input[$position];
            if ($operator !== '*' && $operator !== '/' && $operator !== ':') {
                break;
            }
            $position++;
            $right = $this->power($input, $position);

            if ($operator === '*') {
                $value *= $right;

                continue;
            }
            if ($right === 0.0) {
                throw new InvalidArgumentException('Division durch null.');
            }
            $value /= $right;
        }

        return $value;
    }

    private function power(string $input, int &$position): float {
        $value = $this->term($input, $position);

        if ($position < strlen($input) && $input[$position] === '^') {
            $position++;

            return $value ** $this->power($input, $position);
        }

        return $value;
    }

    private function term(string $input, int &$position): float {
        if ($position >= strlen($input)) {
            throw new InvalidArgumentException('Ausdruck endet unerwartet.');
        }

        $char = $input[$position];

        if ($char === '-') {
            $position++;

            return -$this->term($input, $position);
        }
        if ($char === '+') {
            $position++;

            return $this->term($input, $position);
        }

        if ($char === '(') {
            $position++;
            $value = $this->sum($input, $position);
            if ($position >= strlen($input) || $input[$position] !== ')') {
                throw new InvalidArgumentException('Schließende Klammer fehlt.');
            }
            $position++;

            return $value;
        }

        return $this->number($input, $position);
    }

    private function number(string $input, int &$position): float {
        $start = $position;
        while ($position < strlen($input)
            && (ctype_digit($input[$position]) || $input[$position] === ',' || $input[$position] === '.')) {
            $position++;
        }

        $raw = substr($input, $start, $position - $start);
        if ($raw === '') {
            throw new InvalidArgumentException("Zahl erwartet an Stelle {$start}.");
        }

        return (float) str_replace(',', '.', $raw);
    }
}
