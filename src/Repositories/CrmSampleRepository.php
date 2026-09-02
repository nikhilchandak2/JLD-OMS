<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmSample;

class CrmSampleRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        if (!\App\Support\TableSchema::hasTable('crm_samples')) {
            return [];
        }
        $sql = "SELECT * FROM crm_samples WHERE 1=1";
        $params = [];
        if (!empty($filters['party_id'])) {
            $sql .= " AND party_id = ?";
            $params[] = $filters['party_id'];
        }
        if (!empty($filters['deal_id'])) {
            $sql .= " AND deal_id = ?";
            $params[] = $filters['deal_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        $sql .= " ORDER BY COALESCE(trial_date, dispatch_date, request_date) DESC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($params);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmSample($row);
        }
        return $list;
    }

    public function findById(int $id): ?CrmSample
    {
        if (!\App\Support\TableSchema::hasTable('crm_samples')) {
            return null;
        }
        $sql = "SELECT * FROM crm_samples WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmSample($row) : null;
    }

    public function create(CrmSample $sample): CrmSample
    {
        $sql = "INSERT INTO crm_samples (party_id, deal_id, sample_type, quantity_sent, request_date, dispatch_date, trial_date, status, outcome, technical_feedback, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $sample->partyId,
            $sample->dealId,
            $sample->sampleType,
            $sample->quantitySent,
            $sample->requestDate,
            $sample->dispatchDate,
            $sample->trialDate,
            $sample->status,
            $sample->outcome,
            $sample->technicalFeedback,
            $sample->createdBy,
        ]);
        $sample->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($sample->id);
    }

    public function update(int $id, array $data): ?CrmSample
    {
        $allowed = ['deal_id', 'sample_type', 'quantity_sent', 'request_date', 'dispatch_date', 'trial_date', 'status', 'outcome', 'technical_feedback'];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return $this->findById($id);
        }
        $values[] = $id;
        $sql = "UPDATE crm_samples SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($values);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_samples WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
