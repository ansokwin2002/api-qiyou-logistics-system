<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    const TYPE_DELIVERY = 'delivery';
    const TYPE_PICKUP = 'pickup';

    const STATUS_PENDING = 'pending';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'package_id',
        'type',
        'driver_id',
        'status',
        'ready_at',
        'assigned_at',
        'delivered_at',
        'receiver_name',
        'receiver_phone',
        'receiver_id_type',
        'receiver_id_number',
        'signature',
        'issue_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ready_at' => 'datetime',
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function codCollection()
    {
        return $this->hasOne(CodCollection::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return str_replace('_', ' ', ucfirst($this->status));
    }
}