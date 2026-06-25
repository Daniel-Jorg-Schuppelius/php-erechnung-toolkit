<?php
/*
 * Created on   : Tue Jun 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : XRechnungConformanceTest.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace Tests\Generators;

use DateTimeImmutable;
use ERechnungToolkit\Builders\ERechnungDocumentBuilder;
use ERechnungToolkit\Enums\PaymentMeansCode;
use ERechnungToolkit\Generators\ERechnungGenerator;
use ERechnungToolkit\Validators\KositValidator;
use Tests\Contracts\BaseTestCase;

/**
 * Konformitäts-Roundtrip: eine vollständig aufgebaute XRechnung muss als UBL
 * generiert vom offiziellen KoSIT-Validator akzeptiert werden.
 */
class XRechnungConformanceTest extends BaseTestCase {
    private KositValidator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new KositValidator;
    }

    public function test_generated_ubl_xrechnung_is_kosit_valid(): void {
        if (!$this->validator->isAvailable()) {
            $this->markTestSkipped('KoSIT-Validator nicht verfügbar (Java-Laufzeit fehlt).');
        }

        $leitwegId = '04011000-12345-67';
        $document = ERechnungDocumentBuilder::xrechnung('XR-2026-0815', $leitwegId)
            ->withIssueDate(new DateTimeImmutable('2026-01-15'))
            ->withDueDate(new DateTimeImmutable('2026-02-15'))
            ->withSeller('Verkäufer GmbH', 'DE123456789')
            ->withSellerAddress('Verkäuferstraße 1', '10115', 'Berlin')
            ->withSellerEndpoint('seller@example.com', 'EM')
            ->withSellerContact('Max Müller', '+49 30 123456', 'kontakt@verkaeufer.de')
            ->withSellerBankAccount('DE89370400440532013000', 'COBADEFFXXX')
            ->withBuyer('Öffentliche Verwaltung')
            ->withBuyerAddress('Amtsweg 1', '80333', 'München')
            ->withBuyerLeitwegId($leitwegId)
            ->withPaymentMeans(PaymentMeansCode::SEPA_CREDIT_TRANSFER)
            ->withPaymentTermsNet30()
            ->addLine('Dienstleistung', 1, 1000.00)
            ->build();

        $ubl = (new ERechnungGenerator)->generateUbl($document);
        $result = $this->validator->validate($ubl);

        $this->assertTrue(
            $result->isValid(),
            'Generierte XRechnung ist nicht valide: ' . implode('; ', array_map(
                static fn ($e) => $e->getCode() . ' ' . $e->getText(),
                $result->getErrors()
            ))
        );
        $this->assertTrue($result->isAccepted());
        $this->assertStringContainsString('XRechnung', (string) $result->getScenarioName());
    }
}
