<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormParserTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Parsers;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Entities\Datanorm\DatanormScalePrice;
use ERechnungToolkit\Enums\{DatanormDataMark, DatanormDiscountKind, DatanormPriceIndicator, DatanormVersion};
use ERechnungToolkit\Parsers\DatanormParser;
use RuntimeException;
use Tests\Contracts\BaseTestCase;

/**
 * Tests for the DATANORM 4/5 parser. All fixtures are self-authored after the
 * public record structure — no licensed sample data.
 */
class DatanormParserTest extends BaseTestCase {
    private DatanormParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new DatanormParser;
    }

    private function v4Header(string $info = 'Testkatalog'): string {
        return 'V ' . '160826' . str_pad($info, 40) . str_pad('Zeile 2', 40) . str_pad('Zeile 3', 35) . '04EUR';
    }

    private function v4ArticleFile(): string {
        return implode("\r\n", [
            $this->v4Header(),
            'T;N;LTX1;;1;;Robustes Kupferrohr nach DIN EN 1057;2;;fuer Trinkwasserinstallation;',
            'E;N;INS1;;1;;Masse: $$$$ x $$$$ mm;;;;',
            'A;N;ROHR-15;30;Kupferrohr 15x1;halbhart;1;2;m;18950;R010;SHK;LTX1;',
            'B;N;ROHR-15;CU15;ALT-15;12;0;0;0;4012345678901; ;ROHRE;90;5; ; ;',
            'D;N;ROHR-15;1;E;INS1;$15$1$;2;F;;Haertegrad R250;',
            'A;N;VENTIL-1;00;Absperrventil 1/2 Zoll;;2;0;Stck;4990;;SHK;;',
            'B;N;VENTIL-1;VENT;;;0;0;0;4098765432109; ;VENTILE;90;1; ; ;',
            'A;L;ALT-99;;;;;;;;;;;',
            'A;X;OLD-1;;NEW-1;;;;;;;;;',
        ]) . "\r\n";
    }

    public function test_v4_article_file_is_parsed_with_decoded_price_units(): void {
        $catalog = $this->parser->parse($this->v4ArticleFile(), 'UTF-8');

        self::assertSame(DatanormVersion::V4, $catalog->getVersion());
        self::assertSame(CurrencyCode::Euro, $catalog->getCurrency());
        self::assertSame('2026-08-16', $catalog->getCreationDate()?->format('Y-m-d'));
        self::assertCount(2, $catalog->getArticles());

        $rohr = $catalog->getArticles()[0];
        self::assertSame('ROHR-15', $rohr->getArticleNumber());
        self::assertSame('Kupferrohr 15x1 halbhart', $rohr->getName());
        self::assertSame(DatanormPriceIndicator::ListPrice, $rohr->getPriceIndicator());
        // Price unit CODE 2 → per 100 units, never a divisor of 2.
        self::assertSame(100, $rohr->getPriceUnitAmount());
        self::assertSame('189.50', $rohr->getPrice()?->getAmount());
        self::assertSame('R010', $rohr->getDiscountGroup());
        self::assertSame('SHK', $rohr->getMainProductGroup());
        // Merged from the B-record:
        self::assertSame('CU15', $rohr->getMatchcode());
        self::assertSame('4012345678901', $rohr->getEan());
        self::assertSame('ROHRE', $rohr->getProductGroup());
        self::assertSame(5, $rohr->getMinPackagingAmount());

        $ventil = $catalog->getArticles()[1];
        self::assertSame(DatanormPriceIndicator::NetPrice, $ventil->getPriceIndicator());
        self::assertSame(1, $ventil->getPriceUnitAmount());
        self::assertSame('49.90', $ventil->getPrice()?->getAmount());
    }

    public function test_v4_long_and_insert_texts_are_resolved(): void {
        $catalog = $this->parser->parse($this->v4ArticleFile(), 'UTF-8');

        $rohr = $catalog->getArticles()[0];
        $text = $rohr->getLongText();
        self::assertNotNull($text);
        self::assertStringContainsString('Robustes Kupferrohr nach DIN EN 1057', $text);
        self::assertStringContainsString('fuer Trinkwasserinstallation', $text);
        self::assertStringContainsString('Masse: 15   x 1    mm', $text);
        self::assertStringContainsString('Haertegrad R250', $text);
    }

    public function test_v4_deletion_and_renumbering_become_article_changes(): void {
        $catalog = $this->parser->parse($this->v4ArticleFile(), 'UTF-8');

        $changes = $catalog->getArticleChanges();
        self::assertCount(2, $changes);
        self::assertTrue($changes[0]->isDelete());
        self::assertSame('ALT-99', $changes[0]->getArticleNumber());
        self::assertTrue($changes[1]->isRenumber());
        self::assertSame('OLD-1', $changes[1]->getArticleNumber());
        self::assertSame('NEW-1', $changes[1]->getNewArticleNumber());
    }

    public function test_v4_invalid_price_unit_code_drops_price_with_warning(): void {
        $content = implode("\r\n", [
            $this->v4Header(),
            'A;N;BAD-1;00;Fehlerhafter Artikel;;1;7;Stck;1000;;SHK;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertCount(1, $catalog->getArticles());
        self::assertNull($catalog->getArticles()[0]->getPrice());
        self::assertNotEmpty($catalog->getWarnings());
        self::assertStringContainsString('BAD-1', $catalog->getWarnings()[0]);
    }

    public function test_v4_discount_group_file(): void {
        $content = implode("\r\n", [
            $this->v4Header('Rabattgruppen'),
            'R;;R010;1;3000;Rohre (30% Rabatt);',
            'R;;R020;2;900;Ventile (Faktor 0,9);',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        $rohre = $catalog->getDiscountGroup('R010');
        self::assertNotNull($rohre);
        self::assertSame(DatanormDiscountKind::Discount, $rohre->getKind());
        self::assertSame(30.0, $rohre->getValue());

        $ventile = $catalog->getDiscountGroup('R020');
        self::assertNotNull($ventile);
        self::assertSame(DatanormDiscountKind::Factor, $ventile->getKind());
        self::assertSame(0.9, $ventile->getValue());
    }

    public function test_v4_product_group_file(): void {
        $content = implode("\r\n", [
            $this->v4Header('Warengruppen'),
            'S;;SHK;Sanitaer Heizung Klima;;;',
            'S;;SHK;;ROHRE;Rohre und Fittings;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertCount(2, $catalog->getProductGroups());
        self::assertTrue($catalog->getProductGroups()[0]->isMainGroup());
        self::assertSame('Sanitaer Heizung Klima', $catalog->getProductGroups()[0]->getLabel());
        self::assertSame('Rohre und Fittings', $catalog->resolveProductGroupLabel('SHK', 'ROHRE'));
        self::assertSame('Sanitaer Heizung Klima', $catalog->resolveProductGroupLabel('SHK', 'UNBEKANNT'));
    }

    public function test_v4_price_file_with_three_article_blocks(): void {
        $content = implode("\r\n", [
            $this->v4Header('Preisdatei'),
            'K;;KD-100077; ;',
            'P;A;ROHR-15;1;19950;0;R010;;;;;VENTIL-1;2;4590;1;500;2;950;;;;;;;;;;;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertSame('KD-100077', $catalog->getCustomer()?->getCustomerNumber());
        $changes = $catalog->getPriceChanges();
        self::assertCount(2, $changes);

        self::assertSame('ROHR-15', $changes[0]->getArticleNumber());
        self::assertSame(DatanormPriceIndicator::ListPrice, $changes[0]->getPriceIndicator());
        self::assertSame('199.50', $changes[0]->getPrice()?->getAmount());
        self::assertSame('R010', $changes[0]->getDiscountGroup());
        // DATANORM 4 P-records carry no price unit — the article master's applies.
        self::assertNull($changes[0]->getPriceUnitAmount());

        self::assertSame('VENTIL-1', $changes[1]->getArticleNumber());
        self::assertSame('45.90', $changes[1]->getPrice()?->getAmount());
        self::assertCount(2, $changes[1]->getDiscounts());
        self::assertSame(DatanormDiscountKind::Discount, $changes[1]->getDiscounts()[0]->getKind());
        self::assertSame(5.0, $changes[1]->getDiscounts()[0]->getValue());
        self::assertSame(DatanormDiscountKind::Factor, $changes[1]->getDiscounts()[1]->getKind());
        self::assertSame(0.95, $changes[1]->getDiscounts()[1]->getValue());
    }

    private function v5ArticleFile(): string {
        return implode("\r\n", [
            'V;050;A;20260816;EUR;Testkatalog;Copyright Test;TESTCO;Test GmbH;;;Musterweg 1;D;12345;Musterstadt;',
            'T;N;LTX1;1;01;Robustes Kupferrohr nach DIN EN 1057;',
            'T;N;LTX1;1;02;fuer Trinkwasserinstallation;',
            'T;N;INS1;2;01;Masse: $$$$ x $$$$ mm;',
            'G;N;GRP1;01;1A;rohr15;jpg;Produktfoto;',
            'A;N;ROHR-15;Kupferrohr 15x1;halbhart;MTR;1;100;18950;R010;SHK;ROHRE;CU15;;ALT-15;;HST-1;Typ 15;4012345678901;GRP1;5;12;3;LTX1;90;1;;;1;',
            'D;N;ROHR-15;01;E;$15$1$;INS1;',
            'D;N;ROHR-15;02;F;Haertegrad R250;;',
            'Z;N;ROHR-15;01;1;;Staffel ab 500 m;1;+;1;100;17950;;1;500;99999;',
            'B;A;OLD-1;NEW-1;',
            'B;L;ALT-99;NACHF-1;20261231;',
            'E;12;Testdatei Ende;',
        ]) . "\r\n";
    }

    public function test_v5_article_file_is_parsed_with_direct_price_unit(): void {
        $catalog = $this->parser->parse($this->v5ArticleFile(), 'UTF-8');

        self::assertSame(DatanormVersion::V5, $catalog->getVersion());
        self::assertSame(DatanormDataMark::Articles, $catalog->getDataMark());
        self::assertSame('TESTCO', $catalog->getCreatorShortName());
        self::assertSame('Musterstadt', $catalog->getCreatorCity());
        self::assertSame(12, $catalog->getDeclaredLineCount());
        self::assertSame([], $catalog->getWarnings());

        self::assertCount(1, $catalog->getArticles());
        $rohr = $catalog->getArticles()[0];
        self::assertSame('Kupferrohr 15x1 halbhart', $rohr->getName());
        self::assertSame('MTR', $rohr->getUnit());
        self::assertSame(100, $rohr->getPriceUnitAmount());
        self::assertSame('189.50', $rohr->getPrice()?->getAmount());
        self::assertSame('R010', $rohr->getDiscountGroup());
        self::assertSame('SHK', $rohr->getMainProductGroup());
        self::assertSame('ROHRE', $rohr->getProductGroup());
        self::assertSame('CU15', $rohr->getMatchcode());
        self::assertSame('ALT-15', $rohr->getAltArticleNumber());
        self::assertSame('HST-1', $rohr->getManufacturerNumber());
        self::assertSame('Typ 15', $rohr->getManufacturerType());
        self::assertSame('4012345678901', $rohr->getEan());
        self::assertSame('GRP1', $rohr->getGraphicNumber());
        self::assertSame(5, $rohr->getMinPackagingAmount());
        self::assertSame('12', $rohr->getCataloguePage());
        self::assertSame(3, $rohr->getTextFlag());
        self::assertSame('90', $rohr->getCostIndicator());
        self::assertSame('1', $rohr->getVatIndicator());
    }

    public function test_v5_texts_scale_prices_changes_and_graphics(): void {
        $catalog = $this->parser->parse($this->v5ArticleFile(), 'UTF-8');

        $rohr = $catalog->getArticles()[0];
        $text = $rohr->getLongText();
        self::assertNotNull($text);
        self::assertStringContainsString('DIN EN 1057', $text);
        self::assertStringContainsString('Masse: 15   x 1    mm', $text);
        self::assertStringContainsString('Haertegrad R250', $text);

        self::assertCount(1, $rohr->getScalePrices());
        $scale = $rohr->getScalePrices()[0];
        self::assertSame(DatanormScalePrice::INDICATOR_SCALE_PRICE, $scale->getIndicator());
        self::assertSame('179.50', $scale->getAmount()?->getAmount());
        self::assertSame(100, $scale->getPriceUnitAmount());
        self::assertSame(DatanormScalePrice::BASIS_QUANTITY, $scale->getBasis());
        self::assertSame('500', $scale->getFrom());

        $changes = $catalog->getArticleChanges();
        self::assertCount(2, $changes);
        self::assertTrue($changes[0]->isRenumber());
        self::assertSame('NEW-1', $changes[0]->getNewArticleNumber());
        self::assertTrue($changes[1]->isDelete());
        self::assertSame('NACHF-1', $changes[1]->getSuccessorArticleNumber());
        self::assertSame('2026-12-31', $changes[1]->getExpirationDate()?->format('Y-m-d'));

        self::assertCount(1, $catalog->getGraphicReferences());
        self::assertSame('rohr15', $catalog->getGraphicReferences()[0]->getFilename());
    }

    public function test_v5_price_file(): void {
        $content = implode("\r\n", [
            'V;050;P;20260816;EUR;Preisdatei;;TESTCO;;;;;;;;',
            'K;KD-100077;Muster Kunde GmbH;;;Musterweg 5;D;54321;Beispielstadt;',
            'P;ROHR-15;1;100;19950;R010;;;;;;;;',
            'P;VENTIL-1;2;1;4590;;1;500;2;950;;;20261001;',
            'E;5;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertSame(DatanormDataMark::PriceChanges, $catalog->getDataMark());
        self::assertSame('Muster Kunde GmbH', $catalog->getCustomer()?->getName());

        $changes = $catalog->getPriceChanges();
        self::assertCount(2, $changes);
        self::assertSame(100, $changes[0]->getPriceUnitAmount());
        self::assertSame('199.50', $changes[0]->getPrice()?->getAmount());
        self::assertSame('R010', $changes[0]->getDiscountGroup());
        self::assertSame('2026-10-01', $changes[1]->getValidFrom()?->format('Y-m-d'));
        self::assertCount(2, $changes[1]->getDiscounts());
    }

    public function test_v5_line_count_mismatch_is_warned(): void {
        $content = implode("\r\n", [
            'V;050;A;20260816;EUR;Test;;TESTCO;;;;;;;;',
            'A;N;X-1;Testartikel;;PCE;2;1;1000;;;;',
            'E;99;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertNotEmpty($catalog->getWarnings());
        self::assertStringContainsString('99', $catalog->getWarnings()[0]);
    }

    public function test_cp850_content_is_decoded(): void {
        $utf8 = implode("\r\n", [
            'V;050;A;20260816;EUR;Größenkatalog;;TESTCO;;;;;;;;',
            'A;N;Ü-1;Kupferrohr für Heißwasser;Größe 15;MTR;2;1;1990;;;;',
            'E;3;;',
        ]) . "\r\n";
        $cp850 = iconv('UTF-8', 'CP850', $utf8);
        self::assertNotFalse($cp850);

        $catalog = $this->parser->parse($cp850);

        self::assertSame('Größenkatalog', $catalog->getDescription());
        self::assertSame('Ü-1', $catalog->getArticles()[0]->getArticleNumber());
        self::assertSame('Kupferrohr für Heißwasser Größe 15', $catalog->getArticles()[0]->getName());
    }

    public function test_unknown_record_types_produce_warnings_not_failures(): void {
        $content = implode("\r\n", [
            'V;050;A;20260816;EUR;Test;;TESTCO;;;;;;;;',
            'C;N;WST1;X-1;1.0144;1100;1000;100;;',
            'J;N;SET-1;L;01;Zubehoer;Set;2;X-1;1000;',
            'Q;kaputt',
            'A;N;X-1;Testartikel;;PCE;2;1;1000;;;;',
            'E;6;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertCount(1, $catalog->getArticles());
        self::assertCount(3, $catalog->getWarnings());
    }

    public function test_non_datanorm_content_throws(): void {
        $this->expectException(RuntimeException::class);
        $this->parser->parse("KOP123\r\nPOA456\r\n", 'UTF-8');
    }

    public function test_packaging_amount_tracks_explicit_transfer(): void {
        $content = implode("\r\n", [
            'V;050;A;20260816;EUR;Test;;TESTCO;;;;;;;;',
            // Feld 21 (Mindestverpackungsmenge) übertragen:
            'A;N;VOLL-1;Mit Verpackung;;PCE;2;1;1000;;;;;;;;;;;;5;',
            // Änderungssatz endet vor Feld 21 — Verpackungsmenge unverändert:
            'A;A;DELTA-1;;;;2;1;2000;;;;',
            'E;4;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        [$full, $delta] = $catalog->getArticles();
        self::assertTrue($full->hasPackagingAmount());
        self::assertSame(5, $full->getMinPackagingAmount());
        self::assertFalse($delta->hasPackagingAmount());
        self::assertSame(1, $delta->getMinPackagingAmount());
    }

    public function test_v4_duplicate_article_record_is_skipped_with_warning(): void {
        $content = implode("\r\n", [
            $this->v4Header(),
            'A;N;DUP-1;00;Erster;;2;0;Stck;1000;;SHK;;',
            'A;N;DUP-1;00;Zweiter;;2;0;Stck;2000;;SHK;;',
        ]) . "\r\n";

        $catalog = $this->parser->parse($content, 'UTF-8');

        self::assertCount(1, $catalog->getArticles());
        self::assertSame('10.00', $catalog->getArticles()[0]->getPrice()?->getAmount());
        self::assertNotEmpty($catalog->getWarnings());
    }
}
