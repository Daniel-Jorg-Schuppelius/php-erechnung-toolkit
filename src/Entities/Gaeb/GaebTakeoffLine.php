<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTakeoffLine.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * One line of a quantity survey (GAEB `QTakeoff`, REB-VB 23.003).
 *
 * The survey is not a list of numbers but a small calculation: each line names
 * a formula, its values and an address under which the result can be picked up
 * again. Lines marked as a comment carry only text, a helper value is not added
 * to the total, and a subtotal closes what came before it.
 */
final class GaebTakeoffLine {
    /** Comment - carries explanation only, no arithmetic. */
    public const KIND_COMMENT = '*';

    /** Helper value: computed, addressable, but not part of the total. */
    public const KIND_HELPER = 'H';

    /** Subtotal of the lines before it. */
    public const KIND_SUBTOTAL = 'Z';

    /** @param list<string> $values raw value fields, in the order of the line */
    public function __construct(
        private readonly string $kind = ' ',
        private readonly ?string $explanation = null,
        private readonly ?string $factor = null,
        private readonly ?string $formula = null,
        private readonly array $values = [],
        private readonly ?string $address = null,
        private readonly bool $closesResult = false
    ) {}

    /** `*`, `H`, `Z` or a space for an ordinary line. */
    public function getKind(): string {
        return $this->kind;
    }

    public function getExplanation(): ?string {
        return $this->explanation;
    }

    /** Multiplier of the line, e.g. a repeat count. */
    public function getFactor(): ?string {
        return $this->factor;
    }

    /** Formula number of the REB catalogue; `91` is the free formula. */
    public function getFormula(): ?string {
        return $this->formula;
    }

    /** @return list<string> */
    public function getValues(): array {
        return $this->values;
    }

    /** Address of the line, used to refer back to its result. */
    public function getAddress(): ?string {
        return $this->address;
    }

    /** Does the line end a calculation (trailing `=`)? */
    public function closesResult(): bool {
        return $this->closesResult;
    }

    public function isComment(): bool {
        return $this->kind === self::KIND_COMMENT;
    }

    public function isHelper(): bool {
        return $this->kind === self::KIND_HELPER;
    }

    public function isSubtotal(): bool {
        return $this->kind === self::KIND_SUBTOTAL;
    }

    /** Does the line contribute to the quantity of its item? */
    public function countsTowardsQuantity(): bool {
        return !$this->isComment() && !$this->isHelper();
    }
}
