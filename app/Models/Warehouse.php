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
        'contact_name',
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
        $zoneIds = $this->zones()->select('id');

        return WarehouseBin::whereIn(
            'warehouse_level_id',
            WarehouseLevel::whereIn(
                'warehouse_rack_id',
                WarehouseRack::whereIn('warehouse_zone_id', $zoneIds)->select('id')
            )->select('id')
        );
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