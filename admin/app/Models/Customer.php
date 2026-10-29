<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = ['wa_id', 'name', 'last_delivery_address'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: Phone::display($this->wa_id);
    }

    public function getPhoneAttribute(): string
    {
        return Phone::display($this->wa_id);
    }
}
