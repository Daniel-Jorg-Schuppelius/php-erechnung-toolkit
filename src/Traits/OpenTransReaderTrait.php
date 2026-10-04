<?php
/*
 * Created on   : Mon Sep 28 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : OpenTransReaderTrait.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Traits;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\{Party, PostalAddress};
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Shared reading helpers of the openTRANS 2.1 parsers: loading with root
 * check, XPath values and the BMEcat party block. The using class must use
 * {@see \ERRORToolkit\Traits\ErrorLog}.
 */
trait OpenTransReaderTrait {
    private DOMDocument $dom;
    private DOMXPath $xpath;

    /** Loads the XML and checks the openTRANS root element. */
    private function loadOpenTrans(string $xml, string $rootName): void {
        $this->dom = new DOMDocument;

        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $this->dom->loadXML($xml, LIBXML_NONET);
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
        if ($root === null || $root->namespaceURI !== 'http://www.opentrans.org/XMLSchema/2.1' || $root->localName !== $rootName) {
            $this->logErrorAndThrow(RuntimeException::class, "Unknown format. Expected an openTRANS {$rootName} document.");
        }

        $this->xpath = new DOMXPath($this->dom);
        $this->xpath->registerNamespace('ot', 'http://www.opentrans.org/XMLSchema/2.1');
        $this->xpath->registerNamespace('bmecat', 'http://www.bmecat.org/bmecat/2005');
    }

    private function readOpenTransFile(string $filePath): string {
        if (!file_exists($filePath)) {
            $this->logErrorAndThrow(InvalidArgumentException::class, "File not found: {$filePath}");
        }
        $xml = file_get_contents($filePath);
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Failed to read file: {$filePath}");
        }

        return $xml;
    }

    /** Party with the given PARTY_ROLE below the PARTIES element at $partiesPath. */
    private function readParty(string $partiesPath, string $role): Party {
        $base = "{$partiesPath}/ot:PARTY[ot:PARTY_ROLE='{$role}']";

        $street = $this->getValue("{$base}/ot:ADDRESS/bmecat:STREET");
        $address = $street === null ? null : new PostalAddress(
            streetName: $street,
            postalCode: $this->getValue("{$base}/ot:ADDRESS/bmecat:ZIP"),
            city: $this->getValue("{$base}/ot:ADDRESS/bmecat:CITY"),
            country: $this->getValue("{$base}/ot:ADDRESS/bmecat:COUNTRY_CODED")
        );

        return new Party(
            name: $this->getValue("{$base}/ot:ADDRESS/bmecat:NAME") ?? '',
            postalAddress: $address,
            vatId: $this->getValue("{$base}/ot:ADDRESS/bmecat:VAT_ID"),
            contactName: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:CONTACT_NAME"),
            contactPhone: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:PHONE"),
            contactEmail: $this->getValue("{$base}/ot:ADDRESS/bmecat:CONTACT_DETAILS/bmecat:EMAILS")
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

    private function getDate(string $xpath, ?DOMElement $context = null): ?DateTimeImmutable {
        $value = $context === null ? $this->getValue($xpath) : $this->getNodeValue($context, $xpath);
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
