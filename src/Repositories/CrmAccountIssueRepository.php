<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CrmAccountIssueRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('crm_account_issues')) {
            return null;
        }
        return $this->database->fetch(
            "SELECT i.*, p.name AS party_name, u.name AS raised_by_name, d.title AS deal_title
             FROM crm_account_issues i
             JOIN parties p ON p.id = i.party_id
             LEFT JOIN users u ON u.id = i.raised_by_user_id
             LEFT JOIN crm_deals d ON d.id = i.deal_id
             WHERE i.id = ?",
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findByParty(int $partyId): array
    {
        if (!TableSchema::hasTable('crm_account_issues')) {
            return [];
        }
        $order = TableSchema::hasColumn('crm_account_issues', 'status')
            ? "FIELD(i.status, 'open', 'escalated', 'resolved'), i.raised_on DESC, i.id DESC"
            : 'i.raised_on DESC, i.id DESC';

        return $this->database->fetchAll(
            "SELECT i.*, u.name AS raised_by_name, d.title AS deal_title
             FROM crm_account_issues i
             LEFT JOIN users u ON u.id = i.raised_by_user_id
             LEFT JOIN crm_deals d ON d.id = i.deal_id
             WHERE i.party_id = ?
             ORDER BY {$order}",
            [$partyId]
        );
    }

    public function countsByParty(int $partyId): array
    {
        $out = ['open' => 0, 'resolved' => 0, 'escalated' => 0];
        if (!TableSchema::hasTable('crm_account_issues') || !TableSchema::hasColumn('crm_account_issues', 'status')) {
            return $out;
        }
        $rows = $this->database->fetchAll(
            "SELECT status, COUNT(*) AS c FROM crm_account_issues WHERE party_id = ? GROUP BY status",
            [$partyId]
        );
        foreach ($rows as $row) {
            $out[$row['status']] = (int)$row['c'];
        }

        return $out;
    }

    public function create(array $data): int
    {
        $this->database->execute(
            "INSERT INTO crm_account_issues (
                party_id, deal_id, issue_type, raised_on, description,
                resolution_window_days, status, raised_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, 'open', ?)",
            [
                $data['party_id'],
                $data['deal_id'],
                $data['issue_type'],
                $data['raised_on'],
                $data['description'],
                $data['resolution_window_days'],
                $data['raised_by_user_id'],
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['issue_type', 'raised_on', 'description', 'resolution_window_days', 'deal_id', 'status'];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $sets[] = "{$key} = ?";
                $params[] = $data[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->database->execute(
            "UPDATE crm_account_issues SET " . implode(', ', $sets) . " WHERE id = ?",
            $params
        );
    }

    public function resolve(int $id, string $resolvedOn, string $resolutionNote): void
    {
        $this->database->execute(
            "UPDATE crm_account_issues
             SET status = 'resolved', resolved_on = ?, resolution_note = ?
             WHERE id = ?",
            [$resolvedOn, $resolutionNote, $id]
        );
    }
}
