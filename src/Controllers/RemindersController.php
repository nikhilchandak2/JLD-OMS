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
        $scriptPath = null;
        // Default Windows BusyPayBot locations (used if env vars not set)
        $defaults = [
            'jld_minerals' => 'C:/BusyPayBot/JLD Minerals Private Limited/main.py',
            'jaichand' => 'C:/BusyPayBot/Jaichand Lal Daga/main.py',
        ];
        if ($company === 'jld_minerals') {
            if (!empty($_ENV['REMINDERS_SCRIPT_JLD_MINERALS'])) {
                $scriptPath = $_ENV['REMINDERS_SCRIPT_JLD_MINERALS'];
            } else {
                $scriptPath = $defaults['jld_minerals'];
            }
        } elseif ($company === 'jaichand') {
            if (!empty($_ENV['REMINDERS_SCRIPT_JAICHAND'])) {
                $scriptPath = $_ENV['REMINDERS_SCRIPT_JAICHAND'];
            } else {
                $scriptPath = $defaults['jaichand'];
            }
        }
        if ($scriptPath === null || $scriptPath === '') {
            // Fallback: single default script (e.g. send_reminders.py or one BusyPayBot instance)
            $scriptPath = $_ENV['REMINDERS_SCRIPT'] ?? ($projectRoot . '/scripts/send_reminders.py');
        }
        if ($scriptPath !== '' && $scriptPath[0] !== '/' && !preg_match('#^[A-Za-z]:#', $scriptPath)) {
            $scriptPath = $projectRoot . '/' . $scriptPath;
        }
        $pythonBin = $_ENV['PYTHON_PATH'] ?? 'python';

        if (!is_file($scriptPath)) {
            if ($csvPath && is_file($csvPath)) {
                @unlink($csvPath);
            }
            echo json_encode([
                'success' => false,
                'error' => 'Reminders script not found. Set REMINDERS_SCRIPT in .env to your script path (e.g. scripts/send_reminders.py).',
                'path_checked' => $scriptPath,
            ]);
            return;
        }

        $scriptPath = realpath($scriptPath);
        $baseDir = dirname($scriptPath);
        $args = $csvPath ? ' ' . escapeshellarg($csvPath) : '';
        $cmd = sprintf(
            '%s %s%s 2>&1',
            escapeshellcmd($pythonBin),
            escapeshellarg($scriptPath),
            $args
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            $cmd,
            $descriptorSpec,
            $pipes,
            $baseDir ?: null,
            null
        );

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

        $output = trim($stdout);
        if ($stderr) {
            $output .= "\n" . trim($stderr);
        }

        echo json_encode([
            'success' => ($exitCode === 0),
            'exit_code' => $exitCode,
            'output' => $output ?: '(no output)',
            'used_csv' => $csvPath !== null,
        ]);
    }
}
