<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['menu_item_id', 'change', 'balance_after', 'reason', 'order_id', 'user_id', 'note'];

    protected function casts(): array
    {
        return ['change' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'sale' => 'Sold',
            'cancel' => 'Order cancelled',
            'restock' => 'Restocked',
            default => 'Adjusted',
        };
    }
}
