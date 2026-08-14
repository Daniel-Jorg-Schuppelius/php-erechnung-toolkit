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
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\{AllowanceCharge, Document, InvoiceLine, MonetaryTotal, Party, PaymentTerms, PostalAddress, TaxSubtotal, TaxTotal};
use ERechnungToolkit\Enums\{AllowanceChargeReasonCode, ERechnungProfile, InvoiceType, PaymentMeansCode, TaxCategory, UnitCode};
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for E-Rechnung XML documents.
 *
 * Supports:
 * - UBL 2.1 (Universal Business Language) - XRechnung
 * - UN/CEFACT CII D16B (Cross Industry Invoice) - ZUGFeRD/Factur-X
 */
final class ERechnungParser {
    use ErrorLog;
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
        $this->dom = new DOMDocument;

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
            $this->logErrorAndThrow(RuntimeException::class, $errorMsg);
        }

        libxml_use_internal_errors($internalErrors);

        $this->detectFormat();
        $this->setupXPath();

        if ($this->isUbl) {
            $this->logDebug('Parsing UBL document', ['isCreditNote' => $this->isCreditNote]);
            return $this->parseUbl();
        } elseif ($this->isCii) {
            $this->logDebug('Parsing CII document');
            return $this->parseCii();
        }

        $this->logErrorAndThrow(RuntimeException::class, 'Unknown E-Rechnung format. Expected UBL or CII.');
    }

    /**
     * Parses an E-Rechnung document from file.
     */
    public function parseFile(string $filePath): Document {
        $this->logDebug('Parsing E-Rechnung from file', ['path' => $filePath]);
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }

        $xml = file_get_contents($filePath);
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }

        return $this->parse($xml);
    }

    /**
     * Detects the XML format (UBL or CII).
     */
    private function detectFormat(): void {
        // Reset format flags for reuse of parser instance
        $this->isUbl = false;
        $this->isCii = false;
        $this->isCreditNote = false;

        $root = $this->dom->documentElement;

        if ($root === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'No root element found in XML document');
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
        $id = $this->getUblValue("{$root}/cbc:ID") ?? '';
        $issueDateStr = $this->getUblValue("{$root}/cbc:IssueDate");
        if ($issueDateStr === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'Missing required cbc:IssueDate');
        }
        $issueDate = new DateTimeImmutable($issueDateStr);

        $typeCode = $this->isCreditNote
            ? $this->getUblValue("{$root}/cbc:CreditNoteTypeCode")
            : $this->getUblValue("{$root}/cbc:InvoiceTypeCode");
        $invoiceType = InvoiceType::fromCode($typeCode ?? '') ?? InvoiceType::INVOICE;

        $currencyCode = $this->getUblValue("{$root}/cbc:DocumentCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode ?? '') ?? CurrencyCode::Euro;

        $profileId = $this->getUblValue("{$root}/cbc:CustomizationID");
        $profile = $this->detectProfile($profileId);

        // Seller
        $seller = $this->parseUblParty("{$root}/cac:AccountingSupplierParty/cac:Party");

        // Zahlungsverbindung (BG-17): PayeeFinancialAccount → Seller-Bankdaten.
        $iban = $this->getUblValue("{$root}/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID");
        if ($iban !== null && $iban !== '') {
            $seller = $seller->withBankingInfo(
                $iban,
                $this->getUblValue("{$root}/cac:PaymentMeans/cac:PayeeFinancialAccount/cac:FinancialInstitutionBranch/cbc:ID")
            );
        }

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

        // Verwendungszweck (BT-83).
        $paymentId = $this->getUblValue("{$root}/cac:PaymentMeans/cbc:PaymentID");
        if ($paymentId !== null && $paymentId !== '') {
            $document->setRemittanceInformation($paymentId);
        }

        // Notes
        foreach ($this->nodes("{$root}/cbc:Note") as $noteNode) {
            $document->addNote($noteNode->textContent);
        }

        // Allowances/Charges
        foreach ($this->nodes("{$root}/cac:AllowanceCharge") as $acNode) {
            if (!$acNode instanceof DOMElement) {
                continue;
            }
            $document->addAllowanceCharge($this->parseUblAllowanceCharge($acNode, $currency));
        }

        // Lines
        $lineTag = $this->isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';
        foreach ($this->nodes("{$root}/{$lineTag}") as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $document->addLine($this->parseUblLine($lineNode, $currency));
        }

        // Tax Total
        $taxTotal = $this->parseUblTaxTotal("{$root}/cac:TaxTotal", $currency);
        if ($taxTotal !== null) {
            $document->setTaxTotal($taxTotal);
        }

        // Monetary Total
        $document->setMonetaryTotal($this->parseUblMonetaryTotal("{$root}/cac:LegalMonetaryTotal", $currency));

        return $document;
    }

    /**
     * Parses a CII document.
     */
    private function parseCii(): Document {
        $root = '/rsm:CrossIndustryInvoice';

        // Basic fields
        $id = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:ID") ?? '';

        $issueDateStr = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString");
        $issueDate = $this->parseCiiDate($issueDateStr);
        if ($issueDate === null) {
            $this->logErrorAndThrow(RuntimeException::class, 'Missing or invalid ram:IssueDateTime');
        }

        $typeCode = $this->getCiiValue("{$root}/rsm:ExchangedDocument/ram:TypeCode");
        $invoiceType = InvoiceType::fromCode($typeCode ?? '') ?? InvoiceType::INVOICE;
        $this->isCreditNote = $invoiceType->isCredit();

        $profileId = $this->getCiiValue("{$root}/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID");
        $profile = $this->detectProfile($profileId);

        $currencyCode = $this->getCiiValue("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode ?? '') ?? CurrencyCode::Euro;

        // Seller
        $seller = $this->parseCiiParty("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty");

        // Zahlungsverbindung (BG-17): PayeePartyCreditorFinancialAccount → Seller-Bankdaten.
        $settlement = "{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement";
        $iban = $this->getCiiValue("{$settlement}/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID");
        if ($iban !== null && $iban !== '') {
            $seller = $seller->withBankingInfo(
                $iban,
                $this->getCiiValue("{$settlement}/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID")
            );
        }

        // Buyer
        $buyer = $this->parseCiiParty("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty");

        // Payment means
        $paymentCode = $this->getCiiValue("{$settlement}/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode");

        // Due date
        $dueDateStr = $this->getCiiValue("{$settlement}/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString");

        // Zahlungsbedingungen (BT-20) inkl. Skonto aus der BR-DE-18-Note.
        $paymentTermsNote = $this->getCiiValue("{$settlement}/ram:SpecifiedTradePaymentTerms/ram:Description");

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
            paymentTerms: $paymentTermsNote !== null ? $this->paymentTermsFromNote($paymentTermsNote) : null,
            deliveryDate: $deliveryDateStr ? $this->parseCiiDate($deliveryDateStr) : null
        );

        // Verwendungszweck (BT-83).
        $paymentReference = $this->getCiiValue("{$settlement}/ram:PaymentReference");
        if ($paymentReference !== null && $paymentReference !== '') {
            $document->setRemittanceInformation($paymentReference);
        }

        // Notes
        foreach ($this->nodes("{$root}/rsm:ExchangedDocument/ram:IncludedNote/ram:Content") as $noteNode) {
            $document->addNote($noteNode->textContent);
        }

        // Dokument-Rabatte/-Zuschläge (BG-20/BG-21).
        foreach ($this->nodes("{$settlement}/ram:SpecifiedTradeAllowanceCharge") as $acNode) {
            if (!$acNode instanceof DOMElement) {
                continue;
            }
            $document->addAllowanceCharge($this->parseCiiAllowanceCharge($acNode, $currency));
        }

        // Lines
        foreach ($this->nodes("{$root}/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem") as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $document->addLine($this->parseCiiLine($lineNode, $currency));
        }

        // Tax Total
        $taxTotal = $this->parseCiiTaxTotal("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement", $currency);
        if ($taxTotal !== null) {
            $document->setTaxTotal($taxTotal);
        }

        // Monetary Total
        $document->setMonetaryTotal($this->parseCiiMonetaryTotal("{$root}/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation", $currency));

        return $document;
    }

    // === UBL Parser Helpers ===

    /**
     * Liefert den ersten Treffer-Knoten eines XPath-Ausdrucks (mit false-Guard
     * und DOMNameSpaceNode-Filter), sonst null.
     */
    private function firstNode(string $xpath, ?DOMNode $context = null): ?DOMNode {
        $nodes = $this->xpath->query($xpath, $context);
        if ($nodes === false) {
            return null;
        }
        $node = $nodes->item(0);
        return $node instanceof DOMNode ? $node : null;
    }

    /**
     * Liefert den (unbereinigten) Textinhalt des ersten Treffer-Knotens, sonst null.
     */
    private function firstText(string $xpath, ?DOMNode $context = null): ?string {
        return $this->firstNode($xpath, $context)?->textContent;
    }

    /**
     * Liefert die Treffer-Knoten eines XPath-Ausdrucks (mit false-Guard und
     * DOMNameSpaceNode-Filter) als iterierbare Liste.
     *
     * @return list<DOMNode>
     */
    private function nodes(string $xpath, ?DOMNode $context = null): array {
        $nodes = $this->xpath->query($xpath, $context);
        if ($nodes === false) {
            return [];
        }
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMNode) {
                $result[] = $node;
            }
        }
        return $result;
    }

    private function getUblValue(string $xpath): ?string {
        $node = $this->firstNode($xpath);
        return $node !== null ? trim($node->textContent) : null;
    }

    private function getUblDate(string $xpath): ?DateTimeImmutable {
        $value = $this->getUblValue($xpath);
        if ($value === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
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
        $endpointNode = $this->firstNode("{$xpath}/cbc:EndpointID");
        if ($endpointNode instanceof \DOMElement) {
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

    private function parseUblAllowanceCharge(DOMElement $node, CurrencyCode $currency): AllowanceCharge {
        $chargeIndicator = $this->getNodeValue($node, 'cbc:ChargeIndicator') === 'true';
        $amount = $this->getNodeValue($node, 'cbc:Amount');

        $reasonCode = $this->getNodeValue($node, 'cbc:AllowanceChargeReasonCode');
        $reason = $this->getNodeValue($node, 'cbc:AllowanceChargeReason');
        $percentage = $this->getNodeValue($node, 'cbc:MultiplierFactorNumeric');
        $baseAmount = $this->getNodeValue($node, 'cbc:BaseAmount');

        $taxCategoryCode = null;
        $taxPercent = null;
        $taxCatNodes = $this->xpath->query('cac:TaxCategory', $node);
        $taxCatNode = $taxCatNodes !== false ? $taxCatNodes->item(0) : null;
        if ($taxCatNode instanceof DOMElement) {
            $taxCategoryCode = $this->getNodeValue($taxCatNode, 'cbc:ID');
            $taxPercent = $this->getNodeValue($taxCatNode, 'cbc:Percent');
        }

        return new AllowanceCharge(
            chargeIndicator: $chargeIndicator,
            amount: $this->money($amount, $currency),
            reasonCode: $reasonCode ? AllowanceChargeReasonCode::tryFrom($reasonCode) : null,
            reason: $reason,
            baseAmount: Money::ofNullable($baseAmount, $currency),
            percentage: $percentage !== null ? (float) $percentage : null,
            taxCategory: $taxCategoryCode ? TaxCategory::tryFrom($taxCategoryCode) : null,
            taxPercent: $taxPercent !== null ? (float) $taxPercent : null
        );
    }

    private function parseUblLine(DOMElement $node, CurrencyCode $currency): InvoiceLine {
        $id = $this->getNodeValue($node, 'cbc:ID') ?? '';

        $qtyTag = $this->isCreditNote ? 'cbc:CreditedQuantity' : 'cbc:InvoicedQuantity';
        $qtyNode = $this->firstNode($qtyTag, $node);
        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        if ($qtyNode !== null) {
            $quantity = (float) $qtyNode->textContent;
            if ($qtyNode instanceof \DOMElement) {
                $unitCodeStr = $qtyNode->getAttribute('unitCode');
                $unitCode = UnitCode::tryFrom($unitCodeStr) ?? UnitCode::PIECE;
            }
        }

        $netAmount = $this->money($this->getNodeValue($node, 'cbc:LineExtensionAmount'), $currency);

        $itemName = $this->getNodeValue($node, 'cac:Item/cbc:Name') ?? '';
        $itemDescription = $this->getNodeValue($node, 'cac:Item/cbc:Description');

        $unitPrice = $this->money($this->getNodeValue($node, 'cac:Price/cbc:PriceAmount'), $currency);

        $taxCategoryCode = $this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID');
        $taxPercent = (float) ($this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent') ?? '0');

        $sellersItemId = $this->getNodeValue($node, 'cac:Item/cac:SellersItemIdentification/cbc:ID');
        $buyersItemId = $this->getNodeValue($node, 'cac:Item/cac:BuyersItemIdentification/cbc:ID');

        $line = new InvoiceLine(
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

        // Positionsrabatte/-zuschläge (BG-27/BG-28).
        foreach ($this->nodes('cac:AllowanceCharge', $node) as $acNode) {
            if ($acNode instanceof DOMElement) {
                $line->addAllowanceCharge($this->parseUblAllowanceCharge($acNode, $currency));
            }
        }

        return $line;
    }

    private function parseUblTaxTotal(string $xpath, CurrencyCode $currency): ?TaxTotal {
        $totalAmount = $this->money($this->getUblValue("{$xpath}/cbc:TaxAmount"), $currency);

        $subtotals = [];
        foreach ($this->nodes("{$xpath}/cac:TaxSubtotal") as $subNode) {
            if (!$subNode instanceof DOMElement) {
                continue;
            }
            $taxableAmount = $this->money($this->getNodeValue($subNode, 'cbc:TaxableAmount'), $currency);
            $taxAmount = $this->money($this->getNodeValue($subNode, 'cbc:TaxAmount'), $currency);
            $categoryCode = $this->getNodeValue($subNode, 'cac:TaxCategory/cbc:ID');
            $percent = (float) ($this->getNodeValue($subNode, 'cac:TaxCategory/cbc:Percent') ?? '0');
            $exemptionReason = $this->getNodeValue($subNode, 'cac:TaxCategory/cbc:TaxExemptionReason');

            $subtotals[] = new TaxSubtotal(
                taxableAmount: $taxableAmount,
                taxAmount: $taxAmount,
                category: TaxCategory::tryFrom($categoryCode ?? 'S') ?? TaxCategory::STANDARD,
                percent: $percent,
                exemptionReason: $exemptionReason
            );
        }

        if (empty($subtotals) && $totalAmount->isZero()) {
            return null;
        }

        return new TaxTotal($totalAmount, $subtotals);
    }

    private function parseUblMonetaryTotal(string $xpath, CurrencyCode $currency): MonetaryTotal {
        return new MonetaryTotal(
            lineExtensionAmount: $this->money($this->getUblValue("{$xpath}/cbc:LineExtensionAmount"), $currency),
            taxExclusiveAmount: $this->money($this->getUblValue("{$xpath}/cbc:TaxExclusiveAmount"), $currency),
            taxInclusiveAmount: $this->money($this->getUblValue("{$xpath}/cbc:TaxInclusiveAmount"), $currency),
            payableAmount: $this->money($this->getUblValue("{$xpath}/cbc:PayableAmount"), $currency),
            allowanceTotalAmount: $this->money($this->getUblValue("{$xpath}/cbc:AllowanceTotalAmount"), $currency),
            chargeTotalAmount: $this->money($this->getUblValue("{$xpath}/cbc:ChargeTotalAmount"), $currency),
            prepaidAmount: $this->money($this->getUblValue("{$xpath}/cbc:PrepaidAmount"), $currency)
        );
    }

    private function parseUblPaymentTerms(string $xpath): ?PaymentTerms {
        $note = $this->getUblValue("{$xpath}/cbc:Note");
        if ($note === null) {
            return null;
        }
        return $this->paymentTermsFromNote($note);
    }

    /**
     * Skonto-Konditionen aus der BR-DE-18-Note (XRechnung-CIUS:
     * `#SKONTO#TAGE=n#PROZENT=p#`) zusätzlich typisiert bereitstellen.
     */
    private function paymentTermsFromNote(string $note): PaymentTerms {
        if (preg_match('/#SKONTO#TAGE=(\d+)#PROZENT=(\d+(?:[.,]\d+)?)#/', $note, $matches) === 1) {
            return new PaymentTerms(
                note: $note,
                discountPercent: (float) str_replace(',', '.', $matches[2]),
                discountDays: (int) $matches[1]
            );
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
        $node = $this->firstNode($xpath);
        return $node !== null ? trim($node->textContent) : null;
    }

    private function parseCiiDate(?string $dateStr): ?DateTimeImmutable {
        if ($dateStr === null) {
            return null;
        }
        // Format 102 = YYYYMMDD
        try {
            return DateTimeImmutable::createFromFormat('Ymd', $dateStr) ?: new DateTimeImmutable($dateStr);
        } catch (Exception) {
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
        $endpointNode = $this->firstNode("{$xpath}/ram:URIUniversalCommunication/ram:URIID");
        if ($endpointNode instanceof \DOMElement) {
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

    private function parseCiiLine(DOMElement $node, CurrencyCode $currency): InvoiceLine {
        $id = $this->firstText('ram:AssociatedDocumentLineDocument/ram:LineID', $node) ?? '';
        $itemName = $this->firstText('ram:SpecifiedTradeProduct/ram:Name', $node) ?? '';
        $itemDescription = $this->firstText('ram:SpecifiedTradeProduct/ram:Description', $node);

        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        $qtyNode = $this->firstNode('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $node);
        if ($qtyNode !== null) {
            $quantity = (float) $qtyNode->textContent;
            if ($qtyNode instanceof \DOMElement) {
                $unitCode = UnitCode::tryFrom($qtyNode->getAttribute('unitCode')) ?? UnitCode::PIECE;
            }
        }

        $unitPrice = $this->money(
            $this->firstText('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $node),
            $currency
        );

        $netAmount = $this->money(
            $this->firstText('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $node),
            $currency
        );

        $taxCategoryCode = 'S';
        $taxPercent = 0.0;
        $taxNode = $this->firstNode('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax', $node);
        if ($taxNode !== null) {
            $taxCategoryCode = $this->firstText('ram:CategoryCode', $taxNode) ?? 'S';
            $taxPercent = (float) ($this->firstText('ram:RateApplicablePercent', $taxNode) ?? '0');
        }

        $sellersItemId = $this->firstText('ram:SpecifiedTradeProduct/ram:SellerAssignedID', $node);

        $line = new InvoiceLine(
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

        // Positionsrabatte/-zuschläge (BG-27/BG-28).
        foreach ($this->nodes('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge', $node) as $acNode) {
            if ($acNode instanceof DOMElement) {
                $line->addAllowanceCharge($this->parseCiiAllowanceCharge($acNode, $currency));
            }
        }

        return $line;
    }

    /**
     * CII-Rabatt/-Zuschlag (`ram:SpecifiedTradeAllowanceCharge`) — Pendant zu
     * {@see parseUblAllowanceCharge()} für Dokument- und Zeilenebene.
     */
    private function parseCiiAllowanceCharge(DOMElement $node, CurrencyCode $currency): AllowanceCharge {
        $chargeIndicator = strtolower((string) $this->getNodeValue($node, 'ram:ChargeIndicator/udt:Indicator')) === 'true';
        $reasonCode = $this->getNodeValue($node, 'ram:ReasonCode');
        $percentage = $this->getNodeValue($node, 'ram:CalculationPercent');
        $taxCategoryCode = $this->getNodeValue($node, 'ram:CategoryTradeTax/ram:CategoryCode');
        $taxPercent = $this->getNodeValue($node, 'ram:CategoryTradeTax/ram:RateApplicablePercent');

        return new AllowanceCharge(
            chargeIndicator: $chargeIndicator,
            amount: $this->money($this->getNodeValue($node, 'ram:ActualAmount'), $currency),
            reasonCode: $reasonCode ? AllowanceChargeReasonCode::tryFrom($reasonCode) : null,
            reason: $this->getNodeValue($node, 'ram:Reason'),
            baseAmount: Money::ofNullable($this->getNodeValue($node, 'ram:BasisAmount'), $currency),
            percentage: $percentage !== null ? (float) $percentage : null,
            taxCategory: $taxCategoryCode ? TaxCategory::tryFrom($taxCategoryCode) : null,
            taxPercent: $taxPercent !== null ? (float) $taxPercent : null
        );
    }

    private function parseCiiTaxTotal(string $xpath, CurrencyCode $currency): ?TaxTotal {
        $subtotals = [];

        foreach ($this->nodes("{$xpath}/ram:ApplicableTradeTax") as $taxNode) {
            if (!$taxNode instanceof DOMElement) {
                continue;
            }
            $taxAmount = $this->money($this->getNodeValue($taxNode, 'ram:CalculatedAmount'), $currency);
            $taxableAmount = $this->money($this->getNodeValue($taxNode, 'ram:BasisAmount'), $currency);
            $categoryCode = $this->getNodeValue($taxNode, 'ram:CategoryCode');
            $percent = (float) ($this->getNodeValue($taxNode, 'ram:RateApplicablePercent') ?? '0');
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

    private function parseCiiMonetaryTotal(string $xpath, CurrencyCode $currency): MonetaryTotal {
        return new MonetaryTotal(
            lineExtensionAmount: $this->money($this->getCiiValue("{$xpath}/ram:LineTotalAmount"), $currency),
            taxExclusiveAmount: $this->money($this->getCiiValue("{$xpath}/ram:TaxBasisTotalAmount"), $currency),
            taxInclusiveAmount: $this->money($this->getCiiValue("{$xpath}/ram:GrandTotalAmount"), $currency),
            payableAmount: $this->money($this->getCiiValue("{$xpath}/ram:DuePayableAmount"), $currency),
            allowanceTotalAmount: $this->money($this->getCiiValue("{$xpath}/ram:AllowanceTotalAmount"), $currency),
            chargeTotalAmount: $this->money($this->getCiiValue("{$xpath}/ram:ChargeTotalAmount"), $currency),
            prepaidAmount: $this->money($this->getCiiValue("{$xpath}/ram:TotalPrepaidAmount"), $currency)
        );
    }

    // === Common Helpers ===

    /**
     * XML-Betrag → Money, ohne float-Zwischenschritt (fehlendes Element = 0).
     */
    private function money(?string $value, CurrencyCode $currency): Money {
        return Money::ofNullable($value, $currency) ?? Money::zero($currency);
    }

    private function getNodeValue(DOMElement $node, string $xpath): ?string {
        $first = $this->firstNode($xpath, $node);
        return $first !== null ? trim($first->textContent) : null;
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

        // Check for the KOSIT authority in the URN (XRechnung indicator).
        // Older XRechnung 2.x files carry "xoev-de:kosit", XRechnung 3.0 files
        // carry "xeinkauf.de:kosit" - both must be recognised when parsing.
        if (str_contains($profileId, 'xoev-de:kosit') || str_contains($profileId, 'xeinkauf.de:kosit')) {
            return ERechnungProfile::XRECHNUNG;
        }

        // Fallback for EN16931 (also matches partial URN)
        if (str_contains($profileId, 'en16931') || str_contains($profileId, 'EN16931')) {
            return ERechnungProfile::EN16931;
        }

        return ERechnungProfile::EN16931;
    }
}
