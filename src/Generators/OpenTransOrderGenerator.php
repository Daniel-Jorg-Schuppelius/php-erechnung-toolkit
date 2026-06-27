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

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\{Order, OrderLine, Party};
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
 * Mapping highlights (openTRANS ← Order):
 *  - ORDER_HEADER/ORDER_INFO/ORDER_ID            ← getId()
 *  - PARTIES/PARTY[buyer|supplier]               ← getBuyer()/getSeller()
 *  - ORDER_ITEM/PRODUCT_ID/SUPPLIER_PID          ← line sellersItemId
 *  - PRODUCT_PRICE_FIX/PRICE_AMOUNT              ← line unitPrice
 *  - ORDER_SUMMARY/TOTAL_AMOUNT                  ← getPayableAmount()
 *
 * @see https://www.opentrans.org
 */
final class OpenTransOrderGenerator {
    use ErrorLog;

    public const OT_NS = 'http://www.opentrans.org/XMLSchema/2.1';
    public const BMECAT_NS = 'http://www.bmecat.org/bmecat/2005';

    private DOMDocument $dom;

    /**
     * Generates an openTRANS 2.1 ORDER XML string for the given order.
     */
    public function generateOrder(Order $order): string {
        $this->logDebug('Generating openTRANS ORDER XML', ['id' => $order->getId()]);

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::OT_NS, 'ORDER');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bmecat', self::BMECAT_NS);
        $root->setAttribute('version', '2.1');
        $root->setAttribute('type', 'standard');
        $this->dom->appendChild($root);

        $root->appendChild($this->header($order));
        $root->appendChild($this->itemList($order));
        $root->appendChild($this->summary($order));

        $xml = $this->dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function header(Order $order): DOMElement {
        $header = $this->ot('ORDER_HEADER');

        $controlInfo = $this->ot('CONTROL_INFO');
        $this->otText($controlInfo, 'GENERATOR_INFO', 'ERechnungToolkit');
        $this->otText($controlInfo, 'GENERATION_DATE', (new DateTimeImmutable)->format('Y-m-d\TH:i:s'));
        $header->appendChild($controlInfo);

        $info = $this->ot('ORDER_INFO');
        $this->otText($info, 'ORDER_ID', $order->getId());
        $this->otText($info, 'ORDER_DATE', $order->getIssueDate()->format('Y-m-d\TH:i:s'));

        if ($order->getSalesOrderId() !== null) {
            $this->otText($info, 'ORDER_SUPPLIER_ORDER_ID', $order->getSalesOrderId());
        }

        $info->appendChild($this->parties($order));
        $info->appendChild($this->partiesReference($order));
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

    private function party(Party $party, string $role): DOMElement {
        $node = $this->ot('PARTY');

        $partyId = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:PARTY_ID', $this->partyId($party));
        $partyId->setAttribute('type', $role . '_specific');
        $node->appendChild($partyId);

        $this->otText($node, 'PARTY_ROLE', $role);

        $address = $this->ot('ADDRESS');
        $this->bmecatText($address, 'NAME', $party->getName());

        if ($party->getContactName() !== null) {
            $contact = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:CONTACT_DETAILS');
            $this->bmecatText($contact, 'CONTACT_NAME', $party->getContactName());
            if ($party->getContactPhone() !== null) {
                $this->bmecatText($contact, 'PHONE', $party->getContactPhone());
            }
            if ($party->getContactEmail() !== null) {
                $this->bmecatText($contact, 'EMAILS', $party->getContactEmail());
            }
            $address->appendChild($contact);
        }

        $postal = $party->getPostalAddress();
        if ($postal !== null) {
            if ($postal->getStreetName() !== null) {
                $street = $postal->getStreetName();
                if ($postal->getBuildingNumber() !== null) {
                    $street .= ' ' . $postal->getBuildingNumber();
                }
                $this->bmecatText($address, 'STREET', $street);
            }
            if ($postal->getPostalCode() !== null) {
                $this->bmecatText($address, 'ZIP', $postal->getPostalCode());
            }
            if ($postal->getCity() !== null) {
                $this->bmecatText($address, 'CITY', $postal->getCity());
            }
            if ($postal->getCountryCode() !== null) {
                $this->bmecatText($address, 'COUNTRY_CODED', $postal->getCountryCode());
            }
        }

        if ($party->getVatId() !== null) {
            $this->bmecatText($address, 'VAT_ID', $party->getVatId());
        }

        $node->appendChild($address);

        return $node;
    }

    private function partiesReference(Order $order): DOMElement {
        $ref = $this->ot('ORDER_PARTIES_REFERENCE');

        $buyerRef = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:BUYER_IDREF', $this->partyId($order->getBuyer()));
        $buyerRef->setAttribute('type', 'buyer_specific');
        $ref->appendChild($buyerRef);

        $supplierRef = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:SUPPLIER_IDREF', $this->partyId($order->getSeller()));
        $supplierRef->setAttribute('type', 'supplier_specific');
        $ref->appendChild($supplierRef);

        return $ref;
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

    /** Best available stable identifier for a party (endpoint → VAT → name). */
    private function partyId(Party $party): string {
        return $party->getEndpointId() ?? $party->getVatId() ?? $party->getName();
    }

    private function ot(string $name): DOMElement {
        return $this->dom->createElementNS(self::OT_NS, $name);
    }

    private function otText(DOMElement $parent, string $name, string $value): void {
        $node = $this->dom->createElementNS(self::OT_NS, $name);
        $node->appendChild($this->dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private function bmecatText(DOMElement $parent, string $name, string $value): void {
        $node = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:' . $name);
        $node->appendChild($this->dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private function amount(float $value): string {
        return number_format($value, 2, '.', '');
    }

    /** Quantity without trailing zeros (e.g. 5.0 → "5", 1.5 → "1.5"). */
    private function number(float $value): string {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
