<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransDispatchNotificationParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use DateTimeImmutable;
use DOMElement;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine};
use ERechnungToolkit\Enums\DespatchAdviceProfile;
use ERechnungToolkit\Traits\OpenTransReaderTrait;
use ERRORToolkit\Traits\ErrorLog;

/**
 * Parser for openTRANS 2.1 DISPATCHNOTIFICATION documents (delivery note) onto
 * the shared {@see DespatchAdvice} entity, so consumers of the UBL
 * {@see DespatchAdviceParser} handle both formats alike. The order reference
 * comes from the first item's ORDER_REFERENCE (openTRANS keeps it per item).
 */
final class OpenTransDispatchNotificationParser {
    use ErrorLog;
    use OpenTransReaderTrait;

    public function parse(string $xml): DespatchAdvice {
        $this->loadOpenTrans($xml, 'DISPATCHNOTIFICATION');
        $info = '/ot:DISPATCHNOTIFICATION/ot:DISPATCHNOTIFICATION_HEADER/ot:DISPATCHNOTIFICATION_INFO';
        $items = '/ot:DISPATCHNOTIFICATION/ot:DISPATCHNOTIFICATION_ITEM_LIST/ot:DISPATCHNOTIFICATION_ITEM';

        $customer = $this->readParty("{$info}/ot:PARTIES", 'buyer');
        if ($customer->getName() === '') {
            $customer = $this->readParty("{$info}/ot:PARTIES", 'delivery');
        }

        $advice = new DespatchAdvice(
            id: $this->getValue("{$info}/ot:DISPATCHNOTIFICATION_ID") ?? '',
            issueDate: $this->getDate("{$info}/ot:DISPATCHNOTIFICATION_DATE") ?? new DateTimeImmutable('now'),
            despatchSupplierParty: $this->readParty("{$info}/ot:PARTIES", 'supplier'),
            deliveryCustomerParty: $customer,
            profile: DespatchAdviceProfile::OPENTRANS_DISPATCHNOTIFICATION,
            orderReference: $this->getValue("{$items}/ot:ORDER_REFERENCE/ot:ORDER_ID"),
            shipmentId: $this->getValue("{$info}/ot:SHIPMENT_ID") ?? '1',
            actualDeliveryDate: $this->getDate("{$info}/ot:DELIVERY_DATE/ot:DELIVERY_START_DATE")
        );

        foreach ($this->xpath->query($items) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $advice->addLine($this->line($node));
            }
        }

        return $advice;
    }

    public function parseFile(string $filePath): DespatchAdvice {
        return $this->parse($this->readOpenTransFile($filePath));
    }

    private function line(DOMElement $node): DespatchLine {
        return new DespatchLine(
            id: $this->getNodeValue($node, 'ot:LINE_ITEM_ID') ?? '',
            deliveredQuantity: (float) ($this->getNodeValue($node, 'ot:QUANTITY') ?? '0'),
            unitCode: $this->getNodeValue($node, 'bmecat:ORDER_UNIT') ?? '',
            itemName: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_SHORT') ?? '',
            orderLineId: $this->getNodeValue($node, 'ot:ORDER_REFERENCE/ot:LINE_ITEM_ID'),
            sellersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:SUPPLIER_PID'),
            buyersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:BUYER_PID'),
            note: $this->getNodeValue($node, 'ot:REMARKS')
        );
    }
}
