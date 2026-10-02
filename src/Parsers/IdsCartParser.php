<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsCartParser.php
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
use DOMXPath;
use ERechnungToolkit\Entities\IdsConnect\{IdsAddress, IdsCart, IdsCartItem, IdsRawMaterial};
use ERechnungToolkit\Enums\{IdsItemCharacter, IdsReturnFlag};
use ERRORToolkit\Traits\ErrorLog;
use RuntimeException;

/**
 * Parser für IDS-Connect-Warenkörbe (Rücksprung WKE/WKS vom Großhandels-Shop,
 * Formularfeld `warenkorb`).
 *
 * Zugriffe laufen namespace-agnostisch über `local-name()`; Zahlen werden mit
 * Punkt erwartet, Komma wird toleriert. Ohne DTD (kein DOCTYPE, keine Entitäten)
 * und ohne Netzzugriff geladen. Positionen ohne Artikelnummer, Menge oder
 * Mengeneinheit brechen den Lauf nicht ab — sie landen als Warnung am
 * {@see IdsCart}. Nur ungültiges XML, ein DOCTYPE oder ein fremdes
 * Wurzelelement werfen.
 */
final class IdsCartParser {
    use ErrorLog;

    private const PRICE_SCALE = 4;

    private DOMXPath $xpath;

