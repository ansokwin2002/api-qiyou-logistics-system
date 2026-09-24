<?php

namespace App\Models;

use App\Support\NumberGenerator;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_RECEIVED = 'received';
    const STATUS_IN_WAREHOUSE = 'in_warehouse';
    const STATUS_CUSTOMS = 'customs';
    const STATUS_CLEARED = 'cleared';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_ARRIVED = 'arrived';
    const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_PICKED_UP = 'picked_up';

    const VOLUMETRIC_FACTOR = 5000;

    protected $fillable = [
        'order_id',
        'package_no',
        'barcode',
        'description',
        'weight',
        'length',
        'width',
        'height',
        'volumetric_weight',
        'chargeable_weight',
        'quantity',
        'declared_value',
        'currency',
        'status',
        'current_warehouse_id',
        'current_bin_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'volumetric_weight' => 'decimal:3',
            'chargeable_weight' => 'decimal:3',
            'declared_value' => 'decimal:2',
        ];
    }

    public static function generatePackageNo(): string
    {
        // PKG-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'package_no', 'PKG');
    }

    public static function generateBarcode(): string
    {
        return 'QL' . date('YmdHis') . random_int(1000, 9999);
    }

    /**
     * Calculate volumetric weight, chargeable weight and update the model.
     * Standard formula: (length * width * height in cm) / 5000 = volumetric weight kg
     * Chargeable weight = max(actual weight, volumetric weight)
     */
    public function calculateWeights(int $factor = self::VOLUMETRIC_FACTOR): self
    {
        $volumetric = 0;
        if ($this->length && $this->width && $this->height && $factor > 0) {
            $volumetric = round(($this->length * $this->width * $this->height) / $factor, 3);
        }

        $this->volumetric_weight = $volumetric;
        $this->chargeable_weight = max($this->weight, $volumetric);

        return $this;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function currentWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'current_warehouse_id');
    }

    public function currentBin()
    {
        return $this->belongsTo(WarehouseBin::class, 'current_bin_id');
    }

    public function trackingEvents()
    {
        return $this->morphMany(TrackingEvent::class, 'trackable');
    }

    public function setStatus(string $status, ?string $location = null, ?string $note = null): self
    {
        $this->status = $status;
        $this->save();

        $this->trackingEvents()->create([
            'status' => $status,
            'location' => $location,
            'note' => $note,
            'actor_name' => auth()->user()?->name ?? 'system',
            'actor_id' => auth()->id(),
        ]);

        return $this;
    }
}