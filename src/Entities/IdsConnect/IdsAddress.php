<?php
/*
 * Created on   : Fri Oct 02 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : IdsAddress.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\IdsConnect;

/**
 * Adresse im IDS-Warenkorb (`Address` unter `SupplierInfo`, `CustomerInfo`
 * und `DeliveryPlaceInfo`). Alle Felder sind optional.
 */
final class IdsAddress {
    public function __construct(
        private readonly ?string $name1 = null,
        private readonly ?string $name2 = null,
        private readonly ?string $name3 = null,
        private readonly ?string $name4 = null,
        private readonly ?string $street = null,
        private readonly ?string $postalCode = null,
        private readonly ?string $city = null,
        private readonly ?string $country = null,
        private readonly ?string $iln = null,
        private readonly ?string $contact = null,
        private readonly ?string $phone = null,
        private readonly ?string $fax = null,
        private readonly ?string $email = null,
    ) {}

    public function getName1(): ?string {
        return $this->name1;
    }

    public function getName2(): ?string {
        return $this->name2;
    }

    public function getName3(): ?string {
        return $this->name3;
    }

    public function getName4(): ?string {
        return $this->name4;
    }

    public function getStreet(): ?string {
        return $this->street;
    }

    public function getPostalCode(): ?string {
        return $this->postalCode;
    }

    public function getCity(): ?string {
        return $this->city;
    }

    public function getCountry(): ?string {
        return $this->country;
    }

    /** Globale Lokationsnummer (ILN/GLN). */
    public function getIln(): ?string {
        return $this->iln;
    }

    public function getContact(): ?string {
        return $this->contact;
    }

    public function getPhone(): ?string {
        return $this->phone;
    }

    public function getFax(): ?string {
        return $this->fax;
    }

    public function getEmail(): ?string {
        return $this->email;
    }

    /** Name1 bis Name4, leere ausgelassen, mit Leerzeichen verbunden. */
    public function getName(): ?string {
        $name = trim(implode(' ', array_filter([$this->name1, $this->name2, $this->name3, $this->name4], static fn (?string $part): bool => $part !== null && trim($part) !== '')));

        return $name === '' ? null : $name;
    }
}
