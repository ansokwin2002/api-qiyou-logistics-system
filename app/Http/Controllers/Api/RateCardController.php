<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Package;
use App\Models\RateCard;
use App\Support\FeeCalculator;
use Illuminate\Http\Request;

/**
 * Fee Calculation (module 05) — rate cards + quote engine.
 */
class RateCardController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = RateCard::query()->with(['originWarehouse:id,name,code', 'destinationWarehouse:id,name,code']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('origin_country', 'like', "%{$search}%")
                    ->orWhere('destination_country', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(RateCard $rateCard)
    {
        return $this->ok($rateCard->load(['originWarehouse:id,name,code', 'destinationWarehouse:id,name,code']));
    }

    public function store(Request $request)
    {
        $rateCard = RateCard::create($this->validated($request));

        ActivityLog::record('rate_card.create', "Created rate card {$rateCard->name}");

        return $this->created($rateCard, 'Rate card created');
    }

    public function update(Request $request, RateCard $rateCard)
    {
        $rateCard->update($this->validated($request, true));

        ActivityLog::record('rate_card.update', "Updated rate card {$rateCard->name}");

        return $this->ok($rateCard, 'Rate card updated');
    }

    public function destroy(RateCard $rateCard)
    {
        $name = $rateCard->name;
        $rateCard->delete();

        ActivityLog::record('rate_card.delete', "Deleted rate card {$name}");

        return $this->ok(null, 'Rate card deleted');
    }

    /**
     * POST api/v1/estimate — price a shipment before the order exists.
     * Also used internally when order creation omits `estimated_fee`.
     */
    public function estimate(Request $request)
    {
        $data = $request->validate([
            'transport_method' => ['required', 'string', 'in:air,sea,land'],
            'chargeable_weight' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'origin_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'origin_country' => ['nullable', 'string', 'max:60'],
            'destination_country' => ['nullable', 'string', 'max:60'],
            'currency' => ['nullable', 'string', 'max:10'],
        ]);

        $chargeable = $data['chargeable_weight'] ?? null;

        if ($chargeable === null) {
            $chargeable = Package::make([
                'weight' => $data['weight'] ?? 0,
                'length' => $data['length'] ?? 0,
                'width' => $data['width'] ?? 0,
                'height' => $data['height'] ?? 0,
            ])->calculateWeights()->chargeable_weight;
        }

        $quote = FeeCalculator::quote($data, (float) $chargeable, (int) ($data['quantity'] ?? 1));

        return $this->ok([
            'chargeable_weight' => (float) $chargeable,
            'volumetric_weight' => Package::make([
                'weight' => $data['weight'] ?? 0,
                'length' => $data['length'] ?? 0,
                'width' => $data['width'] ?? 0,
                'height' => $data['height'] ?? 0,
            ])->calculateWeights()->volumetric_weight,
            'quantity' => (int) ($data['quantity'] ?? 1),
            ...$quote,
        ], 'Quote calculated');
    }

    protected function validated(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$rule, 'string', 'max:120'],
            'transport_method' => ['sometimes', 'string', 'in:air,sea,land'],
            'origin_country' => ['nullable', 'string', 'max:60'],
            'destination_country' => ['nullable', 'string', 'max:60'],
            'origin_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'first_kg' => ['nullable', 'numeric', 'min:0'],
            'base_fee' => ['nullable', 'numeric', 'min:0'],
            'rate_per_kg' => ['nullable', 'numeric', 'min:0'],
            'min_fee' => ['nullable', 'numeric', 'min:0'],
            'per_unit_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'status' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
