<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsCartParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Enums\{IdsItemCharacter, IdsReturnFlag, UnitCode};
use ERechnungToolkit\Generators\IdsCartGenerator;
use ERechnungToolkit\Parsers\IdsCartParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

class IdsCartParserTest extends BaseTestCase {
    private const CART = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Warenkorb>
  <WarenkorbInfo>
    <Date>2026-10-02</Date>
    <Time>14:30:05</Time>
    <RueckgabeKZ>Warenkorbrückgabe</RueckgabeKZ>
    <Version>2.5</Version>
  </WarenkorbInfo>
  <Order>
    <OrderInfo>
      <InquiryNo>BE-2026-0042</InquiryNo>
      <OfferNo>AN-77</OfferNo>
      <ModeOfShipment>Lieferung</ModeOfShipment>
      <Cur>EUR</Cur>
      <Kommission>Bad Familie Muster</Kommission>
    </OrderInfo>
    <SupplierInfo>
      <IDNo>L-100</IDNo>
      <Address><Name1>Haustechnik Großhandel</Name1><Name2>GmbH</Name2><City>Würzburg</City></Address>
    </SupplierInfo>
    <CustomerInfo><IDNo>K-4711</IDNo></CustomerInfo>
    <OrderItem>
      <ItemChara>normal</ItemChara>
      <RefItems><Customer>1</Customer><Supplier>10</Supplier></RefItems>
      <EAN>4005176000000</EAN>
      <ArtNo>WT-200</ArtNo>
      <Qty>2.00</Qty>
      <QU>PCE</QU>
      <Kurztext>Waschtisch 60 cm</Kurztext>
      <OfferPrice>250.0000</OfferPrice>
      <NetPrice>375.5000</NetPrice>
      <PriceBasis>1</PriceBasis>
      <VAT>19.00</VAT>
      <Zuschlag>-25.00</Zuschlag>
      <Hinweis>Lieferzeit 5 Tage</Hinweis>
    </OrderItem>
    <OrderItem>
      <ItemChara>alternate</ItemChara>
      <ArtNo>NYM-3X1,5</ArtNo>
      <Qty>50</Qty>
      <QU>MTR</QU>
      <OfferPrice>10000</OfferPrice>
      <NetPrice>522</NetPrice>
      <PriceBasis>1000</PriceBasis>
      <Rohstoffanteil>
        <Rohstoff>CU</Rohstoff>
        <Gewichtsanteilswert>96</Gewichtsanteilswert>
        <Gewichtsanteilseinheit>KGM</Gewichtsanteilseinheit>
        <Basiswert>100</Basiswert>
        <Basiseinheit>MTR</Basiseinheit>
        <Basisnotierung>150</Basisnotierung>
        <NotierungAktuell>300</NotierungAktuell>
      </Rohstoffanteil>
    </OrderItem>
    <OrderItem>
      <Kurztext>ohne Artikelnummer</Kurztext>
      <Qty>1</Qty>
      <QU>PCE</QU>
    </OrderItem>
  </Order>
</Warenkorb>
XML;

    public function test_parses_header_items_and_raw_materials(): void {
        $cart = (new IdsCartParser)->parse(self::CART);

        $this->assertSame(IdsReturnFlag::CartReturn, $cart->getReturnFlag());
        $this->assertFalse($cart->isOrdered());
        $this->assertSame('2026-10-02 14:30:05', $cart->getCreatedAt()?->format('Y-m-d H:i:s'));
        $this->assertSame(CurrencyCode::Euro, $cart->getCurrency());
        $this->assertSame('BE-2026-0042', $cart->getInquiryNumber());
        $this->assertSame('K-4711', $cart->getCustomerNumber());
        $this->assertSame('Haustechnik Großhandel GmbH', $cart->getSupplierAddress()?->getName());
        $this->assertCount(2, $cart->getItems());
        $this->assertCount(1, $cart->getWarnings());

        [$basin, $cable] = $cart->getItems();
        $this->assertSame('WT-200', $basin->getArticleNumber());
        $this->assertSame(UnitCode::PIECE, $basin->getUnitCode());
        $this->assertSame('187.7500', $basin->getNetUnitPrice()?->getAmount());
        $this->assertSame(-25.0, $basin->getSurchargePercent());
        $this->assertSame('1', $basin->getCustomerPosition());
        $this->assertSame('Lieferzeit 5 Tage', $basin->getNotice());

        $this->assertSame(IdsItemCharacter::Alternate, $cable->getItemCharacter());
        $this->assertSame('NYM-3X1,5', $cable->getArticleNumber());
        $this->assertSame('CU', $cable->getRawMaterials()[0]->getMaterial());
        $this->assertSame('300.0000', $cable->getRawMaterials()[0]->getCurrentQuotation()?->getAmount());
        $this->assertSame('10.4400', $cable->getNetUnitPrice()?->getAmount());
    }

    public function test_reads_latin1_and_ordered_flag(): void {
        $xml = mb_convert_encoding(str_replace(['encoding="UTF-8"', '<RueckgabeKZ>Warenkorbrückgabe</RueckgabeKZ>'], ['encoding="ISO-8859-1"', '<RueckgabeKZ>Warenkorbrückgabe mit Bestellung</RueckgabeKZ>'], self::CART), 'ISO-8859-1', 'UTF-8');

        $cart = (new IdsCartParser)->parse($xml);

        $this->assertTrue($cart->isOrdered());
        $this->assertSame('Haustechnik Großhandel GmbH', $cart->getSupplierAddress()?->getName());
    }

    public function test_rejects_doctype_and_foreign_root(): void {
        $this->expectException(RuntimeException::class);
        (new IdsCartParser)->parse('<?xml version="1.0"?><!DOCTYPE Warenkorb [<!ENTITY x "y">]><Warenkorb/>');
    }

    public function test_rejects_foreign_root(): void {
        $this->expectException(RuntimeException::class);
        (new IdsCartParser)->parse('<Order/>');
    }

    public function test_generator_round_trip(): void {
        $cart = (new IdsCartParser)->parse(self::CART);

        $again = (new IdsCartParser)->parse((new IdsCartGenerator)->generate($cart));

        $this->assertSame($cart->getInquiryNumber(), $again->getInquiryNumber());
        $this->assertSame('Bad Familie Muster', $again->getCommission());
        $this->assertCount(2, $again->getItems());
        $this->assertSame('375.5000', $again->getItems()[0]->getNetPrice()?->getAmount());
        $this->assertSame('MTR', $again->getItems()[1]->getUnit());
    }
}
