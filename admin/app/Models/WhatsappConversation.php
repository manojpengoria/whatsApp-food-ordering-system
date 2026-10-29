<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Written only by the Node bot. Read-only in Laravel. */
class WhatsappConversation extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }
}
