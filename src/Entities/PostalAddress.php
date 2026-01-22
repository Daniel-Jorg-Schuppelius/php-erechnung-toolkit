<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : PostalAddress.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Enums\CountryCode;

/**
 * Postal Address for E-Rechnung (EN 16931).
 * 
 * Represents a postal address for seller, buyer, or delivery party.
 * 
 * @package ERechnungToolkit\Entities
 */
final class PostalAddress {
    public function __construct(
        private ?string $streetName = null,
        private ?string $additionalStreetName = null,
        private ?string $buildingNumber = null,
        private ?string $postBox = null,
        private ?string $postalCode = null,
        private ?string $city = null,
        private ?string $countrySubdivision = null,
        private CountryCode|string|null $country = null
    ) {
        if (is_string($this->country)) {
            $this->country = CountryCode::tryFrom($this->country);
        }
    }

    public function getStreetName(): ?string {
        return $this->streetName;
    }

    public function getAdditionalStreetName(): ?string {
        return $this->additionalStreetName;
    }

    public function getBuildingNumber(): ?string {
        return $this->buildingNumber;
    }

    public function getPostBox(): ?string {
        return $this->postBox;
    }

    public function getPostalCode(): ?string {
        return $this->postalCode;
    }

    public function getCity(): ?string {
        return $this->city;
    }

    public function getCountrySubdivision(): ?string {
        return $this->countrySubdivision;
    }

    public function getCountry(): ?CountryCode {
        return $this->country;
    }

    public function getCountryCode(): ?string {
        return $this->country?->value;
    }

    /**
     * Returns the full address as a single line.
     */
    public function getOneLine(): string {
        $parts = array_filter([
            $this->streetName,
            $this->buildingNumber,
            $this->additionalStreetName,
            trim(($this->postalCode ?? '') . ' ' . ($this->city ?? '')),
            $this->country?->value,
        ]);
        return implode(', ', $parts);
    }

    /**
     * Returns the address as formatted lines.
     * 
     * @return string[]
     */
    public function getLines(): array {
        $lines = [];

        if ($this->streetName !== null) {
            $street = $this->streetName;
            if ($this->buildingNumber !== null) {
                $street .= ' ' . $this->buildingNumber;
            }
            $lines[] = $street;
        }

        if ($this->additionalStreetName !== null) {
            $lines[] = $this->additionalStreetName;
        }

        if ($this->postBox !== null) {
            $lines[] = 'Postfach ' . $this->postBox;
        }

        $cityLine = trim(($this->postalCode ?? '') . ' ' . ($this->city ?? ''));
        if ($cityLine !== '') {
            $lines[] = $cityLine;
        }

        if ($this->countrySubdivision !== null) {
            $lines[] = $this->countrySubdivision;
        }

        if ($this->country !== null) {
            $lines[] = $this->country->value;
        }

        return $lines;
    }

    /**
     * Creates an address with updated values.
     */
    public function with(
        ?string $streetName = null,
        ?string $additionalStreetName = null,
        ?string $buildingNumber = null,
        ?string $postBox = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?string $countrySubdivision = null,
        CountryCode|string|null $country = null
    ): self {
        return new self(
            $streetName ?? $this->streetName,
            $additionalStreetName ?? $this->additionalStreetName,
            $buildingNumber ?? $this->buildingNumber,
            $postBox ?? $this->postBox,
            $postalCode ?? $this->postalCode,
            $city ?? $this->city,
            $countrySubdivision ?? $this->countrySubdivision,
            $country ?? $this->country
        );
    }

    /**
     * Creates a German address.
     */
    public static function german(
        string $streetName,
        string $postalCode,
        string $city,
        ?string $buildingNumber = null,
        ?string $additionalStreetName = null
    ): self {
        return new self(
            streetName: $streetName,
            additionalStreetName: $additionalStreetName,
            buildingNumber: $buildingNumber,
            postalCode: $postalCode,
            city: $city,
            country: CountryCode::Germany
        );
    }
}
