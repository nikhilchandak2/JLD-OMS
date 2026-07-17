<?php

namespace App\Support;

/**
 * Indian date convention for the OMS.
 *
 * Display / text input: DD/MM/YYYY (e.g. 01/07/2026 = 1 July 2026)
 * Database / HTML date inputs / APIs (normalized): YYYY-MM-DD
 *
 * MySQL DATE columns must stay as YYYY-MM-DD for sorting and comparisons.
 * All user-facing text dates use the Indian format.
 */
class IndianDate
{
    public const DISPLAY = 'd/m/Y';
    public const STORAGE = 'Y-m-d';

    /**
     * Parse a date string into YYYY-MM-DD for database storage.
     * Prefers Indian DD/MM/YYYY (and DD-MM-YYYY). Also accepts ISO YYYY-MM-DD
     * (from HTML &lt;input type="date"&gt;). Never treats MM/DD/YYYY as US format.
     */
    public static function toStorage(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Already ISO date or datetime prefix
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
            return null;
        }

        // Indian numeric: 01/07/2026, 1-7-2026, 01.07.2026
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = (int)$m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        // 01/07/26 (2-digit year → 2000+)
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2})$/', $value, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $year = 2000 + (int)$m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return null;
        }

        // Textual: 01 Jul 2026, 1-Jul-2026
        $textFormats = ['d M Y', 'd-M-Y', 'd/M/Y', 'j M Y', 'j-M-Y', 'd F Y', 'j F Y'];
        foreach ($textFormats as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            if ($dt instanceof \DateTime) {
                $errors = \DateTime::getLastErrors();
                if (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0) {
                    return $dt->format(self::STORAGE);
                }
            }
        }

        return null;
    }

    public static function isValid(?string $value): bool
    {
        return self::toStorage($value) !== null;
    }

    /**
     * Format a storage date (YYYY-MM-DD or datetime) as DD/MM/YYYY.
     */
    public static function format(?string $value, string $fallback = '—'): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }

        $storage = self::toStorage($value);
        if ($storage === null) {
            // Try datetime "Y-m-d H:i:s"
            $dt = \DateTime::createFromFormat('Y-m-d H:i:s', trim($value));
            if ($dt) {
                return $dt->format(self::DISPLAY);
            }
            return $fallback;
        }

        $dt = \DateTime::createFromFormat(self::STORAGE, $storage);
        return $dt ? $dt->format(self::DISPLAY) : $fallback;
    }

    /**
     * Format datetime as DD/MM/YYYY HH:MM.
     */
    public static function formatDateTime(?string $value, string $fallback = '—'): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }

        $value = trim($value);
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value)
            ?: \DateTime::createFromFormat('Y-m-d H:i', $value)
            ?: (self::toStorage($value) ? \DateTime::createFromFormat(self::STORAGE, self::toStorage($value)) : false);

        return $dt ? $dt->format('d/m/Y H:i') : $fallback;
    }

    /** Today as YYYY-MM-DD (for DB / HTML date inputs). */
    public static function todayStorage(): string
    {
        return date(self::STORAGE);
    }

    /** Today as DD/MM/YYYY (for display). */
    public static function todayDisplay(): string
    {
        return date(self::DISPLAY);
    }
}
