<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'customer_id', 'status', 'order_type', 'source',
        'delivery_address', 'notes', 'subtotal_paise', 'tax_rate_bp', 'tax_paise',
        'delivery_fee_paise', 'total_paise', 'payment_method', 'payment_status',
        'paid_at', 'cancel_reason', 'idempotency_key',
        'dining_table_id', 'guest_name', 'guest_phone', 'created_by',
        'payment_link_id', 'payment_link_url', 'payment_ref', 'payment_reference', 'visible_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'order_type' => OrderType::class,
            'payment_method' => PaymentMethod::class,
            'payment_status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'visible_at' => 'datetime',
            'subtotal_paise' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_paise' => 'integer',
            'delivery_fee_paise' => 'integer',
            'total_paise' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Name to show staff: WhatsApp customer, walk-in guest name, or "Walk-in". */
    public function getCustomerLabelAttribute(): string
    {
        return $this->customer?->display_name ?? ($this->guest_name ?: 'Walk-in guest');
    }

    public function getCustomerPhoneAttribute(): ?string
    {
        return $this->customer?->phone ?? $this->guest_phone;
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->order_type->label().($this->diningTable ? ' · '.$this->diningTable->name : '');
    }

    public function getSourceLabelAttribute(): string
    {
        return $this->source === 'pos' ? 'Counter' : 'WhatsApp';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(OrderNotification::class)->latest('id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::active()));
    }

    /** Real orders: not cancelled and not still waiting for an online payment. */
    public function scopeNotCancelled(Builder $q): Builder
    {
        return $q->whereNotIn('status', OrderStatus::hiddenValues());
    }

    /** Everything staff are allowed to see (hides unpaid online orders). */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('status', '!=', OrderStatus::AwaitingPayment->value);
    }

    public function getTotalAttribute(): string
    {
        return Money::format($this->total_paise);
    }

    public function getItemsCountAttribute(): int
    {
        return (int) ($this->attributes['items_quantity'] ?? $this->items->sum('quantity'));
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }
}
