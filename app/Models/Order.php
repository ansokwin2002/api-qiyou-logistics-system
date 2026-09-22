<?php

namespace App\Models;

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
        $prefix = 'ORD-' . date('Y') . '-';
        $last = self::where('order_no', 'like', $prefix . '%')
            ->orderByDesc('order_no')
            ->value('order_no');

        if ($last) {
            $seq = intval(substr($last, -5)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public static function generateTrackingNo(): string
    {
        $dateStr = date('Ymd');
        $prefix = 'KH' . $dateStr . '-';
        
        // Get last tracking number for today from database
        $last = self::where('tracking_ref', 'like', $prefix . '%')
            ->orderByDesc('tracking_ref')
            ->value('tracking_ref');
        
        if ($last) {
            // Extract the number part after KHYYYYMMDD-
            $num = intval(substr($last, -3)) + 1;
            // Reset if it exceeds 999 (start new day)
            if ($num > 999) {
                $num = 1;
            }
        } else {
            $num = 1;
        }
        
        return $prefix . str_pad((string) $num, 3, '0', STR_PAD_LEFT);
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