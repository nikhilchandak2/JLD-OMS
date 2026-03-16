<?php

namespace App\Services;

use App\Repositories\PartyRepository;
use App\Models\Party;

/**
 * Import parties from CSV with columns: Parties (name), email.
 * Creates new parties or updates email for existing parties (matched by name).
 */
class PartyImportService
{
    private PartyRepository $partyRepo;

    private const NAME_HEADERS = ['parties', 'party', 'name', 'customer', 'company'];
    private const EMAIL_HEADERS = ['email', 'e-mail', 'email address'];

    public function __construct()
    {
        $this->partyRepo = new PartyRepository();
    }

    /**
     * @return array{success: bool, created: int, updated: int, skipped: int, errors: array, preview: array}
     */
    public function importFromCsv(string $csvContent): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $preview = [];

        if (substr($csvContent, 0, 3) === "\xEF\xBB\xBF") {
            $csvContent = substr($csvContent, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $csvContent);
        if (count($lines) < 2) {
            return [
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['CSV must have a header row and at least one data row.'],
                'preview' => [],
            ];
        }

        $headerRow = str_getcsv(array_shift($lines));
        $headerRow = array_map(fn($h) => trim(strtolower((string)$h)), $headerRow);

        $nameCol = $this->findColumnIndex($headerRow, self::NAME_HEADERS);
        $emailCol = $this->findColumnIndex($headerRow, self::EMAIL_HEADERS);

        if ($nameCol === null) {
            return [
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Could not find a "Parties" or "Party" column.'],
                'preview' => [],
            ];
        }

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line);
            if (count($row) <= max($nameCol, $emailCol ?? -1)) {
                continue;
            }
            $name = trim((string)($row[$nameCol] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }
            $email = isset($emailCol) ? trim((string)($row[$emailCol] ?? '')) : '';

            $existing = $this->partyRepo->findByName($name);
            if ($existing) {
                if ($email !== '' && $existing->email !== $email) {
                    $this->partyRepo->update($existing->id, ['email' => $email]);
                    $updated++;
                } else {
                    $skipped++;
                }
                if (count($preview) < 10) {
                    $preview[] = ['name' => $name, 'email' => $email, 'action' => 'updated'];
                }
                continue;
            }

            $party = new Party([
                'name' => $name,
                'contact_person' => $name,
                'phone' => '',
                'email' => $email,
                'address' => '',
                'is_active' => true,
            ]);
            $errs = $party->validate();
            if (!empty($errs)) {
                $errors[] = "Row " . ($i + 2) . " ({$name}): " . implode(', ', $errs);
                continue;
            }
            $this->partyRepo->create($party);
            $created++;
            if (count($preview) < 10) {
                $preview[] = ['name' => $name, 'email' => $email, 'action' => 'created'];
            }
        }

        return [
            'success' => true,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'preview' => $preview,
        ];
    }

    private function findColumnIndex(array $headers, array $candidates): ?int
    {
        foreach ($headers as $idx => $h) {
            if (in_array($h, $candidates, true)) {
                return $idx;
            }
        }
        return null;
    }
}
