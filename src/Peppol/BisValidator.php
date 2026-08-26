<?php
/*
 * Created on   : Wed Aug 26 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : BisValidator.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Peppol;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ERechnungToolkit\Contracts\ValidatorInterface;
use ERechnungToolkit\Enums\ValidationSeverity;
use ERechnungToolkit\Generators\UblSerializer;
use ERechnungToolkit\Validators\{ValidationMessage, ValidationResult};
use ERRORToolkit\Traits\ErrorLog;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prüfung einer UBL-Rechnung gegen die Kernregeln von Peppol BIS Billing 3.0.
 *
 * **Teilmenge, kein Konformitätsnachweis.** Die vollständigen BIS-Regeln liegen
 * als Schematron/XSLT 2.0 vor und lassen sich in reinem PHP (libxslt = XSLT 1.0)
 * nicht ausführen - dafür ist der externe Java-Validator zuständig
 * ({@see \ERechnungToolkit\Validators\KositValidator}). Dieser Validator deckt
 * die Regeln ab, die ohne Schematron entscheidbar sind und die in der Praxis
 * die meisten Ablehnungen verursachen:
 *
 * - EN 16931 Pflichtfelder BR-01 bis BR-16, BR-62/BR-63 (Schema der elektronischen Adresse);
 * - Summenregeln BR-CO-10 und BR-CO-15;
 * - Peppol-Zusatzregeln PEPPOL-EN16931-R001, -R003, -R004, -R010, -R020, -R053.
 *
 * Für den vollständigen Nachweis kann ein Schematron-Validator injiziert
 * werden; dessen Meldungen werden dann mit den eigenen zusammengeführt.
 *
 * Beispiel:
 * ```php
 * $result = (new BisValidator())->validate($ublXml);
 * foreach ($result->getErrors() as $error) {
 *     echo $error . PHP_EOL;
 * }
 * ```
 *
 * @see https://docs.peppol.eu/poacc/billing/3.0/ Peppol BIS Billing 3.0
 */
final class BisValidator implements ValidatorInterface {
    use ErrorLog;

    /** Name des Prüfszenarios in {@see ValidationResult::getScenarioName()}. */
    public const SCENARIO = 'Peppol BIS Billing 3.0 (Kernregeln, Teilmenge)';

    /** Präfix, das die Customization-ID laut PEPPOL-EN16931-R004 haben muss. */
    public const CUSTOMIZATION_PREFIX = 'urn:cen.eu:en16931:2017';

    /** Toleranz beim Vergleich von Summen (Rundung auf zwei Nachkommastellen). */
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(private readonly ?ValidatorInterface $schematronValidator = null) {}

    /**
     * Die Kernregeln laufen ohne externe Abhängigkeiten; ein injizierter
     * Schematron-Validator muss zusätzlich verfügbar sein.
     */
    public function isAvailable(): bool {
        return $this->schematronValidator === null || $this->schematronValidator->isAvailable();
    }

