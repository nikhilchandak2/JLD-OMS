<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;

class CreditOverrideEventRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function append(int $requestId, ?string $fromStatus, string $toStatus, ?int $actorUserId, ?string $note, string $occurredAt): void
    {
        $built = TableSchema::insertSql('credit_override_events', [
            'request_id' => $requestId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actorUserId,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);
        if ($built === null) {
            return;
        }
        $this->database->execute($built[0], $built[1]);
    }

    public function findByRequest(int $requestId): array
    {
        if (!TableSchema::hasTable('credit_override_events')) {
            return [];
        }
        $actorJoin = TableSchema::leftJoinIfColumn(
            'credit_override_events',
            'actor_user_id',
            'users',
            'u',
            'u.id = e.actor_user_id'
        );
        $order = TableSchema::hasColumn('credit_override_events', 'occurred_at')
            ? 'e.occurred_at ASC, e.id ASC'
            : 'e.id ASC';

        return $this->database->fetchAll(
            "SELECT e.*, u.name AS actor_name
             FROM credit_override_events e
             {$actorJoin}
             WHERE e.request_id = ?
             ORDER BY {$order}",
            [$requestId]
        );
    }
}
