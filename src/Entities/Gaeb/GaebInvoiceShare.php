<?php
/*
 * Created on   : Sun Aug 17 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebInvoiceShare.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\ValueObjects\Money;
use ERechnungToolkit\Enums\GaebInvoiceShareType;

/**
 * One component of an invoice (GAEB `InvoiceShare`).
 *
 * The caption is free text because the client names the components: the same
 * type may appear twice under different labels. Amount and percentage may both
 * be present - the percentage refers to the preceding subtotal, which is why
 * the order of the components is their computation order.
 */
final class GaebInvoiceShare {
    public function __construct(
        private readonly GaebInvoiceShareType $type,
        private readonly string $description,
        private readonly ?Money $total = null,
        private readonly ?string $percent = null,
        /** Gegenforderung: Der Betrag kehrt sein Vorzeichen um. */
        private readonly bool $counterClaim = false,
    ) {}

    public function getType(): GaebInvoiceShareType {
        return $this->type;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function getTotal(): ?Money {
        return $this->total;
    }

    public function getPercent(): ?string {
        return $this->percent;
    }

    public function isCounterClaim(): bool {
        return $this->counterClaim;
    }

    /**
     * Does this share lower the sum? Beside the type itself a counter claim
     * turns the sign around - the document marks it rather than writing a
     * negative figure.
     */
    public function lowersTotal(): bool {
        return $this->counterClaim !== $this->type->reducesAmount();
    }
}
