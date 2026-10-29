<?php

namespace App\Enums;

enum OrderType: string
{
    case Delivery = 'delivery';
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Takeaway => 'Takeaway',
            self::DineIn => 'Dine-in',
        };
    }
}
