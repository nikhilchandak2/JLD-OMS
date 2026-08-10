<?php

namespace App\Repositories;

use App\Core\Database;

class BusyDailyInvoiceRepository
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * Insert or update by invoice_no (Busy invoice numbers are unique).
     *
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): int
    {
        $existing = $this->findByInvoiceNo((string)$data['invoice_no']);

        if ($existing) {
            $sql = "
                UPDATE busy_daily_invoices SET
                    invoice_date = ?,
                    party_name = ?,
                    product_name = ?,
                    product_rate = ?,
                    quantity_trucks = ?,
                    loading_weight_tons = ?,
                    vehicle_no = ?,
                    rawana_no = ?,
                    eway_bill_no = ?,
                    order_no_from_invoice = ?,
                    company_id = ?,
                    order_id = ?,
                    dispatch_id = ?,
                    mapping_status = ?,
                    error_message = ?,
                    uploaded_by = COALESCE(?, uploaded_by)
                WHERE id = ?
            ";
            $this->database->execute($sql, [
                $data['invoice_date'],
                $data['party_name'],
                $data['product_name'] ?? null,
                $data['product_rate'] ?? null,
                (int)($data['quantity_trucks'] ?? 1),
                $data['loading_weight_tons'] ?? null,
                $data['vehicle_no'] ?? null,
                $data['rawana_no'] ?? null,
                $data['eway_bill_no'] ?? null,
                $data['order_no_from_invoice'] ?? null,
                $data['company_id'] ?? null,
                $data['order_id'] ?? null,
                $data['dispatch_id'] ?? null,
                $data['mapping_status'] ?? 'unmapped',
                $data['error_message'] ?? null,
                $data['uploaded_by'] ?? null,
                (int)$existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $sql = "
            INSERT INTO busy_daily_invoices (
                invoice_no, invoice_date, party_name, product_name, product_rate,
                quantity_trucks, loading_weight_tons, vehicle_no, rawana_no, eway_bill_no,
                order_no_from_invoice, company_id, order_id, dispatch_id,
                mapping_status, error_message, uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $this->database->execute($sql, [
            $data['invoice_no'],
            $data['invoice_date'],
            $data['party_name'],
            $data['product_name'] ?? null,
            $data['product_rate'] ?? null,
            (int)($data['quantity_trucks'] ?? 1),
            $data['loading_weight_tons'] ?? null,
            $data['vehicle_no'] ?? null,
            $data['rawana_no'] ?? null,
            $data['eway_bill_no'] ?? null,
            $data['order_no_from_invoice'] ?? null,
            $data['company_id'] ?? null,
            $data['order_id'] ?? null,
            $data['dispatch_id'] ?? null,
            $data['mapping_status'] ?? 'unmapped',
            $data['error_message'] ?? null,
            $data['uploaded_by'] ?? null,
        ]);

        return (int)$this->database->lastInsertId();
    }

    public function findByInvoiceNo(string $invoiceNo): ?array
    {
        if ($invoiceNo === '') {
            return null;
        }
        $row = $this->database->fetch(
            'SELECT * FROM busy_daily_invoices WHERE invoice_no = ? LIMIT 1',
            [$invoiceNo]
        );
        return $row ?: null;
    }

    /**
     * Re-open a row that was wrongly flipped to error during an older remap run.
     * Does not change party/product/invoice fields or company_id.
     */
    public function ensureStillUnmapped(int $id, ?string $errorMessage = null): void
    {
        if ($id <= 0) {
            return;
        }
        if ($errorMessage !== null && $errorMessage !== '') {
            $this->database->execute(
                "UPDATE busy_daily_invoices
                 SET mapping_status = 'unmapped',
                     order_id = NULL,
                     dispatch_id = NULL,
                     error_message = ?
                 WHERE id = ?
                   AND mapping_status IN ('unmapped', 'error')",
                [$errorMessage, $id]
            );
            return;
        }
        $this->database->execute(
            "UPDATE busy_daily_invoices
             SET mapping_status = 'unmapped',
                 order_id = NULL,
                 dispatch_id = NULL
             WHERE id = ?
               AND mapping_status IN ('unmapped', 'error')",
            [$id]
        );
    }

    /** Bulk recovery: error → unmapped (invoice fields preserved). */
    public function reopenErrorsAsUnmapped(): void
    {
        $this->database->execute(
            "UPDATE busy_daily_invoices
             SET mapping_status = 'unmapped',
                 order_id = NULL,
                 dispatch_id = NULL
             WHERE mapping_status = 'error'"
        );
    }

    /**
     * Unmapped / error rows eligible for remapping after orders are created later.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findRemapCandidates(array $filters = []): array
    {
        $where = ["bdi.mapping_status IN ('unmapped', 'error')"];
        $params = [];

        $date = trim((string)($filters['date'] ?? ''));
        if ($date !== '') {
            $where[] = 'bdi.invoice_date = ?';
            $params[] = $date;
        } else {
            if (!empty($filters['start_date'])) {
                $where[] = 'bdi.invoice_date >= ?';
                $params[] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $where[] = 'bdi.invoice_date <= ?';
                $params[] = $filters['end_date'];
            }
        }

        if (!empty($filters['company_id'])) {
            $where[] = 'bdi.company_id = ?';
            $params[] = (int)$filters['company_id'];
        }

        if (!empty($filters['invoice_nos']) && is_array($filters['invoice_nos'])) {
            $nos = array_values(array_filter(array_map('strval', $filters['invoice_nos'])));
            if ($nos !== []) {
                $placeholders = implode(',', array_fill(0, count($nos), '?'));
                $where[] = "bdi.invoice_no IN ({$placeholders})";
                foreach ($nos as $no) {
                    $params[] = $no;
                }
            }
        }

        if (!empty($filters['after_id'])) {
            $where[] = 'bdi.id > ?';
            $params[] = (int)$filters['after_id'];
        }

        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 100;
        $limit = max(1, min(200, $limit));

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT bdi.*
            FROM busy_daily_invoices bdi
            WHERE {$whereSql}
            ORDER BY bdi.id ASC
            LIMIT {$limit}
        ";

        return $this->database->fetchAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, summary: array<string, int|float>}
     */
    public function findDaily(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        $date = trim((string)($filters['date'] ?? ''));
        if ($date !== '') {
            $where[] = 'bdi.invoice_date = ?';
            $params[] = $date;
        } else {
            if (!empty($filters['start_date'])) {
                $where[] = 'bdi.invoice_date >= ?';
                $params[] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $where[] = 'bdi.invoice_date <= ?';
                $params[] = $filters['end_date'];
            }
        }

        if (!empty($filters['mapping_status'])) {
            if ($filters['mapping_status'] === 'open') {
                // Unmapped + import/remap errors — anything still needing an order
                $where[] = "bdi.mapping_status IN ('unmapped', 'error')";
            } else {
                $where[] = 'bdi.mapping_status = ?';
                $params[] = $filters['mapping_status'];
            }
        }

        if (!empty($filters['company_id'])) {
            $where[] = 'bdi.company_id = ?';
            $params[] = (int)$filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(bdi.invoice_no LIKE ? OR bdi.party_name LIKE ? OR bdi.product_name LIKE ? OR o.order_no LIKE ?)';
            $q = '%' . $filters['search'] . '%';
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $whereSql = implode(' AND ', $where);

        $summaryRow = $this->database->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN bdi.mapping_status = 'mapped' THEN 1 ELSE 0 END) AS mapped,
                SUM(CASE WHEN bdi.mapping_status = 'unmapped' THEN 1 ELSE 0 END) AS unmapped,
                SUM(CASE WHEN bdi.mapping_status = 'error' THEN 1 ELSE 0 END) AS errors,
                COALESCE(SUM(bdi.quantity_trucks), 0) AS trucks,
                COALESCE(SUM(bdi.loading_weight_tons), 0) AS weight_tons
             FROM busy_daily_invoices bdi
             LEFT JOIN orders o ON bdi.order_id = o.id
             WHERE {$whereSql}",
            $params
        ) ?: [];

        $total = (int)($summaryRow['total'] ?? 0);

        $sql = "
            SELECT
                bdi.*,
                o.order_no,
                c.name AS company_name,
                u.name AS uploaded_by_name
            FROM busy_daily_invoices bdi
            LEFT JOIN orders o ON bdi.order_id = o.id
            LEFT JOIN companies c ON bdi.company_id = c.id
            LEFT JOIN users u ON bdi.uploaded_by = u.id
            WHERE {$whereSql}
            ORDER BY bdi.invoice_date DESC, bdi.id DESC
        ";

        $limitParams = $params;
        if (isset($filters['limit'])) {
            $sql .= ' LIMIT ?';
            $limitParams[] = (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= ' OFFSET ?';
                $limitParams[] = (int)$filters['offset'];
            }
        }

        $rows = $this->database->fetchAll($sql, $limitParams);

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => [
                'total' => $total,
                'mapped' => (int)($summaryRow['mapped'] ?? 0),
                'unmapped' => (int)($summaryRow['unmapped'] ?? 0),
                'errors' => (int)($summaryRow['errors'] ?? 0),
                'trucks' => (int)($summaryRow['trucks'] ?? 0),
                'weight_tons' => (float)($summaryRow['weight_tons'] ?? 0),
            ],
        ];
    }
}
