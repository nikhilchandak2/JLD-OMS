<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmSample;
use App\Support\TableSchema;

class CrmSampleRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(array $filters = []): array
    {
        if (!TableSchema::hasTable('crm_samples')) {
            return [];
        }
        $sql = "SELECT * FROM crm_samples WHERE 1=1";
        $params = [];
        if (!empty($filters['party_id'])) {
            $sql .= " AND party_id = ?";
            $params[] = $filters['party_id'];
        }
        if (!empty($filters['deal_id']) && TableSchema::hasColumn('crm_samples', 'deal_id')) {
            $sql .= " AND deal_id = ?";
            $params[] = $filters['deal_id'];
        }
        if (!empty($filters['status']) && TableSchema::hasColumn('crm_samples', 'status')) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        $orderCols = TableSchema::existingColumns('crm_samples', ['trial_date', 'dispatch_date', 'request_date']);
        $sql .= $orderCols !== []
            ? ' ORDER BY COALESCE(' . implode(', ', $orderCols) . ') DESC'
            : ' ORDER BY id DESC';
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
        if (!TableSchema::hasTable('crm_samples')) {
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
        $built = TableSchema::insertSql('crm_samples', [
            'party_id' => $sample->partyId,
            'deal_id' => $sample->dealId,
            'sample_type' => $sample->sampleType,
            'quantity_sent' => $sample->quantitySent,
            'request_date' => $sample->requestDate,
            'dispatch_date' => $sample->dispatchDate,
            'trial_date' => $sample->trialDate,
            'status' => $sample->status,
            'outcome' => $sample->outcome,
            'technical_feedback' => $sample->technicalFeedback,
            'created_by' => $sample->createdBy,
        ]);
        if ($built === null) {
            throw new \RuntimeException('crm_samples is not available.');
        }
        $stmt = $this->database->getConnection()->prepare($built[0]);
        $stmt->execute($built[1]);
        $sample->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($sample->id);
    }

    public function update(int $id, array $data): ?CrmSample
    {
        $allowed = ['deal_id', 'sample_type', 'quantity_sent', 'request_date', 'dispatch_date', 'trial_date', 'status', 'outcome', 'technical_feedback'];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data) && TableSchema::hasColumn('crm_samples', $f)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return $this->findById($id);
        }
        $values[] = $id;
        $updated = TableSchema::hasColumn('crm_samples', 'updated_at') ? ', updated_at = NOW()' : '';
        $sql = "UPDATE crm_samples SET " . implode(', ', $fields) . "{$updated} WHERE id = ?";
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
