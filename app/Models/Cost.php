<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cost extends Model
{
    const CATEGORIES = ['warehouse', 'freight', 'customs', 'last_mile', 'other'];

    protected $fillable = [
        'order_id',
        'shipment_id',
        'category',
        'amount',
        'currency',
        'description',
        'cost_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cost_date' => 'date',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}