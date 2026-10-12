<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * An Ethiopian mobile number in +2519XXXXXXXX / +2517XXXXXXXX form, or null when the input is
     * not one (for example a landline, which cannot receive SMS).
     * Accepts 0911 234 567, 911234567, 251911234567, +251-911-234-567 and similar.
     */
    public static function ethiopianMobile(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/[\s\-().]/', '', $raw);

        if (preg_match('/^(?:\+?251|0)?([79]\d{8})$/', $digits, $m)) {
            return '+251'.$m[1];
        }

        return null;
    }

    /**
     * The number with all but the last 3 digits hidden, for logs and admin screens.
     */
    public static function mask(string $number): string
    {
        return strlen($number) <= 7 ? $number : substr($number, 0, 4).str_repeat('*', strlen($number) - 7).substr($number, -3);
    }
}
