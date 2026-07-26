<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderXParser.php
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
use DOMXPath;
use ERechnungToolkit\Entities\{Order, OrderLine, Party, PostalAddress};
use ERechnungToolkit\Enums\{OrderProfile, TaxCategory, UnitCode};
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for Order-X CII documents (UN/CEFACT Cross Industry Order, D20B).
 *
 * Detects the SCRDMCCBDACIO message and maps it onto an {@see Order} entity.
 */
final class OrderXParser {
    use ErrorLog;

    private const RSM_NS = 'urn:un:unece:uncefact:data:SCRDMCCBDACIOMessageStructure:100';
    private const RAM_NS = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:128';
    private const UDT_NS = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:128';

    private DOMDocument $dom;
    private DOMXPath $xpath;

    /**
     * Parses an Order-X document from an XML string.
     */
    public function parse(string $xml): Order {
        $this->dom = new DOMDocument;

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

        $root = $this->dom->documentElement;
        if ($root === null || $root->namespaceURI !== self::RSM_NS || $root->localName !== 'SCRDMCCBDACIOMessageStructure') {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected an Order-X (CII) document.');
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('rsm', self::RSM_NS);
        $this->xpath->registerNamespace('ram', self::RAM_NS);
        $this->xpath->registerNamespace('udt', self::UDT_NS);

        return $this->parseOrder();
    }

    /**
     * Parses an Order-X document from a file.
     */
    public function parseFile(string $filePath): Order {
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }
        $xml = file_get_contents($filePath);
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }
        return $this->parse($xml);
    }

    private function parseOrder(): Order {
        $root = '/rsm:SCRDMCCBDACIOMessageStructure';
        $tx = "{$root}/rsm:SupplyChainTradeTransaction";
        $agreement = "{$tx}/ram:ApplicableHeaderTradeAgreement";
        $settlement = "{$tx}/ram:ApplicableHeaderTradeSettlement";

        $id = $this->getValue("{$root}/rsm:ExchangedDocument/ram:ID") ?? '';
        $issueDate = $this->parseDate(
            $this->getValue("{$root}/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString")
        ) ?? new DateTimeImmutable('today');

        $currencyCode = $this->getValue("{$settlement}/ram:OrderCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode ?? 'EUR') ?? CurrencyCode::Euro;

        $seller = $this->parseParty("{$agreement}/ram:SellerTradeParty");
        $buyer = $this->parseParty("{$agreement}/ram:BuyerTradeParty");

        $order = new Order(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            profile: OrderProfile::PEPPOL_ORDER_ONLY,
            buyerReference: $this->getValue("{$agreement}/ram:BuyerReference"),
            contractReference: $this->getValue("{$agreement}/ram:ContractReferencedDocument/ram:IssuerAssignedID"),
            requestedDeliveryStartDate: $this->parseDate(
                $this->getValue("{$tx}/ram:ApplicableHeaderTradeDelivery/ram:RequestedDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString")
            )
        );

        foreach ($this->xpath->query("{$root}/rsm:ExchangedDocument/ram:IncludedNote/ram:Content") as $noteNode) {
            $order->addNote($noteNode->textContent);
        }

        foreach ($this->xpath->query("{$tx}/ram:IncludedSupplyChainTradeLineItem") as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $order->addLine($this->parseLine($lineNode, $currency));
        }

        return $order;
    }

    private function parseParty(string $xpath): Party {
        $name = $this->getValue("{$xpath}/ram:Name") ?? '';

        $street = $this->getValue("{$xpath}/ram:PostalTradeAddress/ram:LineOne");
        $address = $street !== null ? new PostalAddress(
            streetName: $street,
            additionalStreetName: $this->getValue("{$xpath}/ram:PostalTradeAddress/ram:LineTwo"),
            postalCode: $this->getValue("{$xpath}/ram:PostalTradeAddress/ram:PostcodeCode"),
            city: $this->getValue("{$xpath}/ram:PostalTradeAddress/ram:CityName"),
            country: $this->getValue("{$xpath}/ram:PostalTradeAddress/ram:CountryID")
        ) : null;

        $vatId = $this->getValue("{$xpath}/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']");
        $taxRegId = $this->getValue("{$xpath}/ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']");

        $legalEntityId = $this->getValue("{$xpath}/ram:SpecifiedLegalOrganization/ram:ID");
        $legalEntityScheme = null;
        $legalNodes = $this->xpath->query("{$xpath}/ram:SpecifiedLegalOrganization/ram:ID");
        if ($legalNodes !== false && $legalNodes->length > 0 && $legalNodes->item(0) instanceof DOMElement) {
            /** @var DOMElement $legalNode */
            $legalNode = $legalNodes->item(0);
            $legalEntityScheme = $legalNode->getAttribute('schemeID') ?: null;
        }

        $endpointId = $this->getValue("{$xpath}/ram:URIUniversalCommunication/ram:URIID");
        $endpointScheme = null;
        $endpointNodes = $this->xpath->query("{$xpath}/ram:URIUniversalCommunication/ram:URIID");
        if ($endpointNodes !== false && $endpointNodes->length > 0 && $endpointNodes->item(0) instanceof DOMElement) {
            /** @var DOMElement $endpointNode */
            $endpointNode = $endpointNodes->item(0);
            $endpointScheme = $endpointNode->getAttribute('schemeID') ?: null;
        }

        return new Party(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            taxRegistrationId: $taxRegId,
            legalEntityId: $legalEntityId,
            legalEntityScheme: $legalEntityScheme,
            endpointId: $endpointId,
            endpointScheme: $endpointScheme,
            contactName: $this->getValue("{$xpath}/ram:DefinedTradeContact/ram:PersonName"),
            contactPhone: $this->getValue("{$xpath}/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber"),
            contactEmail: $this->getValue("{$xpath}/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID")
        );
    }

    private function parseLine(DOMElement $node, CurrencyCode $currency): OrderLine {
        $id = $this->getNodeValue($node, 'ram:AssociatedDocumentLineDocument/ram:LineID') ?? '';

        $itemName = $this->getNodeValue($node, 'ram:SpecifiedTradeProduct/ram:Name') ?? '';
        $itemDescription = $this->getNodeValue($node, 'ram:SpecifiedTradeProduct/ram:Description');
        $sellersItemId = $this->getNodeValue($node, 'ram:SpecifiedTradeProduct/ram:SellerAssignedID');
        $buyersItemId = $this->getNodeValue($node, 'ram:SpecifiedTradeProduct/ram:BuyerAssignedID');
        $standardItemId = $this->getNodeValue($node, 'ram:SpecifiedTradeProduct/ram:GlobalID');

        $unitPrice = Money::ofNullable($this->getNodeValue(
            $node,
            'ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'
        ), $currency) ?? Money::zero($currency);

        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        $qtyNodes = $this->xpath->query('ram:SpecifiedLineTradeDelivery/ram:RequestedQuantity', $node);
        if ($qtyNodes !== false && $qtyNodes->length > 0) {
            $qtyNode = $qtyNodes->item(0);
            $quantity = (float) $qtyNode->textContent;
            if ($qtyNode instanceof DOMElement) {
                $unitCode = UnitCode::tryFrom($qtyNode->getAttribute('unitCode')) ?? UnitCode::PIECE;
            }
        }

        $netAmount = Money::ofNullable($this->getNodeValue(
            $node,
            'ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'
        ), $currency) ?? Money::zero($currency);

        $categoryCode = $this->getNodeValue($node, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode');
        $percentValue = $this->getNodeValue($node, 'ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent');

        return new OrderLine(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $itemName,
            unitPrice: $unitPrice,
            itemDescription: $itemDescription,
            sellersItemId: $sellersItemId,
            buyersItemId: $buyersItemId,
            standardItemId: $standardItemId,
            taxCategory: $categoryCode !== null ? TaxCategory::tryFrom($categoryCode) : null,
            taxPercent: $percentValue !== null ? (float) $percentValue : null
        );
    }

    private function getValue(string $xpath): ?string {
        $nodes = $this->xpath->query($xpath);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function getNodeValue(DOMElement $node, string $xpath): ?string {
        $nodes = $this->xpath->query($xpath, $node);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        return trim($nodes->item(0)->textContent);
    }

    private function parseDate(?string $value): ?DateTimeImmutable {
        if ($value === null) {
            return null;
        }
        try {
            return DateTimeImmutable::createFromFormat('Ymd', $value) ?: new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
