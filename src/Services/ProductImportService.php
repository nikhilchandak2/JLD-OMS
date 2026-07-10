<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\ProductRepository;

/**
 * Import products from CSV: Product Name + HSN Code columns.
 */
class ProductImportService
{
    private ProductRepository $productRepo;

    private const NAME_HEADERS = [
        'product name', 'product', 'name', 'item', 'item name', 'material', 'description',
    ];
    private const HSN_HEADERS = [
        'hsn code', 'hsn', 'hsn no', 'hsn no.', 'hsn number', 'hsn/sac', 'sac code',
    ];

    public function __construct()
    {
        $this->productRepo = new ProductRepository();
    }

    /**
     * @return array{success: bool, created: int, updated: int, skipped: int, errors: array, preview: array, columns?: array}
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
        $headerRow = array_map(
            fn($h) => $this->normalizeHeader((string)$h),
            str_getcsv($lines[0], $delimiter)
        );

        $nameCol = $this->findColumnIndex($headerRow, self::NAME_HEADERS)
            ?? $this->findColumnByPattern($headerRow, '/product|item|material|name/i');
        $hsnCol = $this->findColumnIndex($headerRow, self::HSN_HEADERS)
            ?? $this->findColumnByPattern($headerRow, '/hsn|sac/i');

        $columns = [
            'name' => $nameCol,
            'hsn' => $hsnCol,
            'headers' => $headerRow,
            'delimiter' => $delimiter,
        ];

        if ($nameCol === null) {
            return $this->result(
                false,
                0,
                0,
                0,
                ['Could not find Product Name column. Found headers: ' . implode(', ', $headerRow)],
                [],
                $columns
            );
        }

        array_shift($lines);

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line, $delimiter);
            $rowNum = $i + 2;
            $name = trim((string)($row[$nameCol] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }

            $hsn = $hsnCol !== null ? trim((string)($row[$hsnCol] ?? '')) : '';
            $hsn = trim($hsn, " \t'\"");

            if ($hsnCol !== null && $hsn === '') {
                $errors[] = "Row {$rowNum} ({$name}): HSN Code is required";
                continue;
            }

            if ($hsn !== '' && !preg_match('/^[0-9A-Za-z.\-\/]{2,20}$/', $hsn)) {
                $errors[] = "Row {$rowNum} ({$name}): Invalid HSN Code ({$hsn})";
                continue;
            }

            $code = $this->productCodeFromName($name);
            $existing = $this->productRepo->findByName($name) ?? $this->productRepo->findByCode($code);

            if ($existing) {
                $updates = [];
                if ($hsn !== '' && ($existing->hsnCode ?? '') !== $hsn) {
                    $updates['hsn_code'] = $hsn;
                }
                if (!$existing->isActive) {
                    $updates['is_active'] = 1;
                }
                if ($updates !== []) {
                    $this->productRepo->update($existing->id, $updates);
                    $updated++;
                    $this->addPreview($preview, $name, $hsn, 'updated');
                } else {
                    $skipped++;
                }
                continue;
            }

            $product = new Product([
                'code' => $code,
                'name' => $name,
                'hsn_code' => $hsn,
                'is_active' => true,
            ]);

            $this->productRepo->create($product);
            $created++;
            $this->addPreview($preview, $name, $hsn, 'created');
        }

        $imported = $created + $updated;
        $success = $imported > 0 || ($imported === 0 && $skipped > 0 && empty($errors));
        if ($imported === 0 && !empty($errors)) {
            $success = false;
            array_unshift($errors, 'No products were imported. See row errors below.');
        }

        return $this->result($success, $created, $updated, $skipped, $errors, $preview, $columns);
    }

    private function productCodeFromName(string $name): string
    {
        $code = trim($name);
        if (strlen($code) > 50) {
            $code = substr($code, 0, 50);
        }
        return $code;
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

    private function normalizeHeader(string $header): string
    {
        $header = trim(strtolower($header));
        $header = preg_replace('/[^\p{L}\p{N}\s\/\-]/u', '', $header) ?? $header;
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;
        return trim($header);
    }

    /** @param array<int, array{name: string, hsn_code: string, action: string}> $preview */
    private function addPreview(array &$preview, string $name, string $hsn, string $action): void
    {
        if (count($preview) >= 10) {
            return;
        }
        $preview[] = ['name' => $name, 'hsn_code' => $hsn, 'action' => $action];
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
