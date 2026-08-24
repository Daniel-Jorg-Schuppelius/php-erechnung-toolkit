<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Enums\{BmecatVersion, UnitCode};
use ERechnungToolkit\Parsers\BmecatParser;
use InvalidArgumentException;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests für den BMEcat-1.2/2005-Parser.
 *
 * Die 1.2-/2005-Fixtures stammen aus dem workDiary-App-Test
 * (tests/Feature/Procurement/CatalogBMEcatImportTest.php) — die erwarteten
 * Werte (Artikelnummern, Beträge mit Skala 4, Klassifikation, Medien-URLs,
 * Staffeln) sind dort als Paritäts-Referenz identisch belegt.
 */
class BmecatParserTest extends BaseTestCase {
    private BmecatParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new BmecatParser;
    }

    /** Fixture aus dem App-Test: zwei ARTICLE-Elemente, BMEcat 1.2. */
    private function bmecat12(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <SUPPLIER_AID>BM-1</SUPPLIER_AID>
      <ARTICLE_DETAILS>
        <DESCRIPTION_SHORT>Kabel NYM-J 3x1,5</DESCRIPTION_SHORT>
        <EAN>4011111111111</EAN>
        <MANUFACTURER_AID>MFR-9</MANUFACTURER_AID>
      </ARTICLE_DETAILS>
      <ARTICLE_PRICE_DETAILS>
        <ARTICLE_PRICE price_type="net_list">
          <PRICE_AMOUNT>1.25</PRICE_AMOUNT>
          <PRICE_CURRENCY>EUR</PRICE_CURRENCY>
        </ARTICLE_PRICE>
      </ARTICLE_PRICE_DETAILS>
    </ARTICLE>
    <ARTICLE>
      <SUPPLIER_AID>BM-2</SUPPLIER_AID>
      <ARTICLE_DETAILS><DESCRIPTION_SHORT>Schalter</DESCRIPTION_SHORT></ARTICLE_DETAILS>
      <ARTICLE_PRICE_DETAILS><ARTICLE_PRICE><PRICE_AMOUNT>3.40</PRICE_AMOUNT></ARTICLE_PRICE></ARTICLE_PRICE_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;
    }

    /** Fixture aus dem App-Test: Staffelpreise (LOWER_BOUND 1/10/100). */
    private function bmecatWithScalePrices(): string {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <SUPPLIER_AID>TR-1</SUPPLIER_AID>
      <ARTICLE_DETAILS><DESCRIPTION_SHORT>Kabel</DESCRIPTION_SHORT></ARTICLE_DETAILS>
      <ARTICLE_PRICE_DETAILS>
        <ARTICLE_PRICE><PRICE_AMOUNT>2.00</PRICE_AMOUNT><LOWER_BOUND>1</LOWER_BOUND></ARTICLE_PRICE>
        <ARTICLE_PRICE><PRICE_AMOUNT>1.80</PRICE_AMOUNT><LOWER_BOUND>10</LOWER_BOUND></ARTICLE_PRICE>
        <ARTICLE_PRICE><PRICE_AMOUNT>1.50</PRICE_AMOUNT><LOWER_BOUND>100</LOWER_BOUND></ARTICLE_PRICE>
      </ARTICLE_PRICE_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;
    }

    public function test_bmecat_12_articles_are_parsed(): void {
        $catalog = $this->parser->parse($this->bmecat12());

        self::assertSame(BmecatVersion::V12, $catalog->getVersion());
        self::assertSame('T_NEW_CATALOG', $catalog->getTransaction());
        self::assertCount(2, $catalog->getArticles());
        self::assertSame([], $catalog->getWarnings());

        // Paritäts-Referenz App-Test: purchase_price '1.2500', gtin, manufacturer_no.
        $kabel = $catalog->getArticles()[0];
        self::assertSame('BM-1', $kabel->getArticleNumber());
        self::assertSame('Kabel NYM-J 3x1,5', $kabel->getName());
        self::assertSame('4011111111111', $kabel->getEan());
        self::assertSame('MFR-9', $kabel->getManufacturerArticleNumber());
        $kabelPreis = $kabel->getPrice();
        self::assertNotNull($kabelPreis);
        self::assertSame('1.2500', $kabelPreis->getAmount()?->getAmount());
        self::assertSame(CurrencyCode::Euro, $kabelPreis->getCurrency());
        self::assertSame('net_list', $kabelPreis->getPriceType());
        self::assertSame([], $kabel->getScalePrices());

        // Ohne PRICE_CURRENCY greift der EUR-Default (Parität zum App-Parser).
        $schalter = $catalog->getArticles()[1];
        self::assertSame('Schalter', $schalter->getName());
        $schalterPreis = $schalter->getPrice();
        self::assertNotNull($schalterPreis);
        self::assertSame('3.4000', $schalterPreis->getAmount()?->getAmount());
        self::assertSame(CurrencyCode::Euro, $schalterPreis->getCurrency());
    }

    public function test_bmecat_2005_product_elements(): void {
        // Fixture aus dem App-Test: PRODUCT/SUPPLIER_PID ohne Namespace.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="2005">
  <T_NEW_CATALOG>
    <PRODUCT>
      <SUPPLIER_PID>P-1</SUPPLIER_PID>
      <PRODUCT_DETAILS><DESCRIPTION_SHORT>Rohr</DESCRIPTION_SHORT></PRODUCT_DETAILS>
      <PRODUCT_PRICE_DETAILS><PRODUCT_PRICE><PRICE_AMOUNT>9.90</PRICE_AMOUNT></PRODUCT_PRICE></PRODUCT_PRICE_DETAILS>
    </PRODUCT>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $catalog = $this->parser->parse($xml);

        self::assertSame(BmecatVersion::V2005, $catalog->getVersion());
        self::assertCount(1, $catalog->getArticles());
        // Paritäts-Referenz App-Test: purchase_price '9.9000'.
        self::assertSame('P-1', $catalog->getArticles()[0]->getArticleNumber());
        self::assertSame('9.9000', $catalog->getArticles()[0]->getPrice()?->getAmount()?->getAmount());
    }

    public function test_bmecat_2005_with_namespace_header_and_units(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT xmlns="http://www.bmecat.org/bmecat/2005" version="2005">
  <HEADER>
    <CATALOG>
      <LANGUAGE>deu</LANGUAGE>
      <CATALOG_ID>KAT-77</CATALOG_ID>
      <CATALOG_VERSION>3.1</CATALOG_VERSION>
      <CATALOG_NAME>Elektro Vollsortiment</CATALOG_NAME>
      <DATETIME type="generation_date"><DATE>2026-08-01</DATE></DATETIME>
      <CURRENCY>CHF</CURRENCY>
    </CATALOG>
    <PARTIES>
      <PARTY>
        <PARTY_ID>4711</PARTY_ID>
        <PARTY_ROLE>supplier</PARTY_ROLE>
        <ADDRESS><NAME>Muster Grosshandel AG</NAME></ADDRESS>
      </PARTY>
    </PARTIES>
  </HEADER>
  <T_NEW_CATALOG>
    <PRODUCT>
      <SUPPLIER_PID>NS-1</SUPPLIER_PID>
      <PRODUCT_DETAILS>
        <DESCRIPTION_SHORT>Leitung</DESCRIPTION_SHORT>
        <INTERNATIONAL_PID type="ean">4022222222222</INTERNATIONAL_PID>
        <MANUFACTURER_PID>M-55</MANUFACTURER_PID>
        <MANUFACTURER_NAME>Musterwerk</MANUFACTURER_NAME>
      </PRODUCT_DETAILS>
      <PRODUCT_ORDER_DETAILS><ORDER_UNIT>MTR</ORDER_UNIT></PRODUCT_ORDER_DETAILS>
      <PRODUCT_PRICE_DETAILS><PRODUCT_PRICE><PRICE_AMOUNT>4.50</PRICE_AMOUNT></PRODUCT_PRICE></PRODUCT_PRICE_DETAILS>
    </PRODUCT>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $catalog = $this->parser->parse($xml);

        self::assertSame(BmecatVersion::V2005, $catalog->getVersion());
        self::assertSame('KAT-77', $catalog->getCatalogId());
        self::assertSame('3.1', $catalog->getCatalogVersion());
        self::assertSame('Elektro Vollsortiment', $catalog->getCatalogName());
        self::assertSame('deu', $catalog->getLanguage());
        self::assertSame(CurrencyCode::SwissFranc, $catalog->getCurrency());
        self::assertSame('Muster Grosshandel AG', $catalog->getSupplierName());
        self::assertSame('2026-08-01', $catalog->getGenerationDate()?->format('Y-m-d'));

        $article = $catalog->getArticles()[0];
        self::assertSame('NS-1', $article->getArticleNumber());
        self::assertSame('4022222222222', $article->getEan());
        self::assertSame('M-55', $article->getManufacturerArticleNumber());
        self::assertSame('Musterwerk', $article->getManufacturerName());
        self::assertSame('MTR', $article->getOrderUnit());
        self::assertSame(UnitCode::METRE, $article->getOrderUnitCode());
        // Preis ohne eigenes PRICE_CURRENCY erbt die Katalogwährung.
        $preis = $article->getPrice();
        self::assertNotNull($preis);
        self::assertSame(CurrencyCode::SwissFranc, $preis->getCurrency());
        self::assertSame('4.5000', $preis->getAmount()?->getAmount());
    }

    public function test_classification_and_media_are_extracted(): void {
        // Fixture aus dem App-Test (Klassifikation + MIME-Zwecke).
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <SUPPLIER_AID>CM-1</SUPPLIER_AID>
      <ARTICLE_DETAILS><DESCRIPTION_SHORT>Sensor</DESCRIPTION_SHORT></ARTICLE_DETAILS>
      <ARTICLE_FEATURES>
        <REFERENCE_FEATURE_SYSTEM_NAME>ECLASS-9.0</REFERENCE_FEATURE_SYSTEM_NAME>
        <REFERENCE_FEATURE_GROUP_ID>27-27-90-01</REFERENCE_FEATURE_GROUP_ID>
      </ARTICLE_FEATURES>
      <MIME_INFO>
        <MIME><MIME_TYPE>image/jpeg</MIME_TYPE><MIME_SOURCE>https://x/img.jpg</MIME_SOURCE><MIME_PURPOSE>normal</MIME_PURPOSE></MIME>
        <MIME><MIME_TYPE>application/pdf</MIME_TYPE><MIME_SOURCE>https://x/ds.pdf</MIME_SOURCE><MIME_PURPOSE>data_sheet</MIME_PURPOSE></MIME>
      </MIME_INFO>
      <ARTICLE_PRICE_DETAILS><ARTICLE_PRICE><PRICE_AMOUNT>5.00</PRICE_AMOUNT></ARTICLE_PRICE></ARTICLE_PRICE_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $catalog = $this->parser->parse($xml);

        // Paritäts-Referenz App-Test: classification_system/code, image_url, datasheet_url.
        $article = $catalog->getArticles()[0];
        self::assertSame('ECLASS-9.0', $article->getClassificationSystem());
        self::assertSame('27-27-90-01', $article->getClassificationGroupId());
        self::assertSame('https://x/img.jpg', $article->getImageUrl());
        self::assertSame('https://x/ds.pdf', $article->getDatasheetUrl());
        self::assertCount(2, $article->getMimes());
        self::assertSame('image/jpeg', $article->getMimes()[0]->getMimeType());
    }

    public function test_scale_prices_are_separated_from_the_base_price(): void {
        $catalog = $this->parser->parse($this->bmecatWithScalePrices());

        $article = $catalog->getArticles()[0];
        // Paritäts-Referenz App-Test: Basispreis '2.0000' (Bound 1), zwei
        // Staffeln (Bounds 10 + 100), Staffel 10 → '1.8000'.
        self::assertSame('2.0000', $article->getPrice()?->getAmount()?->getAmount());
        self::assertCount(3, $article->getPrices());

        $scales = $article->getScalePrices();
        self::assertCount(2, $scales);
        self::assertSame(10.0, $scales[0]->getLowerBound());
        self::assertSame('1.8000', $scales[0]->getAmount()?->getAmount());
        self::assertSame(100.0, $scales[1]->getLowerBound());
        self::assertSame('1.5000', $scales[1]->getAmount()?->getAmount());
    }

    public function test_exponent_notation_prices_are_resolved(): void {
        // Lieferanten-Exporte liefern gelegentlich Exponentialnotation.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <SUPPLIER_AID>EX-1</SUPPLIER_AID>
      <ARTICLE_PRICE_DETAILS><ARTICLE_PRICE><PRICE_AMOUNT>1.25E1</PRICE_AMOUNT></ARTICLE_PRICE></ARTICLE_PRICE_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $catalog = $this->parser->parse($xml);

        self::assertSame('12.5000', $catalog->getArticles()[0]->getPrice()?->getAmount()?->getAmount());
        // Ohne DESCRIPTION_SHORT fällt der Name auf die Artikelnummer zurück.
        self::assertSame('EX-1', $catalog->getArticles()[0]->getName());
    }

    public function test_article_without_supplier_number_is_skipped_with_warning(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <ARTICLE_DETAILS><DESCRIPTION_SHORT>Ohne Nummer</DESCRIPTION_SHORT></ARTICLE_DETAILS>
    </ARTICLE>
    <ARTICLE>
      <SUPPLIER_AID>OK-1</SUPPLIER_AID>
      <ARTICLE_DETAILS><DESCRIPTION_SHORT>Mit Nummer</DESCRIPTION_SHORT></ARTICLE_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $catalog = $this->parser->parse($xml);

        self::assertCount(1, $catalog->getArticles());
        self::assertSame('OK-1', $catalog->getArticles()[0]->getArticleNumber());
        self::assertCount(1, $catalog->getWarnings());
        self::assertStringContainsString('SUPPLIER_AID', $catalog->getWarnings()[0]);
    }

    public function test_order_unit_free_text_resolves_via_piece_family(): void {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<BMECAT version="1.2">
  <T_NEW_CATALOG>
    <ARTICLE>
      <SUPPLIER_AID>U-1</SUPPLIER_AID>
      <ARTICLE_ORDER_DETAILS><ORDER_UNIT>PCE</ORDER_UNIT></ARTICLE_ORDER_DETAILS>
    </ARTICLE>
  </T_NEW_CATALOG>
</BMECAT>
XML;

        $article = $this->parser->parse($xml)->getArticles()[0];

        self::assertSame('PCE', $article->getOrderUnit());
        // "PCE" ist kein UN/ECE-Code — fromText löst über die Stück-Familie auf.
        self::assertSame(UnitCode::PIECE, $article->getOrderUnitCode());
        self::assertSame(UnitCode::UNIT_H87, $article->getOrderUnitCode(UnitCode::UNIT_H87));
    }

    public function test_invalid_xml_throws(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse XML');

        $this->parser->parse('kein xml <<<');
    }

    public function test_unknown_root_element_throws(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected a BMECAT catalog document');

        $this->parser->parse('<?xml version="1.0"?><CATALOGUE version="1.2"/>');
    }

    public function test_unknown_version_throws(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown BMEcat version');

        $this->parser->parse('<?xml version="1.0"?><BMECAT><T_NEW_CATALOG/></BMECAT>');
    }

    public function test_unsupported_version_throws(): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported BMEcat version "3.0"');

        $this->parser->parse('<?xml version="1.0"?><BMECAT version="3.0"><T_NEW_CATALOG/></BMECAT>');
    }

    public function test_parse_file_missing_file_throws(): void {
        $this->expectException(InvalidArgumentException::class);

        $this->parser->parseFile('/nonexistent/bmecat.xml');
    }
}
