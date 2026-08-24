<?php

namespace Tests;

use App\Services\DataFeedIngestService;
use App\Services\DataFreshnessService;
use App\Services\PartyAliasService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

abstract class DataFeedTestCase extends DatabaseTestCase
{
    protected DataFeedIngestService $ingest;
    protected DataFreshnessService $freshness;
    protected PartyAliasService $aliases;
    protected array $admin;
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ingest = new DataFeedIngestService();
        $this->freshness = new DataFreshnessService();
        $this->aliases = new PartyAliasService();
        $this->admin = $this->actor('admin');
        $this->companyId = $this->createCompany();
    }

    protected function actor(string $role): array
    {
        $user = $this->createUser($role);

        return ['id' => $user['id'], 'role' => $role];
    }

    protected function ledgerCsv(array $rows): string
    {
        $lines = ['party_name,party_code,outstanding_amount,invoice_no,invoice_date'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [
                $row['party_name'] ?? '',
                $row['party_code'] ?? '',
                $row['outstanding_amount'] ?? '',
                $row['invoice_no'] ?? '',
                $row['invoice_date'] ?? '',
            ]);
        }

        return implode("\n", $lines);
    }

    protected function dispatchCsv(array $rows): string
    {
        $lines = ['party_name,party_code,grade_code,quantity_tonnes,vehicle_no,destination,invoice_no,dispatch_date'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [
                $row['party_name'] ?? '',
                $row['party_code'] ?? '',
                $row['grade_code'] ?? '',
                $row['quantity_tonnes'] ?? '',
                $row['vehicle_no'] ?? '',
                $row['destination'] ?? '',
                $row['invoice_no'] ?? '',
                $row['dispatch_date'] ?? '',
            ]);
        }

        return implode("\n", $lines);
    }

    protected function xlsxFromCsvRows(array $headers, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$headers], $rows), null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'jldxlsx_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $content = file_get_contents($tmp);
        @unlink($tmp);

        return $content === false ? '' : $content;
    }

    protected function liveLedgerCount(?int $companyId = null): int
    {
        $companyId = $companyId ?? $this->companyId;
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM ledger_outstanding WHERE company_id = ?",
            [$companyId]
        );

        return (int)$row['c'];
    }

    protected function liveDispatchCount(?int $companyId = null): int
    {
        $companyId = $companyId ?? $this->companyId;
        $row = $this->database->fetch(
            "SELECT COUNT(*) AS c FROM dispatch_day_entries WHERE company_id = ?",
            [$companyId]
        );

        return (int)$row['c'];
    }
}
