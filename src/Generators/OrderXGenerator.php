<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\ValueObjects\Money;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\{AllowanceCharge, Order, OrderLine, Party};
use ERechnungToolkit\Enums\OrderXProfile;
use ERRORToolkit\Traits\ErrorLog;
use RuntimeException;

/**
 * Generator for Order-X CII XML (UN/CEFACT Cross Industry Order, D20B).
 *
 * Order-X is the CII counterpart to the UBL Order — the order-side sibling of
 * ZUGFeRD/Factur-X — and can be embedded in a PDF/A-3 file. The structure
 * follows the FeRD/FNFE-MPE Order-X sample (SCRDMCCBDACIO message); note that
 * the ram/qdt/udt namespaces are version 128 (D20B), distinct from the D16B
 * invoice CII.
 *
 * @see https://www.mustangproject.org/order-x/
 */
final class OrderXGenerator {
    use ErrorLog;

    private const RSM_NS = 'urn:un:unece:uncefact:data:SCRDMCCBDACIOMessageStructure:100';
    private const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:128';
    private const QDT_NS = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:128';
    private const UDT_NS = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:128';

    /** Business process for an order (A1). */
    private const BUSINESS_PROCESS = 'A1';

    /** UNTDID 1001 document type code for a commercial order. */
    private const TYPE_CODE = '220';

