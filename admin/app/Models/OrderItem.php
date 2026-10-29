<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'menu_item_id', 'item_name', 'item_code',
        'unit_price_paise', 'quantity', 'line_total_paise',
    ];

    protected function casts(): array
    {
        return [
            'unit_price_paise' => 'integer',
            'quantity' => 'integer',
            'line_total_paise' => 'integer',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
