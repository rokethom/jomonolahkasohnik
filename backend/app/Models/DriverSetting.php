<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'multi_order_active',
    ];

    protected $casts = [
        'multi_order_active' => 'boolean',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
