<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\DataFeedRepository;
use App\Repositories\DataFeedRowRepository;
use App\Repositories\DataFeedRunRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Two-phase ingest: upload → stage → validate → report → promote.
 * Live tables are untouched until the operator confirms promote.
 * Byte-identical re-uploads are a no-op. A different file for the same
 * business date requires explicit supersede confirmation.
 */
class DataFeedIngestService
{
    private Database $database;
    private DataFeedRepository $feeds;
    private DataFeedRunRepository $runs;
    private DataFeedRowRepository $rows;
    private DataFeedSpreadsheetParser $parser;
    private PartyAliasService $aliases;
    private AuditLogRepository $audit;
    private DataFeedPolicy $policy;
    private array $config;
    private DateTimeZone $tz;

    public function __construct()
    {
        $this->database = new Database();
        $this->feeds = new DataFeedRepository();
        $this->runs = new DataFeedRunRepository();
        $this->rows = new DataFeedRowRepository();
        $this->parser = new DataFeedSpreadsheetParser();
        $this->aliases = new PartyAliasService();
        $this->audit = new AuditLogRepository();
        $this->policy = new DataFeedPolicy();
        $this->config = require dirname(__DIR__, 2) . '/config/data_feeds.php';
        $this->tz = new DateTimeZone($this->config['timezone'] ?? 'Asia/Kolkata');
    }

    /**
     * @param array{confirm_supersede?: bool} $options
     * @return array<string,mixed>
     */
    public function upload(
        string $feedKey,
        int $companyId,
        string $businessDate,
        string $filename,
        string $content,
        array $actor,
        array $options = []
    ): array {
        $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::UPLOAD);
        $this->assertFeedKey($feedKey);
        $this->assertBusinessDate($businessDate);
        $this->assertCompany($companyId);
        $this->feeds->ensureForCompany($companyId);

        if ($content === '') {
            throw new DataFeedException('Empty file.');
        }
        if (strlen($content) > (int)$this->config['max_upload_bytes']) {
            throw new DataFeedException('File exceeds the maximum upload size.');
        }

        $hash = hash('sha256', $content);
        $existing = $this->runs->findByHash($feedKey, $companyId, $businessDate, $hash);
        if ($existing) {
            $payload = $this->runPayload((int)$existing['id']);
            $payload['already_processed'] = true;
            $payload['message'] = 'Already processed — this exact file was uploaded for that business date.';

            return $payload;
        }

        $completed = $this->runs->findCompletedForDate($feedKey, $companyId, $businessDate);
        $replacesRunId = null;
        if ($completed && $completed['file_hash'] !== $hash) {
            if (empty($options['confirm_supersede'])) {
                throw new FeedSupersedeRequiredException(
                    'A different file has already been completed for this business date. Confirm supersession to replace it.',
                    [
                        'supersede_required' => true,
                        'existing_run' => $completed,
                    ]
                );
            }
            $replacesRunId = (int)$completed['id'];
        }

        try {
            $parsed = $this->parser->parse($content, $filename);
        } catch (DataFeedException $e) {
            $runId = $this->runs->create([
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'business_date' => $businessDate,
                'uploaded_by_user_id' => $actor['id'] ?? null,
                'uploaded_at' => $this->nowStamp(),
                'original_filename' => $filename,
                'file_hash' => $hash,
                'status' => 'failed',
                'error_summary' => $e->getMessage(),
                'replaces_run_id' => $replacesRunId,
            ]);
            throw new DataFeedException($e->getMessage(), ['run_id' => $runId]);
        }

