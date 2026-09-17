<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Order;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\ShipmentLeg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Shipment::query()->with([
            'order:id,order_no,customer_id',
            'order.customer:id,name',
            'legs.originWarehouse:id,name,code',
            'legs.destinationWarehouse:id,name,code',
        ]);

        if ($search = $request->input('search')) {
            $query->where('shipment_no', 'like', "%{$search}%")
                ->orWhereHas('order', function ($q) use ($search) {
                    $q->where('order_no', 'like', "%{$search}%");
                });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Shipment $shipment)
    {
        $shipment->load([
            'order.customer',
            'order.packages',
            'legs.originWarehouse',
            'legs.destinationWarehouse',
        ]);

        return $this->ok($shipment);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $shipment = Shipment::create([
            'shipment_no' => Shipment::generateShipmentNo(),
            'order_id' => $data['order_id'],
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->created($shipment->load('order'), 'Shipment created');
    }

    public function destroy(Shipment $shipment)
    {
        $shipment->delete();

        return $this->ok(null, 'Shipment deleted');
    }

    public function addLeg(Request $request, Shipment $shipment)
    {
        $data = $request->validate([
            'origin_warehouse_id' => ['required', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'exists:warehouses,id'],
            'transport_method' => ['nullable', 'string', 'in:air,sea,land'],
            'departure_date' => ['nullable', 'date'],
            'arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $legNo = $shipment->legs()->count() + 1;

        $leg = $shipment->legs()->create([
            'leg_no' => $legNo,
            'origin_warehouse_id' => $data['origin_warehouse_id'],
            'destination_warehouse_id' => $data['destination_warehouse_id'],
            'transport_method' => $data['transport_method'] ?? 'air',
            'status' => 'pending',
            'departure_date' => $data['departure_date'] ?? null,
            'arrival_date' => $data['arrival_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->created($leg->load('originWarehouse', 'destinationWarehouse'), 'Leg added');
    }

    public function updateLeg(Request $request, ShipmentLeg $leg)
    {
        $data = $request->validate([
            'origin_warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'transport_method' => ['nullable', 'string', 'in:air,sea,land'],
            'status' => ['nullable', 'string', 'in:pending,in_transit,arrived,cancelled'],
            'departure_date' => ['nullable', 'date'],
            'arrival_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $leg->update($data);

        return $this->ok($leg->fresh(), 'Leg updated');
    }

    /**
     * Mark shipment as departed: sets the first un-departed leg in transit,
     * package status to in_transit.
     */
    public function depart(Shipment $shipment)
    {
        return DB::transaction(function () use ($shipment) {
            $leg = $shipment->legs()->where('status', 'pending')->orderBy('leg_no')->first();

            if (! $leg) {
                return $this->error('No pending legs to depart', 422);
            }

            $leg->update([
                'status' => 'in_transit',
                'departure_date' => now(),
            ]);

            $shipment->update([
                'status' => 'in_transit',
                'departed_at' => now(),
            ]);

            if ($shipment->order) {
                $shipment->order->update(['status' => Order::STATUS_IN_PROGRESS]);

                foreach ($shipment->order->packages as $package) {
                    $package->update(['current_warehouse_id' => $leg->destination_warehouse_id]);
                    $package->setStatus(Package::STATUS_IN_TRANSIT, $leg->originWarehouse->name ?? null, "Shipment {$shipment->shipment_no} departed on leg {$leg->leg_no}");
                }
            }

            return $this->ok($shipment->fresh()->load('legs'), 'Shipment departed');
        });
    }

    /**
     * Mark shipment leg as arrived at destination.
     */
    public function arriveLeg(ShipmentLeg $leg)
    {
        return DB::transaction(function () use ($leg) {
            $leg->update([
                'status' => 'arrived',
                'arrival_date' => now(),
            ]);

            $shipment = $leg->shipment;

            $remainingPending = $shipment->legs()->where('status', 'pending')->count();

            if ($remainingPending === 0) {
                $shipment->update([
                    'status' => 'completed',
                    'arrived_at' => now(),
                ]);
            }

            if ($shipment->order) {
                foreach ($shipment->order->packages as $package) {
                    $package->update(['current_warehouse_id' => $leg->destination_warehouse_id]);
                    if ($shipment->order->fulfillment_method === 'pickup') {
                        $package->setStatus(Package::STATUS_READY_FOR_PICKUP, $leg->destinationWarehouse->name ?? null, 'Shipment leg arrived');
                    } else {
                        $package->setStatus(Package::STATUS_ARRIVED, $leg->destinationWarehouse->name ?? null, 'Shipment leg arrived');
                    }
                }
            }

            return $this->ok($leg->fresh(), 'Leg arrived');
        });
    }
}