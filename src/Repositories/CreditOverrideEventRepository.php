<?php

namespace App\Repositories;

use App\Core\Database;

class CreditOverrideEventRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function append(int $requestId, ?string $fromStatus, string $toStatus, ?int $actorUserId, ?string $note, string $occurredAt): void
    {
        $this->database->execute(
            "INSERT INTO credit_override_events (request_id, from_status, to_status, actor_user_id, note, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$requestId, $fromStatus, $toStatus, $actorUserId, $note, $occurredAt]
        );
    }

    public function findByRequest(int $requestId): array
    {
        return $this->database->fetchAll(
            "SELECT e.*, u.name AS actor_name
             FROM credit_override_events e
             LEFT JOIN users u ON u.id = e.actor_user_id
             WHERE e.request_id = ?
             ORDER BY e.occurred_at ASC, e.id ASC",
            [$requestId]
        );
    }
}
