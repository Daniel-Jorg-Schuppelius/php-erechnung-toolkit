<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransWriterTrait.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Traits;

use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\Party;

/**
 * Shared building blocks of the openTRANS 2.1 generators (ORDER,
 * ORDERRESPONSE, DISPATCHNOTIFICATION): namespaced elements, the BMEcat party
 * block and number formatting. The using class implements
 * {@see \ERechnungToolkit\Contracts\OpenTransNamespaceInterface}.
 */
trait OpenTransWriterTrait {
    private DOMDocument $dom;

    /** Creates the document with the root element and the bmecat namespace declaration. */
    private function openTransRoot(string $name): DOMElement {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::OT_NS, $name);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:bmecat', self::BMECAT_NS);
        $root->setAttribute('version', '2.1');
        $this->dom->appendChild($root);

        return $root;
    }

    private function controlInfo(): DOMElement {
        $controlInfo = $this->ot('CONTROL_INFO');
        $this->otText($controlInfo, 'GENERATOR_INFO', 'ERechnungToolkit');
        $this->otText($controlInfo, 'GENERATION_DATE', (new DateTimeImmutable)->format('Y-m-d\TH:i:s'));

        return $controlInfo;
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

    /** ORDER_PARTIES_REFERENCE with the buyer and supplier id references. */
    private function partiesReference(Party $buyer, Party $supplier): DOMElement {
        $ref = $this->ot('ORDER_PARTIES_REFERENCE');

        $buyerRef = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:BUYER_IDREF', $this->partyId($buyer));
        $buyerRef->setAttribute('type', 'buyer_specific');
        $ref->appendChild($buyerRef);

        $supplierRef = $this->dom->createElementNS(self::BMECAT_NS, 'bmecat:SUPPLIER_IDREF', $this->partyId($supplier));
        $supplierRef->setAttribute('type', 'supplier_specific');
        $ref->appendChild($supplierRef);

        return $ref;
    }

    /** Best available stable identifier for a party (endpoint -> VAT -> name). */
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

    /** DELIVERY_DATE block with identical start and end date. */
    private function deliveryDate(DOMElement $parent, DateTimeImmutable $date): void {
        $delivery = $this->ot('DELIVERY_DATE');
        $delivery->setAttribute('type', 'fixed');
        $this->otText($delivery, 'DELIVERY_START_DATE', $date->format('Y-m-d'));
        $this->otText($delivery, 'DELIVERY_END_DATE', $date->format('Y-m-d'));
        $parent->appendChild($delivery);
    }

    private function amount(Money|float|int|null $value): string {
        if ($value instanceof Money) {
            return $value->getAmount();
        }

        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    /** Quantity without trailing zeros (e.g. 5.0 -> "5", 1.5 -> "1.5"). */
    private function number(float $value): string {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