    /** @throws RuntimeException */
    public function parse(string $xml): IdsCart {
        $dom = new DOMDocument;
        $internalErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML(trim($xml), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        if (!$loaded || $dom->documentElement === null) {
            throw new RuntimeException('IDS-Warenkorb ist kein gültiges XML.');
        }
        if ($dom->doctype !== null) {
            throw new RuntimeException('IDS-Warenkorb mit DOCTYPE wird nicht verarbeitet.');
        }
        if ($dom->documentElement->localName !== 'Warenkorb') {
            throw new RuntimeException('Wurzelelement „Warenkorb“ erwartet, gefunden: ' . $dom->documentElement->localName);
        }
        $this->xpath = new DOMXPath($dom);
        $root = $dom->documentElement;

        $currency = CurrencyCode::tryFrom(strtoupper((string) $this->text($root, 'Order/OrderInfo/Cur'))) ?? CurrencyCode::Euro;
        $items = [];
        $warnings = [];
        foreach ($this->nodes($root, 'Order/OrderItem') as $index => $node) {
            $item = $this->item($node, $currency);
            if ($item === null) {
                $warnings[] = sprintf('Position %d ohne Artikelnummer, Menge oder Mengeneinheit übersprungen.', $index + 1);
                continue;
            }
            $items[] = $item;
        }
        $this->logDebug('IDS-Warenkorb gelesen', ['items' => count($items), 'warnings' => count($warnings)]);

        return new IdsCart(
            items: $items,
            currency: $currency,
            createdAt: $this->dateTime($this->text($root, 'WarenkorbInfo/Date'), $this->text($root, 'WarenkorbInfo/Time')),
            returnFlag: IdsReturnFlag::tryFrom((string) $this->text($root, 'WarenkorbInfo/RueckgabeKZ')),
            version: $this->text($root, 'WarenkorbInfo/Version') ?? '',
            inquiryNumber: $this->text($root, 'Order/OrderInfo/InquiryNo'),
            offerNumber: $this->text($root, 'Order/OrderInfo/OfferNo'),
            orderNumber: $this->text($root, 'Order/OrderInfo/PartNo'),
            orderConfirmationNumber: $this->text($root, 'Order/OrderInfo/OrderConfNo'),
            deliveryWeek: $this->int($this->text($root, 'Order/OrderInfo/DeliveryWeek')),
            deliveryYear: $this->int($this->text($root, 'Order/OrderInfo/DeliveryYear')),
            deliveryDate: $this->dateTime($this->text($root, 'Order/OrderInfo/DeliveryDate'), null),
            modeOfShipment: $this->text($root, 'Order/OrderInfo/ModeOfShipment'),
            additionalText: $this->text($root, 'Order/OrderInfo/ZusatzText'),
            commission: $this->text($root, 'Order/OrderInfo/Kommission'),
            supplierNumber: $this->text($root, 'Order/SupplierInfo/IDNo'),
            supplierAddress: $this->address($root, 'Order/SupplierInfo/Address'),
            customerNumber: $this->text($root, 'Order/CustomerInfo/IDNo'),
            customerAddress: $this->address($root, 'Order/CustomerInfo/Address'),
            deliveryAddress: $this->address($root, 'Order/DeliveryPlaceInfo/Address'),
            warnings: $warnings,
        );
    }

    private function item(DOMElement $node, CurrencyCode $currency): ?IdsCartItem {
        $articleNumber = $this->text($node, 'ArtNo');
        $quantity = $this->decimal($this->text($node, 'Qty'));
        $unit = $this->text($node, 'QU');
        if ($articleNumber === null || $quantity === null || $unit === null) {
            return null;
        }
        $rawMaterials = [];
        foreach ($this->nodes($node, 'Rohstoffanteil') as $raw) {
            $rawMaterials[] = new IdsRawMaterial(
                material: $this->text($raw, 'Rohstoff'),
                weightValue: $this->decimal($this->text($raw, 'Gewichtsanteilswert')),
                weightUnit: $this->text($raw, 'Gewichtsanteilseinheit'),
                baseValue: $this->decimal($this->text($raw, 'Basiswert')),
                baseUnit: $this->text($raw, 'Basiseinheit'),
                baseQuotation: $this->money($this->text($raw, 'Basisnotierung'), $currency),
                currentQuotation: $this->money($this->text($raw, 'NotierungAktuell'), $currency),
            );
        }
        $clarification = $this->text($node, 'TechnClarification');

        return new IdsCartItem(
            articleNumber: $articleNumber,
            quantity: $quantity,
            unit: strtoupper($unit),
            itemCharacter: IdsItemCharacter::tryFrom(strtolower((string) $this->text($node, 'ItemChara'))) ?? IdsItemCharacter::Normal,
            shortText: $this->text($node, 'Kurztext'),
            longText: $this->text($node, 'Langtext'),
            ean: $this->text($node, 'EAN'),
            offerPrice: $this->money($this->text($node, 'OfferPrice'), $currency),
            netPrice: $this->money($this->text($node, 'NetPrice'), $currency),
            priceBasis: $this->decimal($this->text($node, 'PriceBasis')),
            vatPercent: $this->decimal($this->text($node, 'VAT')),
            surchargePercent: $this->decimal($this->text($node, 'Zuschlag')),
            customerPosition: $this->text($node, 'RefItems/Customer'),
            customerSubPosition: $this->text($node, 'RefItems/CustomerSubNo'),
            supplierPosition: $this->text($node, 'RefItems/Supplier'),
            supplierSubPosition: $this->text($node, 'RefItems/SupplierSubNo'),
            technicalClarification: $clarification === null ? null : in_array(strtolower($clarification), ['yes', 'true', 'ja', '1'], true),
            notice: $this->text($node, 'Hinweis'),
            errorCode: $this->int($this->text($node, 'Fehlercode')),
            errorText: $this->text($node, 'Fehlertext'),
            rawMaterials: $rawMaterials,
        );
    }

    private function address(DOMElement $context, string $path): ?IdsAddress {
        $node = $this->nodes($context, $path)[0] ?? null;
        if ($node === null) {
            return null;
        }

        return new IdsAddress(
            name1: $this->text($node, 'Name1'),
            name2: $this->text($node, 'Name2'),
            name3: $this->text($node, 'Name3'),
            name4: $this->text($node, 'Name4'),
            street: $this->text($node, 'Street'),
            postalCode: $this->text($node, 'PCode'),
            city: $this->text($node, 'City'),
            country: $this->text($node, 'Country'),
            iln: $this->text($node, 'ILN'),
            contact: $this->text($node, 'Contact'),
            phone: $this->text($node, 'Phone'),
            fax: $this->text($node, 'Fax'),
            email: $this->text($node, 'Email'),
        );
    }

    /** @return list<DOMElement> */
    private function nodes(DOMElement $context, string $path): array {
        $query = implode('/', array_map(static fn (string $step): string => "*[local-name()='{$step}']", explode('/', $path)));
        $result = $this->xpath->query($query, $context);
        $nodes = [];
        if ($result !== false) {
            foreach ($result as $node) {
                if ($node instanceof DOMElement) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    private function text(DOMElement $context, string $path): ?string {
        $node = $this->nodes($context, $path)[0] ?? null;
        $value = $node !== null ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }

    private function decimal(?string $value): ?float {
        $normalized = $value === null ? null : NumberHelper::normalizeDecimalStringOrNull($value);

        return $normalized === null ? null : (float) $normalized;
    }

    private function int(?string $value): ?int {
        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private function money(?string $value, CurrencyCode $currency): ?Money {
        $normalized = $value === null ? null : NumberHelper::normalizeDecimalStringOrNull($value);

        return $normalized === null ? null : Money::of($normalized, $currency, self::PRICE_SCALE);
    }

    private function dateTime(?string $date, ?string $time): ?DateTimeImmutable {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . ($time !== null && preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1 ? $time : '00:00:00'));

        return $parsed !== false ? $parsed : null;
    }
}
