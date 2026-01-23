<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use ERechnungToolkit\Entities\AllowanceCharge;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PostalAddress;
use ERRORToolkit\Traits\ErrorLog;
use DOMDocument;
use DOMElement;

/**
 * Generator for E-Rechnung XML output.
 * 
 * Supports:
 * - UBL 2.1 (Universal Business Language) for XRechnung
 * - UN/CEFACT CII D16B (Cross Industry Invoice) for ZUGFeRD/Factur-X
 * 
 * @package ERechnungToolkit\Generators
 */
final class ERechnungGenerator {
    use ErrorLog;
    private const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const UBL_CN_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CII_NS = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const QDT_NS = 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100';
    private const UDT_NS = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /**
     * Generates UBL XML for XRechnung.
     */
    public function generateUbl(Document $document): string {
        $this->logDebug('Generating UBL XML', ['id' => $document->getId(), 'type' => $document->getInvoiceType()->name]);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $isCredit = $document->getInvoiceType()->isCredit();
        $rootNs = $isCredit ? self::UBL_CN_NS : self::UBL_NS;
        $rootTag = $isCredit ? 'CreditNote' : 'Invoice';

        $root = $dom->createElementNS($rootNs, $rootTag);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::CAC_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::CBC_NS);
        $dom->appendChild($root);

        // CustomizationID (Profile)
        $this->addUblElement($dom, $root, 'cbc:CustomizationID', $document->getProfile()->value);

