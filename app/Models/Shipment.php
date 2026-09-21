<?php

namespace App\Models;

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
        $prefix = 'SHP-' . date('Y') . '-';
        $last = self::where('shipment_no', 'like', $prefix . '%')
            ->orderByDesc('shipment_no')
            ->value('shipment_no');

        if ($last) {
            $seq = intval(substr($last, -5)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
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