    public function validate(string $xml): ValidationResult {
        $messages = [];

        try {
            $document = $this->load($xml);
            $root = $document->documentElement;
        } catch (InvalidArgumentException $exception) {
            return new ValidationResult(false, false, [
                new ValidationMessage(ValidationSeverity::ERROR, 'TK-PEPPOL-XML', $exception->getMessage()),
            ], self::SCENARIO);
        }

        if (!$root instanceof DOMElement || !in_array($root->localName, ['Invoice', 'CreditNote'], true)) {
            return new ValidationResult(false, false, [
                new ValidationMessage(
                    ValidationSeverity::ERROR,
                    'TK-PEPPOL-ROOT',
                    'Erwartet wird eine UBL-Invoice oder -CreditNote, gefunden: ' . ($root instanceof DOMElement ? $root->localName : 'kein Element') . '.'
                ),
            ], self::SCENARIO);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('cbc', UblSerializer::CBC_NS);
        $xpath->registerNamespace('cac', UblSerializer::CAC_NS);

        $isCreditNote = $root->localName === 'CreditNote';
        $lineElement = $isCreditNote ? 'cac:CreditNoteLine' : 'cac:InvoiceLine';

        $messages = array_merge(
            $messages,
            $this->checkHeader($xpath, $root, $isCreditNote),
            $this->checkParties($xpath, $root),
            $this->checkTotals($xpath, $root, $lineElement),
            $this->checkPeppolRules($xpath, $root)
        );

        if ($this->schematronValidator !== null && $this->schematronValidator->isAvailable()) {
            $messages = array_merge($messages, $this->schematronValidator->validate($xml)->getMessages());
        }

        $hasErrors = $this->hasErrors($messages);

        return new ValidationResult(!$hasErrors, !$hasErrors, $messages, self::SCENARIO);
    }

    public function validateFile(string $filePath): ValidationResult {
        if (!is_file($filePath)) {
            $this->logErrorAndThrow(RuntimeException::class, "Datei nicht gefunden: $filePath");
        }

        $xml = file_get_contents($filePath);
        if ($xml === false) {
            $this->logErrorAndThrow(RuntimeException::class, "Datei konnte nicht gelesen werden: $filePath");
        }

        return $this->validate((string) $xml);
    }

    /**
     * Prüft zusätzlich den SBDH-Umschlag gegen die Envelope-Regeln von Peppol
     * (Dokumenttyp und Prozess passen zur Nutzlast, COUNTRY_C1 vorhanden) und
     * anschließend die Nutzlast selbst.
     */
    public function validateEnvelope(string $sbdhEnvelopeXml): ValidationResult {
        $messages = [];

        try {
            $sbdh = Sbdh::parse($sbdhEnvelopeXml);
            $payload = Sbdh::payloadOf($sbdhEnvelopeXml);
        } catch (InvalidArgumentException $exception) {
            return new ValidationResult(false, false, [
                new ValidationMessage(ValidationSeverity::ERROR, 'TK-PEPPOL-SBDH', $exception->getMessage()),
            ], self::SCENARIO);
        }

        if ($sbdh->getSenderCountry() === null) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'TK-PEPPOL-SBDH-C1',
                'Der Umschlag muss den Scope COUNTRY_C1 mit dem Land des Absenders enthalten (Peppol Envelope 2.0).'
            );
        }

        try {
            $payloadTypeId = DocumentTypeId::fromUbl($payload);
            if (!$payloadTypeId->equals($sbdh->getDocumentTypeId())) {
                $messages[] = new ValidationMessage(
                    ValidationSeverity::ERROR,
                    'TK-PEPPOL-SBDH-DOCID',
                    sprintf(
                        'DOCUMENTID des Umschlags (%s) passt nicht zur Nutzlast (%s).',
                        $sbdh->getDocumentTypeId()->getValue(),
                        $payloadTypeId->getValue()
                    )
                );
            }
        } catch (InvalidArgumentException $exception) {
            $messages[] = new ValidationMessage(ValidationSeverity::ERROR, 'TK-PEPPOL-SBDH-DOCID', $exception->getMessage());
        }

        $payloadResult = $this->validate($payload);
        $messages = array_merge($messages, $payloadResult->getMessages());
        $hasErrors = $this->hasErrors($messages);

