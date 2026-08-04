<?php

namespace App\Repositories;

use App\Core\Database;

class BusyInvoiceUploadRepository
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
                   AND table_name = 'busy_invoice_uploads'"
            );
            if ((int)($row['c'] ?? 0) === 0) {
                $this->database->getConnection()->exec(
                    "CREATE TABLE IF NOT EXISTS busy_invoice_uploads (
                      id INT AUTO_INCREMENT PRIMARY KEY,
                      original_filename VARCHAR(255) NOT NULL,
                      file_type VARCHAR(20) NOT NULL DEFAULT 'csv',
                      stored_path VARCHAR(500) NULL,
                      file_size INT NULL,
                      company_id INT NULL,
                      invoice_count INT NOT NULL DEFAULT 0,
                      mapped_count INT NOT NULL DEFAULT 0,
                      unmapped_count INT NOT NULL DEFAULT 0,
                      failed_count INT NOT NULL DEFAULT 0,
                      status ENUM('processed', 'partial', 'failed', 'legacy') NOT NULL DEFAULT 'processed',
                      parse_notes TEXT NULL,
                      uploaded_by INT NULL,
                      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                      INDEX idx_busy_uploads_created (created_at),
                      INDEX idx_busy_uploads_status (status),
                      INDEX idx_busy_uploads_company (company_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
            }

            $col = $this->database->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'busy_daily_invoices'
                   AND column_name = 'upload_id'"
            );
            if ((int)($col['c'] ?? 0) === 0) {
                try {
                    $this->database->getConnection()->exec(
                        'ALTER TABLE busy_daily_invoices ADD COLUMN upload_id INT NULL AFTER uploaded_by'
                    );
                    $this->database->getConnection()->exec(
                        'ALTER TABLE busy_daily_invoices ADD INDEX idx_busy_daily_upload (upload_id)'
                    );
                } catch (\Throwable $ignored) {
                    // Column may already exist under race
                }
            }
        } catch (\Throwable $e) {
            // Leave schemaReady false so next call retries
            return;
        }

        self::$schemaReady = true;
        $this->backfillLegacyUploads();
    }

    /**
     * Create synthetic history rows from existing daily invoices so older uploads appear.
     */
    private function backfillLegacyUploads(): void
    {
        try {
            $existing = $this->database->fetch('SELECT COUNT(*) AS c FROM busy_invoice_uploads');
            if ((int)($existing['c'] ?? 0) > 0) {
                return;
            }

            $dailyExists = $this->database->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'busy_daily_invoices'"
            );
            if ((int)($dailyExists['c'] ?? 0) === 0) {
                return;
            }

            $batches = $this->database->fetchAll(
                "SELECT DATE(created_at) AS upload_day,
                        uploaded_by,
                        company_id,
                        COUNT(*) AS invoice_count,
                        SUM(CASE WHEN mapping_status = 'mapped' THEN 1 ELSE 0 END) AS mapped_count,
                        SUM(CASE WHEN mapping_status = 'unmapped' THEN 1 ELSE 0 END) AS unmapped_count,
                        SUM(CASE WHEN mapping_status = 'error' THEN 1 ELSE 0 END) AS failed_count,
                        MIN(created_at) AS first_at
                 FROM busy_daily_invoices
                 GROUP BY DATE(created_at), uploaded_by, company_id
                 ORDER BY first_at ASC"
            );

            foreach ($batches as $batch) {
                $day = (string)($batch['upload_day'] ?? '');
                $count = (int)($batch['invoice_count'] ?? 0);
                if ($day === '' || $count <= 0) {
                    continue;
                }
                $this->database->execute(
                    "INSERT INTO busy_invoice_uploads
                        (original_filename, file_type, stored_path, file_size, company_id,
                         invoice_count, mapped_count, unmapped_count, failed_count,
                         status, parse_notes, uploaded_by, created_at)
                     VALUES (?, 'legacy', NULL, NULL, ?, ?, ?, ?, ?, 'legacy', ?, ?, ?)",
                    [
                        'Earlier upload on ' . $day . ' (file not retained)',
                        !empty($batch['company_id']) ? (int)$batch['company_id'] : null,
                        $count,
                        (int)($batch['mapped_count'] ?? 0),
                        (int)($batch['unmapped_count'] ?? 0),
                        (int)($batch['failed_count'] ?? 0),
                        'Reconstructed from daily invoice ledger — original CSV/PDF was not stored.',
                        !empty($batch['uploaded_by']) ? (int)$batch['uploaded_by'] : null,
                        (string)($batch['first_at'] ?? $day . ' 00:00:00'),
                    ]
                );
            }
        } catch (\Throwable $ignored) {
            // Backfill is best-effort
        }
    }

    /**
     * @return int upload id
     */
    public function create(array $data): int
    {
        $this->ensureSchema();
        $this->database->execute(
            "INSERT INTO busy_invoice_uploads
                (original_filename, file_type, stored_path, file_size, company_id,
                 invoice_count, mapped_count, unmapped_count, failed_count,
                 status, parse_notes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['original_filename'],
                $data['file_type'] ?? 'csv',
                $data['stored_path'] ?? null,
                $data['file_size'] ?? null,
                $data['company_id'] ?? null,
                (int)($data['invoice_count'] ?? 0),
                (int)($data['mapped_count'] ?? 0),
                (int)($data['unmapped_count'] ?? 0),
                (int)($data['failed_count'] ?? 0),
                $data['status'] ?? 'processed',
                $data['parse_notes'] ?? null,
                $data['uploaded_by'] ?? null,
            ]
        );

        return (int)$this->database->lastInsertId();
    }

    public function updateStats(int $uploadId, array $stats): void
    {
        $this->ensureSchema();
        $this->database->execute(
            "UPDATE busy_invoice_uploads
             SET invoice_count = ?,
                 mapped_count = ?,
                 unmapped_count = ?,
                 failed_count = ?,
                 status = ?,
                 parse_notes = COALESCE(?, parse_notes)
             WHERE id = ?",
            [
                (int)($stats['invoice_count'] ?? 0),
                (int)($stats['mapped_count'] ?? 0),
                (int)($stats['unmapped_count'] ?? 0),
                (int)($stats['failed_count'] ?? 0),
                $stats['status'] ?? 'processed',
                $stats['parse_notes'] ?? null,
                $uploadId,
            ]
        );
    }

    /**
     * @param list<string> $invoiceNos
     */
    public function linkInvoices(int $uploadId, array $invoiceNos): void
    {
        $this->ensureSchema();
        $invoiceNos = array_values(array_filter(array_map(
            static fn($n) => trim((string)$n),
            $invoiceNos
        ), static fn($n) => $n !== ''));
        if ($uploadId <= 0 || $invoiceNos === []) {
            return;
        }

        $col = $this->database->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'busy_daily_invoices'
               AND column_name = 'upload_id'"
        );
        if ((int)($col['c'] ?? 0) === 0) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($invoiceNos), '?'));
        $params = array_merge([$uploadId], $invoiceNos);
        $this->database->execute(
            "UPDATE busy_daily_invoices SET upload_id = ? WHERE invoice_no IN ({$placeholders})",
            $params
        );
    }

    public function findById(int $id): ?array
    {
        $this->ensureSchema();
        $row = $this->database->fetch(
            "SELECT u.*,
                    usr.name AS uploaded_by_name,
                    c.name AS company_name
             FROM busy_invoice_uploads u
             LEFT JOIN users usr ON usr.id = u.uploaded_by
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE u.id = ?
             LIMIT 1",
            [$id]
        );
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findAll(array $filters = []): array
    {
        $this->ensureSchema();
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['company_id'])) {
            $where[] = 'u.company_id = ?';
            $params[] = (int)$filters['company_id'];
        }
        if (!empty($filters['start_date'])) {
            $where[] = 'DATE(u.created_at) >= ?';
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $where[] = 'DATE(u.created_at) <= ?';
            $params[] = $filters['end_date'];
        }
        if (!empty($filters['q'])) {
            $where[] = 'u.original_filename LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $totalRow = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM busy_invoice_uploads u WHERE {$whereSql}",
            $params
        );
        $total = (int)($totalRow['c'] ?? 0);

        $limit = isset($filters['limit']) ? max(1, min(200, (int)$filters['limit'])) : 50;
        $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;

        $rows = $this->database->fetchAll(
            "SELECT u.*,
                    usr.name AS uploaded_by_name,
                    c.name AS company_name
             FROM busy_invoice_uploads u
             LEFT JOIN users usr ON usr.id = u.uploaded_by
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE {$whereSql}
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function storageDir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'busy-invoice-uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Persist uploaded file under storage/busy-invoice-uploads/.
     * @return string relative path from project root (forward slashes)
     */
    public function storeUploadedFile(string $tmpPath, string $originalFilename, string $ext): string
    {
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalFilename, PATHINFO_FILENAME)) ?: 'busy_invoice';
        $safeBase = substr($safeBase, 0, 80);
        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
        $dest = $this->storageDir() . DIRECTORY_SEPARATOR . $name;
        // Prefer copy — tmp may still be needed / already read by parser
        if (!@copy($tmpPath, $dest)) {
            if (!@move_uploaded_file($tmpPath, $dest)) {
                throw new \RuntimeException('Could not store uploaded invoice file.');
            }
        }
        return 'storage/busy-invoice-uploads/' . $name;
    }
}
