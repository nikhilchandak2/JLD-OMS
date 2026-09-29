<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CreditPolicyTierRepository
{
    private Database $database;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->config = require dirname(__DIR__, 2) . '/config/credit_gate.php';
    }

    public function findActiveByCompany(int $companyId): array
    {
        if (!TableSchema::hasTable('credit_policy_tiers')) {
            return $this->fallbackTiers($companyId);
        }
        $this->ensureForCompany($companyId);
        $active = TableSchema::hasColumn('credit_policy_tiers', 'is_active')
            ? 'AND is_active = 1'
            : '';

        return $this->database->fetchAll(
            "SELECT * FROM credit_policy_tiers
             WHERE company_id = ? {$active}
             ORDER BY tier",
            [$companyId]
        );
    }

    public function findTier(int $companyId, int $tier): ?array
    {
        if (!TableSchema::hasTable('credit_policy_tiers')) {
            return $this->fallbackTier($companyId, $tier);
        }
        $this->ensureForCompany($companyId);
        $active = TableSchema::hasColumn('credit_policy_tiers', 'is_active')
            ? 'AND is_active = 1'
            : '';

        return $this->database->fetch(
            "SELECT * FROM credit_policy_tiers
             WHERE company_id = ? AND tier = ? {$active}",
            [$companyId, $tier]
        );
    }

    public function updateTier(int $companyId, int $tier, array $fields): void
    {
        if (!TableSchema::hasTable('credit_policy_tiers')) {
            return;
        }
        $this->ensureForCompany($companyId);
        $allowed = [
            'threshold_type',
            'threshold_percentage',
            'threshold_amount',
            'routing',
            'allows_provisional_proceed',
            'is_active',
        ];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields) && TableSchema::hasColumn('credit_policy_tiers', $key)) {
                $sets[] = "{$key} = ?";
                $params[] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $companyId;
        $params[] = $tier;
        $this->database->execute(
            "UPDATE credit_policy_tiers SET " . implode(', ', $sets) . " WHERE company_id = ? AND tier = ?",
            $params
        );
    }

    public function ensureForCompany(int $companyId): void
    {
        if (!TableSchema::hasTable('credit_policy_tiers')) {
            return;
        }
        foreach ($this->config['tiers'] as $tier => $meta) {
            $existing = $this->database->fetch(
                "SELECT id FROM credit_policy_tiers WHERE company_id = ? AND tier = ?",
                [$companyId, $tier]
            );
            if ($existing) {
                continue;
            }
            $built = TableSchema::insertSql('credit_policy_tiers', [
                'company_id' => $companyId,
                'tier' => $tier,
                'threshold_type' => $meta['threshold_type'],
                'threshold_percentage' => $meta['threshold_percentage'],
                'threshold_amount' => $meta['threshold_amount'],
                'routing' => $meta['routing'],
                'allows_provisional_proceed' => $meta['allows_provisional_proceed'],
                'is_active' => 1,
            ]);
            if ($built === null) {
                continue;
            }
            $this->database->execute($built[0], $built[1]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function fallbackTiers(int $companyId): array
    {
        $rows = [];
        foreach (array_keys($this->config['tiers']) as $tier) {
            $row = $this->fallbackTier($companyId, (int)$tier);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function fallbackTier(int $companyId, int $tier): ?array
    {
        $meta = $this->config['tiers'][$tier] ?? null;
        if ($meta === null) {
            return null;
        }

        return array_merge($meta, [
            'id' => null,
            'company_id' => $companyId,
            'tier' => $tier,
            'is_active' => 1,
        ]);
    }
}
