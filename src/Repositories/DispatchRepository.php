<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Dispatch;
use App\Support\DispatchSchema;

class DispatchRepository
{
    private Database $database;
    
    public function __construct()
    {
        $this->database = new Database();
    }
    
    public function findAll(array $filters = []): array
    {
        $sql = "
            SELECT d.*, 
                   o.order_no,
                   pt.name as party_name,
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON d.dispatched_by = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Apply filters
        if (!empty($filters['order_id'])) {
            $sql .= " AND d.order_id = ?";
            $params[] = $filters['order_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND d.dispatch_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND d.dispatch_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['status']) && DispatchSchema::hasDispatchStatusColumn()) {
            $sql .= " AND d.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND o.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        
        $sql .= " ORDER BY d.dispatch_date DESC, d.id DESC";
        
        // Add pagination
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int)$filters['limit'];
            
            if (isset($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int)$filters['offset'];
            }
        }
        
        $results = $this->database->fetchAll($sql, $params);
        
        return array_map(function($row) {
            return new Dispatch($row);
        }, $results);
    }
    
    public function findById(int $id): ?Dispatch
    {
        $sql = "
            SELECT d.*, 
                   o.order_no,
                   pt.name as party_name,
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON d.dispatched_by = u.id
            WHERE d.id = ?
        ";
        
        $result = $this->database->fetch($sql, [$id]);
        
        return $result ? new Dispatch($result) : null;
    }
    
    public function findByOrderId(int $orderId): array
    {
        $sql = "
            SELECT d.*, 
                   o.order_no,
                   pt.name as party_name,
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON d.dispatched_by = u.id
            WHERE d.order_id = ?
            ORDER BY d.dispatch_date DESC, d.id DESC
        ";
        
        $results = $this->database->fetchAll($sql, [$orderId]);
        
        return array_map(function($row) {
            return new Dispatch($row);
        }, $results);
    }
    
    public function create(Dispatch $dispatch): int
    {
        $values = [
            'order_id' => $dispatch->orderId,
            'dispatch_date' => $dispatch->dispatchDate,
            'dispatch_qty_trucks' => $dispatch->dispatchQtyTrucks,
            'vehicle_no' => $dispatch->vehicleNo,
            'remarks' => $dispatch->remarks,
            'dispatched_by' => $dispatch->dispatchedBy,
        ];
        $optional = [
            'status' => $dispatch->status ?? 'active',
            'source_dispatch_id' => $dispatch->sourceDispatchId,
            'product_rate' => $dispatch->productRate,
            'loading_weight_tons' => $dispatch->loadingWeightTons,
            'busy_invoice_no' => $dispatch->busyInvoiceNo,
            'rawana_no' => $dispatch->rawanaNo,
            'eway_bill_no' => $dispatch->ewayBillNo,
            'eway_bill_file_path' => $dispatch->ewayBillFilePath,
        ];
        foreach ($optional as $column => $value) {
            if (\App\Support\TableSchema::hasColumn('dispatches', $column)) {
                $values[$column] = $value;
            }
        }
        $columns = array_keys($values);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $this->database->execute(
            'INSERT INTO dispatches (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            array_values($values)
        );

        return (int)$this->database->lastInsertId();
    }

