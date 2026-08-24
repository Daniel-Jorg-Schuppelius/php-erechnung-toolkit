<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatParser.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\Helper\Data\NumberHelper;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Entities\Bmecat\{BmecatArticle, BmecatCatalog, BmecatMime, BmecatPrice};
use ERechnungToolkit\Enums\BmecatVersion;
use ERRORToolkit\Traits\ErrorLog;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Parser für BMEcat-Produktkataloge, Version 1.2 und 2005.
 *
 * Beide Element-Konventionen werden unterstützt und in die gemeinsamen
 * {@see BmecatArticle}-Entities zusammengeführt:
 *  - BMEcat 1.2:  ARTICLE / SUPPLIER_AID / ARTICLE_DETAILS / ARTICLE_PRICE
 *  - BMEcat 2005: PRODUCT / SUPPLIER_PID / PRODUCT_DETAILS / PRODUCT_PRICE
 *
 * Die Version wird am Dokument-Namespace (2005) bzw. am `version`-Attribut des
 * BMECAT-Wurzelelements erkannt. Alle Zugriffe laufen namespace-agnostisch
 * über `local-name()` — reale Kataloge liefern 1.2 meist ohne, 2005 mit und
 * ohne Namespace. Preise werden auf 4 Nachkommastellen kaufmännisch gerundet
 * (Skala 4, {@see Money}); Exponentialnotation aus Lieferanten-Exporten wird
 * über den float-Pfad aufgelöst, da bcmath sie nicht versteht.
 *
 * Artikel ohne Lieferanten-Artikelnummer brechen den Lauf nicht ab — sie
 * werden übersprungen und als Warnung am {@see BmecatCatalog} gesammelt. Nur
 * ungültiges XML, ein fremdes Wurzelelement oder eine unbekannte Version
 * werfen.
 */
final class BmecatParser {
    use ErrorLog;

    /** Namespace-Präfix aller BMEcat-2005-Varianten (2005, 2005.1, 2005fd). */
    private const NS_2005_PREFIX = 'http://www.bmecat.org/bmecat/2005';

    /** Nachkommastellen für Preise und Beträge. */
    private const PRICE_SCALE = 4;

    /** Bekannte Transaktionselemente unterhalb von BMECAT. */
    private const TRANSACTIONS = ['T_NEW_CATALOG', 'T_UPDATE_PRODUCTS', 'T_UPDATE_PRICES'];

    private DOMXPath $xpath;