        $missing = $this->missingRequiredColumns($feedKey, $parsed['headers']);
        if ($missing !== []) {
            $runId = $this->runs->create([
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'business_date' => $businessDate,
                'uploaded_by_user_id' => $actor['id'] ?? null,
                'uploaded_at' => $this->nowStamp(),
                'original_filename' => $filename,
                'file_hash' => $hash,
                'status' => 'failed',
                'error_summary' => 'Missing required column(s): ' . implode(', ', $missing),
                'replaces_run_id' => $replacesRunId,
            ]);
            throw new DataFeedException(
                'Missing required column(s): ' . implode(', ', $missing),
                ['run_id' => $runId, 'missing_columns' => $missing]
            );
        }

        if ($parsed['rows'] === []) {
            $runId = $this->runs->create([
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'business_date' => $businessDate,
                'uploaded_by_user_id' => $actor['id'] ?? null,
                'uploaded_at' => $this->nowStamp(),
                'original_filename' => $filename,
                'file_hash' => $hash,
                'status' => 'failed',
                'error_summary' => 'Empty file.',
                'replaces_run_id' => $replacesRunId,
            ]);
            throw new DataFeedException('Empty file.', ['run_id' => $runId]);
        }

        if (count($parsed['rows']) > (int)$this->config['max_rows']) {
            throw new DataFeedException('File exceeds the maximum allowed rows (' . $this->config['max_rows'] . ').');
        }

        $this->database->beginTransaction();
        try {
            $runId = $this->runs->create([
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'business_date' => $businessDate,
                'uploaded_by_user_id' => $actor['id'] ?? null,
                'uploaded_at' => $this->nowStamp(),
                'original_filename' => $filename,
                'file_hash' => $hash,
                'status' => 'uploaded',
                'rows_total' => count($parsed['rows']),
                'replaces_run_id' => $replacesRunId,
            ]);
            $this->rows->insertMany($runId, array_map(static fn($row) => [
                'row_number' => $row['row_number'],
                'raw' => $row['raw'],
                'status' => 'pending',
            ], $parsed['rows']));

            $this->audit->log($actor['id'] ?? null, 'data_feed_runs', $runId, 'create', null, [
                'feed_key' => $feedKey,
                'company_id' => $companyId,
                'business_date' => $businessDate,
                'file_hash' => $hash,
            ]);
            $this->database->commit();
        } catch (\Throwable $e) {
            $this->database->rollback();
            throw $e;
        }

