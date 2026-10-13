<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    protected $fillable = [
        'name',
        'transport_method',
        'origin_country',
        'destination_country',
        'origin_warehouse_id',
        'destination_warehouse_id',
        'first_kg',
        'base_fee',
        'rate_per_kg',
        'min_fee',
        'per_unit_fee',
        'currency',
        'status',
        'effective_from',
        'effective_to',
        'notes',
    ];

    protected $casts = [
        'first_kg' => 'decimal:3',
        'base_fee' => 'decimal:2',
        'rate_per_kg' => 'decimal:4',
        'min_fee' => 'decimal:2',
        'per_unit_fee' => 'decimal:2',
        'status' => 'boolean',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function originWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * Find the rate card that applies to a route on a given date.
     * Warehouse-specific cards win over country-level cards; the most recently
     * activated matching card wins overall.
     */
    public static function resolve(array $criteria): ?self
    {
        $today = now()->toDateString();

        $query = static::query()
            ->where('status', true)
            ->when($criteria['transport_method'] ?? null, fn ($q, $m) => $q->where('transport_method', $m))
            ->where(function ($q) use ($criteria, $today) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today);
            })
            ->where(function ($q) use ($criteria, $today) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today);
            });

        if (! empty($criteria['origin_warehouse_id'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('origin_warehouse_id')->orWhere('origin_warehouse_id', $criteria['origin_warehouse_id']);
            });
        }
        if (! empty($criteria['destination_warehouse_id'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('destination_warehouse_id')->orWhere('destination_warehouse_id', $criteria['destination_warehouse_id']);
            });
        }
        if (! empty($criteria['origin_country'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('origin_country')->orWhere('origin_country', $criteria['origin_country']);
            });
        }
        if (! empty($criteria['destination_country'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('destination_country')->orWhere('destination_country', $criteria['destination_country']);
            });
        }

        return $query->get()
            ->sortByDesc(fn ($card) => static::specificityOf($card, $criteria))
            ->first();
    }

    private static function specificityOf(self $card, array $criteria): int
    {
        $score = 0;
        if ($card->origin_warehouse_id && $card->origin_warehouse_id == ($criteria['origin_warehouse_id'] ?? null)) {
            $score += 4;
        }
        if ($card->destination_warehouse_id && $card->destination_warehouse_id == ($criteria['destination_warehouse_id'] ?? null)) {
            $score += 4;
        }
        if ($card->origin_country && $card->origin_country === ($criteria['origin_country'] ?? null)) {
            $score += 2;
        }
        if ($card->destination_country && $card->destination_country === ($criteria['destination_country'] ?? null)) {
            $score += 2;
        }

        return $score;
    }
}
