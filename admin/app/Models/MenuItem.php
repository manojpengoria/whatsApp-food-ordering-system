<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    protected $fillable = [
        'menu_category_id', 'code', 'name', 'description', 'image_path', 'catalog_synced_at',
        'price_paise', 'is_veg', 'is_available', 'sort_order',
        'track_stock', 'stock_qty', 'low_stock_at',
    ];

    protected function casts(): array
    {
        return [
            'price_paise' => 'integer',
            'is_veg' => 'boolean',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
            'catalog_synced_at' => 'datetime',
            'track_stock' => 'boolean',
            'stock_qty' => 'integer',
            'low_stock_at' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    /** Retailer id used in the WhatsApp catalog. Stable even if the code changes. */
    public function getRetailerIdAttribute(): string
    {
        return 'wm_'.$this->id;
    }

    /** URL for the admin panel (served by Laravel). */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Public HTTPS URL Meta can download (served by the bot through the tunnel). */
    public function getPublicImageUrlAttribute(): ?string
    {
        $base = rtrim((string) config('restaurant.public_media_url'), '/');

        return ($this->image_path && $base !== '') ? $base.'/'.basename($this->image_path) : null;
    }

    /** Can a customer order this right now? (switched on, category visible, in stock) */
    public function isOrderable(): bool
    {
        return $this->is_available
            && ($this->category?->is_active ?? false)
            && (! $this->track_stock || $this->stock_qty > 0);
    }

    public function isOutOfStock(): bool
    {
        return $this->track_stock && $this->stock_qty <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_qty > 0 && $this->stock_qty <= $this->low_stock_at;
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function getPriceAttribute(): string
    {
        return Money::format($this->price_paise);
    }
}
