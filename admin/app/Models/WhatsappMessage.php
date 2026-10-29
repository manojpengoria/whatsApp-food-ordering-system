<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Written only by the Node bot. Read-only in Laravel. */
class WhatsappMessage extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
