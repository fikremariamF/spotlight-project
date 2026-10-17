<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * ETB amounts are stored as integer santim. These helpers convert at the API boundary
 * using string arithmetic so no float rounding ever touches stored values.
 */
final class Money
{
    /**
     * Convert a birr amount given as "1500", "1500.5" or "1500.50" to santim.
     */
    public static function toSantim(string|int|float $birr): int
    {
        $value = is_float($birr) ? number_format($birr, 2, '.', '') : trim((string) $birr);

        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $m)) {
            throw new InvalidArgumentException("Invalid ETB amount: {$value}");
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * Format santim as a birr decimal string, e.g. 150050 -> "1500.50", -150050 -> "-1500.50".
     */
    public static function toBirr(?int $santim): ?string
    {
        if ($santim === null) {
            return null;
        }
        $abs = abs($santim);

        return ($santim < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
