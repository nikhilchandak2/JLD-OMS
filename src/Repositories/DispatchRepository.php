<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Dispatch;

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
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
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
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
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
                   u.name as dispatched_by_name
            FROM dispatches d
            JOIN orders o ON d.order_id = o.id
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
        $sql = "
            INSERT INTO dispatches (order_id, dispatch_date, dispatch_qty_trucks, vehicle_no, remarks, dispatched_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $dispatch->orderId,
            $dispatch->dispatchDate,
            $dispatch->dispatchQtyTrucks,
            $dispatch->vehicleNo,
            $dispatch->remarks,
            $dispatch->dispatchedBy
        ]);
        
        return (int)$this->database->lastInsertId();
    }
    
    public function update(Dispatch $dispatch): bool
    {
        $sql = "
            UPDATE dispatches 
            SET dispatch_date = ?, dispatch_qty_trucks = ?, vehicle_no = ?, remarks = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $dispatch->dispatchDate,
            $dispatch->dispatchQtyTrucks,
            $dispatch->vehicleNo,
            $dispatch->remarks,
            $dispatch->id
        ]);
    }
    
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM dispatches WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }
    
    public function getTotalDispatchedForOrder(int $orderId): int
    {
        $sql = "SELECT COALESCE(SUM(dispatch_qty_trucks), 0) as total FROM dispatches WHERE order_id = ?";
        $result = $this->database->fetch($sql, [$orderId]);
        return (int)$result['total'];
    }
    
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM dispatches d WHERE 1=1";
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
        
        $result = $this->database->fetch($sql, $params);
        return (int)$result['count'];
    }
}




