<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransDispatchNotificationGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMElement;
use ERechnungToolkit\Contracts\OpenTransNamespaceInterface;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine};
use ERechnungToolkit\Traits\OpenTransWriterTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for openTRANS 2.1 DISPATCHNOTIFICATION documents (delivery note).
 * Maps the same {@see DespatchAdvice} entity as the UBL generator, so one
 * delivery can be emitted as Peppol Despatch Advice or openTRANS.
 *
 * Mapping highlights (openTRANS <- DespatchAdvice):
 *  - DISPATCHNOTIFICATION_ID                     <- getId()
 *  - DELIVERY_DATE                               <- getActualDeliveryDate()
 *  - SHIPMENT_ID                                 <- getShipmentId()
 *  - ITEM/ORDER_REFERENCE/ORDER_ID               <- getOrderReference()
 *  - ITEM/ORDER_REFERENCE/LINE_ITEM_ID           <- line getOrderLineId()
 */
final class OpenTransDispatchNotificationGenerator implements OpenTransNamespaceInterface {
    use ErrorLog;
    use OpenTransWriterTrait;

    public function generate(DespatchAdvice $advice): string {
        $this->logDebug('Generating openTRANS DISPATCHNOTIFICATION XML', ['id' => $advice->getId()]);

        $root = $this->openTransRoot('DISPATCHNOTIFICATION');

        $header = $this->ot('DISPATCHNOTIFICATION_HEADER');
        $header->appendChild($this->controlInfo());
        $info = $this->ot('DISPATCHNOTIFICATION_INFO');
        $this->otText($info, 'DISPATCHNOTIFICATION_ID', $advice->getId());
        $this->otText($info, 'DISPATCHNOTIFICATION_DATE', $advice->getIssueDate()->format('Y-m-d\TH:i:s'));
        if ($advice->getActualDeliveryDate() !== null) {
            $this->deliveryDate($info, $advice->getActualDeliveryDate());
        }
        $parties = $this->ot('PARTIES');
        $parties->appendChild($this->party($advice->getDeliveryCustomerParty(), 'buyer'));
        $parties->appendChild($this->party($advice->getDespatchSupplierParty(), 'supplier'));
        $parties->appendChild($this->party($advice->getDeliveryCustomerParty(), 'delivery'));
        $info->appendChild($parties);

        $supplierRef = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:SUPPLIER_IDREF', $this->partyId($advice->getDespatchSupplierParty()));
        $supplierRef->setAttribute('type', 'supplier_specific');
        $info->appendChild($supplierRef);
        $shipmentRef = $this->ot('SHIPMENT_PARTIES_REFERENCE');
        $deliveryRef = $this->ot('DELIVERY_IDREF');
        $deliveryRef->setAttribute('type', 'delivery_specific');
        $deliveryRef->appendChild($this->dom->createTextNode($this->partyId($advice->getDeliveryCustomerParty())));
        $shipmentRef->appendChild($deliveryRef);
        $info->appendChild($shipmentRef);
        $this->otText($info, 'SHIPMENT_ID', $advice->getShipmentId());
        $header->appendChild($info);
        $root->appendChild($header);

        $list = $this->ot('DISPATCHNOTIFICATION_ITEM_LIST');
        foreach ($advice->getLines() as $line) {
            $list->appendChild($this->item($line, $advice->getOrderReference()));
        }
        $root->appendChild($list);

        $summary = $this->ot('DISPATCHNOTIFICATION_SUMMARY');
        $this->otText($summary, 'TOTAL_ITEM_NUM', (string) count($advice->getLines()));
        $root->appendChild($summary);

        $xml = $this->dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function item(DespatchLine $line, ?string $orderId): DOMElement {
        $item = $this->ot('DISPATCHNOTIFICATION_ITEM');
        $this->otText($item, 'LINE_ITEM_ID', $line->getId());

        $productId = $this->ot('PRODUCT_ID');
        if ($line->getSellersItemId() !== null) {
            $this->bmecatText($productId, 'SUPPLIER_PID', $line->getSellersItemId());
        }
        if ($line->getBuyersItemId() !== null) {
            $this->bmecatText($productId, 'BUYER_PID', $line->getBuyersItemId());
        }
        $this->bmecatText($productId, 'DESCRIPTION_SHORT', $line->getItemName());
        $item->appendChild($productId);

        $this->otText($item, 'QUANTITY', $this->number($line->getDeliveredQuantity()));
        $item->appendChild($this->dom->createElementNS(self::BMECAT_NS, 'bmecat:ORDER_UNIT', $line->getUnitCode()->value));

        if ($orderId !== null) {
            $reference = $this->ot('ORDER_REFERENCE');
            $this->otText($reference, 'ORDER_ID', $orderId);
            if ($line->getOrderLineId() !== null) {
                $this->otText($reference, 'LINE_ITEM_ID', $line->getOrderLineId());
            }
            $item->appendChild($reference);
        }
        if ($line->getNote() !== null) {
            $this->otText($item, 'REMARKS', $line->getNote());
        }

        return $item;
    }
}
