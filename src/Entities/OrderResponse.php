<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderResponse.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;

/**
 * Order response (order confirmation) of a supplier to a buyer's order:
 * references the order id, carries the supplier's own order number and the
 * confirmed lines. Format-neutral; emitted/read as openTRANS ORDERRESPONSE.
 */
final class OrderResponse {
    /** @var list<OrderResponseLine> */
    private array $lines = [];

    public function __construct(
        private string $orderId,
        private DateTimeImmutable $responseDate,
        private Party $buyer,
        private Party $seller,
        private CurrencyCode $currency = CurrencyCode::Euro,
        private ?string $supplierOrderId = null,
        private ?DateTimeImmutable $deliveryDate = null,
        private ?string $note = null
    ) {}

    public function getOrderId(): string {
        return $this->orderId;
    }

    public function getResponseDate(): DateTimeImmutable {
        return $this->responseDate;
    }

    public function getBuyer(): Party {
        return $this->buyer;
    }

    public function getSeller(): Party {
        return $this->seller;
    }

    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    public function getSupplierOrderId(): ?string {
        return $this->supplierOrderId;
    }

    public function getDeliveryDate(): ?DateTimeImmutable {
        return $this->deliveryDate;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    /** @return list<OrderResponseLine> */
    public function getLines(): array {
        return $this->lines;
    }

    public function addLine(OrderResponseLine $line): self {
        $this->lines[] = $line;

        return $this;
    }
}
