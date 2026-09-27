<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMElement;
use ERechnungToolkit\Entities\{Order, OrderLine};
use ERechnungToolkit\Enums\UnitCode;
use ERechnungToolkit\Traits\OpenTransReaderTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Parser for openTRANS 2.1 ORDER documents.
 *
 * Detects an openTRANS `ORDER` and maps it onto the shared {@see Order} entity,
 * the inverse of {@see \ERechnungToolkit\Generators\OpenTransOrderGenerator}. The
 * buyer/supplier are resolved through their PARTY_ROLE, addresses and product ids
 * are read from the embedded BMEcat elements.
 */
final class OpenTransOrderParser {
    use ErrorLog;
    use OpenTransReaderTrait;

    /**
     * Parses an openTRANS ORDER from an XML string.
     */
    public function parse(string $xml): Order {
        $this->loadOpenTrans($xml, 'ORDER');

        return $this->parseOrder();
    }

    /**
     * Parses an openTRANS ORDER from a file.
     */
    public function parseFile(string $filePath): Order {
        return $this->parse($this->readOpenTransFile($filePath));
    }

    private function parseOrder(): Order {
        $info = '/ot:ORDER/ot:ORDER_HEADER/ot:ORDER_INFO';

        $id = $this->getValue("{$info}/ot:ORDER_ID") ?? '';
        $issueDate = $this->getDate("{$info}/ot:ORDER_DATE") ?? new DateTimeImmutable('now');
        $currency = CurrencyCode::tryFrom($this->getValue("{$info}/ot:CURRENCY") ?? 'EUR') ?? CurrencyCode::Euro;

        $buyer = $this->readParty("{$info}/ot:PARTIES", 'buyer');
        $seller = $this->readParty("{$info}/ot:PARTIES", 'supplier');

        $order = new Order(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            salesOrderId: $this->getValue("{$info}/ot:ORDER_SUPPLIER_ORDER_ID")
        );

        foreach ($this->xpath->query('/ot:ORDER/ot:ORDER_ITEM_LIST/ot:ORDER_ITEM') ?: [] as $itemNode) {
            if (!$itemNode instanceof DOMElement) {
                continue;
            }
            $order->addLine($this->parseLine($itemNode, $currency));
        }

        return $order;
    }

    private function parseLine(DOMElement $node, CurrencyCode $currency): OrderLine {
        $id = $this->getNodeValue($node, 'ot:LINE_ITEM_ID') ?? '';
        $quantity = (float) ($this->getNodeValue($node, 'ot:QUANTITY') ?? '0');
        $unitCode = UnitCode::tryFrom($this->getNodeValue($node, 'bmecat:ORDER_UNIT') ?? '') ?? UnitCode::PIECE;
        $unitPrice = Money::ofNullable($this->getNodeValue($node, 'ot:PRODUCT_PRICE_FIX/bmecat:PRICE_AMOUNT'), $currency) ?? Money::zero($currency);
        $netAmount = Money::ofNullable($this->getNodeValue($node, 'ot:PRICE_LINE_AMOUNT'), $currency) ?? $unitPrice->times($quantity);

        return new OrderLine(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_SHORT') ?? '',
            unitPrice: $unitPrice,
            itemDescription: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_LONG'),
            sellersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:SUPPLIER_PID'),
            buyersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:BUYER_PID'),
            standardItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:INTERNATIONAL_PID'),
            note: $this->getNodeValue($node, 'ot:REMARKS')
        );
    }
}