    /**
     * Generates Order-X CII XML for the given order document.
     */
    public function generateCii(Order $order, OrderXProfile $profile = OrderXProfile::COMFORT): string {
        $this->logDebug('Generating Order-X CII XML', ['id' => $order->getId(), 'profile' => $profile->name]);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::RSM_NS, 'rsm:SCRDMCCBDACIOMessageStructure');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::QDT_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT_NS);
        $dom->appendChild($root);

        $this->appendContext($dom, $root, $profile);
        $this->appendDocument($dom, $root, $order);

        $transaction = $dom->createElementNS(self::RSM_NS, 'rsm:SupplyChainTradeTransaction');
        foreach ($order->getLines() as $line) {
            $transaction->appendChild($this->createLineItem($dom, $line));
        }
        $this->appendHeaderAgreement($dom, $transaction, $order);
        $this->appendHeaderDelivery($dom, $transaction, $order);
        $this->appendHeaderSettlement($dom, $transaction, $order);
        $root->appendChild($transaction);

        $xml = $dom->saveXML();
        if ($xml === false) {
            self::logErrorAndThrow(RuntimeException::class, 'XML-Dokument konnte nicht serialisiert werden.');
        }

        return $xml;
    }

    private function appendContext(DOMDocument $dom, DOMElement $root, OrderXProfile $profile): void {
        $context = $dom->createElementNS(self::RSM_NS, 'rsm:ExchangedDocumentContext');

        $businessProcess = $dom->createElementNS(self::RAM_NS, 'ram:BusinessProcessSpecifiedDocumentContextParameter');
        $this->ram($dom, $businessProcess, 'ram:ID', self::BUSINESS_PROCESS);
        $context->appendChild($businessProcess);

        $guideline = $dom->createElementNS(self::RAM_NS, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $this->ram($dom, $guideline, 'ram:ID', $profile->value);
        $context->appendChild($guideline);

        $root->appendChild($context);
    }

    private function appendDocument(DOMDocument $dom, DOMElement $root, Order $order): void {
        $doc = $dom->createElementNS(self::RSM_NS, 'rsm:ExchangedDocument');
        $this->ram($dom, $doc, 'ram:ID', $order->getId());
        $this->ram($dom, $doc, 'ram:TypeCode', self::TYPE_CODE);

        $issueDateTime = $dom->createElementNS(self::RAM_NS, 'ram:IssueDateTime');
        $this->dateString($dom, $issueDateTime, $order->getIssueDate()->format('Ymd'));
        $doc->appendChild($issueDateTime);

        foreach ($order->getNotes() as $note) {
            $includedNote = $dom->createElementNS(self::RAM_NS, 'ram:IncludedNote');
            $this->ram($dom, $includedNote, 'ram:Content', $note);
            $doc->appendChild($includedNote);
        }

        $root->appendChild($doc);
    }

    private function createLineItem(DOMDocument $dom, OrderLine $line): DOMElement {
        $item = $dom->createElementNS(self::RAM_NS, 'ram:IncludedSupplyChainTradeLineItem');

        // AssociatedDocumentLineDocument
        $assocDoc = $dom->createElementNS(self::RAM_NS, 'ram:AssociatedDocumentLineDocument');
        $this->ram($dom, $assocDoc, 'ram:LineID', $line->getId());
        if ($line->getNote() !== null) {
            $note = $dom->createElementNS(self::RAM_NS, 'ram:IncludedNote');
            $this->ram($dom, $note, 'ram:Content', $line->getNote());
            $assocDoc->appendChild($note);
        }
        $item->appendChild($assocDoc);

        // SpecifiedTradeProduct
        $product = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeProduct');
        if ($line->getStandardItemId() !== null) {
            $globalId = $this->ram($dom, $product, 'ram:GlobalID', $line->getStandardItemId());
            if ($line->getStandardItemScheme() !== null) {
                $globalId->setAttribute('schemeID', $line->getStandardItemScheme());
            }
        }
        if ($line->getSellersItemId() !== null) {
            $this->ram($dom, $product, 'ram:SellerAssignedID', $line->getSellersItemId());
        }
        if ($line->getBuyersItemId() !== null) {
            $this->ram($dom, $product, 'ram:BuyerAssignedID', $line->getBuyersItemId());
        }
        $this->ram($dom, $product, 'ram:Name', $line->getItemName());
        if ($line->getItemDescription() !== null) {
            $this->ram($dom, $product, 'ram:Description', $line->getItemDescription());
        }
        $item->appendChild($product);

        // SpecifiedLineTradeAgreement
        $agreement = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeAgreement');
        $netPrice = $dom->createElementNS(self::RAM_NS, 'ram:NetPriceProductTradePrice');
        $this->ram($dom, $netPrice, 'ram:ChargeAmount', $this->amount($line->getUnitPrice()));
        if ($line->getBaseQuantity() !== null && $line->getBaseQuantity() !== 1.0) {
            $basisQty = $this->ram($dom, $netPrice, 'ram:BasisQuantity', $this->amount($line->getBaseQuantity()));
            $basisQty->setAttribute('unitCode', $line->getUnitCode()->value);
        }
        $agreement->appendChild($netPrice);
        $item->appendChild($agreement);

        // SpecifiedLineTradeDelivery
        $delivery = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeDelivery');
        $requestedQty = $this->ram($dom, $delivery, 'ram:RequestedQuantity', $this->amount($line->getQuantity()));
        $requestedQty->setAttribute('unitCode', $line->getUnitCode()->value);
        $item->appendChild($delivery);

        // SpecifiedLineTradeSettlement
        $settlement = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeSettlement');
        if ($line->getTaxCategory() !== null) {
            $tax = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableTradeTax');
            $this->ram($dom, $tax, 'ram:TypeCode', 'VAT');
            $this->ram($dom, $tax, 'ram:CategoryCode', $line->getTaxCategory()->value);
            if ($line->getTaxPercent() !== null) {
                $this->ram($dom, $tax, 'ram:RateApplicablePercent', $this->amount($line->getTaxPercent()));
            }
            $settlement->appendChild($tax);
        }
        $summation = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeSettlementLineMonetarySummation');
        $this->ram($dom, $summation, 'ram:LineTotalAmount', $this->amount($line->getNetAmount()));
        $settlement->appendChild($summation);
        $item->appendChild($settlement);

        return $item;
    }

    private function appendHeaderAgreement(DOMDocument $dom, DOMElement $transaction, Order $order): void {
        $agreement = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeAgreement');

        if ($order->getBuyerReference() !== null) {
            $this->ram($dom, $agreement, 'ram:BuyerReference', $order->getBuyerReference());
        }

        // In an order the buyer is the originator; CII still lists seller first.
        $agreement->appendChild($this->createParty($dom, 'ram:SellerTradeParty', $order->getSeller()));
        $agreement->appendChild($this->createParty($dom, 'ram:BuyerTradeParty', $order->getBuyer()));

        if ($order->getContractReference() !== null) {
            $contractRef = $dom->createElementNS(self::RAM_NS, 'ram:ContractReferencedDocument');
            $this->ram($dom, $contractRef, 'ram:IssuerAssignedID', $order->getContractReference());
            $agreement->appendChild($contractRef);
        }

        $transaction->appendChild($agreement);
    }

    private function appendHeaderDelivery(DOMDocument $dom, DOMElement $transaction, Order $order): void {
        $delivery = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeDelivery');

        if ($order->getRequestedDeliveryStartDate() !== null) {
            $event = $dom->createElementNS(self::RAM_NS, 'ram:RequestedDeliverySupplyChainEvent');
            $occurrence = $dom->createElementNS(self::RAM_NS, 'ram:OccurrenceDateTime');
            $this->dateString($dom, $occurrence, $order->getRequestedDeliveryStartDate()->format('Ymd'));
            $event->appendChild($occurrence);
            $delivery->appendChild($event);
        }

        $transaction->appendChild($delivery);
    }

    private function appendHeaderSettlement(DOMDocument $dom, DOMElement $transaction, Order $order): void {
        $settlement = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeSettlement');
        $currency = $order->getCurrency()->value;

        $this->ram($dom, $settlement, 'ram:OrderCurrencyCode', $currency);

        // Document-level allowances and charges.
        foreach ($order->getAllowanceCharges() as $ac) {
            $settlement->appendChild($this->createAllowanceCharge($dom, $ac));
        }

        // VAT breakdown grouped by category and rate.
        $taxGroups = $this->taxGroups($order);
        $taxTotal = Money::zero($order->getCurrency());
        foreach ($taxGroups as $group) {
            $taxTotal = $taxTotal->plus($group['tax']);
            $tax = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableTradeTax');
            $this->ram($dom, $tax, 'ram:CalculatedAmount', $this->amount($group['tax']));
            $this->ram($dom, $tax, 'ram:TypeCode', 'VAT');
            $this->ram($dom, $tax, 'ram:BasisAmount', $this->amount($group['basis']));
            $this->ram($dom, $tax, 'ram:CategoryCode', $group['category']);
            $this->ram($dom, $tax, 'ram:RateApplicablePercent', $this->amount($group['percent']));
            $settlement->appendChild($tax);
        }

        // Monetary summation.
        $lineTotal = $order->getLineExtensionAmount();
        $allowanceTotal = $order->getAllowanceTotalAmount();
        $chargeTotal = $order->getChargeTotalAmount();
        $taxBasis = $lineTotal->minus($allowanceTotal)->plus($chargeTotal);
        $grandTotal = $taxBasis->plus($taxTotal);

        $summation = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $this->ram($dom, $summation, 'ram:LineTotalAmount', $this->amount($lineTotal));
        if ($chargeTotal->isPositive()) {
            $this->ram($dom, $summation, 'ram:ChargeTotalAmount', $this->amount($chargeTotal));
        }
        if ($allowanceTotal->isPositive()) {
            $this->ram($dom, $summation, 'ram:AllowanceTotalAmount', $this->amount($allowanceTotal));
        }
        $this->ram($dom, $summation, 'ram:TaxBasisTotalAmount', $this->amount($taxBasis));
        $taxTotalElem = $this->ram($dom, $summation, 'ram:TaxTotalAmount', $this->amount($taxTotal));
        $taxTotalElem->setAttribute('currencyID', $currency);
        $this->ram($dom, $summation, 'ram:GrandTotalAmount', $this->amount($grandTotal));
        $settlement->appendChild($summation);

        $transaction->appendChild($settlement);
    }

    private function createParty(DOMDocument $dom, string $tag, Party $party): DOMElement {
        $parent = $dom->createElementNS(self::RAM_NS, $tag);

        $this->ram($dom, $parent, 'ram:Name', $party->getName());

        if ($party->getLegalEntityId() !== null) {
            $legalOrg = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLegalOrganization');
            $id = $this->ram($dom, $legalOrg, 'ram:ID', $party->getLegalEntityId());
            if ($party->getLegalEntityScheme() !== null) {
                $id->setAttribute('schemeID', $party->getLegalEntityScheme());
            }
            $parent->appendChild($legalOrg);
        }

        if ($party->hasContactInfo()) {
            $contact = $dom->createElementNS(self::RAM_NS, 'ram:DefinedTradeContact');
            if ($party->getContactName() !== null) {
                $this->ram($dom, $contact, 'ram:PersonName', $party->getContactName());
            }
            if ($party->getContactPhone() !== null) {
                $phone = $dom->createElementNS(self::RAM_NS, 'ram:TelephoneUniversalCommunication');
                $this->ram($dom, $phone, 'ram:CompleteNumber', $party->getContactPhone());
                $contact->appendChild($phone);
            }
            if ($party->getContactEmail() !== null) {
                $email = $dom->createElementNS(self::RAM_NS, 'ram:EmailURIUniversalCommunication');
                $this->ram($dom, $email, 'ram:URIID', $party->getContactEmail());
                $contact->appendChild($email);
            }
            $parent->appendChild($contact);
        }

        if ($party->getPostalAddress() !== null) {
            $addr = $party->getPostalAddress();
            $address = $dom->createElementNS(self::RAM_NS, 'ram:PostalTradeAddress');
            if ($addr->getPostalCode() !== null) {
                $this->ram($dom, $address, 'ram:PostcodeCode', $addr->getPostalCode());
            }
            if ($addr->getStreetName() !== null) {
                $this->ram($dom, $address, 'ram:LineOne', $addr->getStreetName());
            }
            if ($addr->getAdditionalStreetName() !== null) {
                $this->ram($dom, $address, 'ram:LineTwo', $addr->getAdditionalStreetName());
            }
            if ($addr->getCity() !== null) {
                $this->ram($dom, $address, 'ram:CityName', $addr->getCity());
            }
            if ($addr->getCountryCode() !== null) {
                $this->ram($dom, $address, 'ram:CountryID', $addr->getCountryCode());
            }
            $parent->appendChild($address);
        }

        if ($party->hasEndpoint()) {
            $uri = $dom->createElementNS(self::RAM_NS, 'ram:URIUniversalCommunication');
            $uriId = $this->ram($dom, $uri, 'ram:URIID', $party->getEndpointId() ?? '');
            $uriId->setAttribute('schemeID', $party->getEndpointScheme() ?? '');
            $parent->appendChild($uri);
        }

        if ($party->hasVatId()) {
            $taxReg = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTaxRegistration');
            $id = $this->ram($dom, $taxReg, 'ram:ID', $party->getVatId() ?? '');
            $id->setAttribute('schemeID', 'VA');
            $parent->appendChild($taxReg);
        }
        if ($party->getTaxRegistrationId() !== null) {
            $taxRegFc = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTaxRegistration');
            $idFc = $this->ram($dom, $taxRegFc, 'ram:ID', $party->getTaxRegistrationId());
            $idFc->setAttribute('schemeID', 'FC');
            $parent->appendChild($taxRegFc);
        }

        return $parent;
    }

    private function createAllowanceCharge(DOMDocument $dom, AllowanceCharge $ac): DOMElement {
        $elem = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeAllowanceCharge');

        $indicator = $dom->createElementNS(self::RAM_NS, 'ram:ChargeIndicator');
        $this->udt($dom, $indicator, 'udt:Indicator', $ac->isCharge() ? 'true' : 'false');
        $elem->appendChild($indicator);

        $this->ram($dom, $elem, 'ram:ActualAmount', $this->amount($ac->getAmount()));

        if ($ac->getReason() !== null) {
            $this->ram($dom, $elem, 'ram:Reason', $ac->getReason());
        }

        if ($ac->getTaxCategory() !== null) {
            $categoryTax = $dom->createElementNS(self::RAM_NS, 'ram:CategoryTradeTax');
            $this->ram($dom, $categoryTax, 'ram:TypeCode', 'VAT');
            $this->ram($dom, $categoryTax, 'ram:CategoryCode', $ac->getTaxCategory()->value);
            if ($ac->getTaxPercent() !== null) {
                $this->ram($dom, $categoryTax, 'ram:RateApplicablePercent', $this->amount($ac->getTaxPercent()));
            }
            $elem->appendChild($categoryTax);
        }

        return $elem;
    }

    /**
     * Groups order lines by tax category and rate.
     *
     * @return array<string, array{category: string, percent: float, basis: Money, tax: Money}>
     */
    private function taxGroups(Order $order): array {
        $groups = [];
        foreach ($order->getLines() as $line) {
            if ($line->getTaxCategory() === null || $line->getTaxPercent() === null) {
                continue;
            }
            $key = $line->getTaxCategory()->value . '_' . $line->getTaxPercent();
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'category' => $line->getTaxCategory()->value,
                    'percent' => $line->getTaxPercent(),
                    'basis' => Money::zero($order->getCurrency()),
                    'tax' => Money::zero($order->getCurrency()),
                ];
            }
            $groups[$key]['basis'] = $groups[$key]['basis']->plus($line->getNetAmount());
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['tax'] = $group['basis']->percentage($group['percent']);
        }

        return $groups;
    }

    private function ram(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        $elem = $dom->createElementNS(self::RAM_NS, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($elem);
        return $elem;
    }

    private function udt(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        $elem = $dom->createElementNS(self::UDT_NS, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($elem);
        return $elem;
    }

    private function dateString(DOMDocument $dom, DOMElement $parent, string $yyyymmdd): void {
        $dateString = $dom->createElementNS(self::UDT_NS, 'udt:DateTimeString', $yyyymmdd);
        $dateString->setAttribute('format', '102');
        $parent->appendChild($dateString);
    }

    /**
     * Money liefert seinen kanonischen Betrag (exakte Währungsskala);
     * Prozentsätze bleiben skalar mit zwei Nachkommastellen.
     */
    private function amount(Money|float|int|null $amount): string {
        if ($amount instanceof Money) {
            return $amount->getAmount();
        }

        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