        // ProfileID
        $this->addUblElement($dom, $root, 'cbc:ProfileID', 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0');

        // ID (Invoice number)
        $this->addUblElement($dom, $root, 'cbc:ID', $document->getId());

        // IssueDate
        $this->addUblElement($dom, $root, 'cbc:IssueDate', $document->getIssueDate()->format('Y-m-d'));

        // DueDate
        if ($document->getDueDate() !== null) {
            $this->addUblElement($dom, $root, 'cbc:DueDate', $document->getDueDate()->format('Y-m-d'));
        }

        // InvoiceTypeCode / CreditNoteTypeCode
        $typeTag = $isCredit ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode';
        $this->addUblElement($dom, $root, $typeTag, $document->getInvoiceType()->value);

        // Notes
        foreach ($document->getNotes() as $note) {
            $this->addUblElement($dom, $root, 'cbc:Note', $note);
        }

        // TaxPointDate
        if ($document->getTaxPointDate() !== null) {
            $this->addUblElement($dom, $root, 'cbc:TaxPointDate', $document->getTaxPointDate()->format('Y-m-d'));
        }

        // DocumentCurrencyCode
        $this->addUblElement($dom, $root, 'cbc:DocumentCurrencyCode', $document->getCurrency()->value);

        // BuyerReference
        if ($document->getBuyerReference() !== null) {
            $this->addUblElement($dom, $root, 'cbc:BuyerReference', $document->getBuyerReference());
        }

        // OrderReference
        if ($document->getOrderReference() !== null) {
            $orderRef = $dom->createElementNS(self::CAC_NS, 'cac:OrderReference');
            $this->addUblElement($dom, $orderRef, 'cbc:ID', $document->getOrderReference());
            $root->appendChild($orderRef);
        }

        // BillingReference (for credit notes)
        if ($document->getPrecedingInvoiceReference() !== null) {
            $billingRef = $dom->createElementNS(self::CAC_NS, 'cac:BillingReference');
            $invoiceDocRef = $dom->createElementNS(self::CAC_NS, 'cac:InvoiceDocumentReference');
            $this->addUblElement($dom, $invoiceDocRef, 'cbc:ID', $document->getPrecedingInvoiceReference());
            $billingRef->appendChild($invoiceDocRef);
            $root->appendChild($billingRef);
        }

        // ContractDocumentReference
        if ($document->getContractReference() !== null) {
            $contractRef = $dom->createElementNS(self::CAC_NS, 'cac:ContractDocumentReference');
            $this->addUblElement($dom, $contractRef, 'cbc:ID', $document->getContractReference());
            $root->appendChild($contractRef);
        }

        // ProjectReference
        if ($document->getProjectReference() !== null) {
            $projectRef = $dom->createElementNS(self::CAC_NS, 'cac:ProjectReference');
            $this->addUblElement($dom, $projectRef, 'cbc:ID', $document->getProjectReference());
            $root->appendChild($projectRef);
        }

        // AccountingSupplierParty (Seller)
        $supplierParty = $dom->createElementNS(self::CAC_NS, 'cac:AccountingSupplierParty');
        $this->addUblParty($dom, $supplierParty, $document->getSeller());
        $root->appendChild($supplierParty);

        // AccountingCustomerParty (Buyer)
        $customerParty = $dom->createElementNS(self::CAC_NS, 'cac:AccountingCustomerParty');
        $this->addUblParty($dom, $customerParty, $document->getBuyer());
        $root->appendChild($customerParty);

        // Delivery
        if ($document->getDeliveryDate() !== null || $document->getDeliveryParty() !== null) {
            $delivery = $dom->createElementNS(self::CAC_NS, 'cac:Delivery');
            if ($document->getDeliveryDate() !== null) {
                $this->addUblElement($dom, $delivery, 'cbc:ActualDeliveryDate', $document->getDeliveryDate()->format('Y-m-d'));
            }
            $root->appendChild($delivery);
        }

        // PaymentMeans
        if ($document->getPaymentMeansCode() !== null || $document->getSeller()->hasBankingInfo()) {
            $paymentMeans = $dom->createElementNS(self::CAC_NS, 'cac:PaymentMeans');
            $this->addUblElement(
                $dom,
                $paymentMeans,
                'cbc:PaymentMeansCode',
                $document->getPaymentMeansCode()?->value ?? '30'
            );

            if ($document->getSeller()->hasBankingInfo()) {
                $payeeAccount = $dom->createElementNS(self::CAC_NS, 'cac:PayeeFinancialAccount');
                $this->addUblElement($dom, $payeeAccount, 'cbc:ID', $document->getSeller()->getIban());
                if ($document->getSeller()->getPaymentAccountName() !== null) {
                    $this->addUblElement($dom, $payeeAccount, 'cbc:Name', $document->getSeller()->getPaymentAccountName());
                }
                if ($document->getSeller()->getBic() !== null) {
                    $finInst = $dom->createElementNS(self::CAC_NS, 'cac:FinancialInstitutionBranch');
                    $this->addUblElement($dom, $finInst, 'cbc:ID', $document->getSeller()->getBic());
                    $payeeAccount->appendChild($finInst);
                }
                $paymentMeans->appendChild($payeeAccount);
            }
            $root->appendChild($paymentMeans);
        }

        // PaymentTerms
        if ($document->getPaymentTerms() !== null && $document->getPaymentTerms()->getNote() !== null) {
            $paymentTerms = $dom->createElementNS(self::CAC_NS, 'cac:PaymentTerms');
            $this->addUblElement($dom, $paymentTerms, 'cbc:Note', $document->getPaymentTerms()->getNote());
            $root->appendChild($paymentTerms);
        }

        // AllowanceCharge (document level)
        foreach ($document->getAllowanceCharges() as $ac) {
            $acElem = $this->createUblAllowanceCharge($dom, $ac, $document->getCurrency()->value);
            $root->appendChild($acElem);
        }

        // TaxTotal
        if ($document->getTaxTotal() !== null) {
            $taxTotal = $dom->createElementNS(self::CAC_NS, 'cac:TaxTotal');
            $taxAmount = $this->addUblElement(
                $dom,
                $taxTotal,
                'cbc:TaxAmount',
                $this->formatAmount($document->getTaxTotal()->getTaxAmount())
            );
            $taxAmount->setAttribute('currencyID', $document->getCurrency()->value);

            foreach ($document->getTaxTotal()->getSubtotals() as $subtotal) {
                $taxSubtotal = $dom->createElementNS(self::CAC_NS, 'cac:TaxSubtotal');

                $taxableAmount = $this->addUblElement(
                    $dom,
                    $taxSubtotal,
                    'cbc:TaxableAmount',
                    $this->formatAmount($subtotal->getTaxableAmount())
                );
                $taxableAmount->setAttribute('currencyID', $document->getCurrency()->value);

                $subTaxAmount = $this->addUblElement(
                    $dom,
                    $taxSubtotal,
                    'cbc:TaxAmount',
                    $this->formatAmount($subtotal->getTaxAmount())
                );
                $subTaxAmount->setAttribute('currencyID', $document->getCurrency()->value);

                $taxCategory = $dom->createElementNS(self::CAC_NS, 'cac:TaxCategory');
                $this->addUblElement($dom, $taxCategory, 'cbc:ID', $subtotal->getCategory()->value);
                $this->addUblElement($dom, $taxCategory, 'cbc:Percent', $this->formatAmount($subtotal->getPercent()));

                if ($subtotal->getExemptionReason() !== null) {
                    $this->addUblElement($dom, $taxCategory, 'cbc:TaxExemptionReason', $subtotal->getExemptionReason());
                }

                $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
                $this->addUblElement($dom, $taxScheme, 'cbc:ID', 'VAT');
                $taxCategory->appendChild($taxScheme);

                $taxSubtotal->appendChild($taxCategory);
                $taxTotal->appendChild($taxSubtotal);
            }
            $root->appendChild($taxTotal);
        }

        // LegalMonetaryTotal
        if ($document->getMonetaryTotal() !== null) {
            $monetaryTotal = $dom->createElementNS(self::CAC_NS, 'cac:LegalMonetaryTotal');
            $currency = $document->getCurrency()->value;

            $lineExt = $this->addUblElement(
                $dom,
                $monetaryTotal,
                'cbc:LineExtensionAmount',
                $this->formatAmount($document->getMonetaryTotal()->getLineExtensionAmount())
            );
            $lineExt->setAttribute('currencyID', $currency);

            $taxExcl = $this->addUblElement(
                $dom,
                $monetaryTotal,
                'cbc:TaxExclusiveAmount',
                $this->formatAmount($document->getMonetaryTotal()->getTaxExclusiveAmount())
            );
            $taxExcl->setAttribute('currencyID', $currency);

            $taxIncl = $this->addUblElement(
                $dom,
                $monetaryTotal,
                'cbc:TaxInclusiveAmount',
                $this->formatAmount($document->getMonetaryTotal()->getTaxInclusiveAmount())
            );
            $taxIncl->setAttribute('currencyID', $currency);

            if ($document->getMonetaryTotal()->getAllowanceTotalAmount() > 0) {
                $allowance = $this->addUblElement(
                    $dom,
                    $monetaryTotal,
                    'cbc:AllowanceTotalAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getAllowanceTotalAmount())
                );
                $allowance->setAttribute('currencyID', $currency);
            }

            if ($document->getMonetaryTotal()->getChargeTotalAmount() > 0) {
                $charge = $this->addUblElement(
                    $dom,
                    $monetaryTotal,
                    'cbc:ChargeTotalAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getChargeTotalAmount())
                );
                $charge->setAttribute('currencyID', $currency);
            }

            if ($document->getMonetaryTotal()->getPrepaidAmount() > 0) {
                $prepaid = $this->addUblElement(
                    $dom,
                    $monetaryTotal,
                    'cbc:PrepaidAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getPrepaidAmount())
                );
                $prepaid->setAttribute('currencyID', $currency);
            }

            $payable = $this->addUblElement(
                $dom,
                $monetaryTotal,
                'cbc:PayableAmount',
                $this->formatAmount($document->getMonetaryTotal()->getPayableAmount())
            );
            $payable->setAttribute('currencyID', $currency);

            $root->appendChild($monetaryTotal);
        }

        // Invoice Lines
        $lineTag = $isCredit ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        foreach ($document->getLines() as $line) {
            $lineElem = $this->createUblInvoiceLine($dom, $line, $document->getCurrency()->value, $lineTag);
            $root->appendChild($lineElem);
        }

        return $dom->saveXML();
    }

