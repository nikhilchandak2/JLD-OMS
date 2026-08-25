<?php

namespace App\Repositories;

use App\Core\Database;

class DormancyRuleRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /** @return array<int,array<string,mixed>> */
    public function findActive(): array
    {
        return $this->database->fetchAll(
            "SELECT * FROM dormancy_rules WHERE is_active = 1 ORDER BY id ASC"
        );
    }

    /**
     * Most specific matching rule: company+tier, then company, then tier, then group default.
     *
     * @param array<int,array<string,mixed>> $rules
     * @return array<string,mixed>
     */
    public function match(array $rules, ?int $companyId, ?string $accountTier): array
    {
        $tier = $accountTier !== null && $accountTier !== '' ? $accountTier : null;
        $ranked = [];
        foreach ($rules as $rule) {
            $ruleCompany = $rule['company_id'] === null ? null : (int)$rule['company_id'];
            $ruleTier = $rule['account_tier'] !== null && $rule['account_tier'] !== ''
                ? (string)$rule['account_tier']
                : null;
            if ($ruleCompany !== null && $ruleCompany !== $companyId) {
                continue;
            }
            if ($ruleTier !== null && $ruleTier !== $tier) {
                continue;
            }
            $score = 0;
            if ($ruleCompany !== null) {
                $score += 2;
            }
            if ($ruleTier !== null) {
                $score += 1;
            }
            $ranked[] = [$score, $rule];
        }
        usort($ranked, static fn(array $a, array $b) => $b[0] <=> $a[0]);
        if ($ranked === []) {
            throw new \RuntimeException('No active dormancy rule matches this account.');
        }

        return $ranked[0][1];
    }
}
