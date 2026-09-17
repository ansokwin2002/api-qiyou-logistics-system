<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Package;
use App\Models\Warehouse;
use App\Models\WarehouseBin;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Package::query()->with([
            'order:id,order_no,customer_id,fulfillment_method',
            'order.customer:id,name',
            'currentWarehouse:id,name,code',
            'currentBin:id,code',
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('package_no', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_no', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('current_warehouse_id', $warehouseId);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Package $package)
    {
        $package->load([
            'order.customer',
            'order.originWarehouse',
            'order.destinationWarehouse',
            'currentWarehouse',
            'currentBin.level.rack.zone',
            'trackingEvents' => fn ($q) => $q->latest(),
        ]);

        return $this->ok($package);
    }

    public function scan(Request $request)
    {
        $data = $request->validate([
            'barcode' => ['required', 'string'],
            'location' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
        ]);

        $package = Package::where('barcode', $data['barcode'])
            ->orWhere('package_no', $data['barcode'])
            ->first();

        if (! $package) {
            return $this->error('Package not found', 404);
        }

        return $this->ok([
            'package' => $package->load([
                'order.customer',
                'order.originWarehouse',
                'order.destinationWarehouse',
                'currentWarehouse',
            ]),
            'allowed_next_statuses' => $package->status === Package::STATUS_PENDING
                ? [Package::STATUS_RECEIVED]
                : [],
        ]);
    }

    public function receive(Request $request)
    {
        $data = $request->validate([
            'barcode' => ['required', 'string'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'bin_id' => ['nullable', 'exists:warehouse_bins,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        $package = Package::where('barcode', $data['barcode'])
            ->orWhere('package_no', $data['barcode'])
            ->first();

        if (! $package) {
            return $this->error('Package not found', 404);
        }

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        if ($data['quantity'] ?? null) {
            $package->quantity = $data['quantity'];
        }

        $package->current_warehouse_id = $warehouse->id;

        if (! empty($data['bin_id'])) {
            $bin = WarehouseBin::findOrFail($data['bin_id']);
            $package->current_bin_id = $bin->id;
            $bin->update(['status' => 'occupied']);
        }

        $package->setStatus(Package::STATUS_RECEIVED, $warehouse->name, $data['note'] ?? 'Package received');

        return $this->ok($package->fresh(), 'Package received');
    }

    public function assignBin(Request $request, Package $package)
    {
        $data = $request->validate([
            'bin_id' => ['required', 'exists:warehouse_bins,id'],
            'note' => ['nullable', 'string'],
        ]);

        $bin = WarehouseBin::findOrFail($data['bin_id']);

        $package->update([
            'current_bin_id' => $bin->id,
            'status' => Package::STATUS_IN_WAREHOUSE,
        ]);

        $bin->update(['status' => 'occupied']);

        $package->setStatus(Package::STATUS_IN_WAREHOUSE, $bin->code, $data['note'] ?? 'Assigned to bin '.$bin->code);

        return $this->ok($package->fresh()->load('currentBin'), 'Package stored');
    }

    public function move(Request $request, Package $package)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'bin_id' => ['nullable', 'exists:warehouse_bins,id'],
            'note' => ['nullable', 'string'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $oldBin = $package->current_bin_id ? WarehouseBin::find($package->current_bin_id) : null;

        $package->update([
            'current_warehouse_id' => $warehouse->id,
            'current_bin_id' => $data['bin_id'] ?? null,
        ]);

        $package->setStatus(Package::STATUS_IN_WAREHOUSE, $warehouse->name, $data['note'] ?? 'Package moved');

        return $this->ok($package->fresh(), 'Package moved');
    }
}