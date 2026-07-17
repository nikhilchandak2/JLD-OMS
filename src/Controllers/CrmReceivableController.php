<?php

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\ReceivablesImportService;
use App\Repositories\CrmReceivableEntryRepository;
use App\Repositories\PartyRepository;
use App\Models\CrmReceivableEntry;
use App\Support\IndianDate;

class CrmReceivableController
{
    private const MAX_UPLOAD_BYTES = 5242880; // 5MB
    private const MAX_REFERENCE_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 1000;

    private AuthService $authService;
    private CrmReceivableEntryRepository $receivableRepo;
    private PartyRepository $partyRepo;

    public function __construct()
    {
        $this->authService = new AuthService();
        $this->receivableRepo = new CrmReceivableEntryRepository();
        $this->partyRepo = new PartyRepository();
    }

    private function requireCrmAccess(): bool
    {
        $user = $this->authService->getCurrentUser();
        if (!$user || !$this->authService->hasAnyRole(['entry', 'admin', 'crm', 'accounts', 'sales', 'marketing'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Entry or Admin access required']);
            return false;
        }
        return true;
    }

    public function listByParty(string $partyId): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        $id = (int)$partyId;
        if ($id <= 0 || !$this->partyRepo->findById($id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid party']);
            return;
        }
        try {
            $entries = $this->receivableRepo->findByParty($id);
            $outstanding = $this->receivableRepo->getOutstandingForParty($id);
            $party = $this->partyRepo->findById($id);
            echo json_encode([
                'success' => true,
                'data' => [
                    'entries' => array_map(fn($e) => $e->toArray(), $entries),
                    'outstanding' => $outstanding,
                    'credit_limit' => $party->creditLimit,
                ],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function addEntry(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        $user = $this->authService->getCurrentUser();
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $partyId = (int)($input['party_id'] ?? 0);
        if ($partyId <= 0 || !$this->partyRepo->findById($partyId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid party is required']);
            return;
        }
        $type = trim($input['entry_type'] ?? 'invoice');
        if (!in_array($type, ['invoice', 'payment', 'adjustment'])) {
            http_response_code(400);
            echo json_encode(['error' => 'entry_type must be invoice, payment, or adjustment']);
            return;
        }
        $amount = (float)($input['amount'] ?? 0);
        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Amount must be positive']);
            return;
        }

        $entryDate = !empty($input['entry_date']) ? (string)$input['entry_date'] : date('Y-m-d');
        if (!$this->isValidDate($entryDate)) {
            http_response_code(400);
            echo json_encode(['error' => 'entry_date must be in DD/MM/YYYY or YYYY-MM-DD']);
            return;
        }

        $reference = trim((string)($input['reference'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        if (strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            http_response_code(400);
            echo json_encode(['error' => 'reference is too long']);
            return;
        }
        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            http_response_code(400);
            echo json_encode(['error' => 'description is too long']);
            return;
        }

        $entry = new CrmReceivableEntry();
        $entry->partyId = $partyId;
        $entry->entryType = $type;
        $entry->amount = $amount;
        $entry->entryDate = IndianDate::toStorage($entryDate);
        $entry->reference = $reference;
        $entry->description = $description;
        $entry->createdBy = $user ? (int)$user['id'] : null;
        try {
            $created = $this->receivableRepo->create($entry);
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Entry added',
                'data' => $created->toArray(),
                'outstanding' => $this->receivableRepo->getOutstandingForParty($partyId),
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to add receivable entry', $e);
        }
    }

    public function deleteEntry(string $id): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        $eid = (int)$id;
        $entry = $eid > 0 ? $this->receivableRepo->findById($eid) : null;
        if (!$entry) {
            http_response_code(404);
            echo json_encode(['error' => 'Entry not found']);
            return;
        }
        try {
            $this->receivableRepo->delete($eid);
            echo json_encode(['success' => true, 'message' => 'Entry deleted', 'outstanding' => $this->receivableRepo->getOutstandingForParty($entry->partyId)]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to delete receivable entry', $e);
        }
    }

    /** Summary for dashboard: parties with outstanding, credit limit exceeded */
    public function agingSummary(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        try {
            $pdo = (new \App\Core\Database())->getConnection();
            $sql = "SELECT party_id, SUM(CASE WHEN entry_type = 'invoice' THEN amount WHEN entry_type = 'payment' THEN -amount WHEN entry_type = 'adjustment' THEN amount ELSE 0 END) AS outstanding
                    FROM crm_receivable_entries GROUP BY party_id HAVING outstanding > 0";
            $stmt = $pdo->query($sql);
            $byParty = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $byParty[(int)$row['party_id']] = (float)$row['outstanding'];
            }
            $parties = $this->partyRepo->findAll();
            $list = [];
            foreach ($parties as $p) {
                $out = $byParty[$p->id] ?? 0;
                if ($out <= 0) continue;
                $list[] = [
                    'party_id' => $p->id,
                    'party_name' => $p->name,
                    'outstanding' => $out,
                    'credit_limit' => $p->creditLimit,
                    'over_limit' => $p->creditLimit !== null && $out > $p->creditLimit,
                ];
            }
            usort($list, fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
            echo json_encode(['success' => true, 'data' => $list]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Import bills receivables from CSV. POST with multipart/form-data, file = .csv
     * CSV should have headers: Party Name (or Customer, Name), Amount (or Due, Balance), optional Invoice No, Date
     */
    public function importFromCsv(): void
    {
        header('Content-Type: application/json');
        if (!$this->requireCrmAccess()) return;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error.']);
            return;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            http_response_code(400);
            echo json_encode(['error' => 'Only CSV files are allowed.']);
            return;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            http_response_code(400);
            echo json_encode(['error' => 'CSV file must be between 1 byte and 5MB']);
            return;
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid uploaded file']);
            return;
        }

        $content = file_get_contents($tmpName);
        if ($content === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Could not read file.']);
            return;
        }

        $user = $this->authService->getCurrentUser();
        $createdBy = $user ? (int)$user['id'] : null;

        try {
            $service = new ReceivablesImportService();
            $result = $service->importFromCsv($content, $createdBy);
            echo json_encode([
                'success' => $result['success'],
                'parties_created' => $result['parties_created'],
                'parties_matched' => $result['parties_matched'],
                'invoices_added' => $result['invoices_added'],
                'invoices_updated' => $result['invoices_updated'] ?? 0,
                'errors' => $result['errors'],
                'preview' => $result['preview'],
            ]);
        } catch (\Exception $e) {
            $this->respondServerError('Failed to import receivables CSV', $e);
        }
    }

    private function isValidDate(string $date): bool
    {
        return \App\Support\IndianDate::isValid($date);
    }

    private function respondServerError(string $message, \Throwable $e): void
    {
        error_log($message . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => $message]);
    }
}
