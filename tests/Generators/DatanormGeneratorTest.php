<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormGeneratorTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use CommonToolkit\Enums\CurrencyCode;
use CommonToolkit\ValueObjects\Money;
use DateTimeImmutable;
use ERechnungToolkit\Entities\Datanorm\{
    DatanormArticle,
    DatanormArticleChange,
    DatanormCatalog,
    DatanormCustomer,
    DatanormDiscount,
    DatanormDiscountGroup,
    DatanormPriceChange,
    DatanormProductGroup,
    DatanormRawMaterialSurcharge,
    DatanormScalePrice,
    DatanormTextBlock,
    DatanormWorkTime
};
use ERechnungToolkit\Enums\{DatanormDataMark, DatanormDiscountKind, DatanormPriceIndicator, DatanormVersion};
use ERechnungToolkit\Generators\DatanormGenerator;
use ERechnungToolkit\Parsers\DatanormParser;
use InvalidArgumentException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the DATANORM 4/5 generator, including parser round-trips.
 */
class DatanormGeneratorTest extends BaseTestCase {
    private DatanormGenerator $generator;
    private DatanormParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->generator = new DatanormGenerator;
        $this->parser = new DatanormParser;
    }

    private function catalog(DatanormVersion $version): DatanormCatalog {
        $catalog = new DatanormCatalog(
            version: $version,
            dataMark: DatanormDataMark::Articles,
            creationDate: new DateTimeImmutable('2026-08-16'),
            currency: CurrencyCode::Euro,
            description: 'Testkatalog',
            copyright: 'Copyright Test',
            creatorShortName: 'TESTCO',
            creatorName: 'Test GmbH',
            creatorStreet: 'Musterweg 1',
            creatorCountry: 'D',
            creatorZip: '12345',
            creatorCity: 'Musterstadt',
            infoText: 'Testkatalog'
        );

        $longText = new DatanormTextBlock('LTX1', DatanormTextBlock::USAGE_LONGTEXT);
        $longText->addLine(1, 'Robustes Kupferrohr nach DIN EN 1057');
        $longText->addLine(2, 'fuer Trinkwasserinstallation');
        $catalog->addTextBlock($longText);

        $article = new DatanormArticle(
            articleNumber: 'ROHR-15',
            shortDescription1: 'Kupferrohr 15x1',
            shortDescription2: 'halbhart',
            unit: 'MTR',
            priceIndicator: DatanormPriceIndicator::ListPrice,
            priceUnitAmount: 100,
            price: Money::ofMinor(18950, CurrencyCode::Euro, 2),
            discountGroup: 'R010',
            mainProductGroup: 'SHK',
            productGroup: 'ROHRE'
        );
        $article->setMatchcode('CU15');
        $article->setEan('4012345678901');
        $article->setMinPackagingAmount(5);
        $article->setTextFlag(1);
        $article->setLongTextNumber('LTX1');
        $article->setCostIndicator('90');
        $catalog->addArticle($article);

        $catalog->addArticleChange(DatanormArticleChange::delete('ALT-99', 'NACHF-1', new DateTimeImmutable('2026-12-31')));
        $catalog->addArticleChange(DatanormArticleChange::renumber('OLD-1', 'NEW-1'));

        return $catalog;
    }

    public function test_v5_article_file_structure_and_line_count(): void {
        $output = $this->generator->generateArticleFile($this->catalog(DatanormVersion::V5));

        $lines = explode("\r\n", rtrim($output));
        self::assertStringStartsWith('V;050;A;20260816;EUR;Testkatalog;', $lines[0]);
        self::assertStringEndsWith(';', $lines[0]);
        self::assertSame('T;N;LTX1;1;01;Robustes Kupferrohr nach DIN EN 1057;', $lines[1]);
        self::assertStringStartsWith('A;N;ROHR-15;Kupferrohr 15x1;halbhart;MTR;1;100;18950;R010;SHK;ROHRE;CU15;', $lines[3]);
        self::assertSame('B;L;ALT-99;NACHF-1;20261231;', $lines[4]);
        self::assertSame('B;A;OLD-1;NEW-1;', $lines[5]);
        // E-record declares all lines including V and E.
        self::assertSame(sprintf('E;%d;', count($lines)), $lines[count($lines) - 1]);
    }

    public function test_v4_header_is_fixed_width(): void {
        $output = $this->generator->generateArticleFile($this->catalog(DatanormVersion::V4), DatanormVersion::V4);

        $lines = explode("\r\n", rtrim($output));
        self::assertSame(128, strlen($lines[0]));
        self::assertStringStartsWith('V 160826Testkatalog', $lines[0]);
        self::assertSame('04EUR', substr($lines[0], 123, 5));
    }

    public function test_v4_article_becomes_a_and_b_record_with_coded_price_unit(): void {
        $output = $this->generator->generateArticleFile($this->catalog(DatanormVersion::V4), DatanormVersion::V4);

        // Price unit amount 100 must be coded as "2", the unit mapped to free text.
        self::assertStringContainsString('A;N;ROHR-15;10;Kupferrohr 15x1;halbhart;1;2;m;18950;R010;SHK;LTX1;', $output);
        self::assertStringContainsString('B;N;ROHR-15;CU15;;;0;0;0;4012345678901;;ROHRE;90;5;', $output);
        // DATANORM 4 has no E-footer.
        self::assertStringNotContainsString("\r\nE;", "\r\n" . substr($output, (int) strrpos($output, "\r\nA;")));
    }

    public function test_v4_rejects_unsupported_price_unit_amounts(): void {
        $catalog = new DatanormCatalog(
            version: DatanormVersion::V4,
            dataMark: null,
            creationDate: new DateTimeImmutable('2026-08-16'),
            currency: CurrencyCode::Euro
        );
        $catalog->addArticle(new DatanormArticle(
            articleNumber: 'SIX-PACK',
            shortDescription1: 'Sechserpack',
            priceUnitAmount: 6,
            price: Money::ofMinor(1200, CurrencyCode::Euro, 2)
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->generator->generateArticleFile($catalog);
    }

    public function test_v5_round_trip_preserves_article_data(): void {
        $source = $this->catalog(DatanormVersion::V5);
        $parsed = $this->parser->parse($this->generator->generateArticleFile($source));

        self::assertSame([], $parsed->getWarnings());
        self::assertCount(1, $parsed->getArticles());
        $article = $parsed->getArticles()[0];
        self::assertSame('ROHR-15', $article->getArticleNumber());
        self::assertSame('Kupferrohr 15x1 halbhart', $article->getName());
        self::assertSame(100, $article->getPriceUnitAmount());
        self::assertSame('189.50', $article->getPrice()?->getAmount());
        self::assertSame('4012345678901', $article->getEan());
        self::assertSame(5, $article->getMinPackagingAmount());
        self::assertStringContainsString('DIN EN 1057', (string) $article->getLongText());

        $changes = $parsed->getArticleChanges();
        self::assertCount(2, $changes);
        self::assertSame('2026-12-31', $changes[0]->getExpirationDate()?->format('Y-m-d'));
        self::assertSame('NEW-1', $changes[1]->getNewArticleNumber());
    }

    public function test_z_and_c_records_round_trip_both_versions(): void {
        foreach ([DatanormVersion::V5, DatanormVersion::V4] as $version) {
            $source = $this->catalog($version);
            $article = $source->getArticles()[0];
            $article->addScalePrice(new DatanormScalePrice(
                articleNumber: 'ROHR-15',
                indicator: DatanormScalePrice::INDICATOR_SCALE_PRICE,
                amount: Money::ofMinor(17995, CurrencyCode::Euro, 2),
                percent: null,
                isDiscount: false,
                priceIndicator: DatanormPriceIndicator::ListPrice,
                basis: DatanormScalePrice::BASIS_QUANTITY,
                from: '100',
                to: '499',
                description: 'Staffel ab 100 m'
            ));
            $article->addScalePrice(new DatanormScalePrice(
                articleNumber: 'ROHR-15',
                indicator: DatanormScalePrice::INDICATOR_PERCENT,
                amount: null,
                percent: 5.0,
                isDiscount: true,
                basis: DatanormScalePrice::BASIS_QUANTITY,
                from: '500',
                to: '',
                description: 'Mengenrabatt ab 500 m'
            ));
            $article->addRawMaterialSurcharge(new DatanormRawMaterialSurcharge(
                articleNumber: 'ROHR-15',
                rawMaterial: 'CU',
                method: DatanormRawMaterialSurcharge::METHOD_INTERNATIONAL,
                isDiscount: false,
                isPercent: true,
                percent: 2.0,
                fromDayPrice: Money::of('150', CurrencyCode::Euro, 2),
                toDayPrice: Money::of('200', CurrencyCode::Euro, 2),
                dayPriceFactor: 0.01
            ));
            $article->addRawMaterialSurcharge(new DatanormRawMaterialSurcharge(
                articleNumber: 'ROHR-15',
                rawMaterial: 'CU',
                method: DatanormRawMaterialSurcharge::METHOD_GERMAN,
                includedBasePrice: Money::of('150', CurrencyCode::Euro, 2),
                baseFactor: 0.01,
                weight: 4.30,
                weightFactor: 0.01,
                priceIndicator: DatanormPriceIndicator::NetPrice,
                priceUnitAmount: 1
            ));
            $article->addWorkTime(new DatanormWorkTime('ROHR-15', DatanormWorkTime::PURPOSE_INSTALLATION, 30.0));

            $parsed = $this->parser->parse($this->generator->generateArticleFile($source, $version));
            self::assertSame([], $parsed->getWarnings(), $version->name);
            $roundTripped = $parsed->getArticles()[0];

            $scales = $roundTripped->getScalePrices();
            self::assertCount(2, $scales, $version->name);
            self::assertSame(DatanormScalePrice::INDICATOR_SCALE_PRICE, $scales[0]->getIndicator(), $version->name);
            self::assertSame('179.95', $scales[0]->getAmount()?->getAmount(), $version->name);
            self::assertSame(DatanormScalePrice::BASIS_QUANTITY, $scales[0]->getBasis(), $version->name);
            self::assertSame('100', $scales[0]->getFrom(), $version->name);
            self::assertSame('499', $scales[0]->getTo(), $version->name);
            self::assertSame('Staffel ab 100 m', $scales[0]->getDescription(), $version->name);
            self::assertSame(DatanormScalePrice::INDICATOR_PERCENT, $scales[1]->getIndicator(), $version->name);
            self::assertSame(5.0, $scales[1]->getPercent(), $version->name);
            self::assertTrue($scales[1]->isDiscount(), $version->name);

            $surcharges = $roundTripped->getRawMaterialSurcharges();
            self::assertCount(2, $surcharges, $version->name);
            self::assertSame(DatanormRawMaterialSurcharge::METHOD_INTERNATIONAL, $surcharges[0]->getMethod(), $version->name);
            self::assertSame(2.0, $surcharges[0]->getPercent(), $version->name);
            self::assertSame('150.00', $surcharges[0]->getFromDayPrice()?->getAmount(), $version->name);
            self::assertSame(0.01, $surcharges[0]->getDayPriceFactor(), $version->name);
            self::assertTrue($surcharges[0]->appliesToDayPrice(Money::of('1.75', CurrencyCode::Euro, 2)), $version->name);
            self::assertSame(DatanormRawMaterialSurcharge::METHOD_GERMAN, $surcharges[1]->getMethod(), $version->name);
            // DEL 2,00 €/kg: (2,00 − 1,50) × 0,043 kg = 0,0215 €/Einheit.
            self::assertSame('0.0215', $surcharges[1]->germanSurchargePerPriceUnit(Money::of('2', CurrencyCode::Euro, 2))?->getAmount(), $version->name);

            $workTimes = $roundTripped->getWorkTimes();
            self::assertCount(1, $workTimes, $version->name);
            self::assertSame(DatanormWorkTime::PURPOSE_INSTALLATION, $workTimes[0]->getPurpose(), $version->name);
            self::assertSame(30.0, $workTimes[0]->getMinutes(), $version->name);
        }
    }

    public function test_v4_round_trip_preserves_price_semantics(): void {
        $source = $this->catalog(DatanormVersion::V4);
        $parsed = $this->parser->parse($this->generator->generateArticleFile($source, DatanormVersion::V4));

        $article = $parsed->getArticles()[0];
        self::assertSame(100, $article->getPriceUnitAmount());
        self::assertSame('189.50', $article->getPrice()?->getAmount());
        self::assertSame('4012345678901', $article->getEan());
        self::assertSame('ROHRE', $article->getProductGroup());
        self::assertSame('CU15', $article->getMatchcode());
    }

    public function test_product_and_discount_group_files_round_trip(): void {
        foreach ([DatanormVersion::V4, DatanormVersion::V5] as $version) {
            $catalog = new DatanormCatalog(
                version: $version,
                dataMark: DatanormDataMark::ProductGroups,
                creationDate: new DateTimeImmutable('2026-08-16'),
                currency: CurrencyCode::Euro,
                infoText: 'Gruppen'
            );
            $catalog->addProductGroup(new DatanormProductGroup('SHK', null, 'Sanitaer Heizung Klima'));
            $catalog->addProductGroup(new DatanormProductGroup('SHK', 'ROHRE', 'Rohre und Fittings'));
            $catalog->addDiscountGroup(new DatanormDiscountGroup('R010', DatanormDiscountKind::Discount, 30.0, 'Rohre'));
            $catalog->addDiscountGroup(new DatanormDiscountGroup('R020', DatanormDiscountKind::Factor, 0.9, 'Ventile'));

            $groups = $this->parser->parse($this->generator->generateProductGroupFile($catalog));
            self::assertCount(2, $groups->getProductGroups(), $version->name);
            self::assertSame('Rohre und Fittings', $groups->resolveProductGroupLabel('SHK', 'ROHRE'), $version->name);

            $discounts = $this->parser->parse($this->generator->generateDiscountGroupFile($catalog));
            self::assertSame(30.0, $discounts->getDiscountGroup('R010')?->getValue(), $version->name);
            self::assertSame(0.9, $discounts->getDiscountGroup('R020')?->getValue(), $version->name);
        }
    }

    public function test_price_file_round_trip_both_versions(): void {
        foreach ([DatanormVersion::V4, DatanormVersion::V5] as $version) {
            $catalog = new DatanormCatalog(
                version: $version,
                dataMark: DatanormDataMark::PriceChanges,
                creationDate: new DateTimeImmutable('2026-08-16'),
                currency: CurrencyCode::Euro,
                infoText: 'Preise'
            );
            $catalog->setCustomer(new DatanormCustomer('KD-100077', 'Muster Kunde GmbH'));
            $catalog->addPriceChange(new DatanormPriceChange(
                articleNumber: 'ROHR-15',
                priceIndicator: DatanormPriceIndicator::ListPrice,
                price: Money::ofMinor(19950, CurrencyCode::Euro, 2),
                priceUnitAmount: $version === DatanormVersion::V5 ? 100 : null,
                discountGroup: 'R010'
            ));
            $catalog->addPriceChange(new DatanormPriceChange(
                articleNumber: 'VENTIL-1',
                priceIndicator: DatanormPriceIndicator::NetPrice,
                price: Money::ofMinor(4590, CurrencyCode::Euro, 2),
                priceUnitAmount: $version === DatanormVersion::V5 ? 1 : null,
                discounts: [new DatanormDiscount(DatanormDiscountKind::Discount, 5.0)]
            ));

            $parsed = $this->parser->parse($this->generator->generatePriceFile($catalog));

            self::assertSame('KD-100077', $parsed->getCustomer()?->getCustomerNumber(), $version->name);
            self::assertCount(2, $parsed->getPriceChanges(), $version->name);
            self::assertSame('199.50', $parsed->getPriceChanges()[0]->getPrice()?->getAmount(), $version->name);
            self::assertSame('R010', $parsed->getPriceChanges()[0]->getDiscountGroup(), $version->name);
            self::assertSame(5.0, $parsed->getPriceChanges()[1]->getDiscounts()[0]->getValue(), $version->name);
        }
    }

    public function test_output_is_cp850_with_sanitized_characters(): void {
        $catalog = new DatanormCatalog(
            version: DatanormVersion::V5,
            dataMark: DatanormDataMark::Articles,
            creationDate: new DateTimeImmutable('2026-08-16'),
            currency: CurrencyCode::Euro,
            description: 'Größen-Katalog'
        );
        $catalog->addArticle(new DatanormArticle(
            articleNumber: 'X-1',
            shortDescription1: 'Rohr; 15×1 – 2 m für 5 €',
            priceIndicator: DatanormPriceIndicator::NetPrice,
            price: Money::ofMinor(500, CurrencyCode::Euro, 2)
        ));

        $output = $this->generator->generateArticleFile($catalog);

        // The field-internal semicolon must be replaced, dash and euro transliterated.
        $expectedName = iconv('UTF-8', 'CP850', 'Rohr, 15×1 - 2 m für 5 EUR');
        $expectedDescription = iconv('UTF-8', 'CP850', 'Größen-Katalog');
        self::assertNotFalse($expectedName);
        self::assertNotFalse($expectedDescription);
        self::assertStringContainsString($expectedName, $output);
        // Umlauts survive as CP850 bytes.
        self::assertStringContainsString($expectedDescription, $output);

        // Round-trip: parsing the CP850 output restores the UTF-8 content.
        $parsed = $this->parser->parse($output);
        self::assertSame('Größen-Katalog', $parsed->getDescription());
        self::assertStringContainsString('für 5 EUR', $parsed->getArticles()[0]->getName());
    }
}
