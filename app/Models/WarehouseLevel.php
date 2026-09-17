<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLevel extends Model
{
    protected $fillable = ['warehouse_rack_id', 'name', 'code'];

    public function rack()
    {
        return $this->belongsTo(WarehouseRack::class);
    }

    public function bins()
    {
        return $this->hasMany(WarehouseBin::class);
    }
}