        return $this->validate($runId, $actor);
    }

    /**
     * Re-run row validation. Safe after an alias is created for an unmatched party.
     */
    public function validate(int $runId, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::UPLOAD);
        $run = $this->requireRun($runId);
        if (in_array($run['status'], ['completed', 'superseded', 'promoting'], true)) {
            throw new DataFeedException('This run can no longer be validated.');
        }

        $this->runs->update($runId, ['status' => 'validating']);

        $sourceSystem = $this->aliases->sourceSystemFor($run['feed_key']);
        $aliasMap = $this->aliases->aliasMap($sourceSystem);
        $nameMap = $this->aliases->partyNameMap();
        $existingInvoices = $this->existingInvoiceKeys($run['feed_key'], (int)$run['company_id']);

        $seenInFile = [];
        $accepted = 0;
        $rejected = 0;
        $staged = $this->rows->findByRun($runId);

        foreach ($staged as $row) {
            $result = $this->validateRow(
                $run,
                $row,
                $aliasMap,
                $nameMap,
                $seenInFile,
                $existingInvoices
            );
            $this->rows->updateRow((int)$row['id'], $result);
            if ($result['status'] === 'valid') {
                $accepted++;
            } else {
                $rejected++;
            }
        }

        $status = $rejected === 0 ? 'validated' : 'validated';
        $summary = $rejected === 0
            ? null
            : "{$rejected} of " . count($staged) . " row(s) rejected. Promote is blocked until every row is valid.";

        $this->runs->update($runId, [
            'status' => $status,
            'rows_total' => count($staged),
            'rows_accepted' => $accepted,
            'rows_rejected' => $rejected,
            'error_summary' => $summary,
        ]);

        return $this->runPayload($runId);
    }

    /**
     * Atomically promote valid rows into the live table. Blocked if any row is rejected.
     * $options['fail_after'] is a test-only hook that throws after N live inserts.
     *
     * @param array{fail_after?: int} $options
     */
    public function promote(int $runId, array $actor, array $options = []): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::PROMOTE);
        $run = $this->requireRun($runId);

        if ($run['status'] === 'completed') {
            return $this->runPayload($runId);
        }
        if (!in_array($run['status'], ['validated', 'failed'], true)) {
            throw new FeedPromotionBlockedException('Run must be validated before promote.');
        }
        if ((int)$run['rows_rejected'] > 0) {
            throw new FeedPromotionBlockedException(
                'Promote is blocked while any row is rejected. Resolve unmatched parties or fix the file.',
                ['rows_rejected' => (int)$run['rows_rejected']]
            );
        }
        if ((int)$run['rows_accepted'] === 0) {
            throw new FeedPromotionBlockedException('There are no valid rows to promote.');
        }

        $this->acquireLock($runId, $actor['id'] ?? null);

        $pdo = $this->database->getConnection();
        $useSavepoint = $pdo->inTransaction();
        if ($useSavepoint) {
            $pdo->exec('SAVEPOINT data_feed_promote');
        } else {
            $this->database->beginTransaction();
        }

        try {
            $this->runs->update($runId, ['status' => 'promoting']);

            $asOf = $this->nowStamp();
            $valid = $this->rows->findValid($runId);

            if ($run['replaces_run_id']) {
                $this->replaceLiveRows($run['feed_key'], (int)$run['company_id'], $run['business_date'], (int)$run['replaces_run_id']);
                $this->runs->markSuperseded((int)$run['replaces_run_id']);
            } else {
                $existing = $this->runs->findCompletedForDate($run['feed_key'], (int)$run['company_id'], $run['business_date']);
                if ($existing && (int)$existing['id'] !== $runId) {
                    $this->replaceLiveRows($run['feed_key'], (int)$run['company_id'], $run['business_date'], (int)$existing['id']);
                    $this->runs->markSuperseded((int)$existing['id']);
                }
            }

            $inserted = 0;
            foreach ($valid as $row) {
                $this->insertLiveRow($run, $row, $asOf);
                $inserted++;
                if (isset($options['fail_after']) && $inserted >= (int)$options['fail_after']) {
                    throw new \RuntimeException('Injected promote failure');
                }
            }

            $this->rows->markPromoted($runId);
            $this->runs->update($runId, [
                'status' => 'completed',
                'as_of' => $asOf,
                'error_summary' => null,
            ]);
            $this->audit->log($actor['id'] ?? null, 'data_feed_runs', $runId, 'promote', ['status' => $run['status']], [
                'status' => 'completed',
                'rows' => $inserted,
                'as_of' => $asOf,
            ]);

            if ($useSavepoint) {
                $pdo->exec('RELEASE SAVEPOINT data_feed_promote');
            } else {
                $this->database->commit();
            }
        } catch (\Throwable $e) {
            if ($useSavepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT data_feed_promote');
            } else {
                $this->database->rollback();
            }
            $this->runs->update($runId, [
                'status' => 'failed',
                'error_summary' => $e->getMessage(),
            ]);
            $this->releaseLock($runId);
            throw $e;
        }

        $this->releaseLock($runId);

        return $this->runPayload($runId);
    }

    public function show(int $runId): array
    {
        return $this->runPayload($runId);
    }

    public function rejectionReport(int $runId): array
    {
        $run = $this->requireRun($runId);
        $rejected = $this->rows->findRejected($runId);
        $lines = [];
        $lines[] = ['row_number', 'rejection_reason', 'party_name', 'party_code', 'detail'];
        foreach ($rejected as $row) {
            $raw = $row['raw'] ?? [];
            $lines[] = [
                (string)$row['row_number'],
                (string)$row['rejection_reason'],
                (string)($raw['party_name'] ?? ''),
                (string)($raw['party_code'] ?? ''),
                json_encode($raw, JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'run' => $run,
            'filename' => 'rejections-run-' . $runId . '.csv',
            'rows' => $lines,
        ];
    }

    public function dashboard(): array
    {
        $this->feeds->ensureForAllCompanies();
        $feeds = $this->feeds->listAll();
        $latest = [];
        foreach ($this->runs->latestPerFeed() as $run) {
            $latest[$run['feed_key'] . ':' . $run['company_id']] = $run;
        }

        $freshness = new DataFreshnessService();
        $items = [];
        foreach ($feeds as $feed) {
            $key = $feed['feed_key'] . ':' . $feed['company_id'];
            $asOf = $freshness->asOf($feed['feed_key'], (int)$feed['company_id']);
            $items[] = [
                'feed' => $feed,
                'latest_run' => $latest[$key] ?? null,
                'freshness' => $asOf,
            ];
        }

        return ['feeds' => $items];
    }

    public function updateFeed(int $feedId, array $fields, array $actor): array
    {
        $this->policy->assertCan($actor['role'] ?? null, DataFeedPolicy::CONFIGURE);
        $feed = $this->feeds->findById($feedId);
        if (!$feed) {
            throw new DataFeedException('Feed not found.');
        }

        $update = [];
        if (array_key_exists('owner_user_id', $fields)) {
            $update['owner_user_id'] = $fields['owner_user_id'] !== null && $fields['owner_user_id'] !== ''
                ? (int)$fields['owner_user_id']
                : null;
        }
        if (array_key_exists('deadline_local_time', $fields) && $fields['deadline_local_time'] !== '') {
            $update['deadline_local_time'] = $fields['deadline_local_time'];
        }
        if (array_key_exists('is_active', $fields)) {
            $update['is_active'] = (int)(bool)$fields['is_active'];
        }
        if (array_key_exists('display_name', $fields) && $fields['display_name'] !== '') {
            $update['display_name'] = $fields['display_name'];
        }

        $this->feeds->update($feedId, $update);
        $this->audit->log($actor['id'] ?? null, 'data_feeds', $feedId, 'update', $feed, $update);

        return $this->feeds->findById($feedId) ?? $feed;
    }

    public function template(string $feedKey): array
    {
        $this->assertFeedKey($feedKey);
        if ($feedKey === 'ledger') {
            $headers = ['party_name', 'party_code', 'outstanding_amount', 'invoice_no', 'invoice_date'];
        } else {
            $headers = ['party_name', 'party_code', 'grade_code', 'quantity_tonnes', 'vehicle_no', 'destination', 'invoice_no', 'dispatch_date'];
        }

        return [
            'feed_key' => $feedKey,
            'headers' => $headers,
            'filename' => $feedKey . '-template.csv',
        ];
    }

    public function afterAliasResolved(array $actor): void
    {
        $open = $this->database->fetchAll(
            "SELECT DISTINCT run_id FROM data_feed_rows
             WHERE status = 'rejected' AND rejection_reason = 'unknown_party'"
        );
        foreach ($open as $row) {
            $run = $this->runs->findById((int)$row['run_id']);
            if ($run && in_array($run['status'], ['validated', 'failed', 'uploaded'], true)) {
                $this->validate((int)$row['run_id'], $actor);
            }
        }
    }

    // -----------------------------------------------------------------------

    private function validateRow(
        array $run,
        array $row,
        array $aliasMap,
        array $nameMap,
        array &$seenInFile,
        array $existingInvoices
    ): array {
        $raw = $row['raw'] ?? [];
        $partyName = trim((string)($raw['party_name'] ?? ''));
        $partyCode = trim((string)($raw['party_code'] ?? ''));

        if ($partyName === '' && $partyCode === '') {
            return ['status' => 'rejected', 'rejection_reason' => 'missing_required_column', 'resolved_party_id' => null];
        }

        $sourceSystem = $this->aliases->sourceSystemFor($run['feed_key']);
        $partyId = $this->aliases->resolveFromMaps($sourceSystem, $partyName, $partyCode, $aliasMap, $nameMap);
        if ($partyId === null) {
            return ['status' => 'rejected', 'rejection_reason' => 'unknown_party', 'resolved_party_id' => null];
        }

        if ($run['feed_key'] === 'ledger') {
            $amount = $this->parseNumber($raw['outstanding_amount'] ?? '');
            if ($amount === null) {
                return ['status' => 'rejected', 'rejection_reason' => 'malformed_number', 'resolved_party_id' => $partyId];
            }
        } else {
            $qty = $this->parseNumber($raw['quantity_tonnes'] ?? '');
            if ($qty === null) {
                return ['status' => 'rejected', 'rejection_reason' => 'malformed_number', 'resolved_party_id' => $partyId];
            }
            if (trim((string)($raw['grade_code'] ?? '')) === '') {
                return ['status' => 'rejected', 'rejection_reason' => 'missing_required_column', 'resolved_party_id' => $partyId];
            }
        }

        $rowDate = trim((string)($raw['invoice_date'] ?? $raw['dispatch_date'] ?? ''));
        if ($rowDate !== '') {
            $normalized = $this->normalizeDate($rowDate);
            if ($normalized !== null && $normalized !== $run['business_date']) {
                return ['status' => 'rejected', 'rejection_reason' => 'date_outside_business_date', 'resolved_party_id' => $partyId];
            }
            if ($normalized === null) {
                return ['status' => 'rejected', 'rejection_reason' => 'date_outside_business_date', 'resolved_party_id' => $partyId];
            }
        }

        $dupKey = implode('|', [
            PartyAliasService::normalizeIdentifier($partyCode !== '' ? $partyCode : $partyName),
            (string)($raw['invoice_no'] ?? ''),
            (string)($raw['grade_code'] ?? ''),
            (string)($raw['outstanding_amount'] ?? $raw['quantity_tonnes'] ?? ''),
        ]);
        if (isset($seenInFile[$dupKey])) {
            return ['status' => 'rejected', 'rejection_reason' => 'duplicate_row', 'resolved_party_id' => $partyId];
        }
        $seenInFile[$dupKey] = true;

        $invoiceNo = trim((string)($raw['invoice_no'] ?? ''));
        if ($invoiceNo !== '' && isset($existingInvoices[$invoiceNo])) {
            return ['status' => 'rejected', 'rejection_reason' => 'duplicate_existing', 'resolved_party_id' => $partyId];
        }

        return ['status' => 'valid', 'rejection_reason' => null, 'resolved_party_id' => $partyId];
    }

    private function insertLiveRow(array $run, array $row, string $asOf): void
    {
        $raw = $row['raw'] ?? [];
        $partyId = (int)$row['resolved_party_id'];
        if ($run['feed_key'] === 'ledger') {
            $this->database->execute(
                "INSERT INTO ledger_outstanding
                    (run_id, company_id, party_id, business_date, outstanding_amount, invoice_no, invoice_date, as_of)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $run['id'],
                    $run['company_id'],
                    $partyId,
                    $run['business_date'],
                    $this->parseNumber($raw['outstanding_amount'] ?? '0'),
                    trim((string)($raw['invoice_no'] ?? '')) ?: null,
                    $this->normalizeDate((string)($raw['invoice_date'] ?? '')) ?: null,
                    $asOf,
                ]
            );
            return;
        }

        $this->database->execute(
            "INSERT INTO dispatch_day_entries
                (run_id, company_id, party_id, business_date, grade_code, quantity_tonnes, vehicle_no, destination, invoice_no, as_of)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $run['id'],
                $run['company_id'],
                $partyId,
                $run['business_date'],
                trim((string)($raw['grade_code'] ?? '')),
                $this->parseNumber($raw['quantity_tonnes'] ?? '0'),
                trim((string)($raw['vehicle_no'] ?? '')) ?: null,
                trim((string)($raw['destination'] ?? '')) ?: null,
                trim((string)($raw['invoice_no'] ?? '')) ?: null,
                $asOf,
            ]
        );
    }

    private function replaceLiveRows(string $feedKey, int $companyId, string $businessDate, int $oldRunId): void
    {
        $table = $feedKey === 'ledger' ? 'ledger_outstanding' : 'dispatch_day_entries';
        $this->database->execute(
            "DELETE FROM {$table} WHERE company_id = ? AND business_date = ? AND run_id = ?",
            [$companyId, $businessDate, $oldRunId]
        );
    }

    /** @return array<string,true> */
    private function existingInvoiceKeys(string $feedKey, int $companyId): array
    {
        $table = $feedKey === 'ledger' ? 'ledger_outstanding' : 'dispatch_day_entries';
        $rows = $this->database->fetchAll(
            "SELECT invoice_no FROM {$table} WHERE company_id = ? AND invoice_no IS NOT NULL AND invoice_no != ''",
            [$companyId]
        );
        $keys = [];
        foreach ($rows as $row) {
            $keys[(string)$row['invoice_no']] = true;
        }

        return $keys;
    }

    private function missingRequiredColumns(string $feedKey, array $headers): array
    {
        $required = $this->config['required_columns'][$feedKey] ?? [];
        $missing = [];
        foreach ($required as $col) {
            if (!in_array($col, $headers, true)) {
                $missing[] = $col;
            }
        }
        $hasParty = in_array('party_name', $headers, true) || in_array('party_code', $headers, true);
        if (!$hasParty) {
            $missing[] = 'party_name';
        }

        return $missing;
    }

    private function parseNumber($value): ?float
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.\-]/', '', $value);
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        if (!is_numeric($clean)) {
            return null;
        }

        return (float)$clean;
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        $ts = strtotime($value);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function runPayload(int $runId): array
    {
        $run = $this->requireRun($runId);
        $rejected = $this->rows->findRejected($runId);

        return [
            'run' => $run,
            'can_promote' => $run['status'] === 'validated' && (int)$run['rows_rejected'] === 0 && (int)$run['rows_accepted'] > 0,
            'rejected_preview' => array_slice($rejected, 0, 25),
            'rejected_count' => count($rejected),
        ];
    }

    private function requireRun(int $runId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run) {
            throw new DataFeedException('Run not found.');
        }

        return $run;
    }

    private function assertFeedKey(string $feedKey): void
    {
        if (!isset($this->config['feeds'][$feedKey])) {
            throw new DataFeedException("Unknown feed_key '{$feedKey}'.");
        }
    }

    private function assertBusinessDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new DataFeedException('business_date must be YYYY-MM-DD.');
        }
    }

    private function assertCompany(int $companyId): void
    {
        $row = $this->database->fetch("SELECT id FROM companies WHERE id = ?", [$companyId]);
        if (!$row) {
            throw new DataFeedException('Company not found.');
        }
    }

    private function nowStamp(): string
    {
        return (new DateTimeImmutable('now', $this->tz))->format('Y-m-d H:i:s');
    }

    private function acquireLock(int $runId, ?int $userId): void
    {
        try {
            $this->database->execute(
                "INSERT INTO data_feed_locks (run_id, locked_by_user_id, locked_at) VALUES (?, ?, ?)",
                [$runId, $userId, $this->nowStamp()]
            );
        } catch (\Throwable $e) {
            throw new FeedPromotionBlockedException('This run is already being promoted.');
        }
    }

    private function releaseLock(int $runId): void
    {
        $this->database->execute("DELETE FROM data_feed_locks WHERE run_id = ?", [$runId]);
    }
}
