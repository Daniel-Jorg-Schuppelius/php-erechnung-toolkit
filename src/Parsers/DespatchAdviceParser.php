<?php
/*
 * Created on   : Wed Jun 25 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DespatchAdviceParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\{DespatchAdvice, DespatchLine, Party, PostalAddress};
use ERechnungToolkit\Enums\UnitCode;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for UBL Despatch Advice documents (Peppol BIS Despatch Advice).
 *
 * Detects a UBL `DespatchAdvice` document and maps it onto a
 * {@see DespatchAdvice} entity.
 */
final class DespatchAdviceParser {
    use ErrorLog;

    private const DA_NS = 'urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2';
    private const CAC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC_NS = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private DOMDocument $dom;
    private DOMXPath $xpath;

    /**
     * Parses a UBL Despatch Advice from an XML string.
     */
    public function parse(string $xml): DespatchAdvice {
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
        if ($root === null || $root->namespaceURI !== self::DA_NS || $root->localName !== 'DespatchAdvice') {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a UBL Despatch Advice document.');
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('ubl', self::DA_NS);
        $this->xpath->registerNamespace('cac', self::CAC_NS);
        $this->xpath->registerNamespace('cbc', self::CBC_NS);

        return $this->parseAdvice();
    }

    /**
     * Parses a UBL Despatch Advice from a file.
     */
    public function parseFile(string $filePath): DespatchAdvice {
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }
        $xml = file_get_contents($filePath);
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }
        return $this->parse($xml);
    }

    private function parseAdvice(): DespatchAdvice {
        $root = '/ubl:DespatchAdvice';

        $id = $this->getValue("{$root}/cbc:ID") ?? '';
        $issueDate = new DateTimeImmutable($this->getValue("{$root}/cbc:IssueDate") ?? 'today');

        $supplier = $this->parseParty("{$root}/cac:DespatchSupplierParty/cac:Party");
        $customer = $this->parseParty("{$root}/cac:DeliveryCustomerParty/cac:Party");

        $advice = new DespatchAdvice(
            id: $id,
            issueDate: $issueDate,
            despatchSupplierParty: $supplier,
            deliveryCustomerParty: $customer,
            orderReference: $this->getValue("{$root}/cac:OrderReference/cbc:ID"),
            salesOrderId: $this->getValue("{$root}/cac:OrderReference/cbc:SalesOrderID"),
            shipmentId: $this->getValue("{$root}/cac:Shipment/cbc:ID") ?? '1',
            actualDeliveryDate: $this->getDate("{$root}/cac:Shipment/cac:Delivery/cbc:ActualDeliveryDate"),
            deliveryAddress: $this->parseAddress("{$root}/cac:Shipment/cac:Delivery/cac:DeliveryLocation/cac:Address")
        );

        foreach ($this->xpath->query("{$root}/cbc:Note") ?: [] as $noteNode) {
            if ($noteNode instanceof DOMNode) {
                $advice->addNote($noteNode->textContent);
            }
        }

        foreach ($this->xpath->query("{$root}/cac:DespatchLine") ?: [] as $lineNode) {
            if (!$lineNode instanceof DOMElement) {
                continue;
            }
            $advice->addLine($this->parseLine($lineNode));
        }

        return $advice;
    }

    private function parseParty(string $xpath): Party {
        $name = $this->getValue("{$xpath}/cac:PartyName/cbc:Name")
            ?? $this->getValue("{$xpath}/cac:PartyLegalEntity/cbc:RegistrationName")
            ?? '';

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
            postalAddress: $this->parseAddress("{$xpath}/cac:PostalAddress"),
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
            country: $this->getValue("{$xpath}/cac:Country/cbc:IdentificationCode")
        );
    }

    private function parseLine(DOMElement $node): DespatchLine {
        $id = $this->getNodeValue($node, 'cbc:ID') ?? '';

        $quantity = 0.0;
        $unitCode = UnitCode::PIECE;
        $qtyNodes = $this->xpath->query('cbc:DeliveredQuantity', $node);
        $qtyNode = $qtyNodes !== false ? $qtyNodes->item(0) : null;
        if ($qtyNode instanceof DOMNode) {
            $quantity = (float) $qtyNode->textContent;
            if ($qtyNode instanceof DOMElement) {
                $unitCode = UnitCode::tryFrom($qtyNode->getAttribute('unitCode')) ?? UnitCode::PIECE;
            }
        }

        $backorderValue = $this->getNodeValue($node, 'cbc:BackorderQuantity');

        return new DespatchLine(
            id: $id,
            deliveredQuantity: $quantity,
            unitCode: $unitCode,
            itemName: $this->getNodeValue($node, 'cac:Item/cbc:Name') ?? '',
            orderLineId: $this->getNodeValue($node, 'cac:OrderLineReference/cbc:LineID'),
            itemDescription: $this->getNodeValue($node, 'cac:Item/cbc:Description'),
            sellersItemId: $this->getNodeValue($node, 'cac:Item/cac:SellersItemIdentification/cbc:ID'),
            buyersItemId: $this->getNodeValue($node, 'cac:Item/cac:BuyersItemIdentification/cbc:ID'),
            backorderQuantity: $backorderValue !== null ? (float) $backorderValue : null,
            backorderReason: $this->getNodeValue($node, 'cbc:BackorderReason'),
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
