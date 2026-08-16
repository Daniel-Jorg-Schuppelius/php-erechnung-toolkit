<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebTextComplement.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A gap inside a long text that the bidder or the client fills in (GAEB
 * `TextComplement`). Its number is stable: it must be returned unchanged with
 * the bid, which is why the surrounding text keeps a marker at its place.
 */
final class GaebTextComplement {
    public const KIND_BIDDER = 'Bidder';

    public function __construct(
        private readonly string $mark,
        private readonly ?string $kind = null,
        private readonly ?string $caption = null,
        private readonly ?string $body = null,
        private readonly ?string $tail = null
    ) {}

    /** Value of `MarkLbl`; unique within the item. */
    public function getMark(): string {
        return $this->mark;
    }

    /** Who fills the gap, e.g. `Bidder`. */
    public function getKind(): ?string {
        return $this->kind;
    }

    public function getCaption(): ?string {
        return $this->caption;
    }

    /** The gap itself as delivered, usually a placeholder such as "......". */
    public function getBody(): ?string {
        return $this->body;
    }

    public function getTail(): ?string {
        return $this->tail;
    }

    public function isBidderComplement(): bool {
        return $this->kind === self::KIND_BIDDER;
    }
}
