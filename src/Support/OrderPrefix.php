<?php

namespace App\Support;

/**
 * Helpers for per-company order number prefixes (e.g. JLDMPL-0001).
 */
final class OrderPrefix
{
    /** @var list<string> */
    private const SKIP_WORDS = ['AND', 'OF', 'THE', 'AMP', '&'];

    /** Multi-letter tokens kept whole when present as a word in the name. */
    private const KEEP_TOKENS = ['JLD', 'JL'];

    /**
     * Suggest initials from a company name.
     * Keeps known multi-letter tokens like JLD; skips &/and/of/the.
     */
    public static function suggestFromName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = str_replace(['&', '/', '.', ','], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $parts = array_values(array_filter(explode(' ', $name), static fn($p) => $p !== ''));

        $out = '';
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_WORDS, true)) {
                continue;
            }
            if (in_array($part, self::KEEP_TOKENS, true)) {
                $out .= $part;
                continue;
            }
            $out .= $part[0];
        }

        $out = preg_replace('/[^A-Z0-9]/', '', $out) ?? $out;
        if ($out === '') {
            $out = 'ORD';
        }
        return substr($out, 0, 20);
    }

    public static function format(string $prefix, int $sequence): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix) ?? $prefix);
        $width = max(4, strlen((string)$sequence));
        return $prefix . '-' . str_pad((string)$sequence, $width, '0', STR_PAD_LEFT);
    }
}
