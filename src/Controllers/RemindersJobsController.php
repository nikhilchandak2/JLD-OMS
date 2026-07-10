<?php

namespace App\Controllers;

use App\Services\AuthService;

/**
 * Reminders job queue:
 * - Accounts/Admin upload CSV => create job
 * - Offline runner (on accountant PC) polls next job using REMINDERS_RUNNER_KEY
 * - Runner downloads CSV, runs BusyPayBot locally, posts output back
 */
class RemindersJobsController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    public function create(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'CSV file is required']);
            return;
        }

        $company = isset($_POST['company']) ? trim((string) $_POST['company']) : '';
        if ($company === '') {
            $company = 'jld_minerals';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['csv']['tmp_name']);
        $allowed = ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'];
        $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
        if (!in_array($mime, $allowed, true) && $ext !== 'csv') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid file type. Please upload a CSV file.']);
            return;
        }

        $jobId = $this->newJobId();
        $root = dirname(__DIR__, 2);
        $uploadsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_uploads';
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        @mkdir($uploadsDir, 0775, true);
        @mkdir($jobsDir, 0775, true);

        $originalName = (string)($_FILES['csv']['name'] ?? 'receivables.csv');
        $csvPath = $uploadsDir . DIRECTORY_SEPARATOR . $jobId . '.csv';
        if (!move_uploaded_file($_FILES['csv']['tmp_name'], $csvPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save uploaded CSV']);
            return;
        }

        $job = [
            'id' => $jobId,
            'status' => 'pending',
            'company' => $company,
            'created_at' => gmdate('c'),
            'created_by' => [
                'id' => $user['id'] ?? null,
                'email' => $user['email'] ?? null,
                'role' => $user['role'] ?? null,
            ],
            'csv' => [
                'original_name' => $originalName,
                'path' => $csvPath,
                'size' => (int)filesize($csvPath),
            ],
            'runner' => null,
            'started_at' => null,
            'completed_at' => null,
            'exit_code' => null,
            'success' => null,
            'output' => null,
        ];

        $this->writeJob($jobsDir, $jobId, $job);

        echo json_encode(['success' => true, 'job' => $this->publicJob($job)]);
    }

    public function status(string $id): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $job = $this->readJob($id);
        if ($job === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $job = $this->expireStaleJobIfNeeded($id, $job);

        echo json_encode(['success' => true, 'job' => $this->publicJob($job)]);
    }

    /**
     * POST /api/reminders/jobs/{id}/cancel – mark a stuck running job as failed (admin/accounts).
     */
    public function cancel(string $id): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $root = dirname(__DIR__, 2);
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $id . '.json';
        if (!is_file($jobPath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $fp = fopen($jobPath, 'c+');
        if (!$fp) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Unable to open job file']);
            return;
        }
        flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp);
        $job = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
        if (!is_array($job)) {
            flock($fp, LOCK_UN);
            fclose($fp);
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $status = $job['status'] ?? '';
        if ($status === 'completed') {
            flock($fp, LOCK_UN);
            fclose($fp);
            echo json_encode(['success' => true, 'job' => $this->publicJob($job), 'message' => 'Already completed']);
            return;
        }

        $job['status'] = 'failed';
        $job['success'] = false;
        $job['completed_at'] = gmdate('c');
        $job['exit_code'] = 1;
        $job['output'] = ($job['output'] ?? '') !== ''
            ? (string)$job['output']
            : 'Cancelled or runner stopped. Upload CSV again to retry.';

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode(['success' => true, 'job' => $this->publicJob($job)]);
    }

    public function next(): void
    {
        header('Content-Type: application/json');

        if (!$this->checkRunnerKey()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Runner auth failed']);
            return;
        }

        $root = dirname(__DIR__, 2);
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        @mkdir($jobsDir, 0775, true);

        $company = isset($_GET['company']) ? trim((string)$_GET['company']) : '';
        $runnerId = isset($_GET['runner_id']) ? trim((string)$_GET['runner_id']) : '';
        if ($runnerId === '') {
            $runnerId = 'runner_' . substr(sha1((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 10);
        }

        $this->expireAllStaleJobs($jobsDir);

        $job = $this->claimNextPendingJob($jobsDir, $company, $runnerId);
        if ($job === null) {
            echo json_encode(['success' => true, 'job' => null]);
            return;
        }

        echo json_encode([
            'success' => true,
            'job' => [
                'id' => $job['id'],
                'company' => $job['company'] ?? '',
                'created_at' => $job['created_at'] ?? null,
                'download_url' => '/api/reminders/jobs/' . rawurlencode((string)$job['id']) . '/download',
            ],
        ]);
    }

    public function download(string $id): void
    {
        if (!$this->checkRunnerKey()) {
            http_response_code(403);
            echo 'Runner auth failed';
            return;
        }

        $job = $this->readJob($id);
        if ($job === null) {
            http_response_code(404);
            echo 'Job not found';
            return;
        }
        $path = $job['csv']['path'] ?? null;
        if (!$path || !is_file($path)) {
            http_response_code(404);
            echo 'CSV not found';
            return;
        }

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="reminders_' . $id . '.csv"');
        readfile($path);
    }

    public function complete(string $id): void
    {
        header('Content-Type: application/json');

        if (!$this->checkRunnerKey()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Runner auth failed']);
            return;
        }

        $root = dirname(__DIR__, 2);
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        @mkdir($jobsDir, 0775, true);

        $jobPath = $jobsDir . DIRECTORY_SEPARATOR . $id . '.json';
        if (!is_file($jobPath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Job not found']);
            return;
        }

        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
            return;
        }

        $fp = fopen($jobPath, 'c+');
        if (!$fp) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Unable to open job file']);
            return;
        }
        flock($fp, LOCK_EX);
        $contents = stream_get_contents($fp);
        $job = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
        if (!is_array($job)) {
            $job = ['id' => $id];
        }

        $exitCode = isset($payload['exit_code']) ? (int)$payload['exit_code'] : null;
        $success = isset($payload['success']) ? (bool)$payload['success'] : ($exitCode === 0);
        $output = isset($payload['output']) ? (string)$payload['output'] : '';

        $job['status'] = $success ? 'completed' : 'failed';
        $job['completed_at'] = gmdate('c');
        $job['exit_code'] = $exitCode;
        $job['success'] = $success;
        $job['output'] = $output;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        echo json_encode(['success' => true, 'job' => $this->publicJob($job)]);
    }

    // ---------------- helpers ----------------

    private function checkRunnerKey(): bool
    {
        $expected = trim((string)($_ENV['REMINDERS_RUNNER_KEY'] ?? ''));
        if ($expected === '') {
            return false;
        }
        $got = (string)($_SERVER['HTTP_X_RUNNER_KEY'] ?? '');
        if ($got === '') {
            // Also allow query param for simple testing
            $got = (string)($_GET['key'] ?? '');
        }
        return $got !== '' && hash_equals($expected, $got);
    }

    private function newJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function writeJob(string $jobsDir, string $id, array $job): void
    {
        $path = $jobsDir . DIRECTORY_SEPARATOR . $id . '.json';
        file_put_contents($path, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function readJob(string $id): ?array
    {
        $root = dirname(__DIR__, 2);
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        $path = $jobsDir . DIRECTORY_SEPARATOR . $id . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $job = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($job) ? $job : null;
    }

    private function publicJob(array $job): array
    {
        return [
            'id' => $job['id'] ?? null,
            'status' => $job['status'] ?? null,
            'company' => $job['company'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'exit_code' => $job['exit_code'] ?? null,
            'success' => $job['success'] ?? null,
            'output' => $job['output'] ?? null,
            'runner' => $job['runner'] ?? null,
            'csv' => [
                'original_name' => $job['csv']['original_name'] ?? null,
                'size' => $job['csv']['size'] ?? null,
            ],
        ];
    }

    private function claimNextPendingJob(string $jobsDir, string $company, string $runnerId): ?array
    {
        $files = glob($jobsDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);

        foreach ($files as $path) {
            $fp = @fopen($path, 'c+');
            if (!$fp) {
                continue;
            }
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                fclose($fp);
                continue;
            }

            $contents = stream_get_contents($fp);
            $job = is_string($contents) && $contents !== '' ? json_decode($contents, true) : null;
            if (!is_array($job)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            if (($job['status'] ?? '') !== 'pending') {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }
            if ($company !== '' && ($job['company'] ?? '') !== $company) {
                flock($fp, LOCK_UN);
                fclose($fp);
                continue;
            }

            $job['status'] = 'running';
            $job['started_at'] = gmdate('c');
            $job['runner'] = ['id' => $runnerId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null];

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            return $job;
        }

        return null;
    }

    private function staleMinutes(): int
    {
        $raw = $_ENV['REMINDERS_JOB_STALE_MINUTES'] ?? getenv('REMINDERS_JOB_STALE_MINUTES');
        $mins = is_string($raw) || is_numeric($raw) ? (int)$raw : 0;
        return $mins > 0 ? $mins : 30;
    }

    private function jobRunningMinutes(array $job): ?int
    {
        $started = $job['started_at'] ?? null;
        if (!is_string($started) || $started === '') {
            return null;
        }
        try {
            $start = new \DateTimeImmutable($started);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return (int) floor(($now->getTimestamp() - $start->getTimestamp()) / 60);
        } catch (\Exception) {
            return null;
        }
    }

    private function expireStaleJobIfNeeded(string $id, array $job): array
    {
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }
        $mins = $this->jobRunningMinutes($job);
        if ($mins === null || $mins < $this->staleMinutes()) {
            return $job;
        }

        $job['status'] = 'failed';
        $job['success'] = false;
        $job['completed_at'] = gmdate('c');
        $job['exit_code'] = 124;
        $job['output'] = 'Job timed out (runner stopped or BusyPayBot still running). '
            . "Running for ~{$mins} minutes. Upload CSV again to retry.";

        $root = dirname(__DIR__, 2);
        $jobsDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reminders_jobs';
        $this->writeJob($jobsDir, $id, $job);

        return $job;
    }

    private function expireAllStaleJobs(string $jobsDir): void
    {
        $files = glob($jobsDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        foreach ($files as $path) {
            $id = basename($path, '.json');
            $job = $this->readJob($id);
            if ($job === null) {
                continue;
            }
            $this->expireStaleJobIfNeeded($id, $job);
        }
    }
}

