<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\CrmReceivableEntry;

class CrmReceivableEntryRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findByParty(int $partyId): array
    {
        $sql = "SELECT * FROM crm_receivable_entries WHERE party_id = ? ORDER BY entry_date DESC, id DESC";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$partyId]);
        $list = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $list[] = new CrmReceivableEntry($row);
        }
        return $list;
    }

    /** Outstanding = sum(invoices) - sum(payments) - sum(adjustments reducing balance) */
    public function getOutstandingForParty(int $partyId): float
    {
        $sql = "SELECT entry_type, SUM(amount) AS total FROM crm_receivable_entries WHERE party_id = ? GROUP BY entry_type";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$partyId]);
        $totals = ['invoice' => 0, 'payment' => 0, 'adjustment' => 0];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $totals[$row['entry_type']] = (float)$row['total'];
        }
        return $totals['invoice'] - $totals['payment'] + $totals['adjustment'];
    }

    public function findById(int $id): ?CrmReceivableEntry
    {
        $sql = "SELECT * FROM crm_receivable_entries WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? new CrmReceivableEntry($row) : null;
    }

    public function create(CrmReceivableEntry $entry): CrmReceivableEntry
    {
        $sql = "INSERT INTO crm_receivable_entries (party_id, entry_type, amount, entry_date, reference, description, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([
            $entry->partyId,
            $entry->entryType,
            $entry->amount,
            $entry->entryDate,
            $entry->reference,
            $entry->description,
            $entry->createdBy,
        ]);
        $entry->id = (int)$this->database->getConnection()->lastInsertId();
        return $this->findById($entry->id);
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM crm_receivable_entries WHERE id = ?";
        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
