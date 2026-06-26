<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : UblSerializer.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\{AllowanceCharge, Party, PostalAddress};

/**
 * Shared UBL building blocks for invoice and order documents.
 *
 * The UBL grammar for parties, postal addresses, allowances/charges and the
 * primitive element/amount formatting is identical across the Invoice (EN 16931
 * / XRechnung) and Order (Peppol BIS Order / XBestellung) transactions. This
 * serializer is the single source of truth for those building blocks so the
 * invoice and order generators stay in sync.
 *
 * Only the document envelope (root element, header sequence, line structure)
 * differs between the document types and therefore stays in the respective
 * generator.
 */
final class UblSerializer {
    public const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    public const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /**
     * Creates a cbc:/cac: element with the correct namespace and appends it.
     */
    public function element(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        [$prefix, $localName] = explode(':', $name);
        $ns = match ($prefix) {
            'cac' => self::CAC_NS,
            default => self::CBC_NS,
        };
        $elem = $dom->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($elem);
        return $elem;
    }

    /**
     * Builds a complete cac:Party element (seller, buyer or supplier).
     *
     * The caller appends it under the document-type specific wrapper
     * (cac:AccountingSupplierParty, cac:BuyerCustomerParty, ...).
     */
    public function party(DOMDocument $dom, Party $party): DOMElement {
        $partyElem = $dom->createElementNS(self::CAC_NS, 'cac:Party');

        // EndpointID
        if ($party->hasEndpoint()) {
            $endpoint = $this->element($dom, $partyElem, 'cbc:EndpointID', $party->getEndpointId());
            $endpoint->setAttribute('schemeID', $party->getEndpointScheme());
        }

        // PartyIdentification
        if ($party->getLegalEntityId() !== null) {
            $partyIdent = $dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
            $idElem = $this->element($dom, $partyIdent, 'cbc:ID', $party->getLegalEntityId());
            if ($party->getLegalEntityScheme() !== null) {
                $idElem->setAttribute('schemeID', $party->getLegalEntityScheme());
            }
            $partyElem->appendChild($partyIdent);
        }

        // PartyName
        $partyName = $dom->createElementNS(self::CAC_NS, 'cac:PartyName');
        $this->element($dom, $partyName, 'cbc:Name', $party->getName());
        $partyElem->appendChild($partyName);

        // PostalAddress
        if ($party->getPostalAddress() !== null) {
            $postalAddr = $this->postalAddress($dom, $party->getPostalAddress());
            $partyElem->appendChild($postalAddr);
        }

        // PartyTaxScheme - VAT identifier (BT-31) with TaxScheme/ID = VAT
        if ($party->hasVatId()) {
            $partyTaxScheme = $dom->createElementNS(self::CAC_NS, 'cac:PartyTaxScheme');
            $this->element($dom, $partyTaxScheme, 'cbc:CompanyID', $party->getVatId());
            $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->element($dom, $taxScheme, 'cbc:ID', 'VAT');
            $partyTaxScheme->appendChild($taxScheme);
            $partyElem->appendChild($partyTaxScheme);
        }

        // PartyTaxScheme - national tax registration / Steuernummer (BT-32)
        // with TaxScheme/ID = FC (not VAT).
        if ($party->getTaxRegistrationId() !== null) {
            $partyTaxSchemeFc = $dom->createElementNS(self::CAC_NS, 'cac:PartyTaxScheme');
            $this->element($dom, $partyTaxSchemeFc, 'cbc:CompanyID', $party->getTaxRegistrationId());
            $taxSchemeFc = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->element($dom, $taxSchemeFc, 'cbc:ID', 'FC');
            $partyTaxSchemeFc->appendChild($taxSchemeFc);
            $partyElem->appendChild($partyTaxSchemeFc);
        }

        // PartyLegalEntity
        $partyLegal = $dom->createElementNS(self::CAC_NS, 'cac:PartyLegalEntity');
        $this->element($dom, $partyLegal, 'cbc:RegistrationName', $party->getName());
        $partyElem->appendChild($partyLegal);

        // Contact
        if ($party->hasContactInfo()) {
            $contact = $dom->createElementNS(self::CAC_NS, 'cac:Contact');
            if ($party->getContactName() !== null) {
                $this->element($dom, $contact, 'cbc:Name', $party->getContactName());
            }
            if ($party->getContactPhone() !== null) {
                $this->element($dom, $contact, 'cbc:Telephone', $party->getContactPhone());
            }
            if ($party->getContactEmail() !== null) {
                $this->element($dom, $contact, 'cbc:ElectronicMail', $party->getContactEmail());
            }
            $partyElem->appendChild($contact);
        }

        return $partyElem;
    }

