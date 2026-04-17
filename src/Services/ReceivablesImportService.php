<?php

namespace App\Services;

use App\Repositories\PartyRepository;
use App\Repositories\CrmReceivableEntryRepository;
use App\Models\Party;
use App\Models\CrmReceivableEntry;

/**
 * Import bills receivables from CSV (invoice-wise details, amount due).
 * Matches party by name, creates party if not found, adds invoice entries to CRM receivables.
 */
class ReceivablesImportService
{
    private const MAX_IMPORT_ROWS = 10000;
    private const MAX_PARTY_NAME_LENGTH = 255;
    private const MAX_REFERENCE_LENGTH = 255;

    private PartyRepository $partyRepo;
    private CrmReceivableEntryRepository $receivableRepo;

    /** Column header patterns (lowercase) => our key */
    private const PARTY_HEADERS = ['party name', 'customer', 'party', 'customer name', 'name', 'party name', 'buyer', 'consignee'];
    private const AMOUNT_HEADERS = ['amount', 'due', 'balance', 'outstanding', 'amount due', 'balance due', 'due amount', 'pending'];
    private const REFERENCE_HEADERS = ['invoice no', 'invoice #', 'bill no', 'invoice', 'reference', 'inv no', 'invoice number'];
    private const DATE_HEADERS = ['date', 'due date', 'invoice date', 'bill date'];

    public function __construct()
    {
        $this->partyRepo = new PartyRepository();
        $this->receivableRepo = new CrmReceivableEntryRepository();
    }

    /**
     * @param string $csvContent Raw CSV content
     * @param int|null $createdBy User ID for audit
     * @return array{success: bool, parties_created: int, parties_matched: int, invoices_added: int, invoices_updated: int, errors: array, preview: array}
     */
    public function importFromCsv(string $csvContent, ?int $createdBy = null): array
    {
        $partiesCreated = 0;
        $partiesMatched = 0;
        $invoicesAdded = 0;
        $invoicesUpdated = 0;
        $errors = [];
        $preview = [];
        $seenParties = [];

        // Strip BOM if present (Excel CSV)
        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $csvContent);
        if (count($lines) < 2) {
            return [
                'success' => false,
                'parties_created' => 0,
                'parties_matched' => 0,
                'invoices_added' => 0,
                'invoices_updated' => 0,
                'errors' => ['CSV must have a header row and at least one data row.'],
                'preview' => [],
            ];
        }

        $headerRow = str_getcsv(array_shift($lines));
        $headerRow = array_map(function ($h) {
            return trim(strtolower((string)$h));
        }, $headerRow);

        $partyCol = $this->findColumnIndex($headerRow, self::PARTY_HEADERS);
        $amountCol = $this->findColumnIndex($headerRow, self::AMOUNT_HEADERS);

        if ($partyCol === null) {
            return [
                'success' => false,
                'parties_created' => 0,
                'parties_matched' => 0,
                'invoices_added' => 0,
                'invoices_updated' => 0,
                'errors' => ['Could not find a Party/Customer name column. Use headers like "Party Name", "Customer", or "Name".'],
                'preview' => [array_combine($headerRow, $headerRow) ?: $headerRow],
            ];
        }
        if ($amountCol === null) {
            return [
                'success' => false,
                'parties_created' => 0,
                'parties_matched' => 0,
                'invoices_added' => 0,
                'invoices_updated' => 0,
                'errors' => ['Could not find an Amount/Due column. Use headers like "Amount", "Due", "Balance", or "Amount Due".'],
                'preview' => [array_combine($headerRow, $headerRow) ?: $headerRow],
            ];
        }

        $refCol = $this->findColumnIndex($headerRow, self::REFERENCE_HEADERS);
        $dateCol = $this->findColumnIndex($headerRow, self::DATE_HEADERS);

