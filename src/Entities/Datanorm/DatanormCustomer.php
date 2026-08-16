<?php
/*
 * Created on   : Sat Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : DatanormCustomer.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities\Datanorm;

/**
 * DATANORM K-record (Kontrollsatz): the customer number the file is intended
 * for. Import interfaces should verify it against their own customer number
 * before processing customer-specific data (especially DATPREIS files).
 * DATANORM 5 additionally carries the customer address.
 */
final class DatanormCustomer {
    public function __construct(
        private readonly string $customerNumber,
        private readonly ?string $name = null,
        private readonly ?string $street = null,
        private readonly ?string $country = null,
        private readonly ?string $zip = null,
        private readonly ?string $city = null
    ) {}

    public function getCustomerNumber(): string {
        return $this->customerNumber;
    }

    public function getName(): ?string {
        return $this->name;
    }

    public function getStreet(): ?string {
        return $this->street;
    }

    public function getCountry(): ?string {
        return $this->country;
    }

    public function getZip(): ?string {
        return $this->zip;
    }

    public function getCity(): ?string {
        return $this->city;
    }
}
