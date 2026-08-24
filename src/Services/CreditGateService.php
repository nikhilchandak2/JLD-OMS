<?php

namespace App\Services;

use App\Repositories\CreditPolicyTierRepository;
use App\Repositories\CrmReceivableEntryRepository;
use App\Repositories\DataFeedRepository;
use App\Repositories\PartyRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure evaluation of the three-tier credit gate. Does not write override rows.
 *
 * Outstanding prefers the latest completed ledger feed per legal entity (Task 2).
 * Falls back to crm_receivable_entries when no entity has a ledger file.
 * A missing entity feed warns and does not block (B6).
 */
class CreditGateService
{
    public const TIER_AUTO = 1;
    public const TIER_PASSIVE = 2;
    public const TIER_REALTIME = 3;

    public const ACTION_AUTO_CLEAR = 'auto_clear';
    public const ACTION_QUEUE_OVERRIDE = 'queue_override';
    public const ACTION_BLOCK_UNTIL_DECISION = 'block_until_decision';

    public const STATUS_CLEARED = 'cleared';
    public const STATUS_PENDING_DIRECTOR = 'pending_director';
    public const STATUS_BLOCKED = 'blocked';

    private DataFeedRepository $feeds;
    private DataFreshnessService $freshness;
    private CreditPolicyTierRepository $tiers;
    private CrmReceivableEntryRepository $receivables;
    private PartyRepository $parties;
    private CreditGatePolicy $policy;
    private array $config;
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->feeds = new DataFeedRepository();
        $this->freshness = new DataFreshnessService();
        $this->tiers = new CreditPolicyTierRepository();
        $this->receivables = new CrmReceivableEntryRepository();
        $this->parties = new PartyRepository();
        $this->policy = new CreditGatePolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/credit_gate.php';
        $this->tz = new DateTimeZone($this->config['timezone'] ?? 'Asia/Kolkata');
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluate(int $partyId, int $companyId, float $proposedOrderValue = 0.0): array
    {
        $party = $this->parties->findById($partyId);
        if (!$party) {
            throw new CreditGateException('Party not found.');
        }

        $this->tiers->ensureForCompany($companyId);
        $this->feeds->ensureForCompany($companyId);

        $proposedOrderValue = round(max(0, $proposedOrderValue), 2);
        $limit = $party->creditLimit === null ? null : (float)$party->creditLimit;
        $group = $this->groupOutstanding($partyId);
        $outstanding = round((float)$group['outstanding'], 2);

        $tierRow = null;
        $tier = self::TIER_REALTIME;
        $overage = 0.0;
        $overagePercentage = null;
        $headroom = null;

        $zeroOrNullLimit = $limit === null || $limit <= 0;
        if ($zeroOrNullLimit) {
            $tier = self::TIER_REALTIME;
            $overage = round($outstanding + $proposedOrderValue, 2);
        } else {
            $headroom = round($limit - $outstanding, 2);
            $exposurePaise = $this->toPaise($outstanding) + $this->toPaise($proposedOrderValue);
            $limitPaise = $this->toPaise($limit);
            $overagePaise = max(0, $exposurePaise - $limitPaise);
            $overage = $overagePaise / 100;

            if ($exposurePaise <= $limitPaise) {
                $tier = self::TIER_AUTO;
                $overagePercentage = 0.0;
            } else {
                $tier2 = $this->tiers->findTier($companyId, self::TIER_PASSIVE);
                $ceiling = $tier2 !== null && $tier2['threshold_percentage'] !== null
                    ? (float)$tier2['threshold_percentage']
                    : (float)($this->config['default_tier2_percentage'] ?? 10);
                $overagePercentage = $limitPaise > 0
                    ? round(($overagePaise * 10000) / $limitPaise) / 100
                    : null;
                $withinBand = $this->overageWithinPercentage($overagePaise, $limitPaise, $ceiling);
                $tier = $withinBand ? self::TIER_PASSIVE : self::TIER_REALTIME;
            }
        }

        $tierRow = $this->tiers->findTier($companyId, $tier);
        $routing = $tierRow['routing'] ?? match ($tier) {
            self::TIER_AUTO => 'auto',
            self::TIER_PASSIVE => 'passive_queue',
            default => 'realtime_push',
        };
        $allowsProvisional = $tier === self::TIER_PASSIVE
            && (int)($tierRow['allows_provisional_proceed'] ?? 1) === 1;

        $status = match ($tier) {
            self::TIER_AUTO => self::STATUS_CLEARED,
            self::TIER_PASSIVE => $allowsProvisional ? self::STATUS_PENDING_DIRECTOR : self::STATUS_BLOCKED,
            default => self::STATUS_BLOCKED,
        };
        $action = match ($tier) {
            self::TIER_AUTO => self::ACTION_AUTO_CLEAR,
            self::TIER_PASSIVE => self::ACTION_QUEUE_OVERRIDE,
            default => self::ACTION_BLOCK_UNTIL_DECISION,
        };

        return [
            'party_id' => $partyId,
            'party_name' => $party->name,
            'company_id' => $companyId,
            'tier' => $tier,
            'routing' => $routing,
            'allows_provisional_proceed' => $allowsProvisional,
            'required_action' => $action,
            'credit_gate_status' => $status,
            'credit_limit' => $limit,
            'outstanding' => $outstanding,
            'headroom' => $headroom,
            'proposed_order_value' => $proposedOrderValue,
            'exposure' => round($outstanding + $proposedOrderValue, 2),
            'computed_overage' => round($overage, 2),
            'overage_percentage' => $overagePercentage,
            'threshold_percentage' => isset($tierRow['threshold_percentage']) && $tierRow['threshold_percentage'] !== null
                ? (float)$tierRow['threshold_percentage']
                : ($tier === self::TIER_PASSIVE ? (float)($this->config['default_tier2_percentage'] ?? 10) : null),
            'outstanding_breakdown' => $group['breakdown'],
            'ledger_as_of' => $group['ledger_as_of'],
            'staleness' => $group['staleness'],
            'lagging_entity' => $group['lagging_entity'],
            'missing_entities' => $group['missing_entities'],
            'incomplete_feed' => $group['incomplete_feed'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function serializeForRole(array $evaluation, ?string $role): array
    {
        return $this->policy->serializeForRole($evaluation, $role);
    }

    /**
     * Group-wide outstanding across legal entities (B6).
     *
     * @return array{
     *   outstanding: float,
     *   breakdown: array,
     *   ledger_as_of: ?string,
     *   staleness: string,
     *   lagging_entity: ?array,
     *   missing_entities: array,
     *   incomplete_feed: bool
     * }
     */
    public function groupOutstanding(int $partyId): array
    {
        $this->feeds->ensureForAllCompanies();
        $feeds = $this->feeds->listActiveByKey('ledger');
        $breakdown = [];
        $missing = [];
        $contributing = 0;

        foreach ($feeds as $feed) {
            $companyId = (int)$feed['company_id'];
            $fresh = $this->freshness->asOf('ledger', $companyId);
            if ($fresh['as_of'] === null) {
                $missing[] = [
                    'company_id' => $companyId,
                    'company_name' => $feed['company_name'] ?? null,
                ];
                $breakdown[] = [
                    'company_id' => $companyId,
                    'company_name' => $feed['company_name'] ?? null,
                    'outstanding' => 0.0,
                    'as_of' => null,
                    'business_date' => null,
                    'source' => 'missing',
                ];
                continue;
            }

            $amount = $this->sumLedgerForPartyCompany($partyId, $companyId, (string)$fresh['business_date']);
            $contributing++;
            $breakdown[] = [
                'company_id' => $companyId,
                'company_name' => $feed['company_name'] ?? null,
                'outstanding' => $amount,
                'as_of' => $fresh['as_of'],
                'business_date' => $fresh['business_date'],
                'source' => 'ledger_feed',
            ];
        }

        if ($contributing === 0) {
            $legacy = round($this->receivables->getOutstandingForParty($partyId), 2);
            $group = $feeds === []
                ? ['as_of' => null, 'state' => DataFreshnessService::STATE_MISSING, 'lagging_entity' => null]
                : $this->freshness->groupAsOf('ledger');

            return [
                'outstanding' => $legacy,
                'breakdown' => [[
                    'company_id' => null,
                    'company_name' => 'Legacy receivables',
                    'outstanding' => $legacy,
                    'as_of' => null,
                    'business_date' => null,
                    'source' => 'crm_receivable_entries',
                ]],
                'ledger_as_of' => $group['as_of'] ?? null,
                'staleness' => $group['state'] ?? DataFreshnessService::STATE_MISSING,
                'lagging_entity' => $group['lagging_entity'] ?? null,
                'missing_entities' => $missing,
                'incomplete_feed' => $missing !== [],
            ];
        }

        $group = $this->freshness->groupAsOf('ledger');
        $outstanding = 0.0;
        foreach ($breakdown as $row) {
            if ($row['source'] === 'ledger_feed') {
                $outstanding += (float)$row['outstanding'];
            }
        }

        return [
            'outstanding' => round($outstanding, 2),
            'breakdown' => $breakdown,
            'ledger_as_of' => $group['as_of'],
            'staleness' => $group['state'],
            'lagging_entity' => $group['lagging_entity'],
            'missing_entities' => $missing,
            'incomplete_feed' => $missing !== [],
        ];
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->tz);
    }

    public function expireAfterDays(): int
    {
        return (int)($this->config['expire_after_days'] ?? 7);
    }

    private function sumLedgerForPartyCompany(int $partyId, int $companyId, string $businessDate): float
    {
        $row = (new \App\Core\Database())->fetch(
            "SELECT COALESCE(SUM(outstanding_amount), 0) AS total
             FROM ledger_outstanding
             WHERE party_id = ? AND company_id = ? AND business_date = ?",
            [$partyId, $companyId, $businessDate]
        );

        return round((float)($row['total'] ?? 0), 2);
    }

    private function toPaise(float $rupees): int
    {
        return (int)round($rupees * 100);
    }

    /**
     * overage / limit * 100 <= thresholdPercentage, compared in integer paise.
     */
    private function overageWithinPercentage(int $overagePaise, int $limitPaise, float $thresholdPercentage): bool
    {
        if ($limitPaise <= 0) {
            return false;
        }
        $thresholdHundredths = (int)round($thresholdPercentage * 100);

        return ($overagePaise * 10000) <= ($limitPaise * $thresholdHundredths);
    }
}
