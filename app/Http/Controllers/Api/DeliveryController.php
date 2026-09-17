<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CodCollection;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Delivery::query()->with([
            'order:id,order_no,payment_method,fulfillment_method,customer_id',
            'order.customer:id,name,phone',
            'package:id,package_no,barcode,status',
            'driver:id,name',
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receiver_name', 'like', "%{$search}%")
                    ->orWhere('receiver_phone', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_no', 'like', "%{$search}%");
                    })
                    ->orWhereHas('package', function ($p) use ($search) {
                        $p->where('barcode', 'like', "%{$search}%")
                            ->orWhere('package_no', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($driverId = $request->input('driver_id')) {
            $query->where('driver_id', $driverId);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Delivery $delivery)
    {
        $delivery->load(['order.customer', 'package', 'driver', 'codCollection']);

        return $this->ok($delivery);
    }

    /**
     * Create a delivery task for an order (delivery or self-pickup).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'type' => ['required', 'string', 'in:delivery,pickup'],
            'driver_id' => ['nullable', 'exists:users,id'],
            'receiver_name' => ['nullable', 'string'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $order = Order::findOrFail($data['order_id']);

        if ($data['type'] === Delivery::TYPE_PICKUP && $order->fulfillment_method !== 'pickup') {
            return $this->error('This order is not set up for self-pickup', 422);
        }

        if ($data['type'] === Delivery::TYPE_DELIVERY && $order->fulfillment_method !== 'delivery') {
            return $this->error('This order is set up for self-pickup, not delivery', 422);
        }

        $packageId = $data['package_id'] ?? $order->packages()->value('id');

        $delivery = Delivery::create([
            'order_id' => $order->id,
            'package_id' => $packageId,
            'type' => $data['type'],
            'driver_id' => $data['driver_id'] ?? null,
            'status' => Delivery::STATUS_PENDING,
            'ready_at' => now(),
            'receiver_name' => $data['receiver_name'] ?? null,
            'receiver_phone' => $data['receiver_phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($packageId) {
            $package = Package::find($packageId);
            if ($package && $data['type'] === Delivery::TYPE_PICKUP) {
                $package->setStatus(Package::STATUS_READY_FOR_PICKUP, null, 'Ready for pickup');
            }
        }

        return $this->created($delivery->load('order', 'package'), 'Delivery task created');
    }

    public function update(Request $request, Delivery $delivery)
    {
        $data = $request->validate([
            'driver_id' => ['nullable', 'exists:users,id'],
            'receiver_name' => ['nullable', 'string'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
        ]);

        $delivery->update($data);

        return $this->ok($delivery->fresh(), 'Delivery task updated');
    }

    /**
     * Assign a driver and mark out for delivery.
     */
    public function assignDriver(Request $request, Delivery $delivery)
    {
        $data = $request->validate([
            'driver_id' => ['required', 'exists:users,id'],
        ]);

        $delivery->update([
            'driver_id' => $data['driver_id'],
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'assigned_at' => now(),
        ]);

        if ($delivery->package) {
            $delivery->package->setStatus(Package::STATUS_OUT_FOR_DELIVERY, null, 'Out for delivery');
        }

        if ($delivery->order) {
            $delivery->order->update(['status' => Order::STATUS_IN_PROGRESS]);
        }

        return $this->ok($delivery->fresh()->load('driver'), 'Driver assigned, delivery started');
    }

    /**
     * Complete a self-pickup: verify receiver identity, collect COD if any.
     */
    public function completePickup(Request $request, Delivery $delivery)
    {
        if ($delivery->type !== Delivery::TYPE_PICKUP) {
            return $this->error('This is not a pickup task', 422);
        }

        $data = $request->validate([
            'receiver_name' => ['required', 'string'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'receiver_id_type' => ['required', 'string', 'in:national_id,passport,tax_id'],
            'receiver_id_number' => ['required', 'string'],
            'signature' => ['nullable', 'string'],
            'cod_collected' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($delivery, $data) {
            $delivery->update([
                'status' => Delivery::STATUS_PICKED_UP,
                'delivered_at' => now(),
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'receiver_id_type' => $data['receiver_id_type'],
                'receiver_id_number' => $data['receiver_id_number'],
                'signature' => $data['signature'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->settleCod($delivery, $data, 'warehouse');

            if ($delivery->package) {
                $delivery->package->setStatus(
                    Package::STATUS_PICKED_UP,
                    $delivery->package->currentWarehouse?->name,
                    'Picked up by customer'
                );
            }

            if ($delivery->order) {
                $delivery->order->update(['status' => Order::STATUS_COMPLETED]);
            }

            return $this->ok($delivery->fresh()->load('package', 'codCollection'), 'Pickup completed');
        });
    }

    /**
     * Complete a delivery: proof of receipt, COD collection, driver signature in.
     */
    public function completeDelivery(Request $request, Delivery $delivery)
    {
        if ($delivery->type !== Delivery::TYPE_DELIVERY) {
            return $this->error('This is not a delivery task', 422);
        }

        $data = $request->validate([
            'receiver_name' => ['required', 'string'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'signature' => ['nullable', 'string'],
            'cod_collected' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($delivery, $data) {
            $delivery->update([
                'status' => Delivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'signature' => $data['signature'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->settleCod($delivery, $data, 'driver');

            if ($delivery->package) {
                $delivery->package->setStatus(
                    Package::STATUS_DELIVERED,
                    null,
                    'Delivered to ' . ($delivery->receiver_name ?? 'recipient')
                );
            }

            if ($delivery->order) {
                $delivery->order->update(['status' => Order::STATUS_COMPLETED]);
            }

            return $this->ok($delivery->fresh()->load('package', 'codCollection'), 'Delivery completed');
        });
    }

    public function markFailed(Request $request, Delivery $delivery)
    {
        $data = $request->validate([
            'issue_reason' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $delivery->update([
            'status' => Delivery::STATUS_FAILED,
            'issue_reason' => $data['issue_reason'],
            'notes' => $data['notes'] ?? $delivery->notes,
        ]);

        if ($delivery->package) {
            $delivery->package->setStatus(
                $delivery->type === Delivery::TYPE_PICKUP ? Package::STATUS_READY_FOR_PICKUP : Package::STATUS_ARRIVED,
                null,
                'Delivery failed: ' . $data['issue_reason']
            );
        }

        return $this->ok($delivery->fresh(), 'Delivery marked as failed');
    }

    public function destroy(Delivery $delivery)
    {
        $delivery->delete();

        return $this->ok(null, 'Delivery task deleted');
    }

    /**
     * Auto-create COD collection record when an order is cash on delivery.
     */
    protected function settleCod(Delivery $delivery, array $data, string $via): void
    {
        $order = $delivery->order;

        if (! $order || $order->payment_method !== 'cod') {
            return;
        }

        $expected = $order->estimated_fee;
        $collected = isset($data['cod_collected']) ? floatval($data['cod_collected']) : $expected;

        $status = $collected >= $expected
            ? CodCollection::STATUS_COLLECTED
            : ($collected > 0 ? CodCollection::STATUS_PARTIAL : CodCollection::STATUS_PENDING);

        CodCollection::updateOrCreate(
            ['delivery_id' => $delivery->id],
            [
                'order_id' => $order->id,
                'package_id' => $delivery->package_id,
                'expected_amount' => $expected,
                'collected_amount' => $collected,
                'difference' => round($expected - $collected, 2),
                'collection_via' => $via,
                'settlement_status' => $status,
                'collected_by_id' => auth()->id(),
                'collected_at' => $collected > 0 ? now() : null,
                'notes' => $data['notes'] ?? null,
            ]
        );
    }
}