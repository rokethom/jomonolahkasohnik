<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNoteReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_note_id',
        'author_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::created(function (InternalNoteReply $reply): void {
            $reply->note()->update([
                'last_activity_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(InternalNote::class, 'internal_note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
