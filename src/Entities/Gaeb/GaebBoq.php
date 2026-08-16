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
     */
    public function __construct(
        private readonly ?string $version = null,
        private readonly ?GaebPhase $phase = null,
        private readonly ?string $phaseCode = null,
        private readonly ?string $projectName = null,
        private readonly ?string $externalId = null,
        private readonly array $sections = [],
        private readonly array $items = [],
        private readonly array $upComponents = []
    ) {}

    /** GAEB DA XML version, e.g. "3.3". */
    public function getVersion(): ?string {
        return $this->version;
    }

    /** Exchange phase, null for phases outside the 80 range (e.g. X31). */
    public function getPhase(): ?GaebPhase {
        return $this->phase;
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
