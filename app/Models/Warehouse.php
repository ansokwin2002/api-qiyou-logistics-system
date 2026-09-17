<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'country',
        'phone',
        'type',
        'status',
        'notes',
    ];

    public function zones()
    {
        return $this->hasMany(WarehouseZone::class);
    }

    public function bins()
    {
        return $this->hasManyThrough(WarehouseBin::class, WarehouseZone::class);
    }

    public function ordersAsOrigin()
    {
        return $this->hasMany(Order::class, 'origin_warehouse_id');
    }

    public function ordersAsDestination()
    {
        return $this->hasMany(Order::class, 'destination_warehouse_id');
    }
}