<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappConversation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'context_data' => 'array',
        'is_active' => 'boolean',
        'last_interaction' => 'datetime',
    ];

    public function messages()
    {
        return $this->hasMany(WhatsappMessage::class, 'conversation_id');
    }
}
