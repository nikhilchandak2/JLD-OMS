<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CrmVisitRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function create(array $data): int
    {
        $built = TableSchema::insertSql('crm_visits', [
            'party_id' => $data['party_id'],
            'deal_id' => $data['deal_id'] ?? null,
            'visited_by_user_id' => $data['visited_by_user_id'] ?? null,
            'visit_date' => $data['visit_date'],
            'purpose' => $data['purpose'] ?? null,
            'outcome' => $data['outcome'] ?? null,
            'next_planned_touchpoint' => $data['next_planned_touchpoint'] ?? null,
            'next_action' => $data['next_action'] ?? null,
            'no_followup_needed' => !empty($data['no_followup_needed']) ? 1 : 0,
            'no_followup_reason' => $data['no_followup_reason'] ?? null,
            'logged_via' => $data['logged_via'] ?? 'web',
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_visits is not available.');
        }
        $this->database->execute($built[0], $built[1]);

        return (int)$this->database->lastInsertId();
    }

    public function attachContact(int $visitId, int $contactId): void
    {
        $this->database->execute(
            "INSERT IGNORE INTO crm_visit_contacts (visit_id, contact_id) VALUES (?, ?)",
            [$visitId, $contactId]
        );
    }

    public function findById(int $id): ?array
    {
        if (!TableSchema::hasTable('crm_visits')) {
            return null;
        }
        $ownerJoin = TableSchema::leftJoinIfColumn(
            'crm_visits',
            'visited_by_user_id',
            'users',
            'u',
            'u.id = v.visited_by_user_id'
        );
        $row = $this->database->fetch(
            "SELECT v.*, p.name AS party_name, u.name AS visited_by_name
             FROM crm_visits v
             JOIN parties p ON p.id = v.party_id
             {$ownerJoin}
             WHERE v.id = ?",
            [$id]
        );
        if ($row === null) {
            return null;
        }
        $row['contacts'] = $this->contactsForVisit($id);

        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    public function findByParty(int $partyId): array
    {
        if (!TableSchema::hasTable('crm_visits')) {
            return [];
        }
        $ownerJoin = TableSchema::leftJoinIfColumn(
            'crm_visits',
            'visited_by_user_id',
            'users',
            'u',
            'u.id = v.visited_by_user_id'
        );
        $order = TableSchema::hasColumn('crm_visits', 'visit_date')
            ? 'v.visit_date DESC, v.id DESC'
            : 'v.id DESC';
        $rows = $this->database->fetchAll(
            "SELECT v.*, u.name AS visited_by_name
             FROM crm_visits v
             {$ownerJoin}
             WHERE v.party_id = ?
             ORDER BY {$order}",
            [$partyId]
        );

        return $this->hydrateContacts($rows);
    }

    /**
     * Visits whose next touchpoint has passed, with no later visit or order for that party.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findOverdue(?int $visitedByUserId): array
    {
        [$sql, $params] = $this->overdueSql($visitedByUserId);

        return $this->hydrateContacts($this->database->fetchAll($sql, $params));
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    public function overdueSql(?int $visitedByUserId): array
    {
        if (!TableSchema::hasTable('crm_visits') || !TableSchema::hasColumn('crm_visits', 'next_planned_touchpoint')) {
            return ['SELECT NULL AS id WHERE 1 = 0', []];
        }
        if ($visitedByUserId !== null && !TableSchema::hasColumn('crm_visits', 'visited_by_user_id')) {
            return ['SELECT NULL AS id WHERE 1 = 0', []];
        }
        $force = '';
        if ($visitedByUserId !== null && TableSchema::hasIndex('crm_visits', 'idx_visits_overdue')) {
            $force = 'FORCE INDEX (idx_visits_overdue)';
        } elseif ($visitedByUserId === null && TableSchema::hasIndex('crm_visits', 'idx_visits_touchpoint')) {
            $force = 'FORCE INDEX (idx_visits_touchpoint)';
        }
        $ownerJoin = TableSchema::leftJoinIfColumn(
            'crm_visits',
            'visited_by_user_id',
            'users',
            'u',
            'u.id = v.visited_by_user_id'
        );
        $sql = "SELECT v.*, p.name AS party_name, u.name AS visited_by_name
                FROM crm_visits v {$force}
                JOIN parties p ON p.id = v.party_id
                {$ownerJoin}
                WHERE v.next_planned_touchpoint IS NOT NULL
                  AND v.next_planned_touchpoint < CURDATE()";
        if (TableSchema::hasColumn('crm_visits', 'no_followup_needed')) {
            $sql .= ' AND v.no_followup_needed = 0';
        }
        $params = [];
        if ($visitedByUserId !== null) {
            $sql .= " AND v.visited_by_user_id = ?";
            $params[] = $visitedByUserId;
        }
        $laterVisit = TableSchema::hasColumn('crm_visits', 'visit_date')
            ? "AND later.visit_date > v.visit_date"
            : "AND later.id > v.id";
        $laterOrder = TableSchema::hasColumn('crm_visits', 'visit_date')
            ? "AND o.order_date > v.visit_date"
            : 'AND 1=0';
        $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM crm_visits later
                    WHERE later.party_id = v.party_id
                      {$laterVisit}
                 )
                 AND NOT EXISTS (
                    SELECT 1 FROM orders o
                    WHERE o.party_id = v.party_id
                      {$laterOrder}
                 )
                 ORDER BY v.next_planned_touchpoint ASC, v.id ASC";

        return [$sql, $params];
    }

    /** @return array<int,array<string,mixed>> */
    public function explainOverdue(?int $visitedByUserId): array
    {
        [$sql, $params] = $this->overdueSql($visitedByUserId);

        return $this->database->fetchAll('EXPLAIN ' . $sql, $params);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function hydrateContacts(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(fn(array $r) => (int)$r['id'], $rows);
        if (!TableSchema::hasTable('crm_visit_contacts') || !TableSchema::hasTable('crm_contacts')) {
            foreach ($rows as &$row) {
                $row['contacts'] = [];
            }

            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $links = $this->database->fetchAll(
            "SELECT vc.visit_id, c.id, c.name, c.role, c.phone
             FROM crm_visit_contacts vc
             JOIN crm_contacts c ON c.id = vc.contact_id
             WHERE vc.visit_id IN ({$placeholders})
             ORDER BY c.name",
            $ids
        );
        $byVisit = [];
        foreach ($links as $link) {
            $byVisit[(int)$link['visit_id']][] = [
                'id' => (int)$link['id'],
                'name' => $link['name'],
                'role' => $link['role'],
                'phone' => $link['phone'],
            ];
        }
        foreach ($rows as &$row) {
            $row['contacts'] = $byVisit[(int)$row['id']] ?? [];
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    private function contactsForVisit(int $visitId): array
    {
        if (!TableSchema::hasTable('crm_visit_contacts') || !TableSchema::hasTable('crm_contacts')) {
            return [];
        }
        return $this->database->fetchAll(
            "SELECT c.id, c.name, c.role, c.phone
             FROM crm_visit_contacts vc
             JOIN crm_contacts c ON c.id = vc.contact_id
             WHERE vc.visit_id = ?
             ORDER BY c.name",
            [$visitId]
        );
    }
}
