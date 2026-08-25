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
        $this->database->execute(
            "INSERT INTO crm_visits (
                party_id, deal_id, visited_by_user_id, visit_date, purpose, outcome,
                next_planned_touchpoint, next_action, no_followup_needed, no_followup_reason, logged_via
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['party_id'],
                $data['deal_id'],
                $data['visited_by_user_id'],
                $data['visit_date'],
                $data['purpose'],
                $data['outcome'],
                $data['next_planned_touchpoint'],
                $data['next_action'],
                !empty($data['no_followup_needed']) ? 1 : 0,
                $data['no_followup_reason'],
                $data['logged_via'],
            ]
        );

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
        $row = $this->database->fetch(
            "SELECT v.*, p.name AS party_name, u.name AS visited_by_name
             FROM crm_visits v
             JOIN parties p ON p.id = v.party_id
             LEFT JOIN users u ON u.id = v.visited_by_user_id
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
        $rows = $this->database->fetchAll(
            "SELECT v.*, u.name AS visited_by_name
             FROM crm_visits v
             LEFT JOIN users u ON u.id = v.visited_by_user_id
             WHERE v.party_id = ?
             ORDER BY v.visit_date DESC, v.id DESC",
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
        if (!TableSchema::hasTable('crm_visits')) {
            return ['SELECT NULL AS id WHERE 1 = 0', []];
        }
        $force = '';
        if ($visitedByUserId !== null && TableSchema::hasIndex('crm_visits', 'idx_visits_overdue')) {
            $force = 'FORCE INDEX (idx_visits_overdue)';
        } elseif ($visitedByUserId === null && TableSchema::hasIndex('crm_visits', 'idx_visits_touchpoint')) {
            $force = 'FORCE INDEX (idx_visits_touchpoint)';
        }
        $sql = "SELECT v.*, p.name AS party_name, u.name AS visited_by_name
                FROM crm_visits v {$force}
                JOIN parties p ON p.id = v.party_id
                LEFT JOIN users u ON u.id = v.visited_by_user_id
                WHERE v.next_planned_touchpoint IS NOT NULL
                  AND v.next_planned_touchpoint < CURDATE()
                  AND v.no_followup_needed = 0";
        $params = [];
        if ($visitedByUserId !== null) {
            $sql .= " AND v.visited_by_user_id = ?";
            $params[] = $visitedByUserId;
        }
        $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM crm_visits later
                    WHERE later.party_id = v.party_id
                      AND later.visit_date > v.visit_date
                 )
                 AND NOT EXISTS (
                    SELECT 1 FROM orders o
                    WHERE o.party_id = v.party_id
                      AND o.order_date > v.visit_date
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
