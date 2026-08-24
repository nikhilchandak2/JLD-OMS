<?php

namespace App\Repositories;

use App\Core\Database;

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
        $this->ensureForCompany($companyId);

        return $this->database->fetchAll(
            "SELECT * FROM credit_policy_tiers
             WHERE company_id = ? AND is_active = 1
             ORDER BY tier",
            [$companyId]
        );
    }

    public function findTier(int $companyId, int $tier): ?array
    {
        $this->ensureForCompany($companyId);

        return $this->database->fetch(
            "SELECT * FROM credit_policy_tiers
             WHERE company_id = ? AND tier = ? AND is_active = 1",
            [$companyId, $tier]
        );
    }

    public function updateTier(int $companyId, int $tier, array $fields): void
    {
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
            if (array_key_exists($key, $fields)) {
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
        foreach ($this->config['tiers'] as $tier => $meta) {
            $existing = $this->database->fetch(
                "SELECT id FROM credit_policy_tiers WHERE company_id = ? AND tier = ?",
                [$companyId, $tier]
            );
            if ($existing) {
                continue;
            }
            $this->database->execute(
                "INSERT INTO credit_policy_tiers
                    (company_id, tier, threshold_type, threshold_percentage, threshold_amount, routing, allows_provisional_proceed, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                [
                    $companyId,
                    $tier,
                    $meta['threshold_type'],
                    $meta['threshold_percentage'],
                    $meta['threshold_amount'],
                    $meta['routing'],
                    $meta['allows_provisional_proceed'],
                ]
            );
        }
    }
}
