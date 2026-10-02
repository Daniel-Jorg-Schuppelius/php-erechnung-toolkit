<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsCartGenerator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Generators;

use CommonToolkit\Helper\Data\NumberHelper;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use ERechnungToolkit\Entities\IdsConnect\{IdsAddress, IdsCart, IdsCartItem};
use ERRORToolkit\Traits\ErrorLog;

/**
 * Generator für IDS-Connect-Warenkörbe (Aktion WKS: Handwerkssoftware übergibt
 * einen Warenkorb an den Großhandels-Shop). Leere Felder entfallen; Zahlen mit
 * Punkt und ohne Tausendertrennung, wie es die Spezifikation verlangt.
 */
final class IdsCartGenerator {
    use ErrorLog;

    private DOMDocument $dom;

    public function generate(IdsCart $cart): string {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $root = $this->dom->createElement('Warenkorb');
        $this->dom->appendChild($root);

        $createdAt = $cart->getCreatedAt() ?? new DateTimeImmutable;
        $info = $this->append($root, 'WarenkorbInfo');
        $this->text($info, 'Date', $createdAt->format('Y-m-d'));
        $this->text($info, 'Time', $createdAt->format('H:i:s'));
        $this->text($info, 'RueckgabeKZ', $cart->getReturnFlag()?->value);
        $this->text($info, 'Version', $cart->getVersion());

        $order = $this->append($root, 'Order');
        $orderInfo = $this->append($order, 'OrderInfo');
        $this->text($orderInfo, 'InquiryNo', $cart->getInquiryNumber());
        $this->text($orderInfo, 'OfferNo', $cart->getOfferNumber());
        $this->text($orderInfo, 'PartNo', $cart->getOrderNumber());
        $this->text($orderInfo, 'OrderConfNo', $cart->getOrderConfirmationNumber());
        $this->text($orderInfo, 'DeliveryWeek', $cart->getDeliveryWeek() !== null ? (string) $cart->getDeliveryWeek() : null);
        $this->text($orderInfo, 'DeliveryYear', $cart->getDeliveryYear() !== null ? (string) $cart->getDeliveryYear() : null);
        $this->text($orderInfo, 'DeliveryDate', $cart->getDeliveryDate()?->format('Y-m-d'));
        $this->text($orderInfo, 'ModeOfShipment', $cart->getModeOfShipment() ?? 'Lieferung');
        $this->text($orderInfo, 'Cur', $cart->getCurrency()->value);
        $this->text($orderInfo, 'ZusatzText', $cart->getAdditionalText());
        $this->text($orderInfo, 'Kommission', $cart->getCommission());

        $this->party($order, 'SupplierInfo', $cart->getSupplierNumber(), $cart->getSupplierAddress());
        $this->party($order, 'CustomerInfo', $cart->getCustomerNumber(), $cart->getCustomerAddress());
        if ($cart->getDeliveryAddress() !== null) {
            $this->address($this->append($order, 'DeliveryPlaceInfo'), $cart->getDeliveryAddress());
        }
        foreach ($cart->getItems() as $item) {
            $this->item($order, $item);
        }
        $this->logDebug('IDS-Warenkorb erzeugt', ['items' => count($cart->getItems())]);
        $xml = $this->dom->saveXML();

        return $xml !== false ? $xml : '';
    }

    private function item(DOMElement $order, IdsCartItem $item): void {
        $node = $this->append($order, 'OrderItem');
        $this->text($node, 'ItemChara', $item->getItemCharacter()->value);
        if ($item->getCustomerPosition() !== null || $item->getSupplierPosition() !== null) {
            $refs = $this->append($node, 'RefItems');
            $this->text($refs, 'Customer', $item->getCustomerPosition());
            $this->text($refs, 'CustomerSubNo', $item->getCustomerSubPosition());
            $this->text($refs, 'Supplier', $item->getSupplierPosition());
            $this->text($refs, 'SupplierSubNo', $item->getSupplierSubPosition());
        }
        $this->text($node, 'EAN', $item->getEan());
        $this->text($node, 'ArtNo', $item->getArticleNumber());
        $this->text($node, 'Qty', NumberHelper::toUSFormat($item->getQuantity(), 2));
        $this->text($node, 'QU', $item->getUnit());
        $this->text($node, 'Kurztext', $item->getShortText());
        $this->text($node, 'Langtext', $item->getLongText());
        $this->text($node, 'OfferPrice', $this->amount($item->getOfferPrice()));
        $this->text($node, 'NetPrice', $this->amount($item->getNetPrice()));
        $this->text($node, 'PriceBasis', $item->getPriceBasis() !== null ? NumberHelper::toUSFormat($item->getPriceBasis(), 2) : null);
        $this->text($node, 'VAT', $item->getVatPercent() !== null ? NumberHelper::toUSFormat($item->getVatPercent(), 2) : null);
        $this->text($node, 'Hinweis', $item->getNotice());
    }

    private function party(DOMElement $order, string $element, ?string $number, ?IdsAddress $address): void {
        if ($number === null && $address === null) {
            return;
        }
        $node = $this->append($order, $element);
        $this->text($node, 'IDNo', $number);
        if ($address !== null) {
            $this->address($node, $address);
        }
    }

    private function address(DOMElement $parent, IdsAddress $address): void {
        $node = $this->append($parent, 'Address');
        $this->text($node, 'Name1', $address->getName1());
        $this->text($node, 'Name2', $address->getName2());
        $this->text($node, 'Name3', $address->getName3());
        $this->text($node, 'Name4', $address->getName4());
        $this->text($node, 'Street', $address->getStreet());
        $this->text($node, 'PCode', $address->getPostalCode());
        $this->text($node, 'City', $address->getCity());
        $this->text($node, 'Country', $address->getCountry());
        $this->text($node, 'ILN', $address->getIln());
        $this->text($node, 'Contact', $address->getContact());
        $this->text($node, 'Phone', $address->getPhone());
        $this->text($node, 'Fax', $address->getFax());
        $this->text($node, 'Email', $address->getEmail());
    }

    private function append(DOMElement $parent, string $name): DOMElement {
        $node = $this->dom->createElement($name);
        $parent->appendChild($node);

        return $node;
    }

    private function text(DOMElement $parent, string $name, ?string $value): void {
        if ($value === null || $value === '') {
            return;
        }
        $node = $this->dom->createElement($name);
        $node->appendChild($this->dom->createTextNode($value));
        $parent->appendChild($node);
    }

    private function amount(?Money $money): ?string {
        return $money?->withScale(4)->getAmount();
    }
}
