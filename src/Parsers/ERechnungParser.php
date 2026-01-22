<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : ERechnungParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Entities\AllowanceCharge;
use ERechnungToolkit\Entities\Document;
use ERechnungToolkit\Entities\InvoiceLine;
use ERechnungToolkit\Entities\MonetaryTotal;
use ERechnungToolkit\Entities\Party;
use ERechnungToolkit\Entities\PaymentTerms;
use ERechnungToolkit\Entities\PostalAddress;
use ERechnungToolkit\Entities\TaxSubtotal;
use ERechnungToolkit\Entities\TaxTotal;
use ERechnungToolkit\Enums\AllowanceChargeReasonCode;
use ERechnungToolkit\Enums\ERechnungProfile;
use ERechnungToolkit\Enums\InvoiceType;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Enums\TaxCategory;
use ERechnungToolkit\Enums\UnitCode;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parser for E-Rechnung XML documents.
 * 
 * Supports:
 * - UBL 2.1 (Universal Business Language) - XRechnung
 * - UN/CEFACT CII D16B (Cross Industry Invoice) - ZUGFeRD/Factur-X
 * 
 * @package ERechnungToolkit\Parsers
 */
final class ERechnungParser {
    private const UBL_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const UBL_CN_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CII_NS = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const UDT_NS = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    private DOMDocument $dom;
    private DOMXPath $xpath;
    private bool $isUbl = false;
    private bool $isCii = false;
    private bool $isCreditNote = false;

    /**
     * Parses an E-Rechnung document from XML string.
     */
    public function parse(string $xml): Document {
        $this->dom = new DOMDocument();

        // Suppress warnings and handle errors properly
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $this->dom->loadXML($xml);

        if (!$loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);

            $errorMsg = 'Failed to parse XML';
            if (!empty($errors)) {
                $errorMsg .= ': ' . $errors[0]->message;
            }
            throw new \RuntimeException($errorMsg);
        }

        libxml_use_internal_errors($internalErrors);

        $this->detectFormat();
        $this->setupXPath();

        if ($this->isUbl) {
            return $this->parseUbl();
        } elseif ($this->isCii) {
            return $this->parseCii();
        }

