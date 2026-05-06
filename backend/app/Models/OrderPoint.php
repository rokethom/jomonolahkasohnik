<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'sequence',
        'label',
        'address',
        'lat',
        'lng',
        'geocoded_by',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'lat' => 'decimal:8',
        'lng' => 'decimal:8',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
