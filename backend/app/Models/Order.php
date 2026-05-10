<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\OrderCodeGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_code',
        'user_id',
        'driver_id',
        'service_id',
        'branch_id',
        'service_type',
        'service_code',
        'pickup_address',
        'pickup_lat',
        'pickup_lng',
        'destination_address',
        'destination_lat',
        'destination_lng',
        'distance_km',
        'direction_bearing',
        'is_multi_order',
        'price',
        'service_charge',
        'extra_charge',
        'stops',
        'total_price',
        'source',
        'raw_text',
        'pricing_breakdown',
        'status',
        'cancelled_at',
        'expired_at',
        'geocoded_by',
        'locked_location_hash',
        'device_location_log_id',
        'notes',
        'payment_method',
        'payment_label',
        'payment_meta',
    ];

    protected $casts = [
        'pickup_lat' => 'decimal:8',
        'pickup_lng' => 'decimal:8',
        'destination_lat' => 'decimal:8',
        'destination_lng' => 'decimal:8',
        'distance_km' => 'decimal:2',
        'direction_bearing' => 'decimal:4',
        'is_multi_order' => 'boolean',
        'service_id' => 'integer',
        'branch_id' => 'integer',
        'price' => 'integer',
        'service_charge' => 'integer',
        'extra_charge' => 'integer',
        'stops' => 'integer',
        'total_price' => 'integer',
        'pricing_breakdown' => 'array',
        'status' => OrderStatus::class,
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'device_location_log_id' => 'integer',
        'payment_meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->order_code ??= self::generateOrderCode();
            $order->status ??= OrderStatus::Created;
        });
    }

    public static function generateOrderCode(): string
    {
        return app(OrderCodeGenerator::class)->generate();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(OrderPoint::class)->orderBy('sequence');
    }

    public function rating()
    {
        return $this->hasOne(Rating::class);
    }

    public function operHandleRequests(): HasMany
    {
        return $this->hasMany(OperHandleRequest::class);
    }

    public function crews(): HasMany
    {
        return $this->hasMany(OrderCrew::class);
    }
}
