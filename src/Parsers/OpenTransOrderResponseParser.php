<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderResponseParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMElement;
use ERechnungToolkit\Entities\{OrderResponse, OrderResponseLine};
use ERechnungToolkit\Traits\OpenTransReaderTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Parser for openTRANS 2.1 ORDERRESPONSE documents (order confirmation), the
 * inverse of {@see \ERechnungToolkit\Generators\OpenTransOrderResponseGenerator}.
 * A line without its own delivery date inherits the header's.
 */
final class OpenTransOrderResponseParser {
    use ErrorLog;
    use OpenTransReaderTrait;

    public function parse(string $xml): OrderResponse {
        $this->loadOpenTrans($xml, 'ORDERRESPONSE');
        $info = '/ot:ORDERRESPONSE/ot:ORDERRESPONSE_HEADER/ot:ORDERRESPONSE_INFO';
        $currency = CurrencyCode::tryFrom($this->getValue("{$info}/ot:CURRENCY") ?? 'EUR') ?? CurrencyCode::Euro;
        $headerDelivery = $this->getDate("{$info}/ot:DELIVERY_DATE/ot:DELIVERY_START_DATE");

        $response = new OrderResponse(
            orderId: $this->getValue("{$info}/ot:ORDER_ID") ?? '',
            responseDate: $this->getDate("{$info}/ot:ORDERRESPONSE_DATE") ?? new DateTimeImmutable('now'),
            buyer: $this->readParty("{$info}/ot:PARTIES", 'buyer'),
            seller: $this->readParty("{$info}/ot:PARTIES", 'supplier'),
            currency: $currency,
            supplierOrderId: $this->getValue("{$info}/ot:SUPPLIER_ORDER_ID"),
            deliveryDate: $headerDelivery,
            note: $this->getValue("{$info}/ot:REMARKS")
        );

        foreach ($this->xpath->query('/ot:ORDERRESPONSE/ot:ORDERRESPONSE_ITEM_LIST/ot:ORDERRESPONSE_ITEM') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $response->addLine($this->line($node, $currency, $headerDelivery));
            }
        }

        return $response;
    }

    public function parseFile(string $filePath): OrderResponse {
        return $this->parse($this->readOpenTransFile($filePath));
    }

    private function line(DOMElement $node, CurrencyCode $currency, ?DateTimeImmutable $headerDelivery): OrderResponseLine {
        return new OrderResponseLine(
            lineId: $this->getNodeValue($node, 'ot:LINE_ITEM_ID') ?? '',
            quantity: (float) ($this->getNodeValue($node, 'ot:QUANTITY') ?? '0'),
            unitCode: $this->getNodeValue($node, 'bmecat:ORDER_UNIT') ?? '',
            itemName: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_SHORT') ?? '',
            unitPrice: Money::ofNullable($this->getNodeValue($node, 'ot:PRODUCT_PRICE_FIX/bmecat:PRICE_AMOUNT'), $currency),
            sellersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:SUPPLIER_PID'),
            buyersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:BUYER_PID'),
            deliveryDate: $this->getDate('ot:DELIVERY_DATE/ot:DELIVERY_START_DATE', $node) ?? $headerDelivery,
            note: $this->getNodeValue($node, 'ot:REMARKS')
        );
    }
}