    /**
     * Generates UN/CEFACT CII XML for ZUGFeRD/Factur-X.
     */
    public function generateCii(Document $document): string {
        $this->logDebug('Generating CII XML', ['id' => $document->getId(), 'type' => $document->getInvoiceType()->name]);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::CII_NS, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', self::QDT_NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT_NS);
        $dom->appendChild($root);

        // ExchangedDocumentContext
        $context = $dom->createElementNS(self::CII_NS, 'rsm:ExchangedDocumentContext');
        $guideline = $dom->createElementNS(self::RAM_NS, 'ram:GuidelineSpecifiedDocumentContextParameter');
        $this->addCiiElement($dom, $guideline, 'ram:ID', $document->getProfile()->value);
        $context->appendChild($guideline);
        $root->appendChild($context);

        // ExchangedDocument
        $exchangedDoc = $dom->createElementNS(self::CII_NS, 'rsm:ExchangedDocument');
        $this->addCiiElement($dom, $exchangedDoc, 'ram:ID', $document->getId());
        $this->addCiiElement($dom, $exchangedDoc, 'ram:TypeCode', $document->getInvoiceType()->value);

        $issueDateTime = $dom->createElementNS(self::RAM_NS, 'ram:IssueDateTime');
        $dateTimeString = $dom->createElementNS(self::UDT_NS, 'udt:DateTimeString');
        $dateTimeString->setAttribute('format', '102');
        $dateTimeString->textContent = $document->getIssueDate()->format('Ymd');
        $issueDateTime->appendChild($dateTimeString);
        $exchangedDoc->appendChild($issueDateTime);

        foreach ($document->getNotes() as $note) {
            $includedNote = $dom->createElementNS(self::RAM_NS, 'ram:IncludedNote');
            $this->addCiiElement($dom, $includedNote, 'ram:Content', $note);
            $exchangedDoc->appendChild($includedNote);
        }
        $root->appendChild($exchangedDoc);

        // SupplyChainTradeTransaction
        $transaction = $dom->createElementNS(self::CII_NS, 'rsm:SupplyChainTradeTransaction');

        // Invoice Lines
        foreach ($document->getLines() as $line) {
            $lineItem = $this->createCiiLineItem($dom, $line, $document->getCurrency()->value);
            $transaction->appendChild($lineItem);
        }

        // ApplicableHeaderTradeAgreement
        $agreement = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeAgreement');

        if ($document->getBuyerReference() !== null) {
            $this->addCiiElement($dom, $agreement, 'ram:BuyerReference', $document->getBuyerReference());
        }

        // Seller
        $sellerParty = $dom->createElementNS(self::RAM_NS, 'ram:SellerTradeParty');
        $this->addCiiParty($dom, $sellerParty, $document->getSeller());
        $agreement->appendChild($sellerParty);

        // Buyer
        $buyerParty = $dom->createElementNS(self::RAM_NS, 'ram:BuyerTradeParty');
        $this->addCiiParty($dom, $buyerParty, $document->getBuyer());
        $agreement->appendChild($buyerParty);

        if ($document->getOrderReference() !== null) {
            $buyerOrderRef = $dom->createElementNS(self::RAM_NS, 'ram:BuyerOrderReferencedDocument');
            $this->addCiiElement($dom, $buyerOrderRef, 'ram:IssuerAssignedID', $document->getOrderReference());
            $agreement->appendChild($buyerOrderRef);
        }

        if ($document->getContractReference() !== null) {
            $contractRef = $dom->createElementNS(self::RAM_NS, 'ram:ContractReferencedDocument');
            $this->addCiiElement($dom, $contractRef, 'ram:IssuerAssignedID', $document->getContractReference());
            $agreement->appendChild($contractRef);
        }

        $transaction->appendChild($agreement);

        // ApplicableHeaderTradeDelivery
        $delivery = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeDelivery');
        if ($document->getDeliveryDate() !== null) {
            $occurrence = $dom->createElementNS(self::RAM_NS, 'ram:ActualDeliverySupplyChainEvent');
            $occurrenceDateTime = $dom->createElementNS(self::RAM_NS, 'ram:OccurrenceDateTime');
            $dateTimeStr = $dom->createElementNS(self::UDT_NS, 'udt:DateTimeString');
            $dateTimeStr->setAttribute('format', '102');
            $dateTimeStr->textContent = $document->getDeliveryDate()->format('Ymd');
            $occurrenceDateTime->appendChild($dateTimeStr);
            $occurrence->appendChild($occurrenceDateTime);
            $delivery->appendChild($occurrence);
        }
        $transaction->appendChild($delivery);

        // ApplicableHeaderTradeSettlement
        $settlement = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableHeaderTradeSettlement');
        $this->addCiiElement($dom, $settlement, 'ram:InvoiceCurrencyCode', $document->getCurrency()->value);

        // PaymentMeans
        if ($document->getPaymentMeansCode() !== null || $document->getSeller()->hasBankingInfo()) {
            $paymentMeans = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeSettlementPaymentMeans');
            $this->addCiiElement(
                $dom,
                $paymentMeans,
                'ram:TypeCode',
                $document->getPaymentMeansCode()?->value ?? '30'
            );

            if ($document->getSeller()->hasBankingInfo()) {
                $payeeAccount = $dom->createElementNS(self::RAM_NS, 'ram:PayeePartyCreditorFinancialAccount');
                $this->addCiiElement($dom, $payeeAccount, 'ram:IBANID', $document->getSeller()->getIban());
                $paymentMeans->appendChild($payeeAccount);

                if ($document->getSeller()->getBic() !== null) {
                    $payeeInst = $dom->createElementNS(self::RAM_NS, 'ram:PayeeSpecifiedCreditorFinancialInstitution');
                    $this->addCiiElement($dom, $payeeInst, 'ram:BICID', $document->getSeller()->getBic());
                    $paymentMeans->appendChild($payeeInst);
                }
            }
            $settlement->appendChild($paymentMeans);
        }

        // Tax
        if ($document->getTaxTotal() !== null) {
            foreach ($document->getTaxTotal()->getSubtotals() as $subtotal) {
                $tax = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableTradeTax');
                $this->addCiiElement($dom, $tax, 'ram:CalculatedAmount', $this->formatAmount($subtotal->getTaxAmount()));
                $this->addCiiElement($dom, $tax, 'ram:TypeCode', 'VAT');
                if ($subtotal->getExemptionReason() !== null) {
                    $this->addCiiElement($dom, $tax, 'ram:ExemptionReason', $subtotal->getExemptionReason());
                }
                $this->addCiiElement($dom, $tax, 'ram:BasisAmount', $this->formatAmount($subtotal->getTaxableAmount()));
                $this->addCiiElement($dom, $tax, 'ram:CategoryCode', $subtotal->getCategory()->value);
                $this->addCiiElement($dom, $tax, 'ram:RateApplicablePercent', $this->formatAmount($subtotal->getPercent()));
                $settlement->appendChild($tax);
            }
        }

        // BillingPeriod (if available)
        // PaymentTerms
        if ($document->getPaymentTerms() !== null) {
            $paymentTerms = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradePaymentTerms');
            if ($document->getPaymentTerms()->getNote() !== null) {
                $this->addCiiElement($dom, $paymentTerms, 'ram:Description', $document->getPaymentTerms()->getNote());
            }
            if ($document->getDueDate() !== null) {
                $dueDateTime = $dom->createElementNS(self::RAM_NS, 'ram:DueDateDateTime');
                $dateTimeStr = $dom->createElementNS(self::UDT_NS, 'udt:DateTimeString');
                $dateTimeStr->setAttribute('format', '102');
                $dateTimeStr->textContent = $document->getDueDate()->format('Ymd');
                $dueDateTime->appendChild($dateTimeStr);
                $paymentTerms->appendChild($dueDateTime);
            }
            $settlement->appendChild($paymentTerms);
        }

        // MonetaryTotal
        if ($document->getMonetaryTotal() !== null) {
            $summation = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
            $this->addCiiElement(
                $dom,
                $summation,
                'ram:LineTotalAmount',
                $this->formatAmount($document->getMonetaryTotal()->getLineExtensionAmount())
            );

            if ($document->getMonetaryTotal()->getChargeTotalAmount() > 0) {
                $this->addCiiElement(
                    $dom,
                    $summation,
                    'ram:ChargeTotalAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getChargeTotalAmount())
                );
            }
            if ($document->getMonetaryTotal()->getAllowanceTotalAmount() > 0) {
                $this->addCiiElement(
                    $dom,
                    $summation,
                    'ram:AllowanceTotalAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getAllowanceTotalAmount())
                );
            }

            $this->addCiiElement(
                $dom,
                $summation,
                'ram:TaxBasisTotalAmount',
                $this->formatAmount($document->getMonetaryTotal()->getTaxExclusiveAmount())
            );

            $taxTotal = $this->addCiiElement(
                $dom,
                $summation,
                'ram:TaxTotalAmount',
                $this->formatAmount($document->getTaxTotal()?->getTaxAmount() ?? 0)
            );
            $taxTotal->setAttribute('currencyID', $document->getCurrency()->value);

            $this->addCiiElement(
                $dom,
                $summation,
                'ram:GrandTotalAmount',
                $this->formatAmount($document->getMonetaryTotal()->getTaxInclusiveAmount())
            );

            if ($document->getMonetaryTotal()->getPrepaidAmount() > 0) {
                $this->addCiiElement(
                    $dom,
                    $summation,
                    'ram:TotalPrepaidAmount',
                    $this->formatAmount($document->getMonetaryTotal()->getPrepaidAmount())
                );
            }

            $this->addCiiElement(
                $dom,
                $summation,
                'ram:DuePayableAmount',
                $this->formatAmount($document->getMonetaryTotal()->getPayableAmount())
            );

            $settlement->appendChild($summation);
        }

        $transaction->appendChild($settlement);
        $root->appendChild($transaction);

        return $dom->saveXML();
    }

