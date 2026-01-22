<?php
/*
 * Created on   : Wed Jan 22 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : Party.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Entities;

use CommonToolkit\Helper\Data\BankHelper;

/**
 * Party for E-Rechnung (EN 16931).
 * 
 * Represents seller, buyer, payee, or other parties in the invoice.
 * 
 * @package ERechnungToolkit\Entities
 */
final class Party {
    public function __construct(
        private string $name,
        private ?PostalAddress $postalAddress = null,
        private ?string $vatId = null,
        private ?string $taxRegistrationId = null,
        private ?string $legalEntityId = null,
        private ?string $legalEntityScheme = null,
        private ?string $endpointId = null,
        private ?string $endpointScheme = null,
        private ?string $contactName = null,
        private ?string $contactPhone = null,
        private ?string $contactEmail = null,
        private ?string $iban = null,
        private ?string $bic = null,
        private ?string $bankName = null,
        private ?string $paymentAccountName = null
    ) {
        // Format IBAN if provided (remove spaces, uppercase)
        if ($this->iban !== null) {
            $cleanIban = strtoupper(str_replace(' ', '', $this->iban));
            // Format with spaces every 4 characters for display
            $this->iban = implode(' ', str_split($cleanIban, 4));
        }

        // Validate and format BIC if provided
        if ($this->bic !== null) {
            $this->bic = strtoupper(str_replace(' ', '', $this->bic));
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function getPostalAddress(): ?PostalAddress {
        return $this->postalAddress;
    }

    /**
     * Returns the VAT identification number (USt-IdNr.).
     */
    public function getVatId(): ?string {
        return $this->vatId;
    }

    /**
     * Returns the tax registration number (Steuernummer).
     */
    public function getTaxRegistrationId(): ?string {
        return $this->taxRegistrationId;
    }

    /**
     * Returns the legal entity identifier (e.g., GLN, DUNS).
     */
    public function getLegalEntityId(): ?string {
        return $this->legalEntityId;
    }

    /**
     * Returns the scheme for the legal entity identifier.
     */
    public function getLegalEntityScheme(): ?string {
        return $this->legalEntityScheme;
    }

    /**
     * Returns the electronic address (Leitweg-ID for XRechnung).
     */
    public function getEndpointId(): ?string {
        return $this->endpointId;
    }

    /**
     * Returns the scheme for the endpoint (e.g., 0204 for Leitweg-ID).
     */
    public function getEndpointScheme(): ?string {
        return $this->endpointScheme;
    }

    public function getContactName(): ?string {
        return $this->contactName;
    }

    public function getContactPhone(): ?string {
        return $this->contactPhone;
    }

    public function getContactEmail(): ?string {
        return $this->contactEmail;
    }

    public function getIban(): ?string {
        return $this->iban;
    }

    public function getBic(): ?string {
        return $this->bic;
    }

    public function getBankName(): ?string {
        return $this->bankName;
    }

    public function getPaymentAccountName(): ?string {
        return $this->paymentAccountName;
    }

    /**
     * Returns true if this party has banking information.
     */
    public function hasBankingInfo(): bool {
        return $this->iban !== null;
    }

    /**
     * Returns true if this party has contact information.
     */
    public function hasContactInfo(): bool {
        return $this->contactName !== null
            || $this->contactPhone !== null
            || $this->contactEmail !== null;
    }

    /**
     * Returns true if this party has a VAT ID.
     */
    public function hasVatId(): bool {
        return $this->vatId !== null && $this->vatId !== '';
    }

    /**
     * Returns true if this party has an endpoint ID (required for XRechnung).
     */
    public function hasEndpoint(): bool {
        return $this->endpointId !== null && $this->endpointScheme !== null;
    }

    /**
     * Creates a party with Leitweg-ID (for XRechnung to public sector).
     */
    public function withLeitwegId(string $leitwegId): self {
        return $this->withEndpoint($leitwegId, '0204');
    }

    /**
     * Creates a party with endpoint information.
     */
    public function withEndpoint(string $endpointId, string $scheme): self {
        $clone = clone $this;
        $clone->endpointId = $endpointId;
        $clone->endpointScheme = $scheme;
        return $clone;
    }

    /**
     * Creates a party with banking information.
     */
    public function withBankingInfo(string $iban, ?string $bic = null, ?string $bankName = null): self {
        $clone = clone $this;
        $clone->iban = BankHelper::formatIBAN($iban);
        $clone->bic = $bic !== null ? strtoupper(str_replace(' ', '', $bic)) : null;
        $clone->bankName = $bankName;
        return $clone;
    }

    /**
     * Creates a party with contact information.
     */
    public function withContact(string $name, ?string $phone = null, ?string $email = null): self {
        $clone = clone $this;
        $clone->contactName = $name;
        $clone->contactPhone = $phone;
        $clone->contactEmail = $email;
        return $clone;
    }

    /**
     * Creates a basic seller party.
     */
    public static function seller(
        string $name,
        PostalAddress $address,
        string $vatId,
        ?string $taxRegistrationId = null
    ): self {
        return new self(
            name: $name,
            postalAddress: $address,
            vatId: $vatId,
            taxRegistrationId: $taxRegistrationId
        );
    }

    /**
     * Creates a basic buyer party.
     */
    public static function buyer(
        string $name,
        PostalAddress $address,
        ?string $vatId = null
    ): self {
        return new self(
            name: $name,
            postalAddress: $address,
            vatId: $vatId
        );
    }
}