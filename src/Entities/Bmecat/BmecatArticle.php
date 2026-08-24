<?php
/*
 * Created on   : Sun Aug 24 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BmecatArticle.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Bmecat;

use ERechnungToolkit\Enums\UnitCode;

/**
 * Ein BMEcat-Artikel: ARTICLE (1.2) bzw. PRODUCT (2005) mit Stammdaten,
 * Klassifikation (eCl@ss/ETIM über REFERENCE_FEATURE_*), Medienreferenzen und
 * Preisen inklusive Mengenstaffeln.
 *
 * Die Felder folgen dem gemeinsamen Kern beider Versionen; versionsabhängige
 * Elementnamen (SUPPLIER_AID/SUPPLIER_PID, MANUFACTURER_AID/MANUFACTURER_PID,
 * EAN/INTERNATIONAL_PID) sind bereits vom Parser zusammengeführt.
 */
final class BmecatArticle {
    /** MIME_PURPOSE-Werte, die als Produktbild gelten (Reihenfolge = Dokumentreihenfolge, kein Ranking). */
    public const IMAGE_PURPOSES = ['normal', 'thumbnail', 'detail'];

    /** MIME_PURPOSE-Werte, die als Datenblatt gelten. */
    public const DATASHEET_PURPOSES = ['data_sheet', 'safety_data_sheet', 'datasheet'];

    /** @var list<BmecatPrice> */
    private array $prices = [];

    /** @var list<BmecatMime> */
    private array $mimes = [];

    public function __construct(
        private readonly string $articleNumber,
        private readonly string $descriptionShort = '',
        private readonly ?string $descriptionLong = null,
        private readonly ?string $ean = null,
        private readonly ?string $manufacturerArticleNumber = null,
        private readonly ?string $manufacturerName = null,
        private readonly ?string $classificationSystem = null,
        private readonly ?string $classificationGroupId = null,
        private readonly ?string $orderUnit = null
    ) {}

    /** Lieferanten-Artikelnummer (SUPPLIER_AID bzw. SUPPLIER_PID). */
    public function getArticleNumber(): string {
        return $this->articleNumber;
    }

    public function getDescriptionShort(): string {
        return $this->descriptionShort;
    }

    public function getDescriptionLong(): ?string {
        return $this->descriptionLong;
    }

    /** Anzeigename: Kurzbeschreibung, sonst die Artikelnummer. */
    public function getName(): string {
        return $this->descriptionShort !== '' ? $this->descriptionShort : $this->articleNumber;
    }

    /** EAN/GTIN (EAN in 1.2, INTERNATIONAL_PID in 2005). */
    public function getEan(): ?string {
        return $this->ean;
    }

    /** Hersteller-Artikelnummer (MANUFACTURER_AID bzw. MANUFACTURER_PID). */
    public function getManufacturerArticleNumber(): ?string {
        return $this->manufacturerArticleNumber;
    }

    public function getManufacturerName(): ?string {
        return $this->manufacturerName;
    }

    /** Klassifikationssystem (REFERENCE_FEATURE_SYSTEM_NAME, z. B. "ECLASS-9.0"). */
    public function getClassificationSystem(): ?string {
        return $this->classificationSystem;
    }

    /** Warengruppen-/Klassencode (REFERENCE_FEATURE_GROUP_ID, z. B. "27-27-90-01"). */
    public function getClassificationGroupId(): ?string {
        return $this->classificationGroupId;
    }

    /** Bestelleinheit wie übertragen (ORDER_UNIT, z. B. "C62", "PCE", "MTR"). */
    public function getOrderUnit(): ?string {
        return $this->orderUnit;
    }

    /**
     * Bestelleinheit als UN/ECE-Rec-20-Code, über {@see UnitCode::fromText()}
     * aufgelöst (ISO-Code direkt, sonst Freitext wie "PCE" oder "Stk.").
     * Nicht Erkanntes liefert null statt eines geratenen Codes.
     *
     * @param  UnitCode  $pieceCode  Zielcode der Stück-Familie (Default `C62`).
     */
    public function getOrderUnitCode(UnitCode $pieceCode = UnitCode::PIECE): ?UnitCode {
        return UnitCode::fromText($this->orderUnit, $pieceCode);
    }

    public function addPrice(BmecatPrice $price): void {
        $this->prices[] = $price;
    }

    /** @return list<BmecatPrice> alle Preiselemente in Dokumentreihenfolge */
    public function getPrices(): array {
        return $this->prices;
    }

    /**
     * Basispreis: das erste Preiselement mit deutbarem Betrag (unabhängig von
     * der Staffel-Untergrenze — bei üblichen Katalogen steht der Basispreis
     * mit Untergrenze ≤ 1 an erster Stelle).
     */
    public function getPrice(): ?BmecatPrice {
        foreach ($this->prices as $price) {
            if ($price->getAmount() !== null) {
                return $price;
            }
        }

        return null;
    }

    /** @return list<BmecatPrice> nur echte Mengenstaffeln (Betrag vorhanden, Untergrenze > 1) */
    public function getScalePrices(): array {
        return array_values(array_filter($this->prices, static fn (BmecatPrice $price): bool => $price->isScalePrice()));
    }

    public function addMime(BmecatMime $mime): void {
        $this->mimes[] = $mime;
    }

    /** @return list<BmecatMime> alle Medienreferenzen in Dokumentreihenfolge */
    public function getMimes(): array {
        return $this->mimes;
    }

    /**
     * Erste Quelle (Dokumentreihenfolge), deren MIME_PURPOSE zu einem der
     * Zwecke passt.
     *
     * @param  list<string>  $purposes  Zwecke in Kleinschreibung.
     */
    public function findMimeSource(array $purposes): ?string {
        foreach ($this->mimes as $mime) {
            if ($mime->hasPurpose($purposes)) {
                return $mime->getSource();
            }
        }

        return null;
    }

    /** Produktbild-URL ({@see self::IMAGE_PURPOSES}). */
    public function getImageUrl(): ?string {
        return $this->findMimeSource(self::IMAGE_PURPOSES);
    }

    /** Datenblatt-URL ({@see self::DATASHEET_PURPOSES}). */
    public function getDatasheetUrl(): ?string {
        return $this->findMimeSource(self::DATASHEET_PURPOSES);
    }
}
