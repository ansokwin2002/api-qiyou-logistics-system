<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentLeg extends Model
{
    protected $fillable = [
        'shipment_id',
        'leg_no',
        'origin_warehouse_id',
        'destination_warehouse_id',
        'transport_method',
        'status',
        'departure_date',
        'arrival_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'datetime',
            'arrival_date' => 'datetime',
        ];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function originWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }
}