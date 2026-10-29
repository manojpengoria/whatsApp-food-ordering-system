<?php

namespace App\Support;

final class Phone
{
    /** WhatsApp ids are digits only, 8–15 long (E.164 without "+"). */
    public static function isValidWaId(?string $waId): bool
    {
        return is_string($waId) && preg_match('/^\d{8,15}$/', $waId) === 1;
    }

    /** 919876543210 -> +91 98765 43210 (for display to staff). */
    public static function display(string $waId): string
    {
        if (str_starts_with($waId, '91') && strlen($waId) === 12) {
            return '+91 '.substr($waId, 2, 5).' '.substr($waId, 7);
        }

        return '+'.$waId;
    }

    /** For logs: 919876543210 -> 91******3210 */
    public static function mask(string $waId): string
    {
        if (strlen($waId) <= 6) {
            return str_repeat('*', strlen($waId));
        }

        return substr($waId, 0, 2).str_repeat('*', strlen($waId) - 6).substr($waId, -4);
    }
}
