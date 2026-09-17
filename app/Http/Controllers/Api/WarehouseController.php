<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Warehouse;
use App\Models\WarehouseBin;
use App\Models\WarehouseLevel;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Warehouse::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        return $this->ok($query->orderBy('id', 'desc')->paginate($request->integer('per_page', 15)));
    }

    public function show(Warehouse $warehouse)
    {
        return $this->ok($warehouse->load('zones.racks.levels.bins'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50', 'unique:warehouses,code'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'in:origin,destination,both'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $warehouse = Warehouse::create($data);

        return $this->created($warehouse, 'Warehouse created');
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:warehouses,code,'.$warehouse->id],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'in:origin,destination,both'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $warehouse->update($data);

        return $this->ok($warehouse, 'Warehouse updated');
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return $this->ok(null, 'Warehouse deleted');
    }

    public function structure(Warehouse $warehouse)
    {
        $structure = $warehouse->zones()->with('racks.levels.bins')->get();

        return $this->ok($structure);
    }

    public function storeZone(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        $zone = $warehouse->zones()->create($data);

        return $this->created($zone, 'Zone created');
    }

    public function storeRack(Request $request, WarehouseZone $zone)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        $rack = $zone->racks()->create($data);

        return $this->created($rack, 'Rack created');
    }

    public function storeLevel(Request $request, WarehouseRack $rack)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        $level = $rack->levels()->create($data);

        return $this->created($level, 'Level created');
    }

    public function storeBin(Request $request, WarehouseLevel $level)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:50', 'unique:warehouse_bins,code'],
        ]);

        $bin = $level->bins()->create($data);

        return $this->created($bin, 'Bin created');
    }

    public function updateBin(Request $request, WarehouseBin $bin)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:warehouse_bins,code,'.$bin->id],
            'status' => ['nullable', 'string', 'in:available,occupied,disabled'],
        ]);

        $bin->update($data);

        return $this->ok($bin, 'Bin updated');
    }

    public function destroyBin(WarehouseBin $bin)
    {
        $bin->delete();

        return $this->ok(null, 'Bin deleted');
    }

    public function destroyZone(WarehouseZone $zone)
    {
        $zone->delete();

        return $this->ok(null, 'Zone deleted');
    }

    public function destroyRack(WarehouseRack $rack)
    {
        $rack->delete();

        return $this->ok(null, 'Rack deleted');
    }

    public function destroyLevel(WarehouseLevel $level)
    {
        $level->delete();

        return $this->ok(null, 'Level deleted');
    }
}