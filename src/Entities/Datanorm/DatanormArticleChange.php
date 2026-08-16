<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormArticleChange.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

use DateTimeImmutable;

/**
 * An article deletion or renumbering — DATANORM 5 B-record, DATANORM 4
 * A-record with processing flag `L` or `X`.
 *
 * A deletion may carry a successor article number and an expiration date; a
 * renumbering keeps all article data under the new number.
 */
final class DatanormArticleChange {
    public const TYPE_DELETE = 'delete';
    public const TYPE_RENUMBER = 'renumber';

    private function __construct(
        private readonly string $type,
        private readonly string $articleNumber,
        private readonly ?string $newArticleNumber = null,
        private readonly ?string $successorArticleNumber = null,
        private readonly ?DateTimeImmutable $expirationDate = null
    ) {}

    public static function delete(string $articleNumber, ?string $successor = null, ?DateTimeImmutable $expirationDate = null): self {
        return new self(self::TYPE_DELETE, $articleNumber, null, $successor, $expirationDate);
    }

    public static function renumber(string $articleNumber, string $newArticleNumber): self {
        return new self(self::TYPE_RENUMBER, $articleNumber, $newArticleNumber);
    }

    public function getType(): string {
        return $this->type;
    }

    public function isDelete(): bool {
        return $this->type === self::TYPE_DELETE;
    }

    public function isRenumber(): bool {
        return $this->type === self::TYPE_RENUMBER;
    }

    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    public function getNewArticleNumber(): ?string {
        return $this->newArticleNumber;
    }

    public function getSuccessorArticleNumber(): ?string {
        return $this->successorArticleNumber;
    }

    public function getExpirationDate(): ?DateTimeImmutable {
        return $this->expirationDate;
    }
}