    // === Helper Methods ===

    private function addUblElement(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        [$prefix, $localName] = explode(':', $name);
        $ns = match ($prefix) {
            'cbc' => self::CBC_NS,
            'cac' => self::CAC_NS,
            default => self::UBL_NS,
        };
        $elem = $dom->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($elem);
        return $elem;
    }

    private function addCiiElement(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement {
        [$prefix, $localName] = explode(':', $name);
        $ns = match ($prefix) {
            'ram' => self::RAM_NS,
            'udt' => self::UDT_NS,
            'qdt' => self::QDT_NS,
            default => self::CII_NS,
        };
        $elem = $dom->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1, 'UTF-8'));
        $parent->appendChild($elem);
        return $elem;
    }

    private function addUblParty(DOMDocument $dom, DOMElement $parent, Party $party): void {
        $partyElem = $dom->createElementNS(self::CAC_NS, 'cac:Party');

        // EndpointID
        if ($party->hasEndpoint()) {
            $endpoint = $this->addUblElement($dom, $partyElem, 'cbc:EndpointID', $party->getEndpointId());
            $endpoint->setAttribute('schemeID', $party->getEndpointScheme());
        }

        // PartyIdentification
        if ($party->getLegalEntityId() !== null) {
            $partyIdent = $dom->createElementNS(self::CAC_NS, 'cac:PartyIdentification');
            $idElem = $this->addUblElement($dom, $partyIdent, 'cbc:ID', $party->getLegalEntityId());
            if ($party->getLegalEntityScheme() !== null) {
                $idElem->setAttribute('schemeID', $party->getLegalEntityScheme());
            }
            $partyElem->appendChild($partyIdent);
        }

        // PartyName
        $partyName = $dom->createElementNS(self::CAC_NS, 'cac:PartyName');
        $this->addUblElement($dom, $partyName, 'cbc:Name', $party->getName());
        $partyElem->appendChild($partyName);

        // PostalAddress
        if ($party->getPostalAddress() !== null) {
            $postalAddr = $this->createUblPostalAddress($dom, $party->getPostalAddress());
            $partyElem->appendChild($postalAddr);
        }

        // PartyTaxScheme
        if ($party->hasVatId()) {
            $partyTaxScheme = $dom->createElementNS(self::CAC_NS, 'cac:PartyTaxScheme');
            $this->addUblElement($dom, $partyTaxScheme, 'cbc:CompanyID', $party->getVatId());
            $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->addUblElement($dom, $taxScheme, 'cbc:ID', 'VAT');
            $partyTaxScheme->appendChild($taxScheme);
            $partyElem->appendChild($partyTaxScheme);
        }

        // PartyLegalEntity
        $partyLegal = $dom->createElementNS(self::CAC_NS, 'cac:PartyLegalEntity');
        $this->addUblElement($dom, $partyLegal, 'cbc:RegistrationName', $party->getName());
        if ($party->getTaxRegistrationId() !== null) {
            $this->addUblElement($dom, $partyLegal, 'cbc:CompanyID', $party->getTaxRegistrationId());
        }
        $partyElem->appendChild($partyLegal);

        // Contact
        if ($party->hasContactInfo()) {
            $contact = $dom->createElementNS(self::CAC_NS, 'cac:Contact');
            if ($party->getContactName() !== null) {
                $this->addUblElement($dom, $contact, 'cbc:Name', $party->getContactName());
            }
            if ($party->getContactPhone() !== null) {
                $this->addUblElement($dom, $contact, 'cbc:Telephone', $party->getContactPhone());
            }
            if ($party->getContactEmail() !== null) {
                $this->addUblElement($dom, $contact, 'cbc:ElectronicMail', $party->getContactEmail());
            }
            $partyElem->appendChild($contact);
        }

        $parent->appendChild($partyElem);
    }

    private function addCiiParty(DOMDocument $dom, DOMElement $parent, Party $party): void {
        $this->addCiiElement($dom, $parent, 'ram:Name', $party->getName());

        if ($party->getLegalEntityId() !== null) {
            $id = $this->addCiiElement($dom, $parent, 'ram:ID', $party->getLegalEntityId());
            if ($party->getLegalEntityScheme() !== null) {
                $id->setAttribute('schemeID', $party->getLegalEntityScheme());
            }
        }

        if ($party->hasVatId()) {
            $taxReg = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTaxRegistration');
            $taxId = $this->addCiiElement($dom, $taxReg, 'ram:ID', $party->getVatId());
            $taxId->setAttribute('schemeID', 'VA');
            $parent->appendChild($taxReg);
        }

        if ($party->getPostalAddress() !== null) {
            $address = $dom->createElementNS(self::RAM_NS, 'ram:PostalTradeAddress');
            $addr = $party->getPostalAddress();
            if ($addr->getPostalCode() !== null) {
                $this->addCiiElement($dom, $address, 'ram:PostcodeCode', $addr->getPostalCode());
            }
            if ($addr->getStreetName() !== null) {
                $this->addCiiElement($dom, $address, 'ram:LineOne', $addr->getStreetName());
            }
            if ($addr->getAdditionalStreetName() !== null) {
                $this->addCiiElement($dom, $address, 'ram:LineTwo', $addr->getAdditionalStreetName());
            }
            if ($addr->getCity() !== null) {
                $this->addCiiElement($dom, $address, 'ram:CityName', $addr->getCity());
            }
            if ($addr->getCountryCode() !== null) {
                $this->addCiiElement($dom, $address, 'ram:CountryID', $addr->getCountryCode());
            }
            $parent->appendChild($address);
        }

        if ($party->hasEndpoint()) {
            $uriComm = $dom->createElementNS(self::RAM_NS, 'ram:URIUniversalCommunication');
            $uriId = $this->addCiiElement($dom, $uriComm, 'ram:URIID', $party->getEndpointId());
            $uriId->setAttribute('schemeID', $party->getEndpointScheme());
            $parent->appendChild($uriComm);
        }

        if ($party->hasContactInfo()) {
            $contact = $dom->createElementNS(self::RAM_NS, 'ram:DefinedTradeContact');
            if ($party->getContactName() !== null) {
                $this->addCiiElement($dom, $contact, 'ram:PersonName', $party->getContactName());
            }
            if ($party->getContactPhone() !== null) {
                $phone = $dom->createElementNS(self::RAM_NS, 'ram:TelephoneUniversalCommunication');
                $this->addCiiElement($dom, $phone, 'ram:CompleteNumber', $party->getContactPhone());
                $contact->appendChild($phone);
            }
            if ($party->getContactEmail() !== null) {
                $email = $dom->createElementNS(self::RAM_NS, 'ram:EmailURIUniversalCommunication');
                $this->addCiiElement($dom, $email, 'ram:URIID', $party->getContactEmail());
                $contact->appendChild($email);
            }
            $parent->appendChild($contact);
        }
    }

    private function createUblPostalAddress(DOMDocument $dom, PostalAddress $address): DOMElement {
        $postalAddr = $dom->createElementNS(self::CAC_NS, 'cac:PostalAddress');

        if ($address->getStreetName() !== null) {
            $this->addUblElement($dom, $postalAddr, 'cbc:StreetName', $address->getStreetName());
        }
        if ($address->getAdditionalStreetName() !== null) {
            $this->addUblElement($dom, $postalAddr, 'cbc:AdditionalStreetName', $address->getAdditionalStreetName());
        }
        if ($address->getCity() !== null) {
            $this->addUblElement($dom, $postalAddr, 'cbc:CityName', $address->getCity());
        }
        if ($address->getPostalCode() !== null) {
            $this->addUblElement($dom, $postalAddr, 'cbc:PostalZone', $address->getPostalCode());
        }
        if ($address->getCountrySubdivision() !== null) {
            $this->addUblElement($dom, $postalAddr, 'cbc:CountrySubentity', $address->getCountrySubdivision());
        }
        if ($address->getCountryCode() !== null) {
            $country = $dom->createElementNS(self::CAC_NS, 'cac:Country');
            $this->addUblElement($dom, $country, 'cbc:IdentificationCode', $address->getCountryCode());
            $postalAddr->appendChild($country);
        }

        return $postalAddr;
    }

    private function createUblAllowanceCharge(DOMDocument $dom, AllowanceCharge $ac, string $currency): DOMElement {
        $elem = $dom->createElementNS(self::CAC_NS, 'cac:AllowanceCharge');

        $this->addUblElement($dom, $elem, 'cbc:ChargeIndicator', $ac->isCharge() ? 'true' : 'false');

        if ($ac->getReasonCode() !== null) {
            $this->addUblElement($dom, $elem, 'cbc:AllowanceChargeReasonCode', $ac->getReasonCode()->value);
        }
        if ($ac->getReason() !== null) {
            $this->addUblElement($dom, $elem, 'cbc:AllowanceChargeReason', $ac->getReason());
        }

        if ($ac->getPercentage() !== null) {
            $this->addUblElement($dom, $elem, 'cbc:MultiplierFactorNumeric', $this->formatAmount($ac->getPercentage()));
        }

        $amount = $this->addUblElement($dom, $elem, 'cbc:Amount', $this->formatAmount($ac->getAmount()));
        $amount->setAttribute('currencyID', $currency);

        if ($ac->getBaseAmount() !== null) {
            $base = $this->addUblElement($dom, $elem, 'cbc:BaseAmount', $this->formatAmount($ac->getBaseAmount()));
            $base->setAttribute('currencyID', $currency);
        }

        if ($ac->getTaxCategory() !== null) {
            $taxCategory = $dom->createElementNS(self::CAC_NS, 'cac:TaxCategory');
            $this->addUblElement($dom, $taxCategory, 'cbc:ID', $ac->getTaxCategory()->value);
            if ($ac->getTaxPercent() !== null) {
                $this->addUblElement($dom, $taxCategory, 'cbc:Percent', $this->formatAmount($ac->getTaxPercent()));
            }
            $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
            $this->addUblElement($dom, $taxScheme, 'cbc:ID', 'VAT');
            $taxCategory->appendChild($taxScheme);
            $elem->appendChild($taxCategory);
        }

        return $elem;
    }

    private function createUblInvoiceLine(DOMDocument $dom, InvoiceLine $line, string $currency, string $lineTag): DOMElement {
        $lineElem = $dom->createElementNS(self::CAC_NS, $lineTag);

        $this->addUblElement($dom, $lineElem, 'cbc:ID', $line->getId());

        if ($line->getNote() !== null) {
            $this->addUblElement($dom, $lineElem, 'cbc:Note', $line->getNote());
        }

        $qtyTag = str_contains($lineTag, 'CreditNote') ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $qty = $this->addUblElement($dom, $lineElem, $qtyTag, $this->formatAmount($line->getQuantity()));
        $qty->setAttribute('unitCode', $line->getUnitCode()->value);

        $lineExtAmount = $this->addUblElement(
            $dom,
            $lineElem,
            'cbc:LineExtensionAmount',
            $this->formatAmount($line->getNetAmount())
        );
        $lineExtAmount->setAttribute('currencyID', $currency);

        if ($line->getAccountingCost() !== null) {
            $this->addUblElement($dom, $lineElem, 'cbc:AccountingCost', $line->getAccountingCost());
        }

        // OrderLineReference
        // ItemAllowanceCharge
        foreach ($line->getAllowanceCharges() as $ac) {
            $acElem = $this->createUblAllowanceCharge($dom, $ac, $currency);
            $lineElem->appendChild($acElem);
        }

        // Item
        $item = $dom->createElementNS(self::CAC_NS, 'cac:Item');
        if ($line->getItemDescription() !== null) {
            $this->addUblElement($dom, $item, 'cbc:Description', $line->getItemDescription());
        }
        $this->addUblElement($dom, $item, 'cbc:Name', $line->getItemName());

        if ($line->getBuyersItemId() !== null) {
            $buyersItem = $dom->createElementNS(self::CAC_NS, 'cac:BuyersItemIdentification');
            $this->addUblElement($dom, $buyersItem, 'cbc:ID', $line->getBuyersItemId());
            $item->appendChild($buyersItem);
        }

        if ($line->getSellersItemId() !== null) {
            $sellersItem = $dom->createElementNS(self::CAC_NS, 'cac:SellersItemIdentification');
            $this->addUblElement($dom, $sellersItem, 'cbc:ID', $line->getSellersItemId());
            $item->appendChild($sellersItem);
        }

        if ($line->getStandardItemId() !== null) {
            $standardItem = $dom->createElementNS(self::CAC_NS, 'cac:StandardItemIdentification');
            $stdId = $this->addUblElement($dom, $standardItem, 'cbc:ID', $line->getStandardItemId());
            if ($line->getStandardItemScheme() !== null) {
                $stdId->setAttribute('schemeID', $line->getStandardItemScheme());
            }
            $item->appendChild($standardItem);
        }

        // ClassifiedTaxCategory
        $taxCategory = $dom->createElementNS(self::CAC_NS, 'cac:ClassifiedTaxCategory');
        $this->addUblElement($dom, $taxCategory, 'cbc:ID', $line->getTaxCategory()->value);
        $this->addUblElement($dom, $taxCategory, 'cbc:Percent', $this->formatAmount($line->getTaxPercent()));
        $taxScheme = $dom->createElementNS(self::CAC_NS, 'cac:TaxScheme');
        $this->addUblElement($dom, $taxScheme, 'cbc:ID', 'VAT');
        $taxCategory->appendChild($taxScheme);
        $item->appendChild($taxCategory);

        $lineElem->appendChild($item);

        // Price
        $price = $dom->createElementNS(self::CAC_NS, 'cac:Price');
        $priceAmount = $this->addUblElement($dom, $price, 'cbc:PriceAmount', $this->formatAmount($line->getUnitPrice()));
        $priceAmount->setAttribute('currencyID', $currency);
        if ($line->getBaseQuantity() !== null && $line->getBaseQuantity() !== 1.0) {
            $baseQty = $this->addUblElement($dom, $price, 'cbc:BaseQuantity', $this->formatAmount($line->getBaseQuantity()));
            $baseQty->setAttribute('unitCode', $line->getUnitCode()->value);
        }
        $lineElem->appendChild($price);

        return $lineElem;
    }

    private function createCiiLineItem(DOMDocument $dom, InvoiceLine $line, string $currency): DOMElement {
        $lineItem = $dom->createElementNS(self::RAM_NS, 'ram:IncludedSupplyChainTradeLineItem');

        // AssociatedDocumentLineDocument
        $assocDoc = $dom->createElementNS(self::RAM_NS, 'ram:AssociatedDocumentLineDocument');
        $this->addCiiElement($dom, $assocDoc, 'ram:LineID', $line->getId());
        if ($line->getNote() !== null) {
            $note = $dom->createElementNS(self::RAM_NS, 'ram:IncludedNote');
            $this->addCiiElement($dom, $note, 'ram:Content', $line->getNote());
            $assocDoc->appendChild($note);
        }
        $lineItem->appendChild($assocDoc);

        // SpecifiedTradeProduct
        $product = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeProduct');
        if ($line->getSellersItemId() !== null) {
            $this->addCiiElement($dom, $product, 'ram:SellerAssignedID', $line->getSellersItemId());
        }
        if ($line->getBuyersItemId() !== null) {
            $this->addCiiElement($dom, $product, 'ram:BuyerAssignedID', $line->getBuyersItemId());
        }
        if ($line->getStandardItemId() !== null) {
            $globalId = $this->addCiiElement($dom, $product, 'ram:GlobalID', $line->getStandardItemId());
            if ($line->getStandardItemScheme() !== null) {
                $globalId->setAttribute('schemeID', $line->getStandardItemScheme());
            }
        }
        $this->addCiiElement($dom, $product, 'ram:Name', $line->getItemName());
        if ($line->getItemDescription() !== null) {
            $this->addCiiElement($dom, $product, 'ram:Description', $line->getItemDescription());
        }
        $lineItem->appendChild($product);

        // SpecifiedLineTradeAgreement
        $agreement = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeAgreement');
        $netPrice = $dom->createElementNS(self::RAM_NS, 'ram:NetPriceProductTradePrice');
        $chargeAmount = $this->addCiiElement($dom, $netPrice, 'ram:ChargeAmount', $this->formatAmount($line->getUnitPrice()));
        if ($line->getBaseQuantity() !== null && $line->getBaseQuantity() !== 1.0) {
            $basisQty = $dom->createElementNS(self::RAM_NS, 'ram:BasisQuantity');
            $basisQty->setAttribute('unitCode', $line->getUnitCode()->value);
            $basisQty->textContent = $this->formatAmount($line->getBaseQuantity());
            $netPrice->appendChild($basisQty);
        }
        $agreement->appendChild($netPrice);
        $lineItem->appendChild($agreement);

        // SpecifiedLineTradeDelivery
        $delivery = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeDelivery');
        $billedQty = $dom->createElementNS(self::RAM_NS, 'ram:BilledQuantity');
        $billedQty->setAttribute('unitCode', $line->getUnitCode()->value);
        $billedQty->textContent = $this->formatAmount($line->getQuantity());
        $delivery->appendChild($billedQty);
        $lineItem->appendChild($delivery);

        // SpecifiedLineTradeSettlement
        $settlement = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedLineTradeSettlement');

        // ApplicableTradeTax
        $tax = $dom->createElementNS(self::RAM_NS, 'ram:ApplicableTradeTax');
        $this->addCiiElement($dom, $tax, 'ram:TypeCode', 'VAT');
        $this->addCiiElement($dom, $tax, 'ram:CategoryCode', $line->getTaxCategory()->value);
        $this->addCiiElement($dom, $tax, 'ram:RateApplicablePercent', $this->formatAmount($line->getTaxPercent()));
        $settlement->appendChild($tax);

        // SpecifiedTradeSettlementLineMonetarySummation
        $summation = $dom->createElementNS(self::RAM_NS, 'ram:SpecifiedTradeSettlementLineMonetarySummation');
        $this->addCiiElement($dom, $summation, 'ram:LineTotalAmount', $this->formatAmount($line->getNetAmount()));
        $settlement->appendChild($summation);

        $lineItem->appendChild($settlement);

        return $lineItem;
    }

    private function formatAmount(float $amount): string {
        return number_format($amount, 2, '.', '');
    }
}
