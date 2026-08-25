<?php

namespace App\Repositories;

use App\Core\Database;
use App\Support\TableSchema;
use App\Models\CrmTask;

class CrmTaskRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * @return CrmTask[]
     */
    public function findMine(int $assigneeId): array
    {
        if (!TableSchema::hasTable('crm_tasks')) {
            return [];
        }
        $statusOrder = TableSchema::hasColumn('crm_tasks', 'status')
            ? "CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,"
            : '';
        $sql = "SELECT t.*,
                       u.name AS assigned_to_name,
                       p.name AS party_name
                FROM crm_tasks t
                LEFT JOIN users u ON u.id = t.assigned_to
                LEFT JOIN parties p ON p.id = t.party_id
                WHERE t.assigned_to = ?
                ORDER BY 
                    {$statusOrder}
                    (t.due_date IS NULL) ASC,
                    t.due_date ASC,
                    t.id DESC";

        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$assigneeId]);

        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmTask($row);
        }
        return $list;
    }

    /**
     * @return CrmTask[]
     */
    public function findAll(): array
    {
        if (!TableSchema::hasTable('crm_tasks')) {
            return [];
        }
        $statusOrder = TableSchema::hasColumn('crm_tasks', 'status')
            ? "CASE WHEN t.status = 'pending' THEN 0 ELSE 1 END,"
            : '';
        $sql = "SELECT t.*,
                       u.name AS assigned_to_name,
                       p.name AS party_name
                FROM crm_tasks t
                LEFT JOIN users u ON u.id = t.assigned_to
                LEFT JOIN parties p ON p.id = t.party_id
                ORDER BY 
                    {$statusOrder}
                    (t.due_date IS NULL) ASC,
                    t.due_date ASC,
                    t.id DESC";

        $stmt = $this->database->getConnection()->query($sql);

        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmTask($row);
        }
        return $list;
    }

    public function create(CrmTask $task): CrmTask
    {
        $fields = ['title', 'description', 'party_id', 'due_date', 'assigned_to', 'created_by'];
        $values = [
            $task->title,
            $task->description,
            $task->partyId,
            $task->dueDate,
            $task->assignedTo,
            $task->createdBy,
        ];
        if (TableSchema::hasColumn('crm_tasks', 'status')) {
            $fields[] = 'status';
            $values[] = $task->status;
        }
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO crm_tasks (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';

        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);

        $task->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($task->id);
    }

    public function findById(int $id): ?CrmTask
    {
        $sql = "SELECT t.*,
                       u.name AS assigned_to_name,
                       p.name AS party_name
                FROM crm_tasks t
                LEFT JOIN users u ON u.id = t.assigned_to
                LEFT JOIN parties p ON p.id = t.party_id
                WHERE t.id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmTask($row) : null;
    }

    public function update(int $id, array $data): ?CrmTask
    {
        $allowed = ['title', 'description', 'party_id', 'due_date', 'status', 'assigned_to'];
        $fields = [];
        $values = [];

        foreach ($allowed as $f) {
            if (array_key_exists($f, $data) && ($f !== 'status' || TableSchema::hasColumn('crm_tasks', 'status'))) {
                $fields[] = $f . ' = ?';
                $values[] = $data[$f];
            }
        }

        if (empty($fields)) {
            return $this->findById($id);
        }

        $values[] = $id;
        $sql = "UPDATE crm_tasks SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);

        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_tasks WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}

