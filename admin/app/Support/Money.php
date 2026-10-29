<?php

namespace App\Support;

final class Money
{
    /** 17900 paise -> "₹179"; 17950 -> "₹179.50"; Indian digit grouping (₹1,23,456). */
    public static function format(int $paise): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);
        $rupees = intdiv($paise, 100);
        $fraction = $paise % 100;

        $digits = (string) $rupees;
        if (strlen($digits) > 3) {
            $last3 = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $digits = $rest.','.$last3;
        }

        $out = '₹'.$digits.($fraction ? '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) : '');

        return $negative ? '-'.$out : $out;
    }

    /** "179.50" or "179" -> 17950 paise. Returns null for invalid input. */
    public static function parseRupees(string|int|float|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$r, $f] = array_pad(explode('.', $value, 2), 2, '0');

        return (int) $r * 100 + (int) str_pad($f, 2, '0');
    }

    public static function toRupeesString(int $paise): string
    {
        return $paise % 100 === 0
            ? (string) intdiv($paise, 100)
            : number_format($paise / 100, 2, '.', '');
    }
}