    /**
     * Builds a cac:PostalAddress element.
     */
    public function postalAddress(DOMDocument $dom, PostalAddress $address): DOMElement {
        $postalAddr = $dom->createElementNS(self::CAC_NS, 'cac:PostalAddress');

        if ($address->getStreetName() !== null) {
            $this->element($dom, $postalAddr, 'cbc:StreetName', $address->getStreetName());
        }
        if ($address->getAdditionalStreetName() !== null) {
            $this->element($dom, $postalAddr, 'cbc:AdditionalStreetName', $address->getAdditionalStreetName());
        }
        if ($address->getCity() !== null) {
            $this->element($dom, $postalAddr, 'cbc:CityName', $address->getCity());
        }
        if ($address->getPostalCode() !== null) {
            $this->element($dom, $postalAddr, 'cbc:PostalZone', $address->getPostalCode());
        }
        if ($address->getCountrySubdivision() !== null) {
            $this->element($dom, $postalAddr, 'cbc:CountrySubentity', $address->getCountrySubdivision());
        }
        if ($address->getCountryCode() !== null) {
            $country = $dom->createElementNS(self::CAC_NS, 'cac:Country');
            $this->element($dom, $country, 'cbc:IdentificationCode', $address->getCountryCode());
            $postalAddr->appendChild($country);
        }

        return $postalAddr;
    }

    /**
     * Builds a cac:AllowanceCharge element (document or line level).
     */
    public function allowanceCharge(DOMDocument $dom, AllowanceCharge $ac, string $currency): DOMElement {
        $elem = $dom->createElementNS(self::CAC_NS, 'cac:AllowanceCharge');

        $this->element($dom, $elem, 'cbc:ChargeIndicator', $ac->isCharge() ? 'true' : 'false');

        if ($ac->getReasonCode() !== null) {
            $this->element($dom, $elem, 'cbc:AllowanceChargeReasonCode', $ac->getReasonCode()->value);
        }
        if ($ac->getReason() !== null) {
            $this->element($dom, $elem, 'cbc:AllowanceChargeReason', $ac->getReason());
        }

        if ($ac->getPercentage() !== null) {
            $this->element($dom, $elem, 'cbc:MultiplierFactorNumeric', $this->amount($ac->getPercentage()));
        }

        $amount = $this->element($dom, $elem, 'cbc:Amount', $this->amount($ac->getAmount()));
        $amount->setAttribute('currencyID', $currency);

        if ($ac->getBaseAmount() !== null) {
            $base = $this->element($dom, $elem, 'cbc:BaseAmount', $this->amount($ac->getBaseAmount()));
            $base->setAttribute('currencyID', $currency);
        }

        if ($ac->getTaxCategory() !== null) {
            $taxCategory = $dom->createElementNS(self::CAC_NS, 'cac:TaxCategory');
            $this->element($dom, $taxCategory, 'cbc:ID', $ac->getTaxCategory()->value);
            if ($ac->getTaxPercent() !== null) {
                $this->element($dom, $taxCategory, 'cbc:Percent', $this->amount($ac->getTaxPercent()));
            }
            $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->element($dom, $taxScheme, 'cbc:ID', 'VAT');
            $taxCategory->appendChild($taxScheme);
            $elem->appendChild($taxCategory);
        }

        return $elem;
    }

    /**
     * Normalizes an IBAN for XML emission per EN 16931: no spaces, uppercase.
     */
    public function normalizeIban(?string $iban): string {
        return strtoupper(str_replace(' ', '', (string) $iban));
    }

    /**
     * Formats a monetary/numeric amount with two decimals and a dot separator.
     */
    public function amount(float $amount): string {
        return number_format($amount, 2, '.', '');
    }
}
