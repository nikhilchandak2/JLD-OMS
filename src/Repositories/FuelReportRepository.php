<?php

namespace App\Repositories;

use App\Core\Database;

class FuelReportRepository
{
    private Database $database;
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * Create fuel report tables if missing (safe for portals that skip migrate.php).
     */
    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        try {
            $row = $this->database->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'fuel_report_uploads'"
            );
            if ((int)($row['c'] ?? 0) > 0) {
                self::$schemaReady = true;
                $this->syncKnownKobelcoNames();
                $this->syncKnownJcbNames();
                return;
            }
        } catch (\Throwable $e) {
            // Fall through and try CREATE TABLE
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS fuel_report_uploads (
              id INT AUTO_INCREMENT PRIMARY KEY,
              category ENUM('kobelco', 'jcb', 'dumpers') NOT NULL,
              original_filename VARCHAR(255) NOT NULL,
              file_type VARCHAR(20) NOT NULL,
              stored_path VARCHAR(500) NULL,
              report_month DATE NULL,
              uploaded_by INT NULL,
              machines_found INT NOT NULL DEFAULT 0,
              readings_saved INT NOT NULL DEFAULT 0,
              parse_notes TEXT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_fuel_uploads_category (category),
              INDEX idx_fuel_uploads_month (report_month)
            )",
            "CREATE TABLE IF NOT EXISTS fuel_machines (
              id INT AUTO_INCREMENT PRIMARY KEY,
              category ENUM('kobelco', 'jcb', 'dumpers') NOT NULL,
              name VARCHAR(255) NULL,
              serial_no VARCHAR(120) NULL,
              chassis_no VARCHAR(120) NULL,
              identity_key VARCHAR(255) NOT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              UNIQUE KEY uq_fuel_machine_identity (category, identity_key),
              INDEX idx_fuel_machines_category (category)
            )",
            "CREATE TABLE IF NOT EXISTS fuel_daily_readings (
              id INT AUTO_INCREMENT PRIMARY KEY,
              machine_id INT NOT NULL,
              upload_id INT NULL,
              reading_date DATE NULL,
              fuel_consumed_liters DECIMAL(12, 2) NULL,
              working_hours DECIMAL(10, 2) NULL,
              average_usage DECIMAL(12, 4) NULL,
              extra_json JSON NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_fuel_readings_machine (machine_id),
              INDEX idx_fuel_readings_date (reading_date),
              INDEX idx_fuel_readings_upload (upload_id)
            )",
        ];

        foreach ($statements as $sql) {
            $this->database->getConnection()->exec($sql);
        }
        self::$schemaReady = true;
        $this->syncKnownKobelcoNames();
        $this->syncKnownJcbNames();
    }

    /** Apply fixed site names for known Kobelco serial numbers. */
    public function syncKnownKobelcoNames(): void
    {
        foreach (\App\Services\FuelReportImportService::KOBELCO_MACHINE_NAMES as $serial => $label) {
            $this->database->execute(
                "UPDATE fuel_machines
                 SET name = ?, updated_at = NOW()
                 WHERE category = 'kobelco' AND UPPER(TRIM(serial_no)) = ?",
                [$label, strtoupper($serial)]
            );
        }
    }

    /** Apply fixed site names for known JCB chassis / Asset IDs. */
    public function syncKnownJcbNames(): void
    {
        foreach (\App\Services\FuelReportImportService::JCB_MACHINE_NAMES as $chassis => $label) {
            $this->database->execute(
                "UPDATE fuel_machines
                 SET name = ?, updated_at = NOW()
                 WHERE category = 'jcb' AND UPPER(TRIM(chassis_no)) = ?",
                [$label, strtoupper($chassis)]
            );
        }
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

    public function findUploadById(int $uploadId): ?array
    {
        $row = $this->database->fetch(
            "SELECT * FROM fuel_report_uploads WHERE id = ?",
            [$uploadId]
        );
        return $row ?: null;
    }

    /**
     * Delete an upload, its readings, orphaned machines, and stored file.
     * @return array{success: bool, readings_deleted: int, machines_deleted: int, error?: string}
     */
    public function deleteUpload(int $uploadId): array
    {
        $upload = $this->findUploadById($uploadId);
        if (!$upload) {
            return ['success' => false, 'readings_deleted' => 0, 'machines_deleted' => 0, 'error' => 'Upload not found'];
        }

        $machineRows = $this->database->fetchAll(
            "SELECT DISTINCT machine_id FROM fuel_daily_readings WHERE upload_id = ?",
            [$uploadId]
        );
        $machineIds = array_map(static fn($r) => (int)$r['machine_id'], $machineRows);

        $readingCountRow = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM fuel_daily_readings WHERE upload_id = ?",
            [$uploadId]
        );
        $readingsDeleted = (int)($readingCountRow['c'] ?? 0);

        $this->database->execute(
            "DELETE FROM fuel_daily_readings WHERE upload_id = ?",
            [$uploadId]
        );

        $machinesDeleted = 0;
        foreach ($machineIds as $machineId) {
            if ($machineId <= 0) {
                continue;
            }
            $left = $this->database->fetch(
                "SELECT COUNT(*) AS c FROM fuel_daily_readings WHERE machine_id = ?",
                [$machineId]
            );
            if ((int)($left['c'] ?? 0) === 0) {
                $this->database->execute("DELETE FROM fuel_machines WHERE id = ?", [$machineId]);
                $machinesDeleted++;
            }
        }

        $storedPath = trim((string)($upload['stored_path'] ?? ''));
        if ($storedPath !== '') {
            $fullPath = dirname(__DIR__, 2) . '/' . ltrim(str_replace('\\', '/', $storedPath), '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }

        $this->database->execute("DELETE FROM fuel_report_uploads WHERE id = ?", [$uploadId]);

        return [
            'success' => true,
            'readings_deleted' => $readingsDeleted,
            'machines_deleted' => $machinesDeleted,
            'category' => (string)$upload['category'],
            'original_filename' => (string)$upload['original_filename'],
        ];
    }

    public function findUploadsByCategory(string $category, int $limit = 20): array
    {
        if (!\App\Support\TableSchema::hasTable('fuel_report_uploads')) {
            return [];
        }
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
        if (!\App\Support\TableSchema::hasTable('fuel_daily_readings')) {
            return [];
        }
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
        if (!\App\Support\TableSchema::hasTable('fuel_machines')) {
            return [];
        }
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

        $rows = $this->database->fetchAll(
            "SELECT *
             FROM fuel_daily_readings
             WHERE machine_id = ?{$monthSql}
             ORDER BY reading_date ASC, id ASC
             LIMIT {$limit}",
            $params
        );

        return $this->hydrateReadingExtras($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function hydrateReadingExtras(array $rows): array
    {
        foreach ($rows as &$row) {
            $extra = [];
            if (!empty($row['extra_json'])) {
                $decoded = json_decode((string)$row['extra_json'], true);
                if (is_array($decoded)) {
                    $extra = $decoded;
                }
            }
            $row['extra'] = $extra;
            $vendor = strtolower((string)($extra['vendor'] ?? ''));
            $row['working_hrs_display'] = isset($extra['working_hrs_display'])
                ? $this->formatMax2Decimals((string)$extra['working_hrs_display'])
                : ($vendor === 'jcb' || $vendor === 'dumpers'
                    ? $this->formatMax2Decimals(isset($row['working_hours']) ? (string)$row['working_hours'] : null)
                    : $this->decimalHoursToHhmm(isset($row['working_hours']) ? (float)$row['working_hours'] : null));
            $row['fuel_display'] = isset($extra['fuel_display'])
                ? $this->formatMax2Decimals((string)$extra['fuel_display'])
                : $this->formatFuelDisplay(isset($row['fuel_consumed_liters']) ? (float)$row['fuel_consumed_liters'] : null);
            $row['avg_display'] = isset($extra['avg_display'])
                ? $this->formatMax2Decimals((string)$extra['avg_display'])
                : $this->formatAvgDisplay(isset($row['average_usage']) ? (float)$row['average_usage'] : null);
            $row['engine_on_display'] = isset($extra['engine_on_display'])
                ? $this->formatMax2Decimals((string)$extra['engine_on_display'])
                : null;
            $row['idle_display'] = isset($extra['idle_display'])
                ? $this->formatMax2Decimals((string)$extra['idle_display'])
                : null;
            $row['distance_display'] = isset($extra['distance_display'])
                ? $this->formatMax2Decimals((string)$extra['distance_display'])
                : null;
            $row['mileage_display'] = isset($extra['mileage_display'])
                ? $this->formatMax2Decimals((string)$extra['mileage_display'])
                : null;
            $row['idle_fuel_display'] = isset($extra['idle_fuel_display'])
                ? $this->formatMax2Decimals((string)$extra['idle_fuel_display'])
                : null;
            $row['halt_display'] = isset($extra['halt_display'])
                ? $this->formatMax2Decimals((string)$extra['halt_display'])
                : null;
            $row['vendor'] = $vendor !== '' ? $vendor : null;
        }
        unset($row);
        return $rows;
    }

    private function decimalHoursToHhmm(?float $hours): ?string
    {
        if ($hours === null || !is_finite($hours)) {
            return null;
        }
        $totalMinutes = (int)round($hours * 60);
        $h = intdiv($totalMinutes, 60);
        $m = $totalMinutes % 60;
        return sprintf('%d:%02d', $h, $m);
    }

    private function formatFuelDisplay(?float $liters): ?string
    {
        if ($liters === null || !is_finite($liters)) {
            return null;
        }
        return $this->formatMax2Decimals((string)$liters) . ' L';
    }

    private function formatAvgDisplay(?float $avg): ?string
    {
        if ($avg === null || !is_finite($avg)) {
            return null;
        }
        return $this->formatMax2Decimals((string)$avg) . ' L/h';
    }

    /** Round numeric prefix to max 2 decimals; keep HH:MM and unit suffixes. */
    private function formatMax2Decimals(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,4}:\d{2}(:\d{2})?$/', $value)) {
            return $value;
        }
        if (preg_match('/^(-?\d+(?:\.\d+)?)(.*)$/', $value, $m)) {
            $num = round((float)$m[1], 2);
            $formatted = rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
            if ($formatted === '' || $formatted === '-') {
                $formatted = '0';
            }
            return $formatted . ($m[2] ?? '');
        }
        return $value;
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
