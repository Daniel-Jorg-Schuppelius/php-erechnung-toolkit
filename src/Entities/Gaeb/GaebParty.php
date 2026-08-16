<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebParty.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Gaeb;

/**
 * A party of the award, currently the bidder or contractor (GAEB `CTR`).
 *
 * The schema makes the contractor mandatory in the bid (X84), the award (X86)
 * and its confirmation (X87), and requires a complete postal address: name,
 * street, postal code and city.
 */
final class GaebParty {
    public function __construct(
        private readonly string $name,
        private readonly string $street,
        private readonly string $postalCode,
        private readonly string $city,
        private readonly bool $withinEea = true
    ) {}

    public function getName(): string {
        return $this->name;
    }

    public function getStreet(): string {
        return $this->street;
    }

    public function getPostalCode(): string {
        return $this->postalCode;
    }

    public function getCity(): string {
        return $this->city;
    }

    /** GAEB `CntryType`: inside the European Economic Area or outside. */
    public function isWithinEea(): bool {
        return $this->withinEea;
    }
}
