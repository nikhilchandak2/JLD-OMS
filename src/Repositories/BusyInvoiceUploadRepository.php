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
        if (!self::$schemaReady) {
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
                          invoice_date_from DATE NULL,
                          invoice_date_to DATE NULL,
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

                $this->ensureUploadColumn(
                    'invoice_date_from',
                    'ALTER TABLE busy_invoice_uploads ADD COLUMN invoice_date_from DATE NULL AFTER company_id'
                );
                $this->ensureUploadColumn(
                    'invoice_date_to',
                    'ALTER TABLE busy_invoice_uploads ADD COLUMN invoice_date_to DATE NULL AFTER invoice_date_from'
                );
            } catch (\Throwable $e) {
                error_log('BusyInvoiceUploadRepository::ensureSchema failed: ' . $e->getMessage());
                return;
            }

            self::$schemaReady = true;
        }

        // Always safe to re-run: only unlinked invoices + refresh date ranges
        $this->backfillLegacyUploads();
        $this->syncAllInvoiceDateRanges();
    }

    private function ensureUploadColumn(string $column, string $alterSql): void
    {
        $col = $this->database->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'busy_invoice_uploads'
               AND column_name = ?",
            [$column]
        );
        if ((int)($col['c'] ?? 0) > 0) {
            return;
        }
        try {
            $this->database->getConnection()->exec($alterSql);
        } catch (\Throwable $ignored) {
            // race / already exists
        }
    }

    private function hasUploadInvoiceDateColumns(): bool
    {
        $col = $this->database->fetch(
            "SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'busy_invoice_uploads'
               AND column_name = 'invoice_date_from'"
        );
        return ((int)($col['c'] ?? 0)) > 0;
    }

    /** Fill invoice_date_from/to on every upload from linked daily invoices. */
    public function syncAllInvoiceDateRanges(): void
    {
        if (!$this->hasUploadInvoiceDateColumns()) {
            return;
        }
        try {
            $this->database->execute(
                "UPDATE busy_invoice_uploads u
                 INNER JOIN (
                    SELECT upload_id,
                           MIN(invoice_date) AS dfrom,
                           MAX(invoice_date) AS dto
                    FROM busy_daily_invoices
                    WHERE upload_id IS NOT NULL
                    GROUP BY upload_id
                 ) x ON x.upload_id = u.id
                 SET u.invoice_date_from = x.dfrom,
                     u.invoice_date_to = x.dto"
            );
        } catch (\Throwable $e) {
            error_log('syncAllInvoiceDateRanges failed: ' . $e->getMessage());
        }
    }

    public function refreshInvoiceDatesForUpload(int $uploadId): void
    {
        if ($uploadId <= 0 || !$this->hasUploadInvoiceDateColumns()) {
            return;
        }
        $row = $this->database->fetch(
            "SELECT MIN(invoice_date) AS dfrom, MAX(invoice_date) AS dto
             FROM busy_daily_invoices
             WHERE upload_id = ?",
            [$uploadId]
        );
        if (!$row || empty($row['dfrom'])) {
            return;
        }
        $this->database->execute(
            'UPDATE busy_invoice_uploads SET invoice_date_from = ?, invoice_date_to = ? WHERE id = ?',
            [$row['dfrom'], $row['dto'], $uploadId]
        );
    }

    /**
     * Reconstruct upload history from daily invoices that were never linked to a batch.
     * Safe to re-run: only processes rows with upload_id IS NULL.
     */
    private function backfillLegacyUploads(): void
    {
        try {
            $dailyExists = $this->database->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'busy_daily_invoices'"
            );
            if ((int)($dailyExists['c'] ?? 0) === 0) {
                return;
            }

            $col = $this->database->fetch(
                "SELECT COUNT(*) AS c
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'busy_daily_invoices'
                   AND column_name = 'upload_id'"
            );
            $hasUploadId = ((int)($col['c'] ?? 0)) > 0;

            // Prefer unlinked invoices; if column missing, fall back only when table empty.
            if ($hasUploadId) {
                $unlinked = $this->database->fetch(
                    'SELECT COUNT(*) AS c FROM busy_daily_invoices WHERE upload_id IS NULL'
                );
                if ((int)($unlinked['c'] ?? 0) === 0) {
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
                            MIN(created_at) AS first_at,
                            MIN(invoice_date) AS invoice_date_from,
                            MAX(invoice_date) AS invoice_date_to
                     FROM busy_daily_invoices
                     WHERE upload_id IS NULL
                     GROUP BY DATE(created_at), uploaded_by, company_id
                     ORDER BY first_at ASC"
                );
            } else {
                $existing = $this->database->fetch('SELECT COUNT(*) AS c FROM busy_invoice_uploads');
                if ((int)($existing['c'] ?? 0) > 0) {
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
                            MIN(created_at) AS first_at,
                            MIN(invoice_date) AS invoice_date_from,
                            MAX(invoice_date) AS invoice_date_to
                     FROM busy_daily_invoices
                     GROUP BY DATE(created_at), uploaded_by, company_id
                     ORDER BY first_at ASC"
                );
            }

            $hasDateCols = $this->hasUploadInvoiceDateColumns();

            foreach ($batches as $batch) {
                $day = (string)($batch['upload_day'] ?? '');
                $count = (int)($batch['invoice_count'] ?? 0);
                if ($day === '' || $count <= 0) {
                    continue;
                }

                $invFrom = !empty($batch['invoice_date_from']) ? (string)$batch['invoice_date_from'] : null;
                $invTo = !empty($batch['invoice_date_to']) ? (string)$batch['invoice_date_to'] : null;
                $label = 'Earlier upload on ' . $day . ' (file not retained)';
                if ($invFrom && $invTo) {
                    $label = $invFrom === $invTo
                        ? 'Invoices dated ' . $invFrom . ' (file not retained)'
                        : 'Invoices ' . $invFrom . ' to ' . $invTo . ' (file not retained)';
                }

                if ($hasDateCols) {
                    $this->database->execute(
                        "INSERT INTO busy_invoice_uploads
                            (original_filename, file_type, stored_path, file_size, company_id,
                             invoice_date_from, invoice_date_to,
                             invoice_count, mapped_count, unmapped_count, failed_count,
                             status, parse_notes, uploaded_by, created_at)
                         VALUES (?, 'legacy', NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 'legacy', ?, ?, ?)",
                        [
                            $label,
                            !empty($batch['company_id']) ? (int)$batch['company_id'] : null,
                            $invFrom,
                            $invTo,
                            $count,
                            (int)($batch['mapped_count'] ?? 0),
                            (int)($batch['unmapped_count'] ?? 0),
                            (int)($batch['failed_count'] ?? 0),
                            'Reconstructed from daily invoice ledger — original CSV/PDF was not stored.',
                            !empty($batch['uploaded_by']) ? (int)$batch['uploaded_by'] : null,
                            (string)($batch['first_at'] ?? $day . ' 00:00:00'),
                        ]
                    );
                } else {
                    $this->database->execute(
                        "INSERT INTO busy_invoice_uploads
                            (original_filename, file_type, stored_path, file_size, company_id,
                             invoice_count, mapped_count, unmapped_count, failed_count,
                             status, parse_notes, uploaded_by, created_at)
                         VALUES (?, 'legacy', NULL, NULL, ?, ?, ?, ?, ?, 'legacy', ?, ?, ?)",
                        [
                            $label,
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
                $uploadId = (int)$this->database->lastInsertId();
                if ($uploadId <= 0 || !$hasUploadId) {
                    continue;
                }

                // Link the invoices in this batch so we don't recreate it next time
                $params = [$uploadId, $day];
                $sql = "UPDATE busy_daily_invoices
                        SET upload_id = ?
                        WHERE upload_id IS NULL
                          AND DATE(created_at) = ?";
                if (!empty($batch['uploaded_by'])) {
                    $sql .= ' AND uploaded_by = ?';
                    $params[] = (int)$batch['uploaded_by'];
                } else {
                    $sql .= ' AND uploaded_by IS NULL';
                }
                if (!empty($batch['company_id'])) {
                    $sql .= ' AND company_id = ?';
                    $params[] = (int)$batch['company_id'];
                } else {
                    $sql .= ' AND company_id IS NULL';
                }
                $this->database->execute($sql, $params);
            }
        } catch (\Throwable $e) {
            error_log('backfillLegacyUploads failed: ' . $e->getMessage());
        }
    }

    /**
     * @return int upload id
     */
    public function create(array $data): int
    {
        $this->ensureSchema();
        if ($this->hasUploadInvoiceDateColumns()) {
            $this->database->execute(
                "INSERT INTO busy_invoice_uploads
                    (original_filename, file_type, stored_path, file_size, company_id,
                     invoice_date_from, invoice_date_to,
                     invoice_count, mapped_count, unmapped_count, failed_count,
                     status, parse_notes, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['original_filename'],
                    $data['file_type'] ?? 'csv',
                    $data['stored_path'] ?? null,
                    $data['file_size'] ?? null,
                    $data['company_id'] ?? null,
                    $data['invoice_date_from'] ?? null,
                    $data['invoice_date_to'] ?? null,
                    (int)($data['invoice_count'] ?? 0),
                    (int)($data['mapped_count'] ?? 0),
                    (int)($data['unmapped_count'] ?? 0),
                    (int)($data['failed_count'] ?? 0),
                    $data['status'] ?? 'processed',
                    $data['parse_notes'] ?? null,
                    $data['uploaded_by'] ?? null,
                ]
            );
        } else {
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
        }

        return (int)$this->database->lastInsertId();
    }

    public function updateStats(int $uploadId, array $stats): void
    {
        $this->ensureSchema();
        if ($this->hasUploadInvoiceDateColumns()
            && (array_key_exists('invoice_date_from', $stats) || array_key_exists('invoice_date_to', $stats))) {
            $this->database->execute(
                "UPDATE busy_invoice_uploads
                 SET invoice_count = ?,
                     mapped_count = ?,
                     unmapped_count = ?,
                     failed_count = ?,
                     status = ?,
                     parse_notes = COALESCE(?, parse_notes),
                     invoice_date_from = COALESCE(?, invoice_date_from),
                     invoice_date_to = COALESCE(?, invoice_date_to)
                 WHERE id = ?",
                [
                    (int)($stats['invoice_count'] ?? 0),
                    (int)($stats['mapped_count'] ?? 0),
                    (int)($stats['unmapped_count'] ?? 0),
                    (int)($stats['failed_count'] ?? 0),
                    $stats['status'] ?? 'processed',
                    $stats['parse_notes'] ?? null,
                    $stats['invoice_date_from'] ?? null,
                    $stats['invoice_date_to'] ?? null,
                    $uploadId,
                ]
            );
            return;
        }

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
        $this->refreshInvoiceDatesForUpload($uploadId);
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
        
        if ($row && $this->hasUploadInvoiceDateColumns()) {
            // Get distinct invoice dates for this upload
            $dates = $this->database->fetchAll(
                "SELECT DISTINCT invoice_date 
                 FROM busy_daily_invoices 
                 WHERE upload_id = ? 
                 ORDER BY invoice_date",
                [$id]
            );
            $row['distinct_dates'] = array_column($dates, 'invoice_date');
            $row['date_count'] = count($row['distinct_dates']);
        }
        
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
        $hasDateCols = $this->hasUploadInvoiceDateColumns();

        if (!empty($filters['company_id'])) {
            $where[] = 'u.company_id = ?';
            $params[] = (int)$filters['company_id'];
        }

        // Date filters mean invoice dates (with upload-time fallback for older rows)
        $start = !empty($filters['start_date']) ? (string)$filters['start_date'] : '';
        $end = !empty($filters['end_date']) ? (string)$filters['end_date'] : '';
        if ($start !== '' || $end !== '') {
            if ($hasDateCols) {
                $parts = [];
                if ($start !== '' && $end !== '') {
                    // Overlap: invoice range intersects [start, end]
                    $parts[] = '(u.invoice_date_from IS NOT NULL AND u.invoice_date_to IS NOT NULL
                        AND u.invoice_date_from <= ? AND u.invoice_date_to >= ?)';
                    $params[] = $end;
                    $params[] = $start;
                    $parts[] = '(u.invoice_date_from IS NULL AND DATE(u.created_at) >= ? AND DATE(u.created_at) <= ?)';
                    $params[] = $start;
                    $params[] = $end;
                } elseif ($start !== '') {
                    $parts[] = '(u.invoice_date_to IS NOT NULL AND u.invoice_date_to >= ?)';
                    $params[] = $start;
                    $parts[] = '(u.invoice_date_to IS NULL AND DATE(u.created_at) >= ?)';
                    $params[] = $start;
                } else {
                    $parts[] = '(u.invoice_date_from IS NOT NULL AND u.invoice_date_from <= ?)';
                    $params[] = $end;
                    $parts[] = '(u.invoice_date_from IS NULL AND DATE(u.created_at) <= ?)';
                    $params[] = $end;
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            } else {
                if ($start !== '') {
                    $where[] = 'DATE(u.created_at) >= ?';
                    $params[] = $start;
                }
                if ($end !== '') {
                    $where[] = 'DATE(u.created_at) <= ?';
                    $params[] = $end;
                }
            }
        }

        if (!empty($filters['q'])) {
            $where[] = '(u.original_filename LIKE ? OR CAST(u.id AS CHAR) LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $totalRow = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM busy_invoice_uploads u WHERE {$whereSql}",
            $params
        );
        $total = (int)($totalRow['c'] ?? 0);

        $limit = isset($filters['limit']) ? max(1, min(500, (int)$filters['limit'])) : 100;
        $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;

        $orderBy = $hasDateCols
            ? 'COALESCE(u.invoice_date_from, DATE(u.created_at)) DESC, u.created_at DESC, u.id DESC'
            : 'u.created_at DESC, u.id DESC';

        $rows = $this->database->fetchAll(
            "SELECT u.*,
                    usr.name AS uploaded_by_name,
                    c.name AS company_name
             FROM busy_invoice_uploads u
             LEFT JOIN users usr ON usr.id = u.uploaded_by
             LEFT JOIN companies c ON c.id = u.company_id
             WHERE {$whereSql}
             ORDER BY {$orderBy}
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        // Add distinct date count for each upload if date columns exist
        if ($hasDateCols && !empty($rows)) {
            foreach ($rows as &$row) {
                if ($row['id']) {
                    $dates = $this->database->fetchAll(
                        "SELECT DISTINCT invoice_date 
                         FROM busy_daily_invoices 
                         WHERE upload_id = ? 
                         ORDER BY invoice_date",
                        [$row['id']]
                    );
                    $row['distinct_dates'] = array_column($dates, 'invoice_date');
                    $row['date_count'] = count($row['distinct_dates']);
                }
            }
        }

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