        return new ValidationResult(!$hasErrors, !$hasErrors, $messages, self::SCENARIO);
    }

    /**
     * EN 16931: Pflichtangaben im Rechnungskopf (BR-01 bis BR-05).
     *
     * @return list<ValidationMessage>
     */
    private function checkHeader(DOMXPath $xpath, DOMElement $root, bool $isCreditNote): array {
        $typeCodeElement = $isCreditNote ? 'cbc:CreditNoteTypeCode' : 'cbc:InvoiceTypeCode';

        $rules = [
            ['BR-01', 'cbc:CustomizationID', 'Die Rechnung muss eine Spezifikationskennung (BT-24) enthalten.'],
            ['BR-02', 'cbc:ID', 'Die Rechnung muss eine Rechnungsnummer (BT-1) enthalten.'],
            ['BR-03', 'cbc:IssueDate', 'Die Rechnung muss ein Rechnungsdatum (BT-2) enthalten.'],
            ['BR-04', $typeCodeElement, 'Die Rechnung muss einen Rechnungstyp-Code (BT-3) enthalten.'],
            ['BR-05', 'cbc:DocumentCurrencyCode', 'Die Rechnung muss einen Währungscode (BT-5) enthalten.'],
        ];

        $messages = [];
        foreach ($rules as [$code, $expression, $text]) {
            if ($this->text($xpath, $expression, $root) === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $code, $text, $expression);
            }
        }

        return $messages;
    }

    /**
     * EN 16931: Verkäufer- und Käuferangaben (BR-06 bis BR-11, BR-62/BR-63).
     *
     * @return list<ValidationMessage>
     */
    private function checkParties(DOMXPath $xpath, DOMElement $root): array {
        $parties = [
            ['cac:AccountingSupplierParty/cac:Party', 'Verkäufer', 'BR-06', 'BR-08', 'BR-09', 'BR-62', 'BT-27', 'BT-34'],
            ['cac:AccountingCustomerParty/cac:Party', 'Käufer', 'BR-07', 'BR-10', 'BR-11', 'BR-63', 'BT-44', 'BT-49'],
        ];

        $messages = [];
        foreach ($parties as [$base, $label, $nameRule, $addressRule, $countryRule, $endpointRule, $nameTerm, $endpointTerm]) {
            $name = $this->text($xpath, $base . '/cac:PartyLegalEntity/cbc:RegistrationName', $root)
                ?? $this->text($xpath, $base . '/cac:PartyName/cbc:Name', $root);
            if ($name === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $nameRule, "Der $label-Name ($nameTerm) fehlt.", $base);
            }

            if ($this->node($xpath, $base . '/cac:PostalAddress', $root) === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $addressRule, "Die $label-Anschrift fehlt.", $base . '/cac:PostalAddress');
            }

            if ($this->text($xpath, $base . '/cac:PostalAddress/cac:Country/cbc:IdentificationCode', $root) === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $countryRule, "Der Ländercode der $label-Anschrift fehlt.", $base . '/cac:PostalAddress/cac:Country');
            }

            $endpoint = $this->node($xpath, $base . '/cbc:EndpointID', $root);
            if ($endpoint instanceof DOMElement && $endpoint->getAttribute('schemeID') === '') {
                $messages[] = new ValidationMessage(
                    ValidationSeverity::ERROR,
                    $endpointRule,
                    "Die elektronische Adresse des $label ($endpointTerm) muss eine Schemakennung (EAS) tragen.",
                    $base . '/cbc:EndpointID'
                );
            }
        }

        return $messages;
    }

    /**
     * EN 16931: Summen und Positionen (BR-12 bis BR-16, BR-CO-10, BR-CO-15).
     *
     * @return list<ValidationMessage>
     */
    private function checkTotals(DOMXPath $xpath, DOMElement $root, string $lineElement): array {
        $messages = [];

        $totals = [
            ['BR-12', 'cac:LegalMonetaryTotal/cbc:LineExtensionAmount', 'Die Summe der Positionsbeträge (BT-106) fehlt.'],
            ['BR-13', 'cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', 'Der Gesamtbetrag ohne Umsatzsteuer (BT-109) fehlt.'],
            ['BR-14', 'cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount', 'Der Gesamtbetrag mit Umsatzsteuer (BT-112) fehlt.'],
            ['BR-15', 'cac:LegalMonetaryTotal/cbc:PayableAmount', 'Der Zahlbetrag (BT-115) fehlt.'],
        ];

        foreach ($totals as [$code, $expression, $text]) {
            if ($this->text($xpath, $expression, $root) === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $code, $text, $expression);
            }
        }

        $lines = $xpath->query($lineElement, $root);
        if ($lines === false || $lines->length === 0) {
            $messages[] = new ValidationMessage(ValidationSeverity::ERROR, 'BR-16', 'Die Rechnung muss mindestens eine Position enthalten.', $lineElement);

            return $messages;
        }

        $lineSum = 0.0;
        foreach ($lines as $line) {
            if (!$line instanceof DOMElement) {
                continue;
            }

            $amount = $this->amount($xpath, 'cbc:LineExtensionAmount', $line);
            if ($amount !== null) {
                $lineSum += $amount;
            }
        }

        $lineExtensionAmount = $this->amount($xpath, 'cac:LegalMonetaryTotal/cbc:LineExtensionAmount', $root);
        if ($lineExtensionAmount !== null && abs(round($lineSum, 2) - $lineExtensionAmount) > self::AMOUNT_TOLERANCE) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'BR-CO-10',
                sprintf('Die Summe der Positionsbeträge (%.2f) weicht von BT-106 (%.2f) ab.', $lineSum, $lineExtensionAmount),
                'cac:LegalMonetaryTotal/cbc:LineExtensionAmount'
            );
        }

        $taxExclusive = $this->amount($xpath, 'cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount', $root);
        $taxInclusive = $this->amount($xpath, 'cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount', $root);
        $taxAmount = $this->amount($xpath, 'cac:TaxTotal/cbc:TaxAmount', $root);

        if ($taxExclusive !== null && $taxInclusive !== null && $taxAmount !== null
            && abs(round($taxExclusive + $taxAmount, 2) - $taxInclusive) > self::AMOUNT_TOLERANCE) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'BR-CO-15',
                sprintf(
                    'Der Gesamtbetrag mit Umsatzsteuer (%.2f) entspricht nicht der Summe aus Nettobetrag (%.2f) und Steuerbetrag (%.2f).',
                    $taxInclusive,
                    $taxExclusive,
                    $taxAmount
                ),
                'cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'
            );
        }

        return $messages;
    }

    /**
     * Peppol-Zusatzregeln (PEPPOL-EN16931-R001/R003/R004/R010/R020/R053).
     *
     * @return list<ValidationMessage>
     */
    private function checkPeppolRules(DOMXPath $xpath, DOMElement $root): array {
        $messages = [];

        if ($this->text($xpath, 'cbc:ProfileID', $root) === null) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'PEPPOL-EN16931-R001',
                'Der Geschäftsprozess (cbc:ProfileID) muss angegeben werden.',
                'cbc:ProfileID'
            );
        }

        if ($this->text($xpath, 'cbc:BuyerReference', $root) === null
            && $this->text($xpath, 'cac:OrderReference/cbc:ID', $root) === null) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'PEPPOL-EN16931-R003',
                'Es muss eine Käuferreferenz (BT-10) oder eine Bestellreferenz (BT-13) angegeben werden.',
                'cbc:BuyerReference'
            );
        }

        $customizationId = $this->text($xpath, 'cbc:CustomizationID', $root);
        if ($customizationId !== null && !str_starts_with($customizationId, self::CUSTOMIZATION_PREFIX)) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'PEPPOL-EN16931-R004',
                'Die Spezifikationskennung muss mit "' . self::CUSTOMIZATION_PREFIX . '" beginnen, gefunden: "' . $customizationId . '".',
                'cbc:CustomizationID'
            );
        }

        $endpoints = [
            ['PEPPOL-EN16931-R020', 'cac:AccountingSupplierParty/cac:Party/cbc:EndpointID', 'Die elektronische Adresse des Verkäufers (BT-34) muss angegeben werden.'],
            ['PEPPOL-EN16931-R010', 'cac:AccountingCustomerParty/cac:Party/cbc:EndpointID', 'Die elektronische Adresse des Käufers (BT-49) muss angegeben werden.'],
        ];

        foreach ($endpoints as [$code, $expression, $text]) {
            if ($this->text($xpath, $expression, $root) === null) {
                $messages[] = new ValidationMessage(ValidationSeverity::ERROR, $code, $text, $expression);
            }
        }

        $taxTotals = $xpath->query('cac:TaxTotal[cac:TaxSubtotal]', $root);
        if ($taxTotals !== false && $taxTotals->length !== 1) {
            $messages[] = new ValidationMessage(
                ValidationSeverity::ERROR,
                'PEPPOL-EN16931-R053',
                sprintf('Es muss genau eine Steueraufstellung mit cac:TaxSubtotal geben, gefunden: %d.', $taxTotals->length),
                'cac:TaxTotal'
            );
        }

        return $messages;
    }

    /**
     * @param ValidationMessage[] $messages
     */
    private function hasErrors(array $messages): bool {
        foreach ($messages as $message) {
            if ($message->isError()) {
                return true;
            }
        }

        return false;
    }

    private function load(string $xml): DOMDocument {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: keine Netzzugriffe beim Laden (XXE-/SSRF-Härtung).
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new InvalidArgumentException('Das Dokument konnte nicht als XML geladen werden.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function node(DOMXPath $xpath, string $expression, DOMNode $context): ?DOMNode {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    private function text(DOMXPath $xpath, string $expression, DOMNode $context): ?string {
        $node = $this->node($xpath, $expression, $context);
        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private function amount(DOMXPath $xpath, string $expression, DOMNode $context): ?float {
        $value = $this->text($xpath, $expression, $context);

        return $value === null || !is_numeric($value) ? null : (float) $value;
    }
}