    /**
     * Parst einen BMEcat-Katalog aus einem XML-String.
     *
     * @throws RuntimeException Bei ungültigem XML, fremdem Wurzelelement oder unbekannter Version.
     */
    public function parse(string $xml): BmecatCatalog {
        $dom = new DOMDocument;

        $internalErrors = libxml_use_internal_errors(true);
        // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        if (!$loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($internalErrors);
            $message = 'Failed to parse XML';
            if (!empty($errors)) {
                $message .= ': ' . trim($errors[0]->message);
            }
            $this->logErrorAndThrow(RuntimeException::class, $message);
        }
        libxml_use_internal_errors($internalErrors);

        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'BMECAT') {
            $this->logErrorAndThrow(RuntimeException::class, 'Unknown format. Expected a BMECAT catalog document.');
        }

        $version = $this->detectVersion($root);
        $this->xpath = new DOMXPath($dom);

        $catalog = $this->parseHeader($root, $version);

        foreach ($this->xpath->query('//*[local-name()="ARTICLE" or local-name()="PRODUCT"]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $this->parseArticle($catalog, $node);
            }
        }

        return $catalog;
    }

    /**
     * Parst einen BMEcat-Katalog aus einer Datei.
     */
    public function parseFile(string $filePath): BmecatCatalog {
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
     * Erkennt die Version: 2005-Namespace vor `version`-Attribut ("2005…" vor
     * "1.2…"); ohne beides ist die Datei nicht zuordenbar.
     */
    private function detectVersion(DOMElement $root): BmecatVersion {
        if (str_starts_with((string) $root->namespaceURI, self::NS_2005_PREFIX)) {
            return BmecatVersion::V2005;
        }

        $declared = trim($root->getAttribute('version'));
        if (str_starts_with($declared, '2005')) {
            return BmecatVersion::V2005;
        }
        if (str_starts_with($declared, '1.2')) {
            return BmecatVersion::V12;
        }

        $this->logErrorAndThrow(RuntimeException::class, $declared === ''
            ? 'Unknown BMEcat version: neither a 2005 namespace nor a version attribute was found.'
            : "Unsupported BMEcat version \"{$declared}\". Supported: 1.2 and 2005.");
    }

    /**
     * Liest den Katalog-Kopf (HEADER/CATALOG, Lieferant, Transaktionsart).
     */
    private function parseHeader(DOMElement $root, BmecatVersion $version): BmecatCatalog {
        $transaction = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, self::TRANSACTIONS, true)) {
                $transaction = $child->localName;
                break;
            }
        }

        $header = $this->first($root, '*[local-name()="HEADER"]');
        $catalogElement = $header !== null ? $this->first($header, '*[local-name()="CATALOG"]') : null;

        $currencyText = $catalogElement !== null ? $this->text($catalogElement, '*[local-name()="CURRENCY"]') : '';
        $currency = CurrencyCode::tryFrom($currencyText);

        // BMEcat 1.2 trägt den Lieferanten unter SUPPLIER/SUPPLIER_NAME,
        // BMEcat 2005 unter PARTIES/PARTY mit PARTY_ROLE "supplier".
        $supplierName = null;
        if ($header !== null) {
            $supplierName = $this->textOrNull($header, './/*[local-name()="SUPPLIER_NAME"]')
                ?? $this->textOrNull($header, './/*[local-name()="PARTY"][*[local-name()="PARTY_ROLE"]="supplier"]/*[local-name()="ADDRESS"]/*[local-name()="NAME"]');
        }

        $generationDate = null;
        if ($header !== null) {
            $dateText = $this->text($header, './/*[local-name()="DATETIME"][@type="generation_date"]/*[local-name()="DATE"]');
            if ($dateText !== '') {
                try {
                    $generationDate = new DateTimeImmutable($dateText);
                } catch (Exception) {
                    $generationDate = null;
                }
            }
        }

        $catalog = new BmecatCatalog(
            version: $version,
            transaction: $transaction,
            catalogId: $catalogElement !== null ? $this->textOrNull($catalogElement, '*[local-name()="CATALOG_ID"]') : null,
            catalogVersion: $catalogElement !== null ? $this->textOrNull($catalogElement, '*[local-name()="CATALOG_VERSION"]') : null,
            catalogName: $catalogElement !== null ? $this->textOrNull($catalogElement, '*[local-name()="CATALOG_NAME"]') : null,
            language: $catalogElement !== null ? $this->textOrNull($catalogElement, '*[local-name()="LANGUAGE"]') : null,
            currency: $currency,
            supplierName: $supplierName,
            generationDate: $generationDate
        );

        if ($currencyText !== '' && $currency === null) {
            $catalog->addWarning("Unknown catalog currency \"{$currencyText}\" ignored.");
        }

        return $catalog;
    }

    /**
     * Liest ein ARTICLE-/PRODUCT-Element in eine {@see BmecatArticle}-Entity.
     * Ohne SUPPLIER_AID/SUPPLIER_PID wird der Artikel mit Warnung übersprungen.
     */
    private function parseArticle(BmecatCatalog $catalog, DOMElement $node): void {
        $articleNumber = $this->firstChildText($node, ['SUPPLIER_AID', 'SUPPLIER_PID']);
        if ($articleNumber === '') {
            $catalog->addWarning(sprintf('line %d: %s without SUPPLIER_AID/SUPPLIER_PID — skipped.', $node->getLineNo(), (string) $node->localName));

            return;
        }

        $details = $this->first($node, '*[local-name()="ARTICLE_DETAILS" or local-name()="PRODUCT_DETAILS"]');
        // EAN heißt in BMEcat 2005 INTERNATIONAL_PID (üblicherweise type="ean").
        $ean = $details !== null ? $this->firstChildText($details, ['EAN', 'INTERNATIONAL_PID']) : '';

        $article = new BmecatArticle(
            articleNumber: $articleNumber,
            descriptionShort: $details !== null ? $this->firstChildText($details, ['DESCRIPTION_SHORT']) : '',
            descriptionLong: $details !== null ? $this->nullable($this->firstChildText($details, ['DESCRIPTION_LONG'])) : null,
            ean: $this->nullable($ean),
            manufacturerArticleNumber: $details !== null ? $this->nullable($this->firstChildText($details, ['MANUFACTURER_AID', 'MANUFACTURER_PID'])) : null,
            manufacturerName: $details !== null ? $this->nullable($this->firstChildText($details, ['MANUFACTURER_NAME'])) : null,
            classificationSystem: $this->textOrNull($node, './/*[local-name()="REFERENCE_FEATURE_SYSTEM_NAME"]'),
            classificationGroupId: $this->textOrNull($node, './/*[local-name()="REFERENCE_FEATURE_GROUP_ID"]'),
            orderUnit: $this->textOrNull($node, './/*[local-name()="ORDER_UNIT"]')
        );

        foreach ($this->xpath->query('.//*[local-name()="ARTICLE_PRICE" or local-name()="PRODUCT_PRICE"]', $node) ?: [] as $priceNode) {
            if ($priceNode instanceof DOMElement) {
                $article->addPrice($this->parsePrice($catalog, $priceNode));
            }
        }

        foreach ($this->xpath->query('.//*[local-name()="MIME"]', $node) ?: [] as $mimeNode) {
            if (!$mimeNode instanceof DOMElement) {
                continue;
            }
            $article->addMime(new BmecatMime(
                source: $this->text($mimeNode, './/*[local-name()="MIME_SOURCE"]'),
                purpose: $this->textOrNull($mimeNode, './/*[local-name()="MIME_PURPOSE"]'),
                mimeType: $this->textOrNull($mimeNode, './/*[local-name()="MIME_TYPE"]')
            ));
        }

        $catalog->addArticle($article);
    }

    /**
     * Liest ein ARTICLE_PRICE-/PRODUCT_PRICE-Element. Die Währung kommt aus
     * PRICE_CURRENCY, sonst aus dem Katalog-Kopf, sonst EUR.
     */
    private function parsePrice(BmecatCatalog $catalog, DOMElement $priceNode): BmecatPrice {
        $currency = CurrencyCode::tryFrom($this->text($priceNode, './/*[local-name()="PRICE_CURRENCY"]'))
            ?? $catalog->getCurrency()
            ?? CurrencyCode::Euro;

        $priceType = trim($priceNode->getAttribute('price_type'));

        return new BmecatPrice(
            amount: $this->money($this->text($priceNode, './/*[local-name()="PRICE_AMOUNT"]'), $currency),
            currency: $currency,
            priceType: $priceType !== '' ? $priceType : null,
            lowerBound: $this->quantity($this->text($priceNode, './/*[local-name()="LOWER_BOUND"]')) ?? 1.0
        );
    }

    /**
     * Normalisiert einen Roh-Betrag präzisionswahrend auf {@see Money} mit
     * Skala 4 (kaufmännische Rundung über Money statt float-Roundtrip).
     * Exponentialnotation geht über den float-Pfad, da bcmath sie nicht
     * versteht und Lieferanten-Exporte sie liefern können; Nicht-Zahlen → null.
     */
    private function money(string $raw, CurrencyCode $currency): ?Money {
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw) && stripos($raw, 'e') !== false) {
            $raw = number_format((float) $raw, self::PRICE_SCALE, '.', '');
        }

        $normalized = NumberHelper::normalizeDecimalStringOrNull($raw);
        if ($normalized === null || stripos($normalized, 'e') !== false) {
            return null;
        }

        return Money::of($normalized, $currency, self::PRICE_SCALE);
    }

    /** Mengenwert (LOWER_BOUND) als float; Nicht-Zahlen → null. */
    private function quantity(string $raw): ?float {
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        $normalized = NumberHelper::normalizeDecimalStringOrNull($raw);

        return $normalized !== null ? (float) $normalized : null;
    }

    /** Erstes Element auf den XPath-Ausdruck, null wenn keins passt. */
    private function first(DOMElement $context, string $expression): ?DOMElement {
        foreach ($this->xpath->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /** Getrimmter Text des ersten Treffers, leerer String wenn keiner passt. */
    private function text(DOMElement $context, string $expression): string {
        $nodes = $this->xpath->query($expression, $context);
        if ($nodes === false) {
            return '';
        }
        $node = $nodes->item(0);

        return $node instanceof DOMNode ? trim($node->textContent) : '';
    }

    /** Wie {@see text()}, aber null statt leerem String. */
    private function textOrNull(DOMElement $context, string $expression): ?string {
        return $this->nullable($this->text($context, $expression));
    }

    /**
     * Erstes nicht-leeres direktes Kindelement aus einer Namensliste — die
     * versionsabhängigen Elementnamen (SUPPLIER_AID/SUPPLIER_PID, …) werden so
     * zusammengeführt.
     *
     * @param  list<string>  $names
     */
    private function firstChildText(DOMElement $context, array $names): string {
        foreach ($names as $name) {
            $value = $this->text($context, '*[local-name()="' . $name . '"]');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function nullable(string $value): ?string {
        return $value !== '' ? $value : null;
    }
}
