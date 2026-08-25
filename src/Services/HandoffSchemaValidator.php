<?php

namespace App\Services;

/**
 * Validates handoff payloads against the versioned schema in config/handoff.php.
 * Invalid payloads never reach the database.
 */
class HandoffSchemaValidator
{
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/../../config/handoff.php';
    }

    public function currentVersion(): int
    {
        return (int)$this->config['current_schema_version'];
    }

    /** @return array<string,string> */
    public function deliveryTerms(): array
    {
        return $this->config['delivery_terms'] ?? [];
    }

    /** @return array<string,string> */
    public function packetTypes(): array
    {
        return $this->config['packet_types'] ?? [];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function validate(string $packetType, int $schemaVersion, array $payload): array
    {
        if (!isset($this->config['packet_types'][$packetType])) {
            throw new HandoffException('Unknown packet type.', ['field' => 'packet_type']);
        }

        $schema = $this->config['schemas'][$packetType][$schemaVersion] ?? null;
        if (!is_array($schema)) {
            throw new HandoffException(
                "Schema version {$schemaVersion} is not defined for {$packetType}.",
                ['field' => 'schema_version']
            );
        }

        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new HandoffException(
                    "Missing required field '{$field}'.",
                    ['field' => $field]
                );
            }
        }

        $allowed = $required;
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new HandoffException(
                    "Unknown field '{$key}' is not part of schema v{$schemaVersion}.",
                    ['field' => $key]
                );
            }
        }

        return $packetType === HandoffService::TYPE_SALES_TO_DISPATCH
            ? $this->normalizeSalesV1($payload)
            : $this->normalizeAccountsV1($payload);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeSalesV1(array $payload): array
    {
        $grades = $payload['grades'];
        if (!is_array($grades) || $grades === [] || !$this->isList($grades)) {
            throw new HandoffException(
                "Missing required field 'grades'.",
                ['field' => 'grades']
            );
        }

        $normalizedGrades = [];
        foreach ($grades as $i => $row) {
            if (!is_array($row)) {
                throw new HandoffException("Grade #{$i} must be an object with grade_code and spec.", ['field' => 'grades']);
            }
            $code = trim((string)($row['grade_code'] ?? ''));
            $spec = trim((string)($row['spec'] ?? ''));
            if ($code === '') {
                throw new HandoffException("Each grade needs a grade_code.", ['field' => 'grades']);
            }
            if ($spec === '') {
                throw new HandoffException("Each grade needs a spec.", ['field' => 'grades']);
            }
            $normalizedGrades[] = ['grade_code' => $code, 'spec' => $spec];
        }

        $qty = $payload['quantity_tonnes'];
        if (!is_numeric($qty) || (float)$qty <= 0) {
            throw new HandoffException("quantity_tonnes must be a number greater than zero.", ['field' => 'quantity_tonnes']);
        }

        $packing = trim((string)$payload['packing']);
        if ($packing === '') {
            throw new HandoffException("Missing required field 'packing'.", ['field' => 'packing']);
        }

        $timeline = trim((string)$payload['delivery_timeline']);
        if ($timeline === '') {
            throw new HandoffException("Missing required field 'delivery_timeline'.", ['field' => 'delivery_timeline']);
        }

        $terms = trim((string)$payload['delivery_terms']);
        if (!isset($this->config['delivery_terms'][$terms])) {
            throw new HandoffException(
                'delivery_terms must be Ex-works, FOR, or freight.',
                ['field' => 'delivery_terms']
            );
        }

        if (!is_string($payload['special_handling_notes']) && $payload['special_handling_notes'] !== null) {
            throw new HandoffException(
                "special_handling_notes must be a string.",
                ['field' => 'special_handling_notes']
            );
        }

        return [
            'grades' => $normalizedGrades,
            'quantity_tonnes' => round((float)$qty, 3),
            'packing' => $packing,
            'delivery_timeline' => $timeline,
            'delivery_terms' => $terms,
            'special_handling_notes' => trim((string)$payload['special_handling_notes']),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeAccountsV1(array $payload): array
    {
        $date = trim((string)$payload['delivery_date']);
        if ($date === '' || \DateTimeImmutable::createFromFormat('Y-m-d', $date) === false) {
            throw new HandoffException("delivery_date must be YYYY-MM-DD.", ['field' => 'delivery_date']);
        }

        $quote = trim((string)$payload['quote_reference']);
        if ($quote === '') {
            throw new HandoffException("Missing required field 'quote_reference'.", ['field' => 'quote_reference']);
        }

        $terms = trim((string)$payload['agreed_terms']);
        if ($terms === '') {
            throw new HandoffException("Missing required field 'agreed_terms'.", ['field' => 'agreed_terms']);
        }

        $invoice = trim((string)$payload['invoice_reference']);
        if ($invoice === '') {
            throw new HandoffException("Missing required field 'invoice_reference'.", ['field' => 'invoice_reference']);
        }

        return [
            'delivery_date' => $date,
            'quote_reference' => $quote,
            'agreed_terms' => $terms,
            'invoice_reference' => $invoice,
        ];
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
