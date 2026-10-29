<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Upi = 'upi';
    case Razorpay = 'razorpay';
    case WhatsappPay = 'whatsapp_pay';

    /** Paid before the kitchen sees the order. */
    public function isOnline(): bool
    {
        return in_array($this, [self::Razorpay, self::WhatsappPay], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Upi => 'UPI',
            self::Razorpay => 'Online (Razorpay link)',
            self::WhatsappPay => 'WhatsApp Pay',
        };
    }
}
