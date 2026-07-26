<?php
/*
 * Created on   : Sat Jun 27 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransOrderParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\{Order, OrderLine, Party, PostalAddress};
use ERechnungToolkit\Enums\UnitCode;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser for openTRANS 2.1 ORDER documents.
 *
 * Detects an openTRANS `ORDER` and maps it onto the shared {@see Order} entity,
 * the inverse of {@see \ERechnungToolkit\Generators\OpenTransOrderGenerator}. The
 * buyer/supplier are resolved through their PARTY_ROLE, addresses and product ids
 * are read from the embedded BMEcat elements.
 */
final class OpenTransOrderParser {
    use ErrorLog;

    private const OT_NS = 'http://www.opentrans.org/XMLSchema/2.1';
    private const BMECAT_NS = 'http://www.bmecat.org/bmecat/2005';

    private DOMDocument $dom;
    private DOMXPath $xpath;

    /**
     * Parses an openTRANS ORDER from an XML string.
     */
    public function parse(string $xml): Order {
        $this->dom = new DOMDocument;

        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $this->dom->loadXML($xml);
        if (!$loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            $message = 'Failed to parse XML';
            if (!empty($errors)) {
                $message .= ': ' . $errors[0]->message;
            }
            $this->logErrorAndThrow(RuntimeException::class, $message);
        }
        libxml_use_internal_errors($internalErrors);

        $root = $this->dom->documentElement;
        if ($root === null || $root->namespaceURI !== self::OT_NS || $root->localName !== 'ORDER') {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected an openTRANS ORDER document.');
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('ot', self::OT_NS);
        $this->xpath->registerNamespace('bmecat', self::BMECAT_NS);

        return $this->parseOrder();
    }

    /**
     * Parses an openTRANS ORDER from a file.
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
        $info = '/ot:ORDER/ot:ORDER_HEADER/ot:ORDER_INFO';

        $id = $this->getValue("{$info}/ot:ORDER_ID") ?? '';
        $issueDate = $this->getDate("{$info}/ot:ORDER_DATE") ?? new DateTimeImmutable('now');
        $currency = CurrencyCode::tryFrom($this->getValue("{$info}/ot:CURRENCY") ?? 'EUR') ?? CurrencyCode::Euro;

        $buyer = $this->parseParty('buyer');
        $seller = $this->parseParty('supplier');

        $order = new Order(
            id: $id,
            issueDate: $issueDate,
            buyer: $buyer,
            seller: $seller,
            currency: $currency,
            salesOrderId: $this->getValue("{$info}/ot:ORDER_SUPPLIER_ORDER_ID")
        );

        foreach ($this->xpath->query('/ot:ORDER/ot:ORDER_ITEM_LIST/ot:ORDER_ITEM') ?: [] as $itemNode) {
            if (!$itemNode instanceof DOMElement) {
                continue;
            }
            $order->addLine($this->parseLine($itemNode));
        }

        return $order;
    }

    private function parseParty(string $role): Party {
        $base = '/ot:ORDER/ot:ORDER_HEADER/ot:ORDER_INFO/ot:PARTIES/ot:PARTY'
            . "[ot:PARTY_ROLE='{$role}']";

        $name = $this->getValue("{$base}/ot:ADDRESS/bmecat:NAME") ?? '';
        $vatId = $this->getValue("{$base}/ot:ADDRESS/bmecat:VAT_ID");

        $street = $this->getValue("{$base}/ot:ADDRESS/bmecat:STREET");
        $address = $street === null ? null : new PostalAddress(
            streetName: $street,
            postalCode: $this->getValue("{$base}/ot:ADDRESS/bmecat:ZIP"),
            city: $this->getValue("{$base}/ot:ADDRESS/bmecat:CITY"),
            country: $this->getValue("{$base}/ot:ADDRESS/bmecat:COUNTRY_CODED")
        );

        return new Party(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            contactName: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:CONTACT_NAME"),
            contactPhone: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:PHONE"),
            contactEmail: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:EMAILS")
        );
    }

    private function parseLine(DOMElement $node): OrderLine {
        $id = $this->getNodeValue($node, 'ot:LINE_ITEM_ID') ?? '';
        $quantity = (float) ($this->getNodeValue($node, 'ot:QUANTITY') ?? '0');
        $unitCode = UnitCode::tryFrom($this->getNodeValue($node, 'bmecat:ORDER_UNIT') ?? '') ?? UnitCode::PIECE;
        $unitPrice = (float) ($this->getNodeValue($node, 'ot:PRODUCT_PRICE_FIX/bmecat:PRICE_AMOUNT') ?? '0');

        $netAmount = $this->getNodeValue($node, 'ot:PRICE_LINE_AMOUNT');
        $netAmount = $netAmount !== null ? (float) $netAmount : round($quantity * $unitPrice, 2);

        return new OrderLine(
            id: $id,
            quantity: $quantity,
            unitCode: $unitCode,
            netAmount: $netAmount,
            itemName: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_SHORT') ?? '',
            unitPrice: $unitPrice,
            itemDescription: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:DESCRIPTION_LONG'),
            sellersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:SUPPLIER_PID'),
            buyersItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:BUYER_PID'),
            standardItemId: $this->getNodeValue($node, 'ot:PRODUCT_ID/bmecat:INTERNATIONAL_PID'),
            note: $this->getNodeValue($node, 'ot:REMARKS')
        );
    }

    private function getValue(string $xpath): ?string {
        $nodes = $this->xpath->query($xpath);
        if ($nodes === false) {
            return null;
        }
        $found = $nodes->item(0);

        return $found instanceof DOMNode ? trim($found->textContent) : null;
    }

    private function getNodeValue(DOMElement $node, string $xpath): ?string {
        $nodes = $this->xpath->query($xpath, $node);
        if ($nodes === false) {
            return null;
        }
        $found = $nodes->item(0);

        return $found instanceof DOMNode ? trim($found->textContent) : null;
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
}
