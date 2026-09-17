<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseRack extends Model
{
    protected $fillable = ['warehouse_zone_id', 'name', 'code'];

    public function zone()
    {
        return $this->belongsTo(WarehouseZone::class);
    }

    public function levels()
    {
        return $this->hasMany(WarehouseLevel::class);
    }
}