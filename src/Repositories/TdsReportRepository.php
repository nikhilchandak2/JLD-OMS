<?php

namespace App\Repositories;

use App\Core\Database;

class TdsReportRepository
{
    private Database $database;
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->database = new Database();
    }

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
                   AND table_name = 'tds_uploads'"
            );
            if ((int)($row['c'] ?? 0) > 0) {
                self::$schemaReady = true;
                return;
            }
        } catch (\Throwable $e) {
            // Fall through and try CREATE TABLE
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS tds_uploads (
              id INT AUTO_INCREMENT PRIMARY KEY,
              original_filename VARCHAR(255) NOT NULL,
              file_type VARCHAR(20) NOT NULL,
              period_label VARCHAR(120) NULL,
              period_from DATE NULL,
              period_to DATE NULL,
              rows_imported INT NOT NULL DEFAULT 0,
              uploaded_by INT NULL,
              parse_notes TEXT NULL,
              created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_tds_uploads_created (created_at),
              INDEX idx_tds_uploads_period (period_from, period_to)
            )",
            "CREATE TABLE IF NOT EXISTS tds_voucher_lines (
              id INT AUTO_INCREMENT PRIMARY KEY,
              upload_id INT NOT NULL,
              voucher_date DATE NULL,
              voucher_date_raw VARCHAR(40) NULL,
              voucher_no VARCHAR(80) NULL,
              particulars VARCHAR(255) NULL,
              item_details VARCHAR(255) NULL,
              material_centre VARCHAR(255) NOT NULL,
              qty DECIMAL(14, 3) NOT NULL DEFAULT 0,
              unit VARCHAR(40) NULL,
              price DECIMAL(14, 4) NOT NULL DEFAULT 0,
              amount DECIMAL(16, 2) NOT NULL DEFAULT 0,
              price_band ENUM('below_1000', '1000_1500', '1500_2000', '2000_plus') NOT NULL,
              INDEX idx_tds_lines_upload (upload_id),
              INDEX idx_tds_lines_centre (material_centre),
              INDEX idx_tds_lines_band (price_band),
              INDEX idx_tds_lines_date (voucher_date)
            )",
        ];

        foreach ($statements as $sql) {
            $this->database->getConnection()->exec($sql);
        }
        self::$schemaReady = true;
    }

    public function createUpload(
        string $originalFilename,
        string $fileType,
        ?string $periodLabel,
        ?string $periodFrom,
        ?string $periodTo,
        ?int $uploadedBy
    ): int {
        $this->database->execute(
            "INSERT INTO tds_uploads
                (original_filename, file_type, period_label, period_from, period_to, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$originalFilename, $fileType, $periodLabel, $periodFrom, $periodTo, $uploadedBy]
        );
        return (int)$this->database->lastInsertId();
    }

    public function updateUploadStats(int $uploadId, int $rowsImported, ?string $notes): void
    {
        $this->database->execute(
            "UPDATE tds_uploads SET rows_imported = ?, parse_notes = ? WHERE id = ?",
            [$rowsImported, $notes, $uploadId]
        );
    }

    /**
     * @param list<array{
     *   voucher_date:?string,voucher_date_raw:?string,voucher_no:?string,
     *   particulars:?string,item_details:?string,material_centre:string,
     *   qty:float,unit:?string,price:float,amount:float,price_band:string
     * }> $lines
     */
    public function insertLines(int $uploadId, array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        $sql = "INSERT INTO tds_voucher_lines
            (upload_id, voucher_date, voucher_date_raw, voucher_no, particulars, item_details,
             material_centre, qty, unit, price, amount, price_band)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $pdo = $this->database->getConnection();
        $stmt = $pdo->prepare($sql);
        $count = 0;

        foreach ($lines as $line) {
            $stmt->execute([
                $uploadId,
                $line['voucher_date'],
                $line['voucher_date_raw'],
                $line['voucher_no'],
                $line['particulars'],
                $line['item_details'],
                $line['material_centre'],
                $line['qty'],
                $line['unit'],
                $line['price'],
                $line['amount'],
                $line['price_band'],
            ]);
            $count++;
        }

        return $count;
    }

    public function listUploads(int $limit = 25): array
    {
        if (!\App\Support\TableSchema::hasTable('tds_uploads')) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        return $this->database->fetchAll(
            "SELECT u.*, us.name AS uploaded_by_name
             FROM tds_uploads u
             LEFT JOIN users us ON us.id = u.uploaded_by
             ORDER BY u.id DESC
             LIMIT {$limit}"
        );
    }

    public function findUpload(int $id): ?array
    {
        return $this->database->fetch(
            "SELECT u.*, us.name AS uploaded_by_name
             FROM tds_uploads u
             LEFT JOIN users us ON us.id = u.uploaded_by
             WHERE u.id = ?",
            [$id]
        );
    }

    public function deleteUpload(int $id): void
    {
        $this->database->execute("DELETE FROM tds_uploads WHERE id = ?", [$id]);
    }

    /**
     * Summary by Material Centre with cumulative price slabs (overlapping):
     * - above_1000: Price >= 1000 (no upper cap)
     * - above_1500: Price >= 1500 (no upper cap)
     * - above_2000: Price >= 2000 (no upper cap)
     *
     * A voucher with Price 2500 counts in all three slabs.
     *
     * @return list<array{
     *   material_centre:string,
     *   above_1000_qty:float,above_1000_amt:float,above_1000_n:int,
     *   above_1500_qty:float,above_1500_amt:float,above_1500_n:int,
     *   above_2000_qty:float,above_2000_amt:float,above_2000_n:int,
     *   total_qty:float,total_amt:float,total_n:int
     * }>
     */
    public function summaryByMaterialCentre(int $uploadId): array
    {
        $rows = $this->database->fetchAll(
            "SELECT
                material_centre,
                SUM(CASE WHEN price >= 1000 THEN qty ELSE 0 END) AS above_1000_qty,
                SUM(CASE WHEN price >= 1000 THEN amount ELSE 0 END) AS above_1000_amt,
                SUM(CASE WHEN price >= 1000 THEN 1 ELSE 0 END) AS above_1000_n,
                SUM(CASE WHEN price >= 1500 THEN qty ELSE 0 END) AS above_1500_qty,
                SUM(CASE WHEN price >= 1500 THEN amount ELSE 0 END) AS above_1500_amt,
                SUM(CASE WHEN price >= 1500 THEN 1 ELSE 0 END) AS above_1500_n,
                SUM(CASE WHEN price >= 2000 THEN qty ELSE 0 END) AS above_2000_qty,
                SUM(CASE WHEN price >= 2000 THEN amount ELSE 0 END) AS above_2000_amt,
                SUM(CASE WHEN price >= 2000 THEN 1 ELSE 0 END) AS above_2000_n,
                SUM(qty) AS total_qty,
                SUM(amount) AS total_amt,
                COUNT(*) AS total_n
             FROM tds_voucher_lines
             WHERE upload_id = ?
             GROUP BY material_centre
             ORDER BY material_centre ASC",
            [$uploadId]
        );

        return array_map(static function (array $r): array {
            return [
                'material_centre' => (string)$r['material_centre'],
                'above_1000_qty' => (float)$r['above_1000_qty'],
                'above_1000_amt' => (float)$r['above_1000_amt'],
                'above_1000_n' => (int)$r['above_1000_n'],
                'above_1500_qty' => (float)$r['above_1500_qty'],
                'above_1500_amt' => (float)$r['above_1500_amt'],
                'above_1500_n' => (int)$r['above_1500_n'],
                'above_2000_qty' => (float)$r['above_2000_qty'],
                'above_2000_amt' => (float)$r['above_2000_amt'],
                'above_2000_n' => (int)$r['above_2000_n'],
                'total_qty' => (float)$r['total_qty'],
                'total_amt' => (float)$r['total_amt'],
                'total_n' => (int)$r['total_n'],
            ];
        }, $rows);
    }

    /**
     * Cumulative slab totals (overlapping). Keys: above_1000, above_1500, above_2000.
     */
    public function bandTotals(int $uploadId): array
    {
        $row = $this->database->fetch(
            "SELECT
                SUM(CASE WHEN price >= 1000 THEN 1 ELSE 0 END) AS above_1000_n,
                COALESCE(SUM(CASE WHEN price >= 1000 THEN qty ELSE 0 END), 0) AS above_1000_qty,
                COALESCE(SUM(CASE WHEN price >= 1000 THEN amount ELSE 0 END), 0) AS above_1000_amt,
                SUM(CASE WHEN price >= 1500 THEN 1 ELSE 0 END) AS above_1500_n,
                COALESCE(SUM(CASE WHEN price >= 1500 THEN qty ELSE 0 END), 0) AS above_1500_qty,
                COALESCE(SUM(CASE WHEN price >= 1500 THEN amount ELSE 0 END), 0) AS above_1500_amt,
                SUM(CASE WHEN price >= 2000 THEN 1 ELSE 0 END) AS above_2000_n,
                COALESCE(SUM(CASE WHEN price >= 2000 THEN qty ELSE 0 END), 0) AS above_2000_qty,
                COALESCE(SUM(CASE WHEN price >= 2000 THEN amount ELSE 0 END), 0) AS above_2000_amt
             FROM tds_voucher_lines
             WHERE upload_id = ?",
            [$uploadId]
        ) ?? [];

        return [
            'above_1000' => [
                'n' => (int)($row['above_1000_n'] ?? 0),
                'qty' => (float)($row['above_1000_qty'] ?? 0),
                'amount' => (float)($row['above_1000_amt'] ?? 0),
            ],
            'above_1500' => [
                'n' => (int)($row['above_1500_n'] ?? 0),
                'qty' => (float)($row['above_1500_qty'] ?? 0),
                'amount' => (float)($row['above_1500_amt'] ?? 0),
            ],
            'above_2000' => [
                'n' => (int)($row['above_2000_n'] ?? 0),
                'qty' => (float)($row['above_2000_qty'] ?? 0),
                'amount' => (float)($row['above_2000_amt'] ?? 0),
            ],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listLines(
        int $uploadId,
        ?string $materialCentre = null,
        ?string $priceBand = null,
        int $limit = 5000,
        int $offset = 0
    ): array {
        $limit = max(1, min(20000, $limit));
        $offset = max(0, $offset);
        $where = ['upload_id = ?'];
        $params = [$uploadId];

        if ($materialCentre !== null && $materialCentre !== '' && $materialCentre !== 'all') {
            $where[] = 'material_centre = ?';
            $params[] = $materialCentre;
        }
        if ($priceBand !== null && $priceBand !== '' && $priceBand !== 'all') {
            // Cumulative filters (and legacy exclusive keys mapped to same thresholds)
            if (in_array($priceBand, ['above_1000', '1000_1500'], true)) {
                $where[] = 'price >= 1000';
            } elseif (in_array($priceBand, ['above_1500', '1500_2000'], true)) {
                $where[] = 'price >= 1500';
            } elseif (in_array($priceBand, ['above_2000', '2000_plus'], true)) {
                $where[] = 'price >= 2000';
            } elseif ($priceBand === 'below_1000') {
                $where[] = 'price < 1000';
            }
        }

        $sql = 'SELECT * FROM tds_voucher_lines WHERE ' . implode(' AND ', $where)
            . ' ORDER BY material_centre ASC, voucher_date ASC, id ASC'
            . " LIMIT {$limit} OFFSET {$offset}";

        return $this->database->fetchAll($sql, $params);
    }

    public function listMaterialCentres(int $uploadId): array
    {
        $rows = $this->database->fetchAll(
            "SELECT DISTINCT material_centre
             FROM tds_voucher_lines
             WHERE upload_id = ?
             ORDER BY material_centre ASC",
            [$uploadId]
        );
        return array_map(static fn(array $r) => (string)$r['material_centre'], $rows);
    }
}
