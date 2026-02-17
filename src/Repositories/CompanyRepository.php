<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Company;

class CompanyRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function findAll(): array
    {
        $sql = "SELECT * FROM companies ORDER BY name ASC";
        $results = $this->database->fetchAll($sql);
        return array_map(fn($data) => new Company($data), $results);
    }

    public function findActive(): array
    {
        $sql = "SELECT * FROM companies WHERE status = 'active' ORDER BY name ASC";
        $results = $this->database->fetchAll($sql);
        return array_map(fn($data) => new Company($data), $results);
    }

    public function findById(int $id): ?Company
    {
        $sql = "SELECT * FROM companies WHERE id = ?";
        $result = $this->database->fetchOne($sql, [$id]);
        return $result ? new Company($result) : null;
    }

    public function findByCode(string $code): ?Company
    {
        $sql = "SELECT * FROM companies WHERE code = ?";
        $result = $this->database->fetchOne($sql, [$code]);
        return $result ? new Company($result) : null;
    }

    public function create(Company $company): int
    {
        $sql = "
            INSERT INTO companies (name, code, address, phone, email, contact_person, gst_number, pan_number, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $this->database->execute($sql, [
            $company->name,
            $company->code,
            $company->address,
            $company->phone,
            $company->email,
            $company->contactPerson,
            $company->gstNumber,
            $company->panNumber,
            $company->status
        ]);
        
        return (int)$this->database->lastInsertId();
    }

    public function update(Company $company): bool
    {
        $sql = "
            UPDATE companies 
            SET name = ?, code = ?, address = ?, phone = ?, email = ?, 
                contact_person = ?, gst_number = ?, pan_number = ?, status = ?
            WHERE id = ?
        ";
        
        return $this->database->execute($sql, [
            $company->name,
            $company->code,
            $company->address,
            $company->phone,
            $company->email,
            $company->contactPerson,
            $company->gstNumber,
            $company->panNumber,
            $company->status,
            $company->id
        ]);
    }

    public function delete(int $id): bool
    {
        // Soft delete by setting status to inactive
        $sql = "UPDATE companies SET status = 'inactive' WHERE id = ?";
        return $this->database->execute($sql, [$id]);
    }

    public function getCompanyStats(): array
    {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.code,
                COUNT(o.id) as total_orders,
                COALESCE(SUM(o.order_qty_trucks), 0) as total_trucks_ordered,
                COALESCE(SUM(d.qty_trucks), 0) as total_trucks_dispatched
            FROM companies c
            LEFT JOIN orders o ON c.id = o.company_id
            LEFT JOIN dispatches d ON o.id = d.order_id
            WHERE c.status = 'active'
            GROUP BY c.id, c.name, c.code
            ORDER BY c.name ASC
        ";
        
        return $this->database->fetchAll($sql);
    }
}



