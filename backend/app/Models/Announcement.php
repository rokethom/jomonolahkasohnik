<?php

namespace App\Models;

use App\Models\Concerns\HasActiveDateRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasActiveDateRange;
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];
}
