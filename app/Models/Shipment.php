<?php

namespace App\Models;

use App\Support\NumberGenerator;
use Illuminate\Database\Eloquent\Model;

class Shipment extends Model
{
    protected $fillable = [
        'shipment_no',
        'order_id',
        'carrier',
        'status',
        'departed_at',
        'arrived_at',
        'notes',
    ];

    public static function generateShipmentNo(): string
    {
        // SHP-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'shipment_no', 'SHP');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function legs()
    {
        return $this->hasMany(ShipmentLeg::class)->orderBy('leg_no');
    }
}