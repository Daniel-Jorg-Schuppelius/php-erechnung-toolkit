<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMElement;
use ERechnungToolkit\Contracts\OpenTransNamespaceInterface;
use ERechnungToolkit\Entities\{Order, OrderLine};
use ERechnungToolkit\Traits\OpenTransWriterTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for openTRANS 2.1 ORDER documents.
 *
 * openTRANS is the German business-document standard maintained alongside BMEcat
 * (Fraunhofer IAO / bvse). An ORDER reuses BMEcat element definitions for product
 * and address data, hence the `bmecat` namespace next to the openTRANS one.
 *
 * The same {@see Order} entity that feeds the UBL Order / Order-X generators is
 * mapped here, so a single order can be emitted as XBestellung, Order-X *and*
 * openTRANS without rebuilding the document.
 *
 * Mapping highlights (openTRANS <- Order):
 *  - ORDER_HEADER/ORDER_INFO/ORDER_ID            <- getId()
 *  - PARTIES/PARTY[buyer|supplier]               <- getBuyer()/getSeller()
 *  - ORDER_ITEM/PRODUCT_ID/SUPPLIER_PID          <- line sellersItemId
 *  - PRODUCT_PRICE_FIX/PRICE_AMOUNT              <- line unitPrice
 *  - ORDER_SUMMARY/TOTAL_AMOUNT                  <- getPayableAmount()
 *
 * @see https://www.opentrans.org
 */
final class OpenTransOrderGenerator implements OpenTransNamespaceInterface {
    use ErrorLog;
    use OpenTransWriterTrait;

    /**
     * Generates an openTRANS 2.1 ORDER XML string for the given order.
     */
    public function generateOrder(Order $order): string {
        $this->logDebug('Generating openTRANS ORDER XML', ['id' => $order->getId()]);

        $root = $this->openTransRoot('ORDER');
        $root->setAttribute('type', 'standard');

        $root->appendChild($this->header($order));
        $root->appendChild($this->itemList($order));
        $root->appendChild($this->summary($order));

        $xml = $this->dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function header(Order $order): DOMElement {
        $header = $this->ot('ORDER_HEADER');

        $header->appendChild($this->controlInfo());

        $info = $this->ot('ORDER_INFO');
        $this->otText($info, 'ORDER_ID', $order->getId());
        $this->otText($info, 'ORDER_DATE', $order->getIssueDate()->format('Y-m-d\TH:i:s'));

        if ($order->getSalesOrderId() !== null) {
            $this->otText($info, 'ORDER_SUPPLIER_ORDER_ID', $order->getSalesOrderId());
        }

        $info->appendChild($this->parties($order));
        $info->appendChild($this->partiesReference($order->getBuyer(), $order->getSeller()));
        $this->otText($info, 'CURRENCY', $order->getCurrency()->value);

        $header->appendChild($info);

        return $header;
    }

    private function parties(Order $order): DOMElement {
        $parties = $this->ot('PARTIES');
        $parties->appendChild($this->party($order->getBuyer(), 'buyer'));
        $parties->appendChild($this->party($order->getSeller(), 'supplier'));

        return $parties;
    }

    private function itemList(Order $order): DOMElement {
        $list = $this->ot('ORDER_ITEM_LIST');
        foreach ($order->getLines() as $line) {
            $list->appendChild($this->item($line, $order->getCurrency()->value));
        }

        return $list;
    }

    private function item(OrderLine $line, string $currency): DOMElement {
        $item = $this->ot('ORDER_ITEM');
        $this->otText($item, 'LINE_ITEM_ID', $line->getId());

        $productId = $this->ot('PRODUCT_ID');
        if ($line->getSellersItemId() !== null) {
            $this->bmecatText($productId, 'SUPPLIER_PID', $line->getSellersItemId());
        }
        if ($line->getBuyersItemId() !== null) {
            $this->bmecatText($productId, 'BUYER_PID', $line->getBuyersItemId());
        }
        if ($line->getStandardItemId() !== null) {
            $intId = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:INTERNATIONAL_PID', $line->getStandardItemId());
            $intId->setAttribute('type', strtolower($line->getStandardItemScheme() ?? 'gtin'));
            $productId->appendChild($intId);
        }
        $this->bmecatText($productId, 'DESCRIPTION_SHORT', $line->getItemName());
        if ($line->getItemDescription() !== null) {
            $this->bmecatText($productId, 'DESCRIPTION_LONG', $line->getItemDescription());
        }
        $item->appendChild($productId);

        $this->otText($item, 'QUANTITY', $this->number($line->getQuantity()));
        $orderUnit = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:ORDER_UNIT', $line->getUnitCode()->value);
        $item->appendChild($orderUnit);

        $priceFix = $this->ot('PRODUCT_PRICE_FIX');
        $this->bmecatText($priceFix, 'PRICE_AMOUNT', $this->amount($line->getUnitPrice()));
        $this->bmecatText($priceFix, 'PRICE_CURRENCY', $currency);
        $item->appendChild($priceFix);

        $this->otText($item, 'PRICE_LINE_AMOUNT', $this->amount($line->getNetAmount()));

        if ($line->getNote() !== null) {
            $remark = $this->ot('REMARKS');
            $remark->setAttribute('type', 'order');
            $remark->appendChild($this->dom->createTextNode($line->getNote()));
            $item->appendChild($remark);
        }

        return $item;
    }

    private function summary(Order $order): DOMElement {
        $summary = $this->ot('ORDER_SUMMARY');
        $this->otText($summary, 'TOTAL_ITEM_NUM', (string) $order->countLines());
        $this->otText($summary, 'TOTAL_AMOUNT', $this->amount($order->getPayableAmount()));

        return $summary;
    }
}
