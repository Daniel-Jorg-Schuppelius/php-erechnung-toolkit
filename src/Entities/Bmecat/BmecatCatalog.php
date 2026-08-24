<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatCatalog.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Bmecat;

use CommonToolkit\Enums\CurrencyCode;
use DateTimeImmutable;
use ERechnungToolkit\Enums\BmecatVersion;

/**
 * Der geparste Inhalt eines BMEcat-Katalogs (1.2 oder 2005): Kopf aus
 * HEADER/CATALOG, Transaktionsart (T_NEW_CATALOG, T_UPDATE_PRODUCTS,
 * T_UPDATE_PRICES), Artikel und nicht-fatale Parser-Warnungen (z. B.
 * Artikel ohne Lieferanten-Artikelnummer).
 */
final class BmecatCatalog {
    /** @var list<BmecatArticle> */
    private array $articles = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private readonly BmecatVersion $version,
        private readonly ?string $transaction = null,
        private readonly ?string $catalogId = null,
        private readonly ?string $catalogVersion = null,
        private readonly ?string $catalogName = null,
        private readonly ?string $language = null,
        private readonly ?CurrencyCode $currency = null,
        private readonly ?string $supplierName = null,
        private readonly ?DateTimeImmutable $generationDate = null
    ) {}

    public function getVersion(): BmecatVersion {
        return $this->version;
    }

    /** Transaktionselement ("T_NEW_CATALOG", "T_UPDATE_PRODUCTS", "T_UPDATE_PRICES"), null wenn keins vorhanden. */
    public function getTransaction(): ?string {
        return $this->transaction;
    }

    public function getCatalogId(): ?string {
        return $this->catalogId;
    }

    public function getCatalogVersion(): ?string {
        return $this->catalogVersion;
    }

    public function getCatalogName(): ?string {
        return $this->catalogName;
    }

    /** Katalogsprache (HEADER/CATALOG/LANGUAGE, z. B. "deu"). */
    public function getLanguage(): ?string {
        return $this->language;
    }

    /** Katalogwährung — Fallback für Preise ohne eigenes PRICE_CURRENCY. */
    public function getCurrency(): ?CurrencyCode {
        return $this->currency;
    }

    /** Lieferantenname (SUPPLIER_NAME in 1.2, PARTIES/PARTY[supplier] in 2005). */
    public function getSupplierName(): ?string {
        return $this->supplierName;
    }

    /** Erstellungszeitpunkt (DATETIME type="generation_date"). */
    public function getGenerationDate(): ?DateTimeImmutable {
        return $this->generationDate;
    }

    public function addArticle(BmecatArticle $article): void {
        $this->articles[] = $article;
    }

    /** @return list<BmecatArticle> */
    public function getArticles(): array {
        return $this->articles;
    }

    public function addWarning(string $warning): void {
        $this->warnings[] = $warning;
    }

    /** @return list<string> nicht-fatale Parser-Warnungen */
    public function getWarnings(): array {
        return $this->warnings;
    }
}
