<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTable extends Model
{
    protected $fillable = ['name', 'seats', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The bill still running on this table (not completed or cancelled). */
    public function openOrder(): HasOne
    {
        return $this->hasOne(Order::class)
            ->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()))
            ->latestOfMany();
    }
}