        $rowNum = 1; // after header
        foreach ($lines as $line) {
            $rowNum++;
            if ($rowNum > self::MAX_IMPORT_ROWS + 1) {
                $errors[] = 'Import stopped: CSV exceeds maximum allowed rows (' . self::MAX_IMPORT_ROWS . ').';
                break;
            }

            $row = str_getcsv($line);
            if (count($row) < max($partyCol, $amountCol) + 1) {
                continue;
            }

            $partyName = trim((string)($row[$partyCol] ?? ''));
            $amountRaw = trim((string)($row[$amountCol] ?? ''));
            if ($partyName === '' || $amountRaw === '') {
                continue;
            }

            if (strlen($partyName) > self::MAX_PARTY_NAME_LENGTH) {
                $errors[] = "Row {$rowNum}: Party name is too long – skipped.";
                continue;
            }

            $amount = $this->parseAmount($amountRaw);
            if ($amount <= 0) {
                $errors[] = "Row {$rowNum}: Invalid amount \"{$amountRaw}\" for \"{$partyName}\" – skipped.";
                continue;
            }

            $party = $this->partyRepo->findByName($partyName);
            if (!$party) {
                $party = new Party();
                $party->name = $partyName;
                $party->contactPerson = '';
                $party->phone = '';
                $party->email = '';
                $party->address = '';
                $party->isActive = true;
                $party = $this->partyRepo->create($party);
                $partiesCreated++;
                $seenParties[$partyName] = true;
            } else {
                if (empty($seenParties[$partyName])) {
                    $partiesMatched++;
                    $seenParties[$partyName] = true;
                }
            }

            $reference = $refCol !== null && isset($row[$refCol]) ? trim((string)$row[$refCol]) : '';
            if (strlen($reference) > self::MAX_REFERENCE_LENGTH) {
                $reference = substr($reference, 0, self::MAX_REFERENCE_LENGTH);
            }
            $entryDate = $dateCol !== null && isset($row[$dateCol]) ? $this->parseDate(trim((string)$row[$dateCol])) : date('Y-m-d');

            $description = 'Imported from Busy (CSV)';
            $didUpdate = false;
            if ($reference !== '') {
                $existing = $this->receivableRepo->findInvoiceByPartyAndReference($party->id, $reference);
                if ($existing) {
                    $this->receivableRepo->updateInvoice((int)$existing->id, $amount, $entryDate, $description);
                    $invoicesUpdated++;
                    $didUpdate = true;
                }
            }
            if (!$didUpdate) {
                $entry = new CrmReceivableEntry();
                $entry->partyId = $party->id;
                $entry->entryType = 'invoice';
                $entry->amount = $amount;
                $entry->entryDate = $entryDate;
                $entry->reference = $reference;
                $entry->description = $description;
                $entry->createdBy = $createdBy;
                $this->receivableRepo->create($entry);
                $invoicesAdded++;
            }

            if (count($preview) < 5) {
                $preview[] = [
                    'party_name' => $partyName,
                    'amount' => $amount,
                    'reference' => $reference,
                    'date' => $entryDate,
                ];
            }
        }

        return [
            'success' => true,
            'parties_created' => $partiesCreated,
            'parties_matched' => $partiesMatched,
            'invoices_added' => $invoicesAdded,
            'invoices_updated' => $invoicesUpdated,
            'errors' => $errors,
            'preview' => $preview,
        ];
    }

    private function findColumnIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $i => $h) {
            $h = trim($h);
            if ($h === '') {
                continue;
            }
            foreach ($candidates as $c) {
                if ($h === $c || strpos($h, $c) !== false || strpos($c, $h) !== false) {
                    return $i;
                }
            }
        }
        return null;
    }

    private function parseAmount(string $value): float
    {
        $value = preg_replace('/[^\d.\-]/', '', $value);
        return (float)$value;
    }

    private function parseDate(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $ts = strtotime($value);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }
        return date('Y-m-d');
    }
}
