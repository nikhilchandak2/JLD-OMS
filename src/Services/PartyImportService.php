<?php

namespace App\Services;

use App\Repositories\PartyRepository;
use App\Models\Party;

/**
 * Import parties from CSV.
 * Supports: party name + GST (+ optional email). Handles Excel comma/semicolon CSV.
 */
class PartyImportService
{
    private PartyRepository $partyRepo;

    private const NAME_HEADERS = [
        'parties', 'party', 'name', 'customer', 'company', 'party name',
        'customer name', 'party/customer name', 'buyer name',
    ];
    private const EMAIL_HEADERS = ['email', 'e-mail', 'email address'];
    private const GST_HEADERS = [
        'gst', 'gst no', 'gst no.', 'gst number', 'gstin', 'gstin no',
        'gstin number', 'gstin/uin', 'gst no ', 'gstin/uin of recipient',
    ];

    public function __construct()
    {
        $this->partyRepo = new PartyRepository();
    }

    /**
     * @return array{
     *   success: bool,
     *   created: int,
     *   updated: int,
     *   skipped: int,
     *   errors: array,
     *   preview: array,
     *   columns?: array{name: ?int, gst: ?int, email: ?int, headers: array}
     * }
     */
    public function importFromCsv(string $csvContent): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $preview = [];

        $csvContent = $this->stripBom($csvContent);
        $lines = preg_split('/\r\n|\r|\n/', $csvContent);
        $lines = array_values(array_filter($lines, static fn($line) => trim($line) !== ''));

        if (count($lines) < 2) {
            return $this->result(false, 0, 0, 0, ['CSV must have a header row and at least one data row.'], []);
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headerRow = $this->parseRow($lines[0], $delimiter);
        $headerRow = array_map(fn($h) => $this->normalizeHeader((string)$h), $headerRow);

        $nameCol = $this->findColumnIndex($headerRow, self::NAME_HEADERS)
            ?? $this->findColumnByPattern($headerRow, '/party|parties|customer|buyer|name/i');
        $emailCol = $this->findColumnIndex($headerRow, self::EMAIL_HEADERS)
            ?? $this->findColumnByPattern($headerRow, '/e-?mail/i');
        $gstCol = $this->findColumnIndex($headerRow, self::GST_HEADERS)
            ?? $this->findColumnByPattern($headerRow, '/gst|gstin/i');

        $columns = [
            'name' => $nameCol,
            'gst' => $gstCol,
            'email' => $emailCol,
            'headers' => $headerRow,
            'delimiter' => $delimiter,
        ];

        if ($nameCol === null) {
            return $this->result(
                false,
                0,
                0,
                0,
                [
                    'Could not find a party name column. Found headers: ' . implode(', ', $headerRow),
                ],
                [],
                $columns
            );
        }

        array_shift($lines);

        foreach ($lines as $i => $line) {
            $row = $this->parseRow($line, $delimiter);
            $rowNum = $i + 2;
            $name = trim((string)($row[$nameCol] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }

            $email = $emailCol !== null ? trim((string)($row[$emailCol] ?? '')) : '';
            $gstRaw = $gstCol !== null ? trim((string)($row[$gstCol] ?? '')) : '';
            $gstRaw = trim($gstRaw, " \t'\"");
            $gst = Party::normalizeGstNumber($gstRaw);

            if ($gstCol !== null && $gst === '') {
                $errors[] = "Row {$rowNum} ({$name}): GST number is required";
                continue;
            }

            if ($gst !== '' && !Party::isValidGstFormat($gst)) {
                $errors[] = "Row {$rowNum} ({$name}): Invalid GST number ({$gstRaw})";
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

        $imported = $created + $updated;
        $success = $imported > 0 || ($imported === 0 && $skipped > 0 && empty($errors));
        if ($imported === 0 && !empty($errors)) {
            $success = false;
            array_unshift($errors, 'No parties were imported. See row errors below.');
        }

        return $this->result($success, $created, $updated, $skipped, $errors, $preview, $columns);
    }

    private function stripBom(string $content): string
    {
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            return substr($content, 3);
        }
        if (substr($content, 0, 2) === "\xFF\xFE" || substr($content, 0, 2) === "\xFE\xFF") {
            return mb_convert_encoding($content, 'UTF-8', 'UTF-16');
        }
        return $content;
    }

    private function detectDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        $tab = substr_count($line, "\t");
        if ($semi > $comma && $semi >= $tab) {
            return ';';
        }
        if ($tab > $comma && $tab > $semi) {
            return "\t";
        }
        return ',';
    }

    /** @return array<int, string> */
    private function parseRow(string $line, string $delimiter): array
    {
        return str_getcsv($line, $delimiter);
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim(strtolower($header));
        $header = preg_replace('/[^\p{L}\p{N}\s\/\-]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;
        return trim($header);
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

    private function findColumnByPattern(array $headers, string $pattern): ?int
    {
        foreach ($headers as $idx => $h) {
            if ($h !== '' && preg_match($pattern, $h)) {
                return $idx;
            }
        }
        return null;
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, array{name: string, email: string, gst_number: string, action: string}> $preview
     * @param array<string, mixed>|null $columns
     * @return array{success: bool, created: int, updated: int, skipped: int, errors: array, preview: array, columns?: array}
     */
    private function result(
        bool $success,
        int $created,
        int $updated,
        int $skipped,
        array $errors,
        array $preview,
        ?array $columns = null
    ): array {
        $out = [
            'success' => $success,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'preview' => $preview,
        ];
        if ($columns !== null) {
            $out['columns'] = $columns;
        }
        return $out;
    }
}
