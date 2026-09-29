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
        $raisedJoin = TableSchema::leftJoinIfColumn(
            'crm_account_issues',
            'raised_by_user_id',
            'users',
            'u',
            'u.id = i.raised_by_user_id'
        );
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = i.deal_id', ['id', 'title']);
        return $this->database->fetch(
            "SELECT i.*, p.name AS party_name, u.name AS raised_by_name, d.title AS deal_title
             FROM crm_account_issues i
             JOIN parties p ON p.id = i.party_id
             {$raisedJoin}
             {$dealJoin}
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
            ? "FIELD(i.status, 'open', 'escalated', 'resolved'), " . (
                TableSchema::hasColumn('crm_account_issues', 'raised_on') ? 'i.raised_on DESC, i.id DESC' : 'i.id DESC'
            )
            : (TableSchema::hasColumn('crm_account_issues', 'raised_on') ? 'i.raised_on DESC, i.id DESC' : 'i.id DESC');

        $raisedJoin = TableSchema::leftJoinIfColumn(
            'crm_account_issues',
            'raised_by_user_id',
            'users',
            'u',
            'u.id = i.raised_by_user_id'
        );
        $dealJoin = TableSchema::leftJoinOrStub('crm_deals', 'd', 'd.id = i.deal_id', ['id', 'title']);
        return $this->database->fetchAll(
            "SELECT i.*, u.name AS raised_by_name, d.title AS deal_title
             FROM crm_account_issues i
             {$raisedJoin}
             {$dealJoin}
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
        $built = TableSchema::insertSql('crm_account_issues', [
            'party_id' => $data['party_id'],
            'deal_id' => $data['deal_id'] ?? null,
            'issue_type' => $data['issue_type'] ?? 'other',
            'raised_on' => $data['raised_on'] ?? null,
            'description' => $data['description'] ?? null,
            'resolution_window_days' => $data['resolution_window_days'] ?? 7,
            'status' => 'open',
            'raised_by_user_id' => $data['raised_by_user_id'] ?? null,
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_account_issues is not available.');
        }
        $this->database->execute($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['issue_type', 'raised_on', 'description', 'resolution_window_days', 'deal_id', 'status'];
        $sets = [];
        $params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data) && TableSchema::hasColumn('crm_account_issues', $key)) {
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
        $sets = [];
        $params = [];
        if (TableSchema::hasColumn('crm_account_issues', 'status')) {
            $sets[] = "status = 'resolved'";
        }
        if (TableSchema::hasColumn('crm_account_issues', 'resolved_on')) {
            $sets[] = 'resolved_on = ?';
            $params[] = $resolvedOn;
        }
        if (TableSchema::hasColumn('crm_account_issues', 'resolution_note')) {
            $sets[] = 'resolution_note = ?';
            $params[] = $resolutionNote;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->database->execute(
            'UPDATE crm_account_issues SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }
}
