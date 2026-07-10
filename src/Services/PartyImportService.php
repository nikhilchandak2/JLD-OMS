<?php

namespace App\Services;

use App\Repositories\PartyRepository;
use App\Models\Party;

/**
 * Import parties from CSV.
 * Supports: party name + optional email + optional GST (gst, gst no, gstin, etc.).
 */
class PartyImportService
{
    private PartyRepository $partyRepo;

    private const NAME_HEADERS = ['parties', 'party', 'name', 'customer', 'company', 'party name'];
    private const EMAIL_HEADERS = ['email', 'e-mail', 'email address'];
    private const GST_HEADERS = ['gst', 'gst no', 'gst no.', 'gst number', 'gstin', 'gstin no'];

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
        $gstCol = $this->findColumnIndex($headerRow, self::GST_HEADERS);

        if ($nameCol === null) {
            return [
                'success' => false,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['Could not find a party name column (Parties, Party, Name, etc.).'],
                'preview' => [],
            ];
        }

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = str_getcsv($line);
            $rowNum = $i + 2;
            $name = trim((string)($row[$nameCol] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }

            $email = $emailCol !== null ? trim((string)($row[$emailCol] ?? '')) : '';
            $gstRaw = $gstCol !== null ? trim((string)($row[$gstCol] ?? '')) : '';
            $gst = Party::normalizeGstNumber($gstRaw);

            if ($gstCol !== null && $gst === '') {
                $errors[] = "Row {$rowNum} ({$name}): GST number is required";
                continue;
            }

            if ($gst !== '' && !Party::isValidGstFormat($gst)) {
                $errors[] = "Row {$rowNum} ({$name}): Invalid GST number format";
                continue;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$rowNum} ({$name}): Invalid email format";
                continue;
            }

            if ($email === '') {
                $email = $this->placeholderEmail($name);
            }

            $existing = $this->partyRepo->findByName($name);
            if ($existing) {
                $updates = [];
                if ($gst !== '' && $existing->gstNumber !== $gst) {
                    $dup = $this->partyRepo->findByGstNumber($gst, $existing->id);
                    if ($dup) {
                        $errors[] = "Row {$rowNum} ({$name}): GST already exists";
                        continue;
                    }
                    $updates['gst_number'] = $gst;
                }
                if ($email !== '' && $existing->email !== $email) {
                    $updates['email'] = $email;
                }

                if ($updates !== []) {
                    $this->partyRepo->update($existing->id, $updates);
                    $updated++;
                    $this->addPreview($preview, $name, $email, $gst, 'updated');
                } else {
                    $skipped++;
                }
                continue;
            }

            if ($gst !== '') {
                $dup = $this->partyRepo->findByGstNumber($gst);
                if ($dup) {
                    $errors[] = "Row {$rowNum} ({$name}): GST already exists";
                    continue;
                }
            }

            $party = new Party([
                'name' => $name,
                'contact_person' => $name,
                'gst_number' => $gst,
                'phone' => '-',
                'email' => $email,
                'address' => '',
                'is_active' => true,
            ]);

            $this->partyRepo->create($party);
            $created++;
            $this->addPreview($preview, $name, $email, $gst, 'created');
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

    private function placeholderEmail(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-') ?: 'party';
        return $slug . '@import.jldminerals.com';
    }

    /** @param array<int, array{name: string, email: string, gst_number: string, action: string}> $preview */
    private function addPreview(array &$preview, string $name, string $email, string $gst, string $action): void
    {
        if (count($preview) >= 10) {
            return;
        }
        $preview[] = [
            'name' => $name,
            'email' => $email,
            'gst_number' => $gst,
            'action' => $action,
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
