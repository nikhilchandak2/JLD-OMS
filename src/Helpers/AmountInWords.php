<?php

namespace App\Helpers;

/**
 * Convert amount (number) to Indian Rupees in words.
 * e.g. 437892 -> "FOUR LAKH THIRTY SEVEN THOUSAND EIGHT HUNDRED NINETY TWO ONLY"
 */
class AmountInWords
{
    private static array $ones = [
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
        'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'
    ];

    private static array $tens = [
        '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'
    ];

    public static function toRupees($amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $num = (float) preg_replace('/[^0-9.]/', '', (string) $amount);
        $intPart = (int) floor($num);
        $decPart = (int) round(($num - $intPart) * 100);

        $words = self::toWords($intPart);
        if ($words === '') {
            $words = 'ZERO';
        }
        $words .= ' ONLY';
        if ($decPart > 0) {
            $words .= ' AND ' . self::toWords($decPart) . ' PAISE ONLY';
        }
        return $words;
    }

    private static function toWords(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n < 20) {
            return self::$ones[$n];
        }
        if ($n < 100) {
            return trim(self::$tens[(int)($n / 10)] . ' ' . self::toWords($n % 10));
        }
        if ($n < 1000) {
            $h = (int)($n / 100);
            $r = $n % 100;
            return trim(self::toWords($h) . ' HUNDRED ' . self::toWords($r));
        }
        if ($n < 100000) {
            $th = (int)($n / 1000);
            $r = $n % 1000;
            return trim(self::toWords($th) . ' THOUSAND ' . self::toWords($r));
        }
        if ($n < 10000000) {
            $lakh = (int)($n / 100000);
            $r = $n % 100000;
            return trim(self::toWords($lakh) . ' LAKH ' . self::toWords($r));
        }
        $crore = (int)($n / 10000000);
        $r = $n % 10000000;
        return trim(self::toWords($crore) . ' CRORE ' . self::toWords($r));
    }
}
