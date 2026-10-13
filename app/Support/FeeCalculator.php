<?php

namespace App\Support;

use App\Models\RateCard;

/**
 * Fee Calculation (module 05).
 *
 * Chargeable weight is already derived on the package as
 * max(actual weight, volumetric weight). This service turns a chargeable
 * weight + route into a logistics fee using the active rate card.
 */
class FeeCalculator
{
    /**
     * @return array{amount: float, currency: string, rate_card_id: int|null, breakdown: array}
     */
    public static function quote(array $criteria, float $chargeableWeight, int $quantity = 1): array
    {
        $card = RateCard::resolve($criteria);

        if (! $card) {
            return [
                'amount' => 0.0,
                'currency' => $criteria['currency'] ?? 'USD',
                'rate_card_id' => null,
                'breakdown' => ['note' => 'No active rate card matched this route.'],
            ];
        }

        $firstKg = (float) $card->first_kg;
        $included = min($chargeableWeight, $firstKg);
        $extra = max($chargeableWeight - $firstKg, 0);

        $amount = (float) $card->base_fee
            + ($extra * (float) $card->rate_per_kg)
            + (max($quantity - 1, 0) * (float) $card->per_unit_fee);

        $minFee = (float) $card->min_fee;
        $beforeMin = $amount;
        $amount = max($amount, $minFee);

        return [
            'amount' => round($amount, 2),
            'currency' => $card->currency,
            'rate_card_id' => $card->id,
            'breakdown' => [
                'rate_card' => $card->name,
                'chargeable_weight' => $chargeableWeight,
                'first_kg' => $firstKg,
                'weight_included_in_base' => round($included, 3),
                'chargeable_weight_over_base' => round($extra, 3),
                'base_fee' => (float) $card->base_fee,
                'additional_weight_fee' => round($extra * (float) $card->rate_per_kg, 2),
                'additional_units' => max($quantity - 1, 0),
                'unit_fee' => (float) $card->per_unit_fee,
                'subtotal' => round($beforeMin, 2),
                'minimum_applied' => $minFee > 0 && $beforeMin < $minFee,
                'min_fee' => $minFee,
                'total' => round($amount, 2),
            ],
        ];
    }

    /**
     * Total chargeable weight of an order's packages.
     */
    public static function orderChargeableWeight($order): float
    {
        $packages = $order->relationLoaded('packages') ? $order->packages : $order->packages()->get();

        return round((float) $packages->sum('chargeable_weight'), 3);
    }
}