    public function updateLifecycle(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        foreach (['status', 'rejection_reason', 'transferred_to_dispatch_id', 'source_dispatch_id'] as $col) {
            if (array_key_exists($col, $data) && \App\Support\TableSchema::hasColumn('dispatches', $col)) {
                $fields[] = "{$col} = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($fields)) {
            return true;
        }

        $params[] = $id;
        $sql = 'UPDATE dispatches SET ' . implode(', ', $fields) . ' WHERE id = ?';
        return $this->database->execute($sql, $params);
    }
    
    public function update(Dispatch $dispatch): bool
    {
        $values = [
            'dispatch_date' => $dispatch->dispatchDate,
            'dispatch_qty_trucks' => $dispatch->dispatchQtyTrucks,
            'vehicle_no' => $dispatch->vehicleNo,
            'remarks' => $dispatch->remarks,
        ];
        $optional = [
            'product_rate' => $dispatch->productRate,
            'loading_weight_tons' => $dispatch->loadingWeightTons,
            'busy_invoice_no' => $dispatch->busyInvoiceNo,
            'rawana_no' => $dispatch->rawanaNo,
            'eway_bill_no' => $dispatch->ewayBillNo,
            'eway_bill_file_path' => $dispatch->ewayBillFilePath,
        ];
        foreach ($optional as $column => $value) {
            if (\App\Support\TableSchema::hasColumn('dispatches', $column)) {
                $values[$column] = $value;
            }
        }
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        $params[] = $dispatch->id;

        return $this->database->execute(
            'UPDATE dispatches SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }
    
    public function findByBusyInvoiceNo(string $invoiceNo): ?Dispatch
    {
        if ($invoiceNo === '' || !\App\Support\TableSchema::hasColumn('dispatches', 'busy_invoice_no')) {
            return null;
        }

        $sql = "
            SELECT d.*, o.order_no, pt.name as party_name, u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            JOIN parties pt ON o.party_id = pt.id
            LEFT JOIN users u ON d.dispatched_by = u.id
            WHERE d.busy_invoice_no = ?
            LIMIT 1
        ";

        $result = $this->database->fetch($sql, [$invoiceNo]);
        return $result ? new Dispatch($result) : null;
    }

    public function updateEwayBillFile(int $id, string $relativePath): bool
    {
        if (!DispatchSchema::hasEwayBillFileColumn()) {
            return false;
        }
        return $this->database->execute(
            'UPDATE dispatches SET eway_bill_file_path = ? WHERE id = ?',
            [$relativePath, $id]
        );
    }

    public function delete(int $id): bool
    {
        $sql = "DELETE FROM dispatches WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
    
    /** Trucks and dispatch count for today's date. */
    public function getDispatchedTodayTotals(?int $companyId = null): array
    {
        $activeWhere = DispatchSchema::activeDispatchWhere('d');
        $sql = "
            SELECT COUNT(*) AS dispatch_count,
                   COALESCE(SUM(d.dispatch_qty_trucks), 0) AS trucks
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
            WHERE d.dispatch_date = CURDATE() AND {$activeWhere}
        ";
        $params = [];
        if ($companyId !== null && $companyId > 0) {
            $sql .= " AND o.company_id = ?";
            $params[] = $companyId;
        }
        $row = $this->database->fetch($sql, $params);
        return [
            'dispatch_count' => (int)($row['dispatch_count'] ?? 0),
            'trucks' => (int)($row['trucks'] ?? 0),
        ];
    }

    public function getTotalDispatchedForOrder(int $orderId): int
    {
        $activeWhere = DispatchSchema::activeDispatchWhere();
        $sql = "SELECT COALESCE(SUM(dispatch_qty_trucks), 0) as total FROM dispatches WHERE order_id = ? AND {$activeWhere}";
        $result = $this->database->fetch($sql, [$orderId]);
        return (int)$result['total'];
    }
    
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM dispatches d JOIN orders o ON d.order_id = o.id WHERE 1=1";
        $params = [];
        
        // Apply same filters as findAll
        if (!empty($filters['order_id'])) {
            $sql .= " AND d.order_id = ?";
            $params[] = $filters['order_id'];
        }
        
        if (!empty($filters['start_date'])) {
            $sql .= " AND d.dispatch_date >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (!empty($filters['end_date'])) {
            $sql .= " AND d.dispatch_date <= ?";
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['status']) && DispatchSchema::hasDispatchStatusColumn()) {
            $sql .= " AND d.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND o.company_id = ?";
            $params[] = (int)$filters['company_id'];
        }
        
        $result = $this->database->fetch($sql, $params);
        return (int)$result['count'];
    }
}




