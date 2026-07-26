<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OrderParser.php
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
use ERechnungToolkit\Entities\{Order, OrderLine, Party, PostalAddress};
use ERechnungToolkit\Enums\{OrderProfile, TaxCategory, UnitCode};
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for UBL Order documents (Peppol BIS Order only / XBestellung).
 *
 * Detects a UBL `Order` document and maps it onto an {@see Order} entity. The
 * buyer is read from cac:BuyerCustomerParty, the supplier from
 * cac:SellerSupplierParty.
 */
final class OrderParser {
    use ErrorLog;

    private const ORDER_NS = 'urn:oasis:names:specification:ubl:schema:xsd:Order-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private DOMDocument $dom;
    private DOMXPath $xpath;

    /**
     * Parses a UBL Order from an XML string.
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
        if ($root === null || $root->namespaceURI !== self::ORDER_NS || $root->localName !== 'Order') {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a UBL Order document.');
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('ubl', self::ORDER_NS);
        $this->xpath->registerNamespace('cac', self::CAC_NS);
        $this->xpath->registerNamespace('cbc', self::CBC_NS);

        return $this->parseOrder();
    }

    /**
     * Parses a UBL Order from a file.
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
        $root = '/ubl:Order';

        $id = $this->getValue("{$root}/cbc:ID") ?? '';
        $issueDate = new DateTimeImmutable($this->getValue("{$root}/cbc:IssueDate") ?? 'now');

        $currencyCode = $this->getValue("{$root}/cbc:DocumentCurrencyCode");
        $currency = CurrencyCode::tryFrom($currencyCode ?? 'EUR') ?? CurrencyCode::Euro;

        $profile = $this->detectProfile(
            $this->getValue("{$root}/cbc:CustomizationID"),
            $this->getValue("{$root}/cbc:ProfileID")
        );

        $buyer = $this->parseParty("{$root}/cac:BuyerCustomerParty/cac:Party");
        $seller = $this->parseParty("{$root}/cac:SellerSupplierParty/cac:Party");

        $order = new Order(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            profile: $profile,
            salesOrderId: $this->getValue("{$root}/cbc:SalesOrderID"),
            contractReference: $this->getValue("{$root}/cac:Contract/cbc:ID"),
            originatorDocumentReference: $this->getValue("{$root}/cac:OriginatorDocumentReference/cbc:ID"),
            requestedDeliveryStartDate: $this->getDate("{$root}/cac:Delivery/cac:RequestedDeliveryPeriod/cbc:StartDate"),
            requestedDeliveryEndDate: $this->getDate("{$root}/cac:Delivery/cac:RequestedDeliveryPeriod/cbc:EndDate")
        );

        foreach ($this->xpath->query("{$root}/cbc:Note") ?: [] as $noteNode) {
            if ($noteNode instanceof DOMNode) {
                $order->addNote($noteNode->textContent);
            }
        }

        foreach ($this->xpath->query("{$root}/cac:OrderLine/cac:LineItem") ?: [] as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $order->addLine($this->parseLine($lineNode, $currency));
        }

        return $order;
    }

    private function detectProfile(?string $customizationId, ?string $profileId): OrderProfile {
        $haystack = strtolower(($customizationId ?? '') . ' ' . ($profileId ?? ''));
        if (str_contains($haystack, 'xbestellung')) {
            return OrderProfile::XBESTELLUNG;
        }
        return OrderProfile::PEPPOL_ORDER_ONLY;
    }

    private function parseParty(string $xpath): Party {
        $name = $this->getValue("{$xpath}/cac:PartyName/cbc:Name")
            ?? $this->getValue("{$xpath}/cac:PartyLegalEntity/cbc:RegistrationName")
            ?? '';

        $address = $this->parseAddress("{$xpath}/cac:PostalAddress");
        $vatId = $this->getValue("{$xpath}/cac:PartyTaxScheme/cbc:CompanyID");

        $endpointId = $this->getValue("{$xpath}/cbc:EndpointID");
        $endpointScheme = null;
        $endpointNodes = $this->xpath->query("{$xpath}/cbc:EndpointID");
        if ($endpointNodes !== false && $endpointNodes->length > 0 && $endpointNodes->item(0) instanceof DOMElement) {
            /** @var DOMElement $endpointNode */
            $endpointNode = $endpointNodes->item(0);
            $endpointScheme = $endpointNode->getAttribute('schemeID') ?: null;
        }

        return new Party(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            endpointId: $endpointId,
            endpointScheme: $endpointScheme,
            contactName: $this->getValue("{$xpath}/cac:Contact/cbc:Name"),
            contactPhone: $this->getValue("{$xpath}/cac:Contact/cbc:Telephone"),
            contactEmail: $this->getValue("{$xpath}/cac:Contact/cbc:ElectronicMail")
        );
    }

    private function parseAddress(string $xpath): ?PostalAddress {
        $street = $this->getValue("{$xpath}/cbc:StreetName");
        if ($street === null) {
            return null;
        }
        return new PostalAddress(
            streetName: $street,
            additionalStreetName: $this->getValue("{$xpath}/cbc:AdditionalStreetName"),
            postalCode: $this->getValue("{$xpath}/cbc:PostalZone"),
            city: $this->getValue("{$xpath}/cbc:CityName"),
            countrySubdivision: $this->getValue("{$xpath}/cbc:CountrySubentity"),
            country: $this->getValue("{$xpath}/cac:Country/cbc:IdentificationCode")
        );
    }

    private function parseLine(DOMElement $node, CurrencyCode $currency): OrderLine {
        $id = $this->getNodeValue($node, 'cbc:ID') ?? '';

        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        $qtyNodes = $this->xpath->query('cbc:Quantity', $node);
        $qtyNode = $qtyNodes !== false ? $qtyNodes->item(0) : null;
        if ($qtyNode instanceof DOMNode) {
            $quantity = (float) $qtyNode->textContent;
            if ($qtyNode instanceof DOMElement) {
                $unitCode = UnitCode::tryFrom($qtyNode->getAttribute('unitCode')) ?? UnitCode::PIECE;
            }
        }

        $netAmount = Money::ofNullable($this->getNodeValue($node, 'cbc:LineExtensionAmount'), $currency) ?? Money::zero($currency);
        $unitPrice = Money::ofNullable($this->getNodeValue($node, 'cac:Price/cbc:PriceAmount'), $currency) ?? Money::zero($currency);

        $itemName = $this->getNodeValue($node, 'cac:Item/cbc:Name') ?? '';
        $itemDescription = $this->getNodeValue($node, 'cac:Item/cbc:Description');
        $sellersItemId = $this->getNodeValue($node, 'cac:Item/cac:SellersItemIdentification/cbc:ID');
        $buyersItemId = $this->getNodeValue($node, 'cac:Item/cac:BuyersItemIdentification/cbc:ID');

        $taxCategoryCode = $this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:ID');
        $taxPercentValue = $this->getNodeValue($node, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent');

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
            taxCategory: $taxCategoryCode !== null ? TaxCategory::tryFrom($taxCategoryCode) : null,
            taxPercent: $taxPercentValue !== null ? (float) $taxPercentValue : null,
            note: $this->getNodeValue($node, 'cbc:Note')
        );
    }

    private function getValue(string $xpath): ?string {
        $nodes = $this->xpath->query($xpath);
        if ($nodes === false) {
            return null;
        }
        $node = $nodes->item(0);
        return $node instanceof DOMNode ? trim($node->textContent) : null;
    }

    private function getDate(string $xpath): ?DateTimeImmutable {
        $value = $this->getValue($xpath);
        if ($value === null) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function getNodeValue(DOMElement $node, string $xpath): ?string {
        $nodes = $this->xpath->query($xpath, $node);
        if ($nodes === false) {
            return null;
        }
        $found = $nodes->item(0);
        return $found instanceof DOMNode ? trim($found->textContent) : null;
    }
}
