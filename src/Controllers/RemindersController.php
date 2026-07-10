<?php

namespace App\Controllers;

use App\Services\AuthService;

/**
 * Runs the external Python script for email & WhatsApp reminders.
 * Access: admin, accounts.
 */
class RemindersController
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * POST /api/reminders/run – run the reminders script.
     * Body: either JSON {} or multipart/form-data with optional file "csv" (bills/receivables CSV).
     * If CSV is uploaded, it is saved to a temp file and the path is passed to the Python script as first argument.
     */
    public function run(): void
    {
        header('Content-Type: application/json');

        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['admin', 'accounts'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $csvPath = null;
        if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($_FILES['csv']['tmp_name']);
            $allowed = ['text/csv', 'text/plain', 'application/csv', 'application/octet-stream'];
            $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
            if (!in_array($mime, $allowed) && $ext !== 'csv') {
                echo json_encode(['success' => false, 'error' => 'Invalid file type. Please upload a CSV file.']);
                return;
            }
            $csvPath = sys_get_temp_dir() . '/reminders_' . uniqid('', true) . '.csv';
            if (!move_uploaded_file($_FILES['csv']['tmp_name'], $csvPath)) {
                echo json_encode(['success' => false, 'error' => 'Failed to save uploaded CSV.']);
                return;
            }
        }

        $company = isset($_POST['company']) ? trim((string) $_POST['company']) : '';
        if ($company === '' && (!isset($_FILES['csv']) || empty($_FILES['csv']['tmp_name']))) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json) && isset($json['company'])) {
                    $company = trim((string) $json['company']);
                }
            }
        }

        $projectRoot = dirname(__DIR__, 2);
        $candidates = $this->buildScriptCandidates($projectRoot, $company);
        $scriptPath = $this->resolveExistingScript($candidates);
        $pythonBin = $this->envValue('PYTHON_PATH') ?? 'python';

        if ($scriptPath === null) {
            if ($csvPath && is_file($csvPath)) {
                @unlink($csvPath);
            }
            $tried = implode('; ', $candidates);
            echo json_encode([
                'success' => false,
                'error' => 'Reminders script not found. Set REMINDERS_SCRIPT_JLD_MINERALS / REMINDERS_SCRIPT_JAICHAND '
                    . '(or REMINDERS_SCRIPT) in .env to a path that exists on this server. Tried: ' . $tried,
                'paths_tried' => $candidates,
            ]);
            return;
        }
        $baseDir = dirname($scriptPath);
        // Use argument-array form to avoid shell quoting issues (especially on Linux servers).
        // Also run python unbuffered (-u) so output is visible in the UI.
        $cmd = [$pythonBin, '-u', $scriptPath];
        if ($csvPath) {
            $cmd[] = $csvPath;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $baseDir ?: null, null);

        if (!is_resource($proc)) {
            if ($csvPath && is_file($csvPath)) {
                @unlink($csvPath);
            }
            echo json_encode(['success' => false, 'error' => 'Failed to start reminders script']);
            return;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($csvPath && is_file($csvPath)) {
            @unlink($csvPath);
        }

        $stdout = (string) $stdout;
        $stderr = (string) $stderr;
        $combined = trim($stdout);
        if (trim($stderr) !== '') {
            $combined .= ($combined !== '' ? "\n\n" : '') . trim($stderr);
        }

        echo json_encode([
            'success' => ($exitCode === 0),
            'exit_code' => $exitCode,
            'output' => $combined !== '' ? $combined : '(no output)',
            'stdout_len' => strlen($stdout),
            'stderr_len' => strlen($stderr),
            'used_csv' => $csvPath !== null,
        ]);
    }

    /** @return list<string> */
    private function buildScriptCandidates(string $projectRoot, string $company): array
    {
        $bundled = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'send_reminders.py';
        $defaults = [
            'jld_minerals' => [
                $projectRoot . '/busypaybot/jld-minerals/main.py',
                '/var/www/busypaybot/jld-minerals/main.py',
            ],
            'jaichand' => [
                $projectRoot . '/busypaybot/jaichand/main.py',
                '/var/www/busypaybot/jaichand/main.py',
            ],
        ];
        if (PHP_OS_FAMILY === 'Windows') {
            array_unshift(
                $defaults['jld_minerals'],
                'C:/BusyPayBot/JLD Minerals Private Limited/main.py'
            );
            array_unshift(
                $defaults['jaichand'],
                'C:/BusyPayBot/Jaichand Lal Daga/main.py'
            );
        }

        $candidates = [];
        if ($company === 'jld_minerals') {
            $env = $this->envValue('REMINDERS_SCRIPT_JLD_MINERALS');
            if ($env !== null) {
                $candidates[] = $env;
            }
            $candidates = array_merge($candidates, $defaults['jld_minerals']);
        } elseif ($company === 'jaichand') {
            $env = $this->envValue('REMINDERS_SCRIPT_JAICHAND');
            if ($env !== null) {
                $candidates[] = $env;
            }
            $candidates = array_merge($candidates, $defaults['jaichand']);
        }

        $fallback = $this->envValue('REMINDERS_SCRIPT');
        if ($fallback !== null) {
            $candidates[] = $fallback;
        }
        $candidates[] = $bundled;

        $resolved = [];
        foreach ($candidates as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[/\\\\]#', $path)) {
                $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            }
            $resolved[] = $path;
        }

        return array_values(array_unique($resolved));
    }

    /** @param list<string> $candidates */
    private function resolveExistingScript(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            if (!is_file($normalized)) {
                continue;
            }
            $real = realpath($normalized);
            return $real !== false ? $real : $normalized;
        }

        return null;
    }

    private function envValue(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
