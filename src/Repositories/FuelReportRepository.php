<?php

namespace App\Repositories;

use App\Core\Database;

class FuelReportRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    public function createUpload(
        string $category,
        string $originalFilename,
        string $fileType,
        ?string $storedPath,
        ?string $reportMonth,
        ?int $uploadedBy
    ): int {
        $this->database->execute(
            "INSERT INTO fuel_report_uploads
                (category, original_filename, file_type, stored_path, report_month, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$category, $originalFilename, $fileType, $storedPath, $reportMonth, $uploadedBy]
        );
        return (int)$this->database->lastInsertId();
    }

    public function updateUploadStats(int $uploadId, int $machinesFound, int $readingsSaved, ?string $notes): void
    {
        $this->database->execute(
            "UPDATE fuel_report_uploads
             SET machines_found = ?, readings_saved = ?, parse_notes = ?
             WHERE id = ?",
            [$machinesFound, $readingsSaved, $notes, $uploadId]
        );
    }

    public function findUploadsByCategory(string $category, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->database->fetchAll(
            "SELECT u.*, usr.name AS uploaded_by_name
             FROM fuel_report_uploads u
             LEFT JOIN users usr ON usr.id = u.uploaded_by
             WHERE u.category = ?
             ORDER BY COALESCE(u.report_month, u.created_at) DESC, u.created_at DESC
             LIMIT {$limit}",
            [$category]
        );
    }

    /**
     * Distinct months (YYYY-MM) that have readings in a category.
     * @return list<string>
     */
    public function listMonthsForCategory(string $category): array
    {
        $rows = $this->database->fetchAll(
            "SELECT DISTINCT DATE_FORMAT(r.reading_date, '%Y-%m') AS ym
             FROM fuel_daily_readings r
             INNER JOIN fuel_machines m ON m.id = r.machine_id
             WHERE m.category = ? AND r.reading_date IS NOT NULL
             ORDER BY ym DESC",
            [$category]
        );
        return array_values(array_filter(array_map(
            static fn($row) => (string)($row['ym'] ?? ''),
            $rows
        )));
    }

    /**
     * Distinct months for one machine.
     * @return list<string>
     */
    public function listMonthsForMachine(int $machineId): array
    {
        $rows = $this->database->fetchAll(
            "SELECT DISTINCT DATE_FORMAT(reading_date, '%Y-%m') AS ym
             FROM fuel_daily_readings
             WHERE machine_id = ? AND reading_date IS NOT NULL
             ORDER BY ym DESC",
            [$machineId]
        );
        return array_values(array_filter(array_map(
            static fn($row) => (string)($row['ym'] ?? ''),
            $rows
        )));
    }

    /**
     * Upsert machine by category + identity key. Returns machine id.
     */
    public function upsertMachine(
        string $category,
        ?string $name,
        ?string $serialNo,
        ?string $chassisNo,
        string $identityKey
    ): int {
        $existing = $this->database->fetch(
            "SELECT id, name, serial_no, chassis_no FROM fuel_machines
             WHERE category = ? AND identity_key = ?",
            [$category, $identityKey]
        );

        if ($existing) {
            $newName = $name ?: $existing['name'];
            $newSerial = $serialNo ?: $existing['serial_no'];
            $newChassis = $chassisNo ?: $existing['chassis_no'];
            $this->database->execute(
                "UPDATE fuel_machines
                 SET name = ?, serial_no = ?, chassis_no = ?, updated_at = NOW()
                 WHERE id = ?",
                [$newName, $newSerial, $newChassis, (int)$existing['id']]
            );
            return (int)$existing['id'];
        }

        $this->database->execute(
            "INSERT INTO fuel_machines (category, name, serial_no, chassis_no, identity_key)
             VALUES (?, ?, ?, ?, ?)",
            [$category, $name, $serialNo, $chassisNo, $identityKey]
        );
        return (int)$this->database->lastInsertId();
    }

    public function insertDailyReading(
        int $machineId,
        ?int $uploadId,
        ?string $readingDate,
        ?float $fuelLiters,
        ?float $workingHours,
        ?float $averageUsage,
        ?array $extra
    ): void {
        $this->database->execute(
            "INSERT INTO fuel_daily_readings
                (machine_id, upload_id, reading_date, fuel_consumed_liters, working_hours, average_usage, extra_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $machineId,
                $uploadId,
                $readingDate,
                $fuelLiters,
                $workingHours,
                $averageUsage,
                $extra !== null ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    /** Replace existing day when re-uploading the same month for a machine. */
    public function deleteReadingByMachineDate(int $machineId, string $readingDate): void
    {
        $this->database->execute(
            "DELETE FROM fuel_daily_readings WHERE machine_id = ? AND reading_date = ?",
            [$machineId, $readingDate]
        );
    }

    /**
     * Machines for a category with aggregates. Optional month filter YYYY-MM.
     */
    public function listMachinesWithStats(string $category, ?string $month = null): array
    {
        $monthOk = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month);

        if ($monthOk) {
            return $this->database->fetchAll(
                "SELECT
                    m.id,
                    m.category,
                    m.name,
                    m.serial_no,
                    m.chassis_no,
                    m.updated_at,
                    COUNT(r.id) AS reading_count,
                    1 AS months_count,
                    MIN(r.reading_date) AS first_reading_date,
                    MAX(r.reading_date) AS last_reading_date,
                    ROUND(COALESCE(SUM(r.fuel_consumed_liters), 0), 2) AS total_fuel_liters,
                    ROUND(COALESCE(SUM(r.working_hours), 0), 2) AS total_working_hours,
                    ROUND(
                        CASE
                            WHEN COALESCE(SUM(r.working_hours), 0) > 0
                            THEN COALESCE(SUM(r.fuel_consumed_liters), 0) / SUM(r.working_hours)
                            ELSE COALESCE(AVG(r.average_usage), 0)
                        END
                    , 4) AS avg_usage
                 FROM fuel_machines m
                 INNER JOIN fuel_daily_readings r ON r.machine_id = m.id
                    AND DATE_FORMAT(r.reading_date, '%Y-%m') = ?
                 WHERE m.category = ?
                 GROUP BY m.id, m.category, m.name, m.serial_no, m.chassis_no, m.updated_at
                 ORDER BY COALESCE(m.name, m.serial_no, m.chassis_no)",
                [$month, $category]
            );
        }

        return $this->database->fetchAll(
            "SELECT
                m.id,
                m.category,
                m.name,
                m.serial_no,
                m.chassis_no,
                m.updated_at,
                COUNT(r.id) AS reading_count,
                COUNT(DISTINCT DATE_FORMAT(r.reading_date, '%Y-%m')) AS months_count,
                MIN(r.reading_date) AS first_reading_date,
                MAX(r.reading_date) AS last_reading_date,
                ROUND(COALESCE(SUM(r.fuel_consumed_liters), 0), 2) AS total_fuel_liters,
                ROUND(COALESCE(SUM(r.working_hours), 0), 2) AS total_working_hours,
                ROUND(
                    CASE
                        WHEN COALESCE(SUM(r.working_hours), 0) > 0
                        THEN COALESCE(SUM(r.fuel_consumed_liters), 0) / SUM(r.working_hours)
                        ELSE COALESCE(AVG(r.average_usage), 0)
                    END
                , 4) AS avg_usage
             FROM fuel_machines m
             LEFT JOIN fuel_daily_readings r ON r.machine_id = m.id
             WHERE m.category = ?
             GROUP BY m.id, m.category, m.name, m.serial_no, m.chassis_no, m.updated_at
             ORDER BY COALESCE(m.name, m.serial_no, m.chassis_no)",
            [$category]
        );
    }

    /**
     * Category-level summary for optional month.
     */
    public function categorySummary(string $category, ?string $month = null): array
    {
        $monthOk = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month);

        if ($monthOk) {
            $row = $this->database->fetch(
                "SELECT
                    COUNT(DISTINCT m.id) AS machine_count,
                    COUNT(r.id) AS reading_count,
                    1 AS months_count,
                    ROUND(COALESCE(SUM(r.fuel_consumed_liters), 0), 2) AS total_fuel_liters,
                    ROUND(COALESCE(SUM(r.working_hours), 0), 2) AS total_working_hours
                 FROM fuel_machines m
                 INNER JOIN fuel_daily_readings r ON r.machine_id = m.id
                    AND DATE_FORMAT(r.reading_date, '%Y-%m') = ?
                 WHERE m.category = ?",
                [$month, $category]
            ) ?: [];
        } else {
            $row = $this->database->fetch(
                "SELECT
                    COUNT(DISTINCT m.id) AS machine_count,
                    COUNT(r.id) AS reading_count,
                    COUNT(DISTINCT DATE_FORMAT(r.reading_date, '%Y-%m')) AS months_count,
                    ROUND(COALESCE(SUM(r.fuel_consumed_liters), 0), 2) AS total_fuel_liters,
                    ROUND(COALESCE(SUM(r.working_hours), 0), 2) AS total_working_hours
                 FROM fuel_machines m
                 LEFT JOIN fuel_daily_readings r ON r.machine_id = m.id
                 WHERE m.category = ?",
                [$category]
            ) ?: [];
        }

        return [
            'machine_count' => (int)($row['machine_count'] ?? 0),
            'reading_count' => (int)($row['reading_count'] ?? 0),
            'months_count' => (int)($row['months_count'] ?? 0),
            'total_fuel_liters' => (float)($row['total_fuel_liters'] ?? 0),
            'total_working_hours' => (float)($row['total_working_hours'] ?? 0),
        ];
    }

    public function getMachineDailyReadings(int $machineId, ?string $month = null, int $limit = 400): array
    {
        $limit = max(1, min(1000, $limit));
        $params = [$machineId];
        $monthSql = '';
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $monthSql = " AND DATE_FORMAT(reading_date, '%Y-%m') = ?";
            $params[] = $month;
        }

        return $this->database->fetchAll(
            "SELECT *
             FROM fuel_daily_readings
             WHERE machine_id = ?{$monthSql}
             ORDER BY reading_date ASC, id ASC
             LIMIT {$limit}",
            $params
        );
    }

    public function findMachineById(int $id): ?array
    {
        $row = $this->database->fetch(
            "SELECT * FROM fuel_machines WHERE id = ?",
            [$id]
        );
        return $row ?: null;
    }

    public function categoryCounts(): array
    {
        $rows = $this->database->fetchAll(
            "SELECT category, COUNT(*) AS machine_count
             FROM fuel_machines
             GROUP BY category"
        );
        $out = ['kobelco' => 0, 'jcb' => 0, 'dumpers' => 0];
        foreach ($rows as $row) {
            $cat = (string)$row['category'];
            if (isset($out[$cat])) {
                $out[$cat] = (int)$row['machine_count'];
            }
        }
        return $out;
    }
}
