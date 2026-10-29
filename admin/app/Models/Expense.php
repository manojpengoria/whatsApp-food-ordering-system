<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = ['Groceries', 'Vegetables', 'Dairy', 'Gas & Fuel', 'Salary', 'Rent', 'Electricity', 'Packaging', 'Repairs', 'Marketing', 'Other'];

    protected $fillable = ['spent_on', 'category', 'description', 'amount_paise', 'payment_method', 'user_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount_paise' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAmountAttribute(): string
    {
        return Money::format($this->amount_paise);
    }
}
