<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderResponseGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMElement;
use ERechnungToolkit\Contracts\OpenTransNamespaceInterface;
use ERechnungToolkit\Entities\{OrderResponse, OrderResponseLine};
use ERechnungToolkit\Traits\OpenTransWriterTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for openTRANS 2.1 ORDERRESPONSE documents (order confirmation),
 * the supplier's answer to an openTRANS ORDER.
 *
 * Mapping highlights (openTRANS <- OrderResponse):
 *  - ORDERRESPONSE_INFO/ORDER_ID                 <- getOrderId() (buyer's order)
 *  - ORDERRESPONSE_INFO/SUPPLIER_ORDER_ID        <- getSupplierOrderId()
 *  - ORDERRESPONSE_ITEM/LINE_ITEM_ID             <- line getLineId()
 *  - ORDERRESPONSE_ITEM/DELIVERY_DATE            <- line getDeliveryDate()
 */
final class OpenTransOrderResponseGenerator implements OpenTransNamespaceInterface {
    use ErrorLog;
    use OpenTransWriterTrait;

    public function generate(OrderResponse $response): string {
        $this->logDebug('Generating openTRANS ORDERRESPONSE XML', ['order' => $response->getOrderId()]);

        $root = $this->openTransRoot('ORDERRESPONSE');

        $header = $this->ot('ORDERRESPONSE_HEADER');
        $header->appendChild($this->controlInfo());
        $info = $this->ot('ORDERRESPONSE_INFO');
        $this->otText($info, 'ORDER_ID', $response->getOrderId());
        $this->otText($info, 'ORDERRESPONSE_DATE', $response->getResponseDate()->format('Y-m-d\TH:i:s'));
        if ($response->getSupplierOrderId() !== null) {
            $this->otText($info, 'SUPPLIER_ORDER_ID', $response->getSupplierOrderId());
        }
        if ($response->getDeliveryDate() !== null) {
            $this->deliveryDate($info, $response->getDeliveryDate());
        }
        $parties = $this->ot('PARTIES');
        $parties->appendChild($this->party($response->getBuyer(), 'buyer'));
        $parties->appendChild($this->party($response->getSeller(), 'supplier'));
        $info->appendChild($parties);
        $info->appendChild($this->partiesReference($response->getBuyer(), $response->getSeller()));
        $this->otText($info, 'CURRENCY', $response->getCurrency()->value);
        if ($response->getNote() !== null) {
            $this->otText($info, 'REMARKS', $response->getNote());
        }
        $header->appendChild($info);
        $root->appendChild($header);

        $list = $this->ot('ORDERRESPONSE_ITEM_LIST');
        foreach ($response->getLines() as $line) {
            $list->appendChild($this->item($line, $response->getCurrency()->value));
        }
        $root->appendChild($list);

        $summary = $this->ot('ORDERRESPONSE_SUMMARY');
        $this->otText($summary, 'TOTAL_ITEM_NUM', (string) count($response->getLines()));
        $root->appendChild($summary);

        $xml = $this->dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function item(OrderResponseLine $line, string $currency): DOMElement {
        $item = $this->ot('ORDERRESPONSE_ITEM');
        $this->otText($item, 'LINE_ITEM_ID', $line->getLineId());

        $productId = $this->ot('PRODUCT_ID');
        if ($line->getSellersItemId() !== null) {
            $this->bmecatText($productId, 'SUPPLIER_PID', $line->getSellersItemId());
        }
        if ($line->getBuyersItemId() !== null) {
            $this->bmecatText($productId, 'BUYER_PID', $line->getBuyersItemId());
        }
        if ($line->getItemName() !== '') {
            $this->bmecatText($productId, 'DESCRIPTION_SHORT', $line->getItemName());
        }
        $item->appendChild($productId);

        $this->otText($item, 'QUANTITY', $this->number($line->getQuantity()));
        $item->appendChild($this->dom->createElementNS(self::BMECAT_NS, 'bmecat:ORDER_UNIT', $line->getUnitCode()->value));

        if ($line->getUnitPrice() !== null) {
            $priceFix = $this->ot('PRODUCT_PRICE_FIX');
            $this->bmecatText($priceFix, 'PRICE_AMOUNT', $this->amount($line->getUnitPrice()));
            $this->bmecatText($priceFix, 'PRICE_CURRENCY', $currency);
            $item->appendChild($priceFix);
        }
        if ($line->getDeliveryDate() !== null) {
            $this->deliveryDate($item, $line->getDeliveryDate());
        }
        if ($line->getNote() !== null) {
            $this->otText($item, 'REMARKS', $line->getNote());
        }

        return $item;
    }
}
