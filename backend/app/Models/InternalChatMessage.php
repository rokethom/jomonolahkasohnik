<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_chat_room_id',
        'sender_id',
        'message',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(InternalChatRoom::class, 'internal_chat_room_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
