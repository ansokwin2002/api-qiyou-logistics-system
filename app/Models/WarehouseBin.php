<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseBin extends Model
{
    protected $fillable = ['warehouse_level_id', 'name', 'code', 'status'];

    public function level()
    {
        return $this->belongsTo(WarehouseLevel::class);
    }
}