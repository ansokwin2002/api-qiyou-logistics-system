<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Order;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Order::query()->with([
            'customer:id,name',
            'originWarehouse:id,name,code',
            'destinationWarehouse:id,name,code',
        ]);

        if ($search = $request->input('search')) {
            $query->where('order_no', 'like', "%{$search}%")
                ->orWhereHas('customer', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Order $order)
    {
        $order->load([
            'customer',
            'originWarehouse',
            'destinationWarehouse',
            'packages.currentWarehouse',
            'packages.currentBin.level.rack.zone',
            'trackingEvents' => fn ($q) => $q->latest(),
        ]);

        return $this->ok($order);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'origin_warehouse_id' => ['required', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'exists:warehouses,id'],
            'transport_method' => ['nullable', 'string', 'in:air,sea,land'],
            'payment_method' => ['nullable', 'string', 'in:prepaid,cod,both'],
            'fulfillment_method' => ['nullable', 'string', 'in:delivery,pickup'],
            'estimated_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.description' => ['nullable', 'string'],
            'packages.*.weight' => ['required', 'numeric', 'min:0'],
            'packages.*.length' => ['nullable', 'numeric', 'min:0'],
            'packages.*.width' => ['nullable', 'numeric', 'min:0'],
            'packages.*.height' => ['nullable', 'numeric', 'min:0'],
            'packages.*.quantity' => ['nullable', 'integer', 'min:1'],
            'packages.*.declared_value' => ['nullable', 'numeric', 'min:0'],
            'packages.*.currency' => ['nullable', 'string', 'max:10'],
        ]);

        return DB::transaction(function () use ($data) {
            $order = Order::create([
                'order_no' => Order::generateOrderNo(),
                'tracking_ref' => Order::generateTrackingNo(),
                'customer_id' => $data['customer_id'],
                'origin_warehouse_id' => $data['origin_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'transport_method' => $data['transport_method'] ?? 'air',
                'payment_method' => $data['payment_method'] ?? 'prepaid',
                'fulfillment_method' => $data['fulfillment_method'] ?? 'delivery',
                'status' => Order::STATUS_PENDING,
                'estimated_fee' => $data['estimated_fee'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['packages'] as $pkgData) {
                $package = new Package([
                    'package_no' => Package::generatePackageNo(),
                    'barcode' => Package::generateBarcode(),
                    'description' => $pkgData['description'] ?? null,
                    'weight' => $pkgData['weight'],
                    'length' => $pkgData['length'] ?? 0,
                    'width' => $pkgData['width'] ?? 0,
                    'height' => $pkgData['height'] ?? 0,
                    'quantity' => $pkgData['quantity'] ?? 1,
                    'declared_value' => $pkgData['declared_value'] ?? 0,
                    'currency' => $pkgData['currency'] ?? $data['currency'] ?? 'USD',
                    'status' => Package::STATUS_PENDING,
                    'current_warehouse_id' => $data['origin_warehouse_id'],
                ]);
                $package->calculateWeights();
                $order->packages()->save($package);
            }

            $order->load('packages');

            return $this->created($order, 'Order created');
        });
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate([
            'customer_id' => ['sometimes', 'exists:customers,id'],
            'origin_warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['sometimes', 'exists:warehouses,id'],
            'transport_method' => ['nullable', 'string', 'in:air,sea,land'],
            'payment_method' => ['nullable', 'string', 'in:prepaid,cod,both'],
            'fulfillment_method' => ['nullable', 'string', 'in:delivery,pickup'],
            'status' => ['nullable', 'string', 'in:pending,confirmed,in_progress,completed,cancelled'],
            'estimated_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $order->update($data);

        return $this->ok($order, 'Order updated');
    }

    public function destroy(Order $order)
    {
        $order->delete();

        return $this->ok(null, 'Order deleted');
    }

    public function track(Request $request, ?string $query = null)
    {
        $search = trim($query ?? $request->input('search', ''));

        if ($search === '') {
            return $this->error('Tracking number required', 422);
        }

        $bare = ltrim($search, '#');

        $package = Package::with([
            'order.customer',
            'order.originWarehouse',
            'order.destinationWarehouse',
            'trackingEvents' => fn ($q) => $q->orderByDesc('created_at'),
        ])->where(function ($q) use ($search, $bare) {
            $q->where('barcode', $search)
                ->orWhere('package_no', $search)
                ->orWhereHas('order', function ($o) use ($search, $bare) {
                    $o->where('order_no', $search)
                        ->orWhere('tracking_ref', $search)
                        ->orWhere('tracking_ref', $bare)
                        ->orWhere('tracking_ref', '#' . $bare);
                });
        })->first();

        if (! $package) {
            return $this->error('Package or order not found', 404);
        }

        return $this->ok($package);
    }
}