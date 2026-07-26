<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine, PostalAddress};
use ERRORToolkit\Traits\ErrorLog;
use RuntimeException;

/**
 * Generator for UBL Despatch Advice XML (Peppol BIS Despatch Advice).
 *
 * Party, postal address and the primitive element/amount serialization are
 * shared with the invoice and order generators via {@see UblSerializer}; only
 * the despatch advice envelope and the despatch-line structure are specific.
 *
 * The element order follows the OASIS UBL 2.1 DespatchAdvice schema sequence.
 */
final class DespatchAdviceGenerator {
    use ErrorLog;

    private const DA_NS = 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2';
    private const CAC_NS = UblSerializer::CAC_NS;

    /** UNCL 1001 code for a despatch advice. */
    private const TYPE_CODE = '351';

    private UblSerializer $ubl;

    public function __construct() {
        $this->ubl = new UblSerializer;
    }

    /**
     * Generates UBL Despatch Advice XML for the given document.
     */
    public function generateUbl(DespatchAdvice $advice): string {
        $this->logDebug('Generating UBL Despatch Advice XML', ['id' => $advice->getId()]);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::DA_NS, 'DespatchAdvice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', UblSerializer::CAC_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', UblSerializer::CBC_NS);
        $dom->appendChild($root);

        $this->ubl->element($dom, $root, 'cbc:CustomizationID', $advice->getProfile()->customizationId());
        $this->ubl->element($dom, $root, 'cbc:ProfileID', $advice->getProfile()->profileId());
        $this->ubl->element($dom, $root, 'cbc:ID', $advice->getId());
        $this->ubl->element($dom, $root, 'cbc:IssueDate', $advice->getIssueDate()->format('Y-m-d'));
        $this->ubl->element($dom, $root, 'cbc:DespatchAdviceTypeCode', self::TYPE_CODE);

        foreach ($advice->getNotes() as $note) {
            $this->ubl->element($dom, $root, 'cbc:Note', $note);
        }

        // OrderReference (the order this delivery fulfills)
        if ($advice->getOrderReference() !== null) {
            $orderRef = $dom->createElementNS(self::CAC_NS, 'cac:OrderReference');
            $this->ubl->element($dom, $orderRef, 'cbc:ID', $advice->getOrderReference());
            if ($advice->getSalesOrderId() !== null) {
                $this->ubl->element($dom, $orderRef, 'cbc:SalesOrderID', $advice->getSalesOrderId());
            }
            $root->appendChild($orderRef);
        }

        // DespatchSupplierParty (sender of the goods)
        $supplier = $dom->createElementNS(self::CAC_NS, 'cac:DespatchSupplierParty');
        $supplier->appendChild($this->ubl->party($dom, $advice->getDespatchSupplierParty()));
        $root->appendChild($supplier);

        // DeliveryCustomerParty (recipient of the goods)
        $customer = $dom->createElementNS(self::CAC_NS, 'cac:DeliveryCustomerParty');
        $customer->appendChild($this->ubl->party($dom, $advice->getDeliveryCustomerParty()));
        $root->appendChild($customer);

        // Shipment
        $root->appendChild($this->createShipment($dom, $advice));

        // DespatchLines
        foreach ($advice->getLines() as $line) {
            $root->appendChild($this->createDespatchLine($dom, $line));
        }

        $xml = $dom->saveXML();
        if ($xml === false) {
            self::logErrorAndThrow(RuntimeException::class, 'XML-Dokument konnte nicht serialisiert werden.');
        }

        return $xml;
    }

    private function createShipment(DOMDocument $dom, DespatchAdvice $advice): DOMElement {
        $shipment = $dom->createElementNS(self::CAC_NS, 'cac:Shipment');
        $this->ubl->element($dom, $shipment, 'cbc:ID', $advice->getShipmentId());

        if ($advice->getActualDeliveryDate() !== null || $advice->getDeliveryAddress() !== null) {
            $delivery = $dom->createElementNS(self::CAC_NS, 'cac:Delivery');
            if ($advice->getActualDeliveryDate() !== null) {
                $this->ubl->element($dom, $delivery, 'cbc:ActualDeliveryDate', $advice->getActualDeliveryDate()->format('Y-m-d'));
            }
            if ($advice->getDeliveryAddress() !== null) {
                $location = $dom->createElementNS(self::CAC_NS, 'cac:DeliveryLocation');
                $location->appendChild($this->createAddress($dom, $advice->getDeliveryAddress()));
                $delivery->appendChild($location);
            }
            $shipment->appendChild($delivery);
        }

        return $shipment;
    }

    private function createDespatchLine(DOMDocument $dom, DespatchLine $line): DOMElement {
        $despatchLine = $dom->createElementNS(self::CAC_NS, 'cac:DespatchLine');

        $this->ubl->element($dom, $despatchLine, 'cbc:ID', $line->getId());

        if ($line->getNote() !== null) {
            $this->ubl->element($dom, $despatchLine, 'cbc:Note', $line->getNote());
        }

        $qty = $this->ubl->element($dom, $despatchLine, 'cbc:DeliveredQuantity', $this->ubl->amount($line->getDeliveredQuantity()));
        $qty->setAttribute('unitCode', $line->getUnitCode()->value);

        if ($line->getBackorderQuantity() !== null) {
            $backorder = $this->ubl->element($dom, $despatchLine, 'cbc:BackorderQuantity', $this->ubl->amount($line->getBackorderQuantity()));
            $backorder->setAttribute('unitCode', $line->getUnitCode()->value);
            if ($line->getBackorderReason() !== null) {
                $this->ubl->element($dom, $despatchLine, 'cbc:BackorderReason', $line->getBackorderReason());
            }
        }

        // OrderLineReference (link back to the originating order line)
        if ($line->getOrderLineId() !== null) {
            $orderLineRef = $dom->createElementNS(self::CAC_NS, 'cac:OrderLineReference');
            $this->ubl->element($dom, $orderLineRef, 'cbc:LineID', $line->getOrderLineId());
            $despatchLine->appendChild($orderLineRef);
        }

        // Item
        $despatchLine->appendChild($this->createItem($dom, $line));

        return $despatchLine;
    }

    private function createItem(DOMDocument $dom, DespatchLine $line): DOMElement {
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

        return $item;
    }

    private function createAddress(DOMDocument $dom, PostalAddress $address): DOMElement {
        $addr = $dom->createElementNS(self::CAC_NS, 'cac:Address');

        if ($address->getStreetName() !== null) {
            $this->ubl->element($dom, $addr, 'cbc:StreetName', $address->getStreetName());
        }
        if ($address->getAdditionalStreetName() !== null) {
            $this->ubl->element($dom, $addr, 'cbc:AdditionalStreetName', $address->getAdditionalStreetName());
        }
        if ($address->getCity() !== null) {
            $this->ubl->element($dom, $addr, 'cbc:CityName', $address->getCity());
        }
        if ($address->getPostalCode() !== null) {
            $this->ubl->element($dom, $addr, 'cbc:PostalZone', $address->getPostalCode());
        }
        if ($address->getCountryCode() !== null) {
            $country = $dom->createElementNS(self::CAC_NS, 'cac:Country');
            $this->ubl->element($dom, $country, 'cbc:IdentificationCode', $address->getCountryCode());
            $addr->appendChild($country);
        }

        return $addr;
    }
}
