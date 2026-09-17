<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Cost;
use Illuminate\Http\Request;

class CostController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Cost::query()->with([
            'order:id,order_no',
            'shipment:id,shipment_no',
        ]);

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_no', 'like', "%{$search}%");
                    });
            });
        }

        if ($from = $request->input('from')) {
            $query->where('cost_date', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('cost_date', '<=', $to);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Cost $cost)
    {
        return $this->ok($cost->load('order', 'shipment'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'exists:orders,id'],
            'shipment_id' => ['nullable', 'exists:shipments,id'],
            'category' => ['required', 'string', 'in:warehouse,freight,customs,last_mile,other'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'cost_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $cost = Cost::create([
            'order_id' => $data['order_id'] ?? null,
            'shipment_id' => $data['shipment_id'] ?? null,
            'category' => $data['category'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'USD',
            'description' => $data['description'] ?? null,
            'cost_date' => $data['cost_date'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->created($cost, 'Cost recorded');
    }

    public function update(Request $request, Cost $cost)
    {
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'in:warehouse,freight,customs,last_mile,other'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'cost_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $cost->update($data);

        return $this->ok($cost->fresh(), 'Cost updated');
    }

    public function destroy(Cost $cost)
    {
        $cost->delete();

        return $this->ok(null, 'Cost deleted');
    }
}