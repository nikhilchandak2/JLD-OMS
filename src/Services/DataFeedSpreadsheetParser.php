<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads CSV or Excel into a header + row list. Does not validate business rules.
 */
class DataFeedSpreadsheetParser
{
    private array $config;

    public function __construct()
    {
        $this->config = require dirname(__DIR__, 2) . '/config/data_feeds.php';
    }

    /**
     * @return array{headers: string[], rows: array<int, array{row_number: int, raw: array<string,string>}>}
     */
    public function parse(string $content, string $filename): array
    {
        if ($content === '' || trim($content) === '') {
            throw new DataFeedException('Empty file.');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            return $this->parseSpreadsheet($content, $ext);
        }

        return $this->parseCsv($content);
    }

    /** @return array{headers: string[], rows: array<int, array{row_number: int, raw: array<string,string>}>} */
    private function parseCsv(string $content): array
    {
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, static fn($line) => trim((string)$line) !== ''));
        if ($lines === []) {
            throw new DataFeedException('Empty file.');
        }

        $headerCells = str_getcsv(array_shift($lines));
        $canonical = $this->canonicalizeHeaders($headerCells);

        $rows = [];
        $rowNumber = 2;
        foreach ($lines as $line) {
            $cells = str_getcsv((string)$line);
            $raw = $this->rowFromCells($canonical, $cells);
            if ($this->isBlank($raw)) {
                $rowNumber++;
                continue;
            }
            $rows[] = ['row_number' => $rowNumber, 'raw' => $raw];
            $rowNumber++;
        }

        return ['headers' => array_values(array_filter($canonical)), 'rows' => $rows];
    }

    /** @return array{headers: string[], rows: array<int, array{row_number: int, raw: array<string,string>}>} */
    private function parseSpreadsheet(string $content, string $ext): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jldfeed_');
        if ($tmp === false) {
            throw new DataFeedException('Could not create a temporary file for the spreadsheet.');
        }
        $path = $tmp . '.' . $ext;
        rename($tmp, $path);
        file_put_contents($path, $content);

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $matrix = $sheet->toArray(null, true, true, false);
        } finally {
            @unlink($path);
        }

        if ($matrix === []) {
            throw new DataFeedException('Empty file.');
        }

        $headerCells = array_map(static fn($v) => (string)($v ?? ''), array_shift($matrix) ?? []);
        $canonical = $this->canonicalizeHeaders($headerCells);

        $rows = [];
        $rowNumber = 2;
        foreach ($matrix as $cells) {
            $cells = array_map(static fn($v) => $v === null ? '' : (string)$v, $cells);
            $raw = $this->rowFromCells($canonical, $cells);
            if ($this->isBlank($raw)) {
                $rowNumber++;
                continue;
            }
            $rows[] = ['row_number' => $rowNumber, 'raw' => $raw];
            $rowNumber++;
        }

        return ['headers' => array_values(array_filter($canonical)), 'rows' => $rows];
    }

    /** @param array<int,mixed> $headerCells */
    private function canonicalizeHeaders(array $headerCells): array
    {
        $aliases = $this->config['header_aliases'];
        $canonical = [];
        foreach ($headerCells as $i => $header) {
            $normalized = $this->normalizeHeader((string)$header);
            $underscored = str_replace(' ', '_', $normalized);
            $key = '';
            foreach ($aliases as $canonicalKey => $candidates) {
                $spaced = str_replace('_', ' ', $canonicalKey);
                if (
                    $normalized === $canonicalKey
                    || $underscored === $canonicalKey
                    || $normalized === $spaced
                    || in_array($normalized, $candidates, true)
                ) {
                    $key = $canonicalKey;
                    break;
                }
            }
            $canonical[$i] = $key;
        }

        return $canonical;
    }

    /** @param array<int,string> $canonical @param array<int,mixed> $cells */
    private function rowFromCells(array $canonical, array $cells): array
    {
        $raw = [];
        foreach ($canonical as $i => $key) {
            if ($key === '') {
                continue;
            }
            $raw[$key] = trim((string)($cells[$i] ?? ''));
        }

        return $raw;
    }

    private function isBlank(array $raw): bool
    {
        foreach ($raw as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', ' ', $header) ?? $header;

        return $header;
    }
}
