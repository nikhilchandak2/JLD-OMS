<?php

namespace App\Repositories;

use App\Core\Database;
use App\Models\Company;
use App\Support\TableSchema;

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
        $sql = "SELECT * FROM companies";
        if (TableSchema::hasColumn('companies', 'status')) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY name ASC";
        $results = $this->database->fetchAll($sql);
        return array_map(fn($data) => new Company($data), $results);
    }

    /**
     * The active company carrying the most recent order, used as the landing company on login.
     * Alphabetical order puts whichever entity happens to sort first in front of the one people
     * actually trade under, which makes a fresh login look like an empty system.
     */
    public function findMostRecentlyTrading(): ?Company
    {
        $sql = "
            SELECT c.*
            FROM companies c
            LEFT JOIN orders o ON o.company_id = c.id
            WHERE " . (TableSchema::hasColumn('companies', 'status') ? "c.status = 'active'" : '1=1') . "
            GROUP BY c.id
            ORDER BY MAX(o.order_date) IS NULL ASC, MAX(o.order_date) DESC, COUNT(o.id) DESC, c.id ASC
            LIMIT 1
        ";
        $result = $this->database->fetch($sql);
        return $result ? new Company($result) : null;
    }

    public function findById(int $id): ?Company
    {
        $sql = "SELECT * FROM companies WHERE id = ?";
        $result = $this->database->fetch($sql, [$id]);
        return $result ? new Company($result) : null;
    }

    public function findByCode(string $code): ?Company
    {
        $sql = "SELECT * FROM companies WHERE code = ?";
        $result = $this->database->fetch($sql, [$code]);
        return $result ? new Company($result) : null;
    }

    public function create(Company $company): int
    {
        if ($company->orderPrefix === '' && $company->name !== '') {
            $company->orderPrefix = \App\Support\OrderPrefix::suggestFromName($company->name);
        }

        $fields = ['name', 'code', 'address', 'phone', 'email', 'contact_person', 'gst_number', 'pan_number'];
        $values = [
            $company->name,
            $company->code,
            $company->address,
            $company->phone,
            $company->email,
            $company->contactPerson,
            $company->gstNumber,
            $company->panNumber,
        ];
        if (TableSchema::hasColumn('companies', 'order_prefix')) {
            $fields[] = 'order_prefix';
            $values[] = $company->orderPrefix !== '' ? $company->orderPrefix : null;
        }
        if (TableSchema::hasColumn('companies', 'status')) {
            $fields[] = 'status';
            $values[] = $company->status;
        }
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $this->database->execute(
            'INSERT INTO companies (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')',
            $values
        );

        return (int)$this->database->lastInsertId();
    }

    public function update(Company $company): bool
    {
        $sets = [
            'name = ?', 'code = ?', 'address = ?', 'phone = ?', 'email = ?',
            'contact_person = ?', 'gst_number = ?', 'pan_number = ?',
        ];
        $values = [
            $company->name,
            $company->code,
            $company->address,
            $company->phone,
            $company->email,
            $company->contactPerson,
            $company->gstNumber,
            $company->panNumber,
        ];
        if (TableSchema::hasColumn('companies', 'order_prefix')) {
            $sets[] = 'order_prefix = ?';
            $values[] = $company->orderPrefix !== '' ? $company->orderPrefix : null;
        }
        if (TableSchema::hasColumn('companies', 'status')) {
            $sets[] = 'status = ?';
            $values[] = $company->status;
        }
        $values[] = $company->id;

        return $this->database->execute(
            'UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $values
        );
    }

    public function delete(int $id): bool
    {
        if (!TableSchema::hasColumn('companies', 'status')) {
            return false;
        }
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
            WHERE " . (TableSchema::hasColumn('companies', 'status') ? "c.status = 'active'" : '1=1') . "
            GROUP BY c.id, c.name, c.code
            ORDER BY c.name ASC
        ";
        
        return $this->database->fetchAll($sql);
    }
}



