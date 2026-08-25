<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\DispatchSchema;

/**
 * Bounded lookups for the new-rep briefing. Order/dispatch history is aggregated
 * in one GROUP BY so the query count does not grow with dispatch volume.
 */
class BriefingRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function party(int $partyId): ?array
    {
        return $this->database->fetch(
            "SELECT id, name, phone, email, address, credit_limit FROM parties WHERE id = ?",
            [$partyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function contacts(int $partyId): array
    {
        return $this->database->fetchAll(
            "SELECT c.id, c.name, c.role, c.phone, c.email, c.is_primary,
                    c.influence_level, c.relationship_strength, c.preferred_channel,
                    c.preferred_language, c.context_notes
             FROM crm_contacts c
             WHERE c.party_id = ?
             ORDER BY c.is_primary DESC, c.name ASC",
            [$partyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function currentCompetitors(int $partyId): array
    {
        return $this->database->fetchAll(
            "SELECT competitor_name, grade_code, estimated_share_pct, reason_code,
                    reason_note, intelligence_type
             FROM crm_competitor_positions
             WHERE party_id = ? AND is_current = 1
             ORDER BY competitor_name, grade_code",
            [$partyId]
        );
    }

    /**
     * Open and escalated issues plus a capped slice of resolved history.
     *
     * @return list<array<string,mixed>>
     */
    public function issues(int $partyId, int $resolvedLimit): array
    {
        $cap = max(30, (int)$resolvedLimit);

        return $this->database->fetchAll(
            "SELECT id, issue_type, status, raised_on, description, resolved_on, resolution_note
             FROM crm_account_issues
             WHERE party_id = ?
             ORDER BY FIELD(status, 'open', 'escalated', 'resolved'), raised_on DESC, id DESC
             LIMIT {$cap}",
            [$partyId]
        );
    }

    public function lastVisit(int $partyId): ?array
    {
        return $this->database->fetch(
            "SELECT v.id, v.visit_date, v.purpose, v.outcome, v.next_planned_touchpoint,
                    v.next_action, v.no_followup_needed, u.name AS visited_by_name
             FROM crm_visits v
             LEFT JOIN users u ON u.id = v.visited_by_user_id
             WHERE v.party_id = ?
             ORDER BY v.visit_date DESC, v.id DESC
             LIMIT 1",
            [$partyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function visitContacts(int $visitId): array
    {
        return $this->database->fetchAll(
            "SELECT c.name, c.role
             FROM crm_visit_contacts vc
             JOIN crm_contacts c ON c.id = vc.contact_id
             WHERE vc.visit_id = ?
             ORDER BY c.name",
            [$visitId]
        );
    }

    /**
     * Last N calendar months of dispatched tonnes, one row per grade.
     *
     * @return list<array<string,mixed>>
     */
    public function orderPattern(int $partyId, string $from, string $to): array
    {
        $active = DispatchSchema::activeDispatchWhere('d');

        return $this->database->fetchAll(
            "SELECT UPPER(p.code) AS grade_code,
                    SUM(" . DispatchSchema::tonnesExpr('d', 'o') . ") AS tonnes
             FROM dispatches d
             JOIN orders o ON o.id = d.order_id
             JOIN products p ON p.id = o.product_id
             WHERE o.party_id = ?
               AND d.dispatch_date >= ? AND d.dispatch_date < ?
               AND p.code IS NOT NULL AND p.code <> ''
               AND {$active}
             GROUP BY UPPER(p.code)
             ORDER BY tonnes DESC, grade_code",
            [$partyId, $from, $to]
        );
    }

    public function forecastPeriod(string $yearMonth): ?array
    {
        return $this->database->fetch(
            "SELECT id, period_month, status FROM forecast_periods WHERE period_month = ?",
            [$yearMonth]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forecastActuals(int $periodId, int $partyId): array
    {
        return $this->database->fetchAll(
            "SELECT grade_code, forecast_low, forecast_high, actual_tonnes, variance_vs_midpoint, as_of
             FROM forecast_actuals
             WHERE period_id = ? AND party_id = ?
             ORDER BY grade_code",
            [$periodId, $partyId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function openDeals(int $partyId): array
    {
        return $this->database->fetchAll(
            "SELECT id, title, stage, status, stage_entered_at
             FROM crm_deals
             WHERE party_id = ? AND deleted_at IS NULL AND status = 'active'
             ORDER BY stage DESC, id DESC",
            [$partyId]
        );
    }

    public function partyOutstanding(int $partyId): float
    {
        $ledger = $this->database->fetch(
            "SELECT SUM(outstanding_amount) AS total
             FROM ledger_outstanding
             WHERE party_id = ?
               AND business_date = (SELECT MAX(business_date) FROM ledger_outstanding WHERE party_id = ?)",
            [$partyId, $partyId]
        );
        if ($ledger !== null && $ledger['total'] !== null) {
            return round((float)$ledger['total'], 2);
        }

        $rows = $this->database->fetchAll(
            "SELECT entry_type, SUM(amount) AS total
             FROM crm_receivable_entries
             WHERE party_id = ?
             GROUP BY entry_type",
            [$partyId]
        );
        $invoice = 0.0;
        $payment = 0.0;
        $adjustment = 0.0;
        foreach ($rows as $row) {
            if ($row['entry_type'] === 'invoice') {
                $invoice = (float)$row['total'];
            } elseif ($row['entry_type'] === 'payment') {
                $payment = (float)$row['total'];
            } else {
                $adjustment += (float)$row['total'];
            }
        }

        return round($invoice - $payment + $adjustment, 2);
    }

    public function oldestLedgerAsOf(): ?string
    {
        $row = $this->database->fetch(
            "SELECT MIN(as_of) AS ledger_as_of
             FROM data_feed_runs
             WHERE feed_key = 'ledger' AND status = 'completed' AND as_of IS NOT NULL"
        );

        return ($row['ledger_as_of'] ?? null) !== null ? (string)$row['ledger_as_of'] : null;
    }
}
