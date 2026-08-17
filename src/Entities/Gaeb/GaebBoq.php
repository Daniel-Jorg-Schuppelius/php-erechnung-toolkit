<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebBoq.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

use CommonToolkit\Enums\CurrencyCode;
use ERechnungToolkit\Enums\GaebPhase;

/**
 * Format neutral bill of quantity: the result of reading a GAEB file and the
 * input for writing one. Keeping it free of any storage concern is what allows
 * the same document to travel between the exchange phases and, later, between
 * the GAEB format families (D, P and XML).
 */
final class GaebBoq {
    /** Placeholder that marks a text complement inside a long text. */
    public const COMPLEMENT_MARKER = '[[TC:%s]]';

    /**
     * @param list<GaebSection>      $sections
     * @param list<GaebItem>         $items
     * @param list<GaebUpComponent>  $upComponents unit price shares required by the client
     * @param list<GaebCatalog>      $catalogs catalogues the assignments point to
     * @param list<GaebChangeOrder>  $changeOrders addendum heads of the document
     */
    public function __construct(
        private readonly ?string $version = null,
        private readonly ?string $phaseCode = null,
        private readonly ?string $projectName = null,
        private readonly ?string $externalId = null,
        private readonly array $sections = [],
        private readonly array $items = [],
        private readonly array $upComponents = [],
        private readonly ?GaebTotals $totals = null,
        private readonly CurrencyCode $currency = CurrencyCode::Euro,
        private readonly array $catalogs = [],
        private readonly array $changeOrders = []
    ) {}

    /** GAEB DA XML version, e.g. "3.3". */
    public function getVersion(): ?string {
        return $this->version;
    }

    /**
     * Exchange phase, derived from the code. Null means the code is unknown to
     * this version of the toolkit - the raw value stays available for the
     * message that says so.
     */
    public function getPhase(): ?GaebPhase {
        return GaebPhase::fromCode($this->phaseCode);
    }

    /** Raw DA code as found in the file, e.g. "31" or "84". */
    public function getPhaseCode(): ?string {
        return $this->phaseCode;
    }

    public function getProjectName(): ?string {
        return $this->projectName;
    }

    public function getExternalId(): ?string {
        return $this->externalId;
    }

    /** @return list<GaebSection> */
    public function getSections(): array {
        return $this->sections;
    }

    /** @return list<GaebItem> */
    public function getItems(): array {
        return $this->items;
    }

    /** @return list<GaebUpComponent> */
    public function getUpComponents(): array {
        return $this->upComponents;
    }

    /** Sums of the whole bill of quantity, including any discount. */
    /**
     * Currency of every amount in this document (GAEB `Cur`). It is mandatory in
     * the file, so it is never guessed here beyond the euro default.
     */
    public function getCurrency(): CurrencyCode {
        return $this->currency;
    }

    /**
     * Catalogues declared in the header (`Ctlg`); assignments reference them by
     * id. The type carries the edition, so a cost group is unambiguous.
     *
     * @return list<GaebCatalog>
     */
    public function getCatalogs(): array {
        return $this->catalogs;
    }

    /**
     * Addendum heads. The items carry only the number; who raised an addendum,
     * why and since when it is agreed stands here.
     *
     * @return list<GaebChangeOrder>
     */
    public function getChangeOrders(): array {
        return $this->changeOrders;
    }

    /** The head belonging to an addendum number, if the document names one. */
    public function getChangeOrder(string $number): ?GaebChangeOrder {
        foreach ($this->changeOrders as $order) {
            if ($order->getNumber() === $number) {
                return $order;
            }
        }

        return null;
    }

    public function getTotals(): ?GaebTotals {
        return $this->totals;
    }

    public function countSections(): int {
        return count($this->sections);
    }

    public function countItems(): int {
        return count($this->items);
    }

    /** Marker that stands for a text complement inside a long text. */
    public static function complementMarker(string $mark): string {
        return sprintf(self::COMPLEMENT_MARKER, $mark);
    }
}
