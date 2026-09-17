<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodCollection extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_COLLECTED = 'collected';
    const STATUS_PARTIAL = 'partial';
    const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'delivery_id',
        'order_id',
        'package_id',
        'expected_amount',
        'collected_amount',
        'difference',
        'collection_via',
        'settlement_status',
        'collected_by_id',
        'collected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'collected_at' => 'datetime',
        ];
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function collectedBy()
    {
        return $this->belongsTo(User::class, 'collected_by_id');
    }
}