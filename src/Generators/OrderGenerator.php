<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\{Order, OrderLine};
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator for UBL Order XML output (Peppol BIS Order only / XBestellung).
 *
 * Emits a `Order` document in the UBL Order-2 namespace. Party, postal address
 * and allowance/charge serialization is shared with the invoice generator via
 * {@see UblSerializer}; only the order envelope and the order-line structure are
 * order-specific.
 *
 * The element order follows the OASIS UBL 2.1 Order schema sequence so the
 * output is schema-valid.
 */
final class OrderGenerator {
    use ErrorLog;

    private const ORDER_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Order-2';
    private const CAC_NS = UblSerializer::CAC_NS;

    /** UNTDID 1001 code for a commercial order. */
    private const ORDER_TYPE_CODE = '220';

    private UblSerializer $ubl;

    public function __construct() {
        $this->ubl = new UblSerializer;
    }

    /**
     * Generates UBL Order XML for the given order document.
     */
    public function generateUbl(Order $order): string {
        $this->logDebug('Generating UBL Order XML', ['id' => $order->getId(), 'profile' => $order->getProfile()->name]);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::ORDER_NS, 'Order');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', UblSerializer::CAC_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', UblSerializer::CBC_NS);
        $dom->appendChild($root);

        $currency = $order->getCurrency()->value;

        // CustomizationID / ProfileID
        $this->ubl->element($dom, $root, 'cbc:CustomizationID', $order->getProfile()->customizationId());
        $this->ubl->element($dom, $root, 'cbc:ProfileID', $order->getProfile()->profileId());

        // ID (Order number)
        $this->ubl->element($dom, $root, 'cbc:ID', $order->getId());

        // SalesOrderID (seller's order reference, optional)
        if ($order->getSalesOrderId() !== null) {
            $this->ubl->element($dom, $root, 'cbc:SalesOrderID', $order->getSalesOrderId());
        }

        // IssueDate
        $this->ubl->element($dom, $root, 'cbc:IssueDate', $order->getIssueDate()->format('Y-m-d'));

        // OrderTypeCode (220 = commercial order)
        $this->ubl->element($dom, $root, 'cbc:OrderTypeCode', self::ORDER_TYPE_CODE);

        // Notes
        foreach ($order->getNotes() as $note) {
            $this->ubl->element($dom, $root, 'cbc:Note', $note);
        }

        // DocumentCurrencyCode
        $this->ubl->element($dom, $root, 'cbc:DocumentCurrencyCode', $currency);

        // OriginatorDocumentReference (e.g. requisition / catalogue request)
        if ($order->getOriginatorDocumentReference() !== null) {
            $originatorRef = $dom->createElementNS(self::CAC_NS, 'cac:OriginatorDocumentReference');
            $this->ubl->element($dom, $originatorRef, 'cbc:ID', $order->getOriginatorDocumentReference());
            $root->appendChild($originatorRef);
        }

        // Contract
        if ($order->getContractReference() !== null) {
            $contract = $dom->createElementNS(self::CAC_NS, 'cac:Contract');
            $this->ubl->element($dom, $contract, 'cbc:ID', $order->getContractReference());
            $root->appendChild($contract);
        }

        // BuyerCustomerParty (the ordering party / originator)
        $buyerParty = $dom->createElementNS(self::CAC_NS, 'cac:BuyerCustomerParty');
        $buyerParty->appendChild($this->ubl->party($dom, $order->getBuyer()));
        $root->appendChild($buyerParty);

        // SellerSupplierParty (the supplier / recipient)
        $sellerParty = $dom->createElementNS(self::CAC_NS, 'cac:SellerSupplierParty');
        $sellerParty->appendChild($this->ubl->party($dom, $order->getSeller()));
        $root->appendChild($sellerParty);

        // Delivery (requested delivery period)
        $this->appendDelivery($dom, $root, $order);

        // Document-level AllowanceCharge
        foreach ($order->getAllowanceCharges() as $ac) {
            $root->appendChild($this->ubl->allowanceCharge($dom, $ac, $currency));
        }

        // AnticipatedMonetaryTotal
        $this->appendAnticipatedMonetaryTotal($dom, $root, $order, $currency);

        // OrderLines
        foreach ($order->getLines() as $line) {
            $root->appendChild($this->createOrderLine($dom, $line, $currency));
        }

        return $dom->saveXML();
    }

    private function appendDelivery(DOMDocument $dom, DOMElement $root, Order $order): void {
        $start = $order->getRequestedDeliveryStartDate();
        $end = $order->getRequestedDeliveryEndDate();

        if ($start === null && $end === null) {
            return;
        }

        $delivery = $dom->createElementNS(self::CAC_NS, 'cac:Delivery');
        $period = $dom->createElementNS(self::CAC_NS, 'cac:RequestedDeliveryPeriod');
        if ($start !== null) {
            $this->ubl->element($dom, $period, 'cbc:StartDate', $start->format('Y-m-d'));
        }
        if ($end !== null) {
            $this->ubl->element($dom, $period, 'cbc:EndDate', $end->format('Y-m-d'));
        }
        $delivery->appendChild($period);
        $root->appendChild($delivery);
    }

    private function appendAnticipatedMonetaryTotal(
        DOMDocument $dom,
        DOMElement $root,
        Order $order,
        string $currency
    ): void {
        $total = $dom->createElementNS(self::CAC_NS, 'cac:AnticipatedMonetaryTotal');

        $lineExt = $this->ubl->element($dom, $total, 'cbc:LineExtensionAmount', $this->ubl->amount($order->getLineExtensionAmount()));
        $lineExt->setAttribute('currencyID', $currency);

        if ($order->getAllowanceTotalAmount() > 0) {
            $allowance = $this->ubl->element($dom, $total, 'cbc:AllowanceTotalAmount', $this->ubl->amount($order->getAllowanceTotalAmount()));
            $allowance->setAttribute('currencyID', $currency);
        }

        if ($order->getChargeTotalAmount() > 0) {
            $charge = $this->ubl->element($dom, $total, 'cbc:ChargeTotalAmount', $this->ubl->amount($order->getChargeTotalAmount()));
            $charge->setAttribute('currencyID', $currency);
        }

        $payable = $this->ubl->element($dom, $total, 'cbc:PayableAmount', $this->ubl->amount($order->getPayableAmount()));
        $payable->setAttribute('currencyID', $currency);

        $root->appendChild($total);
    }

    private function createOrderLine(DOMDocument $dom, OrderLine $line, string $currency): DOMElement {
        $orderLine = $dom->createElementNS(self::CAC_NS, 'cac:OrderLine');
        $lineItem = $dom->createElementNS(self::CAC_NS, 'cac:LineItem');

        // ID
        $this->ubl->element($dom, $lineItem, 'cbc:ID', $line->getId());

        // Note
        if ($line->getNote() !== null) {
            $this->ubl->element($dom, $lineItem, 'cbc:Note', $line->getNote());
        }

        // Quantity
        $qty = $this->ubl->element($dom, $lineItem, 'cbc:Quantity', $this->ubl->amount($line->getQuantity()));
        $qty->setAttribute('unitCode', $line->getUnitCode()->value);

        // LineExtensionAmount
        $lineExt = $this->ubl->element($dom, $lineItem, 'cbc:LineExtensionAmount', $this->ubl->amount($line->getNetAmount()));
        $lineExt->setAttribute('currencyID', $currency);

        // PartialDeliveryIndicator
        if ($line->getPartialDeliveryAllowed() !== null) {
            $this->ubl->element($dom, $lineItem, 'cbc:PartialDeliveryIndicator', $line->getPartialDeliveryAllowed() ? 'true' : 'false');
        }

        // Price
        $price = $dom->createElementNS(self::CAC_NS, 'cac:Price');
        $priceAmount = $this->ubl->element($dom, $price, 'cbc:PriceAmount', $this->ubl->amount($line->getUnitPrice()));
        $priceAmount->setAttribute('currencyID', $currency);
        if ($line->getBaseQuantity() !== null && $line->getBaseQuantity() !== 1.0) {
            $baseQty = $this->ubl->element($dom, $price, 'cbc:BaseQuantity', $this->ubl->amount($line->getBaseQuantity()));
            $baseQty->setAttribute('unitCode', $line->getUnitCode()->value);
        }
        $lineItem->appendChild($price);

        // Item
        $lineItem->appendChild($this->createItem($dom, $line));

        $orderLine->appendChild($lineItem);
        return $orderLine;
    }

    private function createItem(DOMDocument $dom, OrderLine $line): DOMElement {
        $item = $dom->createElementNS(self::CAC_NS, 'cac:Item');

        if ($line->getItemDescription() !== null) {
            $this->ubl->element($dom, $item, 'cbc:Description', $line->getItemDescription());
        }
        $this->ubl->element($dom, $item, 'cbc:Name', $line->getItemName());

        if ($line->getBuyersItemId() !== null) {
            $buyersItem = $dom->createElementNS(self::CAC_NS, 'cac:BuyersItemIdentification');
            $this->ubl->element($dom, $buyersItem, 'cbc:ID', $line->getBuyersItemId());
            $item->appendChild($buyersItem);
        }

        if ($line->getSellersItemId() !== null) {
            $sellersItem = $dom->createElementNS(self::CAC_NS, 'cac:SellersItemIdentification');
            $this->ubl->element($dom, $sellersItem, 'cbc:ID', $line->getSellersItemId());
            $item->appendChild($sellersItem);
        }

        if ($line->getStandardItemId() !== null) {
            $standardItem = $dom->createElementNS(self::CAC_NS, 'cac:StandardItemIdentification');
            $stdId = $this->ubl->element($dom, $standardItem, 'cbc:ID', $line->getStandardItemId());
            if ($line->getStandardItemScheme() !== null) {
                $stdId->setAttribute('schemeID', $line->getStandardItemScheme());
            }
            $item->appendChild($standardItem);
        }

        // ClassifiedTaxCategory (optional in orders)
        if ($line->getTaxCategory() !== null) {
            $taxCategory = $dom->createElementNS(self::CAC_NS, 'cac:ClassifiedTaxCategory');
            $this->ubl->element($dom, $taxCategory, 'cbc:ID', $line->getTaxCategory()->value);
            if ($line->getTaxPercent() !== null) {
                $this->ubl->element($dom, $taxCategory, 'cbc:Percent', $this->ubl->amount($line->getTaxPercent()));
            }
            $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->ubl->element($dom, $taxScheme, 'cbc:ID', 'VAT');
            $taxCategory->appendChild($taxScheme);
            $item->appendChild($taxCategory);
        }

        return $item;
    }
}
