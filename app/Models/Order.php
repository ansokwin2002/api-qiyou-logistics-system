<?php

namespace App\Models;

use App\Support\NumberGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'order_no',
        'tracking_ref',
        'customer_id',
        'sender_name',
        'sender_phone',
        'origin_warehouse_id',
        'destination_warehouse_id',
        'transport_method',
        'payment_method',
        'fulfillment_method',
        'receiver_name',
        'receiver_phone',
        'receiver_address',
        'status',
        'estimated_fee',
        'currency',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_fee' => 'decimal:2',
        ];
    }

    public static function generateOrderNo(): string
    {
        // ORD-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'order_no', 'ORD');
    }

    public static function generateTrackingNo(): string
    {
        // KH-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'tracking_ref', 'KH');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function originWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function trackingEvents()
    {
        return $this->morphMany(TrackingEvent::class, 'trackable');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return str_replace('_', ' ', ucfirst($this->status));
    }
}