        throw new \RuntimeException('Unknown E-Rechnung format. Expected UBL or CII.');
    }

    /**
     * Parses an E-Rechnung document from file.
     */
    public function parseFile(string $filePath): Document {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $xml = file_get_contents($filePath);
        if ($xml === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        return $this->parse($xml);
    }

    /**
     * Detects the XML format (UBL or CII).
     */
    private function detectFormat(): void {
        $root = $this->dom->documentElement;

        if ($root === null) {
            throw new \RuntimeException('No root element found in XML document');
        }

        $ns = $root->namespaceURI;
        $localName = $root->localName;

        if ($ns === self::UBL_NS && $localName === 'Invoice') {
            $this->isUbl = true;
            $this->isCreditNote = false;
        } elseif ($ns === self::UBL_CN_NS && $localName === 'CreditNote') {
            $this->isUbl = true;
            $this->isCreditNote = true;
        } elseif ($ns === self::CII_NS && $localName === 'CrossIndustryInvoice') {
            $this->isCii = true;
        }
    }

    /**
     * Sets up XPath with namespaces.
     */
    private function setupXPath(): void {
        $this->xpath = new DOMXPath($this->dom);

        if ($this->isUbl) {
            if ($this->isCreditNote) {
                $this->xpath->registerNamespace('ubl', self::UBL_CN_NS);
            } else {
                $this->xpath->registerNamespace('ubl', self::UBL_NS);
            }
            $this->xpath->registerNamespace('cac', self::CAC_NS);
            $this->xpath->registerNamespace('cbc', self::CBC_NS);
        } elseif ($this->isCii) {
            $this->xpath->registerNamespace('rsm', self::CII_NS);
            $this->xpath->registerNamespace('ram', self::RAM_NS);
            $this->xpath->registerNamespace('udt', self::UDT_NS);
        }
    }

    /**
     * Parses a UBL document.
     */
    private function parseUbl(): Document {
        $root = $this->isCreditNote ? '/ubl:CreditNote' : '/ubl:Invoice';

        // Basic fields
        $id = $this->getUblValue("{$root}/cbc:ID");
        $issueDate = new DateTimeImmutable($this->getUblValue("{$root}/cbc:IssueDate"));

        $typeCode = $this->isCreditNote
            ? $this->getUblValue("{$root}/cbc:CreditNoteTypeCode")
            : $this->getUblValue("{$root}/cbc:InvoiceTypeCode");
        $invoiceType = InvoiceType::fromCode($typeCode) ?? InvoiceType::INVOICE;

        $currencyCode = $this->getUblValue("{$root}/cbc:DocumentCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode) ?? CurrencyCode::Euro;

        $profileId = $this->getUblValue("{$root}/cbc:CustomizationID");
        $profile = $this->detectProfile($profileId);

        // Seller
        $seller = $this->parseUblParty("{$root}/cac:AccountingSupplierParty/cac:Party");

        // Buyer
        $buyer = $this->parseUblParty("{$root}/cac:AccountingCustomerParty/cac:Party");

        // Create document
        $document = new Document(
            id: $id,
            issueDate: $issueDate,
            invoiceType: $invoiceType,
            seller: $seller,
            buyer: $buyer,
            currency: $currency,
            profile: $profile,
            dueDate: $this->getUblDate("{$root}/cbc:DueDate"),
            taxPointDate: $this->getUblDate("{$root}/cbc:TaxPointDate"),
            buyerReference: $this->getUblValue("{$root}/cbc:BuyerReference"),
            orderReference: $this->getUblValue("{$root}/cac:OrderReference/cbc:ID"),
            contractReference: $this->getUblValue("{$root}/cac:ContractDocumentReference/cbc:ID"),
            projectReference: $this->getUblValue("{$root}/cac:ProjectReference/cbc:ID"),
            paymentMeansCode: $this->parsePaymentMeansCode("{$root}/cac:PaymentMeans/cbc:PaymentMeansCode"),
            paymentTerms: $this->parseUblPaymentTerms("{$root}/cac:PaymentTerms"),
            deliveryDate: $this->getUblDate("{$root}/cac:Delivery/cbc:ActualDeliveryDate"),
            precedingInvoiceReference: $this->getUblValue("{$root}/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID")
        );

        // Notes
        foreach ($this->xpath->query("{$root}/cbc:Note") as $noteNode) {
            $document->addNote($noteNode->textContent);
        }

        // Allowances/Charges
        foreach ($this->xpath->query("{$root}/cac:AllowanceCharge") as $acNode) {
            $ac = $this->parseUblAllowanceCharge($acNode);
            if ($ac !== null) {
                $document->addAllowanceCharge($ac);
            }
        }

        // Lines
        $lineTag = $this->isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        foreach ($this->xpath->query("{$root}/{$lineTag}") as $lineNode) {
            $line = $this->parseUblLine($lineNode);
            if ($line !== null) {
                $document->addLine($line);
            }
        }

        // Tax Total
        $taxTotal = $this->parseUblTaxTotal("{$root}/cac:TaxTotal", $currency);
        if ($taxTotal !== null) {
            $document->setTaxTotal($taxTotal);
        }

        // Monetary Total
        $monetaryTotal = $this->parseUblMonetaryTotal("{$root}/cac:LegalMonetaryTotal", $currency);
        if ($monetaryTotal !== null) {
            $document->setMonetaryTotal($monetaryTotal);
        }

        return $document;
    }

    /**
     * Parses a CII document.
     */
    private function parseCii(): Document {
        $root = '/rsm:CrossIndustryInvoice';

        // Basic fields
        $id = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:ID");

        $issueDateStr = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString");
        $issueDate = $this->parseCiiDate($issueDateStr);

        $typeCode = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:TypeCode");
        $invoiceType = InvoiceType::fromCode($typeCode) ?? InvoiceType::INVOICE;
        $this->isCreditNote = $invoiceType->isCredit();

        $profileId = $this->getCiiValue("{$root}/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID");
        $profile = $this->detectProfile($profileId);

        $currencyCode = $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode) ?? CurrencyCode::Euro;

        // Seller
        $seller = $this->parseCiiParty("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty");

        // Buyer
        $buyer = $this->parseCiiParty("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty");

        // Payment means
        $paymentCode = $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode");

        // Due date
        $dueDateStr = $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString");

        // Delivery date
        $deliveryDateStr = $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString");

        // Create document
        $document = new Document(
            id: $id,
            issueDate: $issueDate,
            invoiceType: $invoiceType,
            seller: $seller,
            buyer: $buyer,
            currency: $currency,
            profile: $profile,
            dueDate: $dueDateStr ? $this->parseCiiDate($dueDateStr) : null,
            buyerReference: $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerReference"),
            orderReference: $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID"),
            contractReference: $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID"),
            paymentMeansCode: $paymentCode ? PaymentMeansCode::fromCode($paymentCode) : null,
            deliveryDate: $deliveryDateStr ? $this->parseCiiDate($deliveryDateStr) : null
        );

        // Notes
        foreach ($this->xpath->query("{$root}/rsm:ExchangedDocument/ram:IncludedNote/ram:Content") as $noteNode) {
            $document->addNote($noteNode->textContent);
        }

        // Lines
        foreach ($this->xpath->query("{$root}/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem") as $lineNode) {
            $line = $this->parseCiiLine($lineNode);
            if ($line !== null) {
                $document->addLine($line);
            }
        }

        // Tax Total
        $taxTotal = $this->parseCiiTaxTotal("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement", $currency);
        if ($taxTotal !== null) {
            $document->setTaxTotal($taxTotal);
        }

        // Monetary Total
        $monetaryTotal = $this->parseCiiMonetaryTotal("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation", $currency);
        if ($monetaryTotal !== null) {
            $document->setMonetaryTotal($monetaryTotal);
        }

        return $document;
    }

    // === UBL Parser Helpers ===

    private function getUblValue(string $xpath): ?string {
        $nodes = $this->xpath->query($xpath);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function getUblDate(string $xpath): ?DateTimeImmutable {
        $value = $this->getUblValue($xpath);
        if ($value === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseUblParty(string $xpath): Party {
        $name = $this->getUblValue("{$xpath}/cac:PartyName/cbc:Name")
            ?? $this->getUblValue("{$xpath}/cac:PartyLegalEntity/cbc:RegistrationName")
            ?? '';

        $address = $this->parseUblAddress("{$xpath}/cac:PostalAddress");

        $vatId = $this->getUblValue("{$xpath}/cac:PartyTaxScheme/cbc:CompanyID");
        $taxId = $this->getUblValue("{$xpath}/cac:PartyLegalEntity/cbc:CompanyID");

        $endpointId = $this->getUblValue("{$xpath}/cbc:EndpointID");
        $endpointScheme = null;
        $endpointNodes = $this->xpath->query("{$xpath}/cbc:EndpointID");
        if ($endpointNodes->length > 0 && $endpointNodes->item(0) instanceof \DOMElement) {
            /** @var \DOMElement $endpointNode */
            $endpointNode = $endpointNodes->item(0);
            $endpointScheme = $endpointNode->getAttribute('schemeID') ?: null;
        }

        $contactName = $this->getUblValue("{$xpath}/cac:Contact/cbc:Name");
        $contactPhone = $this->getUblValue("{$xpath}/cac:Contact/cbc:Telephone");
        $contactEmail = $this->getUblValue("{$xpath}/cac:Contact/cbc:ElectronicMail");

        return new Party(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            taxRegistrationId: $taxId,
            endpointId: $endpointId,
            endpointScheme: $endpointScheme,
            contactName: $contactName,
            contactPhone: $contactPhone,
            contactEmail: $contactEmail
        );
    }

    private function parseUblAddress(string $xpath): ?PostalAddress {
        $street = $this->getUblValue("{$xpath}/cbc:StreetName");
        if ($street === null) {
            return null;
        }

        return new PostalAddress(
            streetName: $street,
            additionalStreetName: $this->getUblValue("{$xpath}/cbc:AdditionalStreetName"),
            postalCode: $this->getUblValue("{$xpath}/cbc:PostalZone"),
            city: $this->getUblValue("{$xpath}/cbc:CityName"),
            countrySubdivision: $this->getUblValue("{$xpath}/cbc:CountrySubentity"),
            country: $this->getUblValue("{$xpath}/cac:Country/cbc:IdentificationCode")
        );
    }

    private function parseUblAllowanceCharge(DOMElement $node): ?AllowanceCharge {
        $chargeIndicator = $this->getNodeValue($node, 'cbc:ChargeIndicator') === 'true';
        $amount = (float)($this->getNodeValue($node, 'cbc:Amount') ?? '0');

        $reasonCode = $this->getNodeValue($node, 'cbc:AllowanceChargeReasonCode');
        $reason = $this->getNodeValue($node, 'cbc:AllowanceChargeReason');
        $percentage = $this->getNodeValue($node, 'cbc:MultiplierFactorNumeric');
        $baseAmount = $this->getNodeValue($node, 'cbc:BaseAmount');

        $taxCategoryCode = null;
        $taxPercent = null;
        $taxCatNodes = $this->xpath->query('cac:TaxCategory', $node);
        if ($taxCatNodes->length > 0) {
            $taxCatNode = $taxCatNodes->item(0);
            $taxCategoryCode = $this->getNodeValue($taxCatNode, 'cbc:ID');
            $taxPercent = $this->getNodeValue($taxCatNode, 'cbc:Percent');
        }

        return new AllowanceCharge(
            chargeIndicator: $chargeIndicator,
            amount: $amount,
            reasonCode: $reasonCode ? AllowanceChargeReasonCode::tryFrom($reasonCode) : null,
            reason: $reason,
            baseAmount: $baseAmount !== null ? (float)$baseAmount : null,
            percentage: $percentage !== null ? (float)$percentage : null,
            taxCategory: $taxCategoryCode ? TaxCategory::tryFrom($taxCategoryCode) : null,
            taxPercent: $taxPercent !== null ? (float)$taxPercent : null
        );
    }

    private function parseUblLine(DOMElement $node): ?InvoiceLine {
        $id = $this->getNodeValue($node, 'cbc:ID') ?? '';

        $qtyTag = $this->isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $qtyNodes = $this->xpath->query($qtyTag, $node);
        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        if ($qtyNodes->length > 0) {
            $qtyNode = $qtyNodes->item(0);
            $quantity = (float)$qtyNode->textContent;
            if ($qtyNode instanceof \DOMElement) {
                $unitCodeStr = $qtyNode->getAttribute('unitCode');
                $unitCode = UnitCode::tryFrom($unitCodeStr) ?? UnitCode::PIECE;
            }
        }

        $netAmount = (float)($this->getNodeValue($node, 'cbc:LineExtensionAmount') ?? '0');

        $itemName = $this->getNodeValue($node, 'cac:Item/cbc:Name') ?? '';
        $itemDescription = $this->getNodeValue($node, 'cac:Item/cbc:Description');

        $unitPrice = (float)($this->getNodeValue($node, 'cac:Price/cbc:PriceAmount') ?? '0');

        $taxCategoryCode = $this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID');
        $taxPercent = (float)($this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent') ?? '0');

        $sellersItemId = $this->getNodeValue($node, 'cac:Item/cac:SellersItemIdentification/cbc:ID');
        $buyersItemId = $this->getNodeValue($node, 'cac:Item/cac:BuyersItemIdentification/cbc:ID');

        return new InvoiceLine(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $itemName,
            unitPrice: $unitPrice,
            taxCategory: TaxCategory::tryFrom($taxCategoryCode ?? 'S') ?? TaxCategory::STANDARD,
            taxPercent: $taxPercent,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId,
            buyersItemId: $buyersItemId,
            note: $this->getNodeValue($node, 'cbc:Note'),
            accountingCost: $this->getNodeValue($node, 'cbc:AccountingCost')
        );
    }

    private function parseUblTaxTotal(string $xpath, CurrencyCode $currency): ?TaxTotal {
        $totalAmount = (float)($this->getUblValue("{$xpath}/cbc:TaxAmount") ?? '0');

        $subtotals = [];
        foreach ($this->xpath->query("{$xpath}/cac:TaxSubtotal") as $subNode) {
            $taxableAmount = (float)($this->getNodeValue($subNode, 'cbc:TaxableAmount') ?? '0');
            $taxAmount = (float)($this->getNodeValue($subNode, 'cbc:TaxAmount') ?? '0');
            $categoryCode = $this->getNodeValue($subNode, 'cac:TaxCategory/cbc:ID');
            $percent = (float)($this->getNodeValue($subNode, 'cac:TaxCategory/cbc:Percent') ?? '0');
            $exemptionReason = $this->getNodeValue($subNode, 'cac:TaxCategory/cbc:TaxExemptionReason');

            $subtotals[] = new TaxSubtotal(
                taxableAmount: $taxableAmount,
                taxAmount: $taxAmount,
                category: TaxCategory::tryFrom($categoryCode ?? 'S') ?? TaxCategory::STANDARD,
                percent: $percent,
                exemptionReason: $exemptionReason
            );
        }

        if (empty($subtotals) && $totalAmount === 0.0) {
            return null;
        }

        return new TaxTotal($totalAmount, $currency, $subtotals);
    }

    private function parseUblMonetaryTotal(string $xpath, CurrencyCode $currency): ?MonetaryTotal {
        $lineExtension = (float)($this->getUblValue("{$xpath}/cbc:LineExtensionAmount") ?? '0');
        $taxExclusive = (float)($this->getUblValue("{$xpath}/cbc:TaxExclusiveAmount") ?? '0');
        $taxInclusive = (float)($this->getUblValue("{$xpath}/cbc:TaxInclusiveAmount") ?? '0');
        $payable = (float)($this->getUblValue("{$xpath}/cbc:PayableAmount") ?? '0');
        $allowance = (float)($this->getUblValue("{$xpath}/cbc:AllowanceTotalAmount") ?? '0');
        $charge = (float)($this->getUblValue("{$xpath}/cbc:ChargeTotalAmount") ?? '0');
        $prepaid = (float)($this->getUblValue("{$xpath}/cbc:PrepaidAmount") ?? '0');

        return new MonetaryTotal(
            lineExtensionAmount: $lineExtension,
            taxExclusiveAmount: $taxExclusive,
            taxInclusiveAmount: $taxInclusive,
            payableAmount: $payable,
            currency: $currency,
            allowanceTotalAmount: $allowance,
            chargeTotalAmount: $charge,
            prepaidAmount: $prepaid
        );
    }

    private function parseUblPaymentTerms(string $xpath): ?PaymentTerms {
        $note = $this->getUblValue("{$xpath}/cbc:Note");
        if ($note === null) {
            return null;
        }
        return new PaymentTerms(note: $note);
    }

    private function parsePaymentMeansCode(string $xpath): ?PaymentMeansCode {
        $code = $this->getUblValue($xpath);
        if ($code === null) {
            return null;
        }
        return PaymentMeansCode::fromCode($code);
    }

    // === CII Parser Helpers ===

    private function getCiiValue(string $xpath): ?string {
        $nodes = $this->xpath->query($xpath);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function parseCiiDate(?string $dateStr): ?DateTimeImmutable {
        if ($dateStr === null) {
            return null;
        }
        // Format 102 = YYYYMMDD
        try {
            return DateTimeImmutable::createFromFormat('Ymd', $dateStr) ?: new DateTimeImmutable($dateStr);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseCiiParty(string $xpath): Party {
        $name = $this->getCiiValue("{$xpath}/ram:Name") ?? '';

        $street = $this->getCiiValue("{$xpath}/ram:PostalTradeAddress/ram:LineOne");
        $address = $street !== null ? new PostalAddress(
            streetName: $street,
            additionalStreetName: $this->getCiiValue("{$xpath}/ram:PostalTradeAddress/ram:LineTwo"),
            postalCode: $this->getCiiValue("{$xpath}/ram:PostalTradeAddress/ram:PostcodeCode"),
            city: $this->getCiiValue("{$xpath}/ram:PostalTradeAddress/ram:CityName"),
            country: $this->getCiiValue("{$xpath}/ram:PostalTradeAddress/ram:CountryID")
        ) : null;

        $vatId = $this->getCiiValue("{$xpath}/ram:SpecifiedTaxRegistration/ram:ID");

        $endpointId = $this->getCiiValue("{$xpath}/ram:URIUniversalCommunication/ram:URIID");
        $endpointScheme = null;
        $endpointNodes = $this->xpath->query("{$xpath}/ram:URIUniversalCommunication/ram:URIID");
        if ($endpointNodes->length > 0 && $endpointNodes->item(0) instanceof \DOMElement) {
            /** @var \DOMElement $endpointNode */
            $endpointNode = $endpointNodes->item(0);
            $endpointScheme = $endpointNode->getAttribute('schemeID') ?: null;
        }

        $contactName = $this->getCiiValue("{$xpath}/ram:DefinedTradeContact/ram:PersonName");
        $contactPhone = $this->getCiiValue("{$xpath}/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber");
        $contactEmail = $this->getCiiValue("{$xpath}/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID");

        return new Party(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            endpointId: $endpointId,
            endpointScheme: $endpointScheme,
            contactName: $contactName,
            contactPhone: $contactPhone,
            contactEmail: $contactEmail
        );
    }

    private function parseCiiLine(DOMElement $node): ?InvoiceLine {
        $id = '';
        $assocDocNodes = $this->xpath->query('ram:AssociatedDocumentLineDocument/ram:LineID', $node);
        if ($assocDocNodes->length > 0) {
            $id = $assocDocNodes->item(0)->textContent;
        }

        $itemName = '';
        $nameNodes = $this->xpath->query('ram:SpecifiedTradeProduct/ram:Name', $node);
        if ($nameNodes->length > 0) {
            $itemName = $nameNodes->item(0)->textContent;
        }

        $itemDescription = null;
        $descNodes = $this->xpath->query('ram:SpecifiedTradeProduct/ram:Description', $node);
        if ($descNodes->length > 0) {
            $itemDescription = $descNodes->item(0)->textContent;
        }

        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        $qtyNodes = $this->xpath->query('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $node);
        if ($qtyNodes->length > 0) {
            $qtyNode = $qtyNodes->item(0);
            $quantity = (float)$qtyNode->textContent;
            if ($qtyNode instanceof \DOMElement) {
                $unitCodeStr = $qtyNode->getAttribute('unitCode');
                $unitCode = UnitCode::tryFrom($unitCodeStr) ?? UnitCode::PIECE;
            }
        }

        $unitPrice = 0.0;
        $priceNodes = $this->xpath->query('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $node);
        if ($priceNodes->length > 0) {
            $unitPrice = (float)$priceNodes->item(0)->textContent;
        }

        $netAmount = 0.0;
        $netNodes = $this->xpath->query('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $node);
        if ($netNodes->length > 0) {
            $netAmount = (float)$netNodes->item(0)->textContent;
        }

        $taxCategoryCode = 'S';
        $taxPercent = 0.0;
        $taxNodes = $this->xpath->query('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax', $node);
        if ($taxNodes->length > 0) {
            $taxNode = $taxNodes->item(0);
            $catNodes = $this->xpath->query('ram:CategoryCode', $taxNode);
            if ($catNodes->length > 0) {
                $taxCategoryCode = $catNodes->item(0)->textContent;
            }
            $rateNodes = $this->xpath->query('ram:RateApplicablePercent', $taxNode);
            if ($rateNodes->length > 0) {
                $taxPercent = (float)$rateNodes->item(0)->textContent;
            }
        }

        $sellersItemId = null;
        $sellerIdNodes = $this->xpath->query('ram:SpecifiedTradeProduct/ram:SellerAssignedID', $node);
        if ($sellerIdNodes->length > 0) {
            $sellersItemId = $sellerIdNodes->item(0)->textContent;
        }

        return new InvoiceLine(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $itemName,
            unitPrice: $unitPrice,
            taxCategory: TaxCategory::tryFrom($taxCategoryCode) ?? TaxCategory::STANDARD,
            taxPercent: $taxPercent,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId
        );
    }

    private function parseCiiTaxTotal(string $xpath, CurrencyCode $currency): ?TaxTotal {
        $subtotals = [];

        foreach ($this->xpath->query("{$xpath}/ram:ApplicableTradeTax") as $taxNode) {
            $taxAmount = (float)($this->getNodeValue($taxNode, 'ram:CalculatedAmount') ?? '0');
            $taxableAmount = (float)($this->getNodeValue($taxNode, 'ram:BasisAmount') ?? '0');
            $categoryCode = $this->getNodeValue($taxNode, 'ram:CategoryCode');
            $percent = (float)($this->getNodeValue($taxNode, 'ram:RateApplicablePercent') ?? '0');
            $exemptionReason = $this->getNodeValue($taxNode, 'ram:ExemptionReason');

            $subtotals[] = new TaxSubtotal(
                taxableAmount: $taxableAmount,
                taxAmount: $taxAmount,
                category: TaxCategory::tryFrom($categoryCode ?? 'S') ?? TaxCategory::STANDARD,
                percent: $percent,
                exemptionReason: $exemptionReason
            );
        }

        if (empty($subtotals)) {
            return null;
        }

        return TaxTotal::fromSubtotals($subtotals, $currency);
    }

    private function parseCiiMonetaryTotal(string $xpath, CurrencyCode $currency): ?MonetaryTotal {
        $lineTotal = (float)($this->getCiiValue("{$xpath}/ram:LineTotalAmount") ?? '0');
        $taxBasis = (float)($this->getCiiValue("{$xpath}/ram:TaxBasisTotalAmount") ?? '0');
        $grandTotal = (float)($this->getCiiValue("{$xpath}/ram:GrandTotalAmount") ?? '0');
        $duePayable = (float)($this->getCiiValue("{$xpath}/ram:DuePayableAmount") ?? '0');
        $allowance = (float)($this->getCiiValue("{$xpath}/ram:AllowanceTotalAmount") ?? '0');
        $charge = (float)($this->getCiiValue("{$xpath}/ram:ChargeTotalAmount") ?? '0');
        $prepaid = (float)($this->getCiiValue("{$xpath}/ram:TotalPrepaidAmount") ?? '0');

        return new MonetaryTotal(
            lineExtensionAmount: $lineTotal,
            taxExclusiveAmount: $taxBasis,
            taxInclusiveAmount: $grandTotal,
            payableAmount: $duePayable,
            currency: $currency,
            allowanceTotalAmount: $allowance,
            chargeTotalAmount: $charge,
            prepaidAmount: $prepaid
        );
    }

    // === Common Helpers ===

    private function getNodeValue(DOMElement $node, string $xpath): ?string {
        $nodes = $this->xpath->query($xpath, $node);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function detectProfile(?string $profileId): ERechnungProfile {
        if ($profileId === null) {
            return ERechnungProfile::EN16931;
        }

        // Check for exact match first
        foreach (ERechnungProfile::cases() as $profile) {
            if ($profileId === $profile->value) {
                return $profile;
            }
        }

        // Check for specific profiles (most specific first to avoid partial matches)
        // XRechnung profiles (contains "xrechnung" keyword)
        if (str_contains(strtolower($profileId), 'xrechnung')) {
            if (str_contains(strtolower($profileId), 'extension') || str_contains($profileId, 'conformant')) {
                return ERechnungProfile::XRECHNUNG_EXTENSION;
            }
            return ERechnungProfile::XRECHNUNG;
        }

        // Extended profile
        if (str_contains(strtolower($profileId), 'extended')) {
            return ERechnungProfile::EXTENDED;
        }

        // Basic profiles
        if (str_contains(strtolower($profileId), 'basic')) {
            return str_contains(strtolower($profileId), 'wl')
                ? ERechnungProfile::BASIC_WL
                : ERechnungProfile::BASIC;
        }

        // Minimum profile
        if (str_contains(strtolower($profileId), 'minimum')) {
            return ERechnungProfile::MINIMUM;
        }

        // Check for xoev-de in the URN (XRechnung indicator)
        if (str_contains($profileId, 'xoev-de:kosit')) {
            return ERechnungProfile::XRECHNUNG;
        }

        // Fallback for EN16931 (also matches partial URN)
        if (str_contains($profileId, 'en16931') || str_contains($profileId, 'EN16931')) {
            return ERechnungProfile::EN16931;
        }

        return ERechnungProfile::EN16931;
    }
}