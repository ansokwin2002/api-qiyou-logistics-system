<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CodCollection;
use App\Models\Cost;
use App\Models\Customer;
use App\Models\CustomsDeclaration;
use App\Models\Delivery;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Package;
use App\Models\Shipment;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;

/**
 * Compatibility controller for the legacy frontend admin panel.
 * Maps manageapi/* CRUD-style endpoints to the backend's data layer.
 */
class ManageApiController extends Controller
{
    use ApiResponse;

    /**
     * Response format compatible with frontend admin panel.
     * Frontend expects: { code: '1', message: '...', data: ... }
     */
    protected function frontendOk($data = null, string $message = 'Success')
    {
        return response()->json([
            'code' => '1',
            'message' => $message,
            'data' => $data,
        ], 200);
    }

    protected function frontendError(string $message = 'Error', int $status = 400)
    {
        return response()->json([
            'code' => '0',
            'message' => $message,
            'data' => null,
        ], $status);
    }

    /**
     * Extract parameters from both query string and nested JSON body.
     * Frontend sends: { data: { key: value } }
     */
    protected function getParams(Request $request)
    {
        $bodyData = $request->input('data', []);
        if (!is_array($bodyData)) {
            $bodyData = [];
        }
        return array_merge($request->all(), $bodyData);
    }

    // ==================== ORDER ====================

    public function orderList(Request $request)
    {
        $params = $this->getParams($request);
        $keyword = $params['keyword'] ?? null;
        $status = $params['status'] ?? null;
        $transportMethod = $params['transportMethod'] ?? null;
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $query = Order::with([
            'customer:id,name,email,phone',
            'originWarehouse:id,name,code',
            'destinationWarehouse:id,name,code',
            'packages',
        ]);

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%")
                    ->orWhere('tracking_ref', 'like', "%{$keyword}%")
                    ->orWhereHas('customer', function ($cq) use ($keyword) {
                        $cq->where('name', 'like', "%{$keyword}%")
                            ->orWhere('email', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status) {
            $query->where('status', $this->normalizeStatus($status));
        }

        if ($transportMethod) {
            $query->where('transport_method', strtolower($transportMethod));
        }

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        // Transform orders to frontend schema
        $items = $paginator->getCollection()->map(function ($order) {
            return $this->transformOrderForFrontend($order);
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function orderGet(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $order = Order::with([
            'customer',
            'originWarehouse',
            'destinationWarehouse',
            'packages',
        ])->find($id);

        if (! $order) {
            return $this->frontendError('Order not found', 404);
        }

        return $this->frontendOk($this->transformOrderForFrontend($order));
    }

    public function orderAdd(Request $request)
    {
        $params = $this->getParams($request);
        $data = $request->validate([
            'customer_id' => ['sometimes', 'exists:customers,id'],
            'customerName' => ['sometimes', 'string', 'max:255'],
            'origin' => ['sometimes', 'string', 'max:255'],
            'destination' => ['sometimes', 'string', 'max:255'],
            'transportMethod' => ['nullable', 'string'],
            'fulfillmentMethod' => ['nullable', 'string'],
            'chargeableWeight' => ['nullable', 'numeric'],
            'logisticsFee' => ['nullable', 'numeric', 'min:0'],
            'codAmount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string'],
            'remark' => ['nullable', 'string'],
        ]);

        // Merge data from nested object
        $data = array_merge($data, $params);

        // Resolve linked records from the dialog's free-text fields
        $customerId = $data['customer_id'] ?? null;
        if (! $customerId && ! empty($data['customerName'])) {
            $customerId = Customer::where('name', 'like', '%' . $data['customerName'] . '%')->value('id');
        }
        $originId = $this->warehouseIdByName($data['origin'] ?? null);
        $destinationId = $this->warehouseIdByName($data['destination'] ?? null);
        $fulfillment = strtolower($data['fulfillmentMethod'] ?? 'delivery');
        if ($fulfillment === 'self-pickup') {
            $fulfillment = 'pickup';
        }

        return DB::transaction(function () use ($data, $customerId, $originId, $destinationId, $fulfillment) {
            $order = Order::create([
                'order_no' => Order::generateOrderNo(),
                'tracking_ref' => Order::generateTrackingNo(),
                'customer_id' => $customerId,
                'origin_warehouse_id' => $originId,
                'destination_warehouse_id' => $destinationId,
                'transport_method' => strtolower($data['transportMethod'] ?? 'air'),
                'payment_method' => ($data['codAmount'] ?? 0) > 0 ? 'cod' : 'prepaid',
                'fulfillment_method' => $fulfillment,
                'status' => $this->normalizeStatus($data['status'] ?? Order::STATUS_PENDING),
                'estimated_fee' => $data['logisticsFee'] ?? 0,
                'currency' => 'USD',
                'notes' => $data['remark'] ?? null,
            ]);

            // Every order needs at least one package so it flows through
            // warehouse / shipment / delivery screens.
            $package = new Package([
                'package_no' => Package::generatePackageNo(),
                'barcode' => Package::generateBarcode(),
                'description' => 'General cargo',
                'weight' => max((float) ($data['chargeableWeight'] ?? 1), 0.1),
                'quantity' => 1,
                'declared_value' => 0,
                'currency' => 'USD',
                'status' => Package::STATUS_PENDING,
                'current_warehouse_id' => $originId,
            ]);
            $package->calculateWeights();
            $order->packages()->save($package);

            // First tracking event so the tracking page shows the new order.
            $order->trackingEvents()->create([
                'status' => $order->status,
                'location' => optional($order->originWarehouse)->name,
                'note' => 'Order created',
                'actor_name' => 'Admin',
            ]);

            // COD expectation is tracked from the start.
            if ($order->payment_method === 'cod' && $order->estimated_fee > 0) {
                CodCollection::create([
                    'order_id' => $order->id,
                    'expected_amount' => $order->estimated_fee,
                    'collected_amount' => 0,
                    'difference' => 0,
                    'settlement_status' => CodCollection::STATUS_PENDING,
                ]);
            }

            return $this->frontendOk([
                'id' => $order->id,
                'code' => '1',
                'message' => 'Order created',
            ]);
        });
    }

    public function orderEdit(Request $request)
    {
        $params = $this->getParams($request);
        $data = array_merge($request->all(), $params);

        $order = Order::find($data['id'] ?? null);

        if (! $order) {
            return $this->frontendError('Order not found', 404);
        }

        $updateData = [];
        if (isset($data['transportMethod'])) {
            $updateData['transport_method'] = strtolower($data['transportMethod']);
        }
        if (isset($data['fulfillmentMethod'])) {
            $updateData['fulfillment_method'] = strtolower($data['fulfillmentMethod']);
        }
        if (isset($data['logisticsFee'])) {
            $updateData['estimated_fee'] = $data['logisticsFee'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = $this->normalizeStatus($data['status']);
        }
        if (isset($data['remark'])) {
            $updateData['notes'] = $data['remark'];
        }
        if (isset($data['codAmount'])) {
            $updateData['payment_method'] = $data['codAmount'] > 0 ? 'cod' : 'prepaid';
        }

        $order->update($updateData);

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Order updated',
        ]);
    }

    public function orderDel(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $order = Order::find($id);

        if (! $order) {
            return $this->frontendError('Order not found', 404);
        }

        $order->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Order deleted',
        ]);
    }

    // ==================== CUSTOMER ====================

    public function customerList(Request $request)
    {
        $query = Customer::withCount('orders');

        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%");
            });
        }

        $page = $request->integer('page', 1);
        $pageSize = $request->integer('pageSize', 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        return $this->frontendOk([
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function customerAdd(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $customer = Customer::create($data);

        return $this->frontendOk([
            'id' => $customer->id,
            'code' => '1',
            'message' => 'Customer created',
        ]);
    }

    public function customerEdit(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'exists:customers,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
        ]);

        $customer = Customer::find($data['id']);
        $customer->update(Arr::except($data, 'id'));

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Customer updated',
        ]);
    }

    public function customerDel(Request $request)
    {
        $id = $request->input('id');
        $customer = Customer::find($id);

        if (! $customer) {
            return $this->frontendError('Customer not found', 404);
        }

        $customer->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Customer deleted',
        ]);
    }

    // ==================== WAREHOUSE ====================

    public function warehouseList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Warehouse::withCount(['ordersAsOrigin', 'ordersAsDestination']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%")
                    ->orWhere('city', 'like', "%{$keyword}%");
            });
        }

        if ($type = ($params['type'] ?? null)) {
            $dbType = ['origin' => 'origin', 'destination' => 'destination', 'transit' => 'both'][strtolower($type)] ?? strtolower($type);
            $query->where('type', $dbType);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderBy('name')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($w) {
            return [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'address' => $w->address,
                'location' => trim(($w->city ?? '') . ', ' . ($w->country ?? ''), ' ,'),
                'city' => $w->city,
                'country' => $w->country,
                'type' => ['origin' => 'Origin', 'destination' => 'Destination', 'both' => 'Transit'][$w->type] ?? $w->type,
                'contactName' => $w->contact_name,
                'phone' => $w->phone,
                'state' => $w->status === 'active' ? 1 : 0,
                'ordersCount' => ($w->orders_as_origin_count ?? 0) + ($w->orders_as_destination_count ?? 0),
                'addTime' => $w->created_at ? strtotime($w->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function warehouseGet(Request $request)
    {
        $id = $request->input('id');
        $warehouse = Warehouse::with(['zones.racks.levels.bins'])->find($id);

        if (! $warehouse) {
            return $this->frontendError('Warehouse not found', 404);
        }

        return $this->frontendOk($warehouse);
    }

    public function warehouseAdd(Request $request)
    {
        $params = $this->getParams($request);
        $data = $this->mapWarehouseParams(array_merge($request->all(), $params));

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'unique:warehouses,code'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:origin,destination,both'],
            'status' => ['nullable', 'string'],
        ])->validate();

        $warehouse = Warehouse::create($validated);

        return $this->frontendOk([
            'id' => $warehouse->id,
            'code' => '1',
            'message' => 'Warehouse created',
        ]);
    }

    public function warehouseEdit(Request $request)
    {
        $params = $this->getParams($request);
        $data = $this->mapWarehouseParams(array_merge($request->all(), $params));

        $validated = validator($data, [
            'id' => ['required', 'exists:warehouses,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'unique:warehouses,code,' . ($data['id'] ?? 0)],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:origin,destination,both'],
            'status' => ['nullable', 'string'],
        ])->validate();

        $warehouse = Warehouse::find($validated['id']);
        $warehouse->update(Arr::except($validated, 'id'));

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Warehouse updated',
        ]);
    }

    /**
     * Translate the admin dialog's frontend fields (contactName, location,
     * state, Transit) into warehouse table columns.
     */
    protected function mapWarehouseParams(array $data): array
    {
        if (array_key_exists('contactName', $data)) {
            $data['contact_name'] = $data['contactName'];
        }
        if (! empty($data['location']) && empty($data['city'])) {
            $parts = array_map('trim', explode(',', $data['location'], 2));
            $data['city'] = $parts[0] ?? null;
            $data['country'] = $parts[1] ?? ($parts[0] ?? null);
        }
        if (array_key_exists('state', $data)) {
            $data['status'] = ((int) $data['state'] === 1) ? 'active' : 'inactive';
        }
        if (! empty($data['type'])) {
            $data['type'] = ['origin' => 'origin', 'destination' => 'destination', 'transit' => 'both'][strtolower($data['type'])] ?? $data['type'];
        }

        return $data;
    }

    public function warehouseDel(Request $request)
    {
        $id = $request->input('id');
        $warehouse = Warehouse::find($id);

        if (! $warehouse) {
            return $this->frontendError('Warehouse not found', 404);
        }

        $warehouse->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Warehouse deleted',
        ]);
    }

    // ==================== PACKAGE ====================

    public function packageList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Package::with([
            'order:id,order_no,customer_id',
            'currentWarehouse:id,name',
            'currentBin:id,code',
        ]);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('package_no', 'like', "%{$keyword}%")
                    ->orWhere('barcode', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($p) {
            return [
                'id' => $p->id,
                'packageNo' => $p->package_no,
                'barcode' => $p->barcode,
                'orderNo' => $p->order?->order_no ?? 'N/A',
                'itemName' => $p->description,
                'quantity' => $p->quantity,
                'weight' => (float) $p->weight,
                'volumetricWeight' => (float) $p->volumetric_weight,
                'chargeableWeight' => (float) $p->chargeable_weight,
                'binLocation' => $p->currentBin?->code ?? $p->currentWarehouse?->name,
                'status' => strtoupper(str_replace('_', ' ', $p->status)),
                'declaredValue' => (float) $p->declared_value,
                'addTime' => $p->created_at ? strtotime($p->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function packageAdd(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'description' => ['nullable', 'string'],
            'weight' => ['required', 'numeric', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $package = new Package([
            'order_id' => $data['order_id'],
            'package_no' => Package::generatePackageNo(),
            'barcode' => Package::generateBarcode(),
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'],
            'length' => $data['length'] ?? 0,
            'width' => $data['width'] ?? 0,
            'height' => $data['height'] ?? 0,
            'quantity' => $data['quantity'] ?? 1,
            'declared_value' => $data['declared_value'] ?? 0,
            'status' => Package::STATUS_PENDING,
        ]);
        $package->calculateWeights();
        $package->save();

        return $this->frontendOk([
            'id' => $package->id,
            'code' => '1',
            'message' => 'Package created',
        ]);
    }

    public function packageDel(Request $request)
    {
        $id = $request->input('id');
        $package = Package::find($id);

        if (! $package) {
            return $this->frontendError('Package not found', 404);
        }

        $package->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Package deleted',
        ]);
    }

    // ==================== SHIPMENT ====================

    public function shipmentList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Shipment::with([
            'order:id,order_no',
            'legs.originWarehouse:id,name',
            'legs.destinationWarehouse:id,name',
        ]);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('shipment_no', 'like', "%{$keyword}%")
                    ->orWhere('carrier', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeShipmentStatus($status));
        }

        if ($transport = ($params['transportMethod'] ?? null)) {
            $query->whereHas('legs', function ($q) use ($transport) {
                $q->where('transport_method', strtolower($transport));
            });
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($s) {
            $firstLeg = $s->legs->first();
            $lastLeg = $s->legs->last();

            return [
                'id' => $s->id,
                'shipmentNo' => $s->shipment_no,
                'orderNo' => $s->order?->order_no ?? 'N/A',
                'origin' => $firstLeg?->originWarehouse?->name ?? 'N/A',
                'destination' => $lastLeg?->destinationWarehouse?->name ?? 'N/A',
                'transportMethod' => $firstLeg ? ucfirst($firstLeg->transport_method) : null,
                'carrier' => $s->carrier,
                'departureTime' => $s->departed_at
                    ? strtotime($s->departed_at)
                    : ($firstLeg?->departure_date ? strtotime($firstLeg->departure_date) : null),
                'arrivalEstimate' => $s->arrived_at
                    ? strtotime($s->arrived_at)
                    : ($lastLeg?->arrival_date ? strtotime($lastLeg->arrival_date) : null),
                'status' => $this->displayShipmentStatus($s->status),
                'remark' => $s->notes,
                'addTime' => $s->created_at ? strtotime($s->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    // ==================== DELIVERY ====================

    public function deliveryList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Delivery::where('type', Delivery::TYPE_DELIVERY)
            ->with(['order:id,order_no', 'driver:id,name,phone', 'package:id,quantity', 'codCollection']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('receiver_name', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeDeliveryStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($d) {
            return [
                'id' => $d->id,
                'deliveryNo' => NumberGenerator::displayNo('DLV', $d),
                'orderNo' => $d->order?->order_no ?? 'N/A',
                'driverId' => $d->driver_id,
                'driverName' => $d->driver?->name ?? 'Unassigned',
                'receiverName' => $d->receiver_name,
                'receiverPhone' => $d->receiver_phone,
                'quantity' => $d->package?->quantity ?? 1,
                'codExpected' => (float) ($d->codCollection?->expected_amount ?? 0),
                'codCollected' => (float) ($d->codCollection?->collected_amount ?? 0),
                'codDifference' => (float) ($d->codCollection?->difference ?? 0),
                'status' => $this->displayDeliveryStatus($d->status),
                'note' => $d->notes,
                'signature' => $d->signature,
                'issueReason' => $d->issue_reason,
                'addTime' => $d->created_at ? strtotime($d->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function deliveryGet(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $d = Delivery::with(['order:id,order_no', 'driver:id,name,phone', 'package:id,quantity', 'codCollection'])->find($id);

        if (! $d) {
            return $this->frontendError('Delivery not found', 404);
        }

        return $this->frontendOk([
            'id' => $d->id,
            'deliveryNo' => NumberGenerator::displayNo('DLV', $d),
            'orderNo' => $d->order?->order_no ?? '',
            'driverId' => $d->driver_id,
            'driverName' => $d->driver?->name ?? '',
            'receiverName' => $d->receiver_name ?? '',
            'receiverPhone' => $d->receiver_phone ?? '',
            'quantity' => $d->package?->quantity ?? 1,
            'codExpected' => (float) ($d->codCollection?->expected_amount ?? 0),
            'codCollected' => (float) ($d->codCollection?->collected_amount ?? 0),
            'status' => $this->displayDeliveryStatus($d->status),
            'note' => $d->notes ?? '',
            'signature' => $d->signature,
            'issueReason' => $d->issue_reason,
        ]);
    }

    public function deliveryAdd(Request $request)
    {
        $params = $this->getParams($request);

        if (empty($params['orderNo'])) {
            return $this->frontendError('Order No. is required');
        }

        $driverId = $this->resolveDriverUserId($params);
        if (! $driverId) {
            return $this->frontendError('Driver is required');
        }
        if (! Driver::where('user_id', $driverId)->exists()) {
            return $this->frontendError('Driver not found');
        }

        $order = Order::where('order_no', $params['orderNo'])->first();
        if (! $order) {
            return $this->frontendError('Order not found: ' . $params['orderNo']);
        }

        if (Delivery::where('order_id', $order->id)->where('type', Delivery::TYPE_DELIVERY)->exists()) {
            return $this->frontendError('A delivery task already exists for this order');
        }

        $status = $this->normalizeDeliveryStatus($params['status'] ?? 'ASSIGNED');
        $packageId = $order->packages()->value('id');

        return DB::transaction(function () use ($params, $order, $driverId, $status, $packageId) {
            $delivery = Delivery::create([
                'order_id' => $order->id,
                'package_id' => $packageId,
                'type' => Delivery::TYPE_DELIVERY,
                'driver_id' => $driverId,
                'status' => $status,
                'ready_at' => now(),
                'assigned_at' => $driverId ? now() : null,
                'delivered_at' => in_array($status, [Delivery::STATUS_DELIVERED], true) ? now() : null,
                'receiver_name' => $params['receiverName'] ?? $order->receiver_name ?? null,
                'receiver_phone' => $params['receiverPhone'] ?? $order->receiver_phone ?? null,
                'notes' => $params['note'] ?? null,
            ]);

            if ($packageId && ! empty($params['quantity'])) {
                Package::where('id', $packageId)->update(['quantity' => (int) $params['quantity']]);
            }

            $this->syncDeliveryCod($delivery, $order, $params);

            if ($status === Delivery::STATUS_OUT_FOR_DELIVERY && $packageId) {
                Package::where('id', $packageId)->update(['status' => Package::STATUS_OUT_FOR_DELIVERY]);
            }

            if ($status === Delivery::STATUS_DELIVERED && $packageId) {
                Package::where('id', $packageId)->update(['status' => Package::STATUS_DELIVERED]);
            }

            return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Delivery created');
        });
    }

    public function deliveryEdit(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? null;
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return $this->frontendError('Delivery not found', 404);
        }

        $driverId = $delivery->driver_id;
        if (array_key_exists('driverId', $params) || array_key_exists('driverName', $params)) {
            $driverId = $this->resolveDriverUserId($params);
            if (! empty($params['driverId']) && ! $driverId) {
                return $this->frontendError('Driver not found');
            }
        }

        $status = isset($params['status'])
            ? $this->normalizeDeliveryStatus($params['status'])
            : $delivery->status;

        return DB::transaction(function () use ($params, $delivery, $driverId, $status) {
            $delivery->update([
                'driver_id' => $driverId,
                'status' => $status,
                'receiver_name' => $params['receiverName'] ?? $delivery->receiver_name,
                'receiver_phone' => $params['receiverPhone'] ?? $delivery->receiver_phone,
                'notes' => $params['note'] ?? $delivery->notes,
                'assigned_at' => $driverId ? ($delivery->assigned_at ?? now()) : $delivery->assigned_at,
                'delivered_at' => $status === Delivery::STATUS_DELIVERED
                    ? ($delivery->delivered_at ?? now())
                    : $delivery->delivered_at,
            ]);

            if (! empty($params['quantity']) && $delivery->package_id) {
                Package::where('id', $delivery->package_id)->update(['quantity' => (int) $params['quantity']]);
            }

            if ($delivery->order) {
                $this->syncDeliveryCod($delivery, $delivery->order, $params);
            }

            return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Delivery updated');
        });
    }

    public function deliveryDel(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return $this->frontendError('Delivery not found', 404);
        }

        $delivery->delete();

        return $this->frontendOk(null, 'Delivery deleted');
    }

    public function deliveryDrivers()
    {
        $items = Driver::query()
            ->where('status', 'active')
            ->with('user:id,name,email,phone')
            ->orderBy('name')
            ->get()
            ->filter(fn ($d) => $d->user_id)
            ->map(fn ($d) => [
                'id' => $d->user_id,
                'driverId' => $d->id,
                'name' => $d->user?->name ?? $d->name,
                'phone' => $d->phone ?? $d->user?->phone,
                'email' => $d->user?->email,
            ])
            ->values();

        return $this->frontendOk($items);
    }

    public function deliveryLive()
    {
        $items = Delivery::query()
            ->where('type', Delivery::TYPE_DELIVERY)
            ->where('status', Delivery::STATUS_OUT_FOR_DELIVERY)
            ->with([
                'order:id,order_no,tracking_ref,receiver_name,receiver_phone',
                'driver:id,name,phone',
                'location',
            ])
            ->withCount(['codCollection'])
            ->get()
            ->map(fn (Delivery $d) => [
                'id' => $d->id,
                'deliveryNo' => NumberGenerator::displayNo('DLV', $d),
                'orderNo' => $d->order?->order_no,
                'trackingRef' => $d->order?->tracking_ref,
                'driverId' => $d->driver_id,
                'driverName' => $d->driver?->name ?? 'Unassigned',
                'driverPhone' => $d->driver?->phone,
                'receiverName' => $d->receiver_name,
                'receiverPhone' => $d->receiver_phone,
                'status' => $this->displayDeliveryStatus($d->status),
                'latitude' => $d->location?->latitude,
                'longitude' => $d->location?->longitude,
                'accuracy' => $d->location?->accuracy,
                'speed' => $d->location?->speed,
                'heading' => $d->location?->heading,
                'recordedAt' => $d->location?->recorded_at?->toIso8601String(),
                'hasLocation' => $d->location !== null,
            ])
            ->values();

        return $this->frontendOk($items);
    }

    public function deliveryOrders(Request $request)
    {
        $params = $this->getParams($request);
        $keyword = $params['keyword'] ?? $params['q'] ?? null;

        $query = Order::query()
            ->where('fulfillment_method', 'delivery')
            ->with([
                'packages:id,order_id,quantity',
                'customer:id,name,phone',
            ])
            ->whereDoesntHave('deliveries', function ($q) {
                $q->where('type', Delivery::TYPE_DELIVERY);
            })
            ->orderByDesc('id');

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%")
                    ->orWhere('tracking_ref', 'like', "%{$keyword}%")
                    ->orWhere('receiver_name', 'like', "%{$keyword}%")
                    ->orWhere('receiver_phone', 'like', "%{$keyword}%")
                    ->orWhereHas('customer', function ($cq) use ($keyword) {
                        $cq->where('name', 'like', "%{$keyword}%")
                            ->orWhere('phone', 'like', "%{$keyword}%");
                    });
            });
        }

        $items = $query->limit(50)->get()->map(function ($order) {
            $qty = (int) ($order->packages->sum('quantity') ?: 1);

            return [
                'id' => $order->id,
                'orderNo' => $order->order_no,
                'trackingRef' => $order->tracking_ref,
                'customerName' => $order->customer?->name,
                'receiverName' => $order->receiver_name ?? $order->customer?->name,
                'receiverPhone' => $order->receiver_phone ?? $order->customer?->phone,
                'receiverAddress' => $order->receiver_address,
                'quantity' => $qty > 0 ? $qty : 1,
                'codExpected' => $order->payment_method === 'cod' ? (float) $order->estimated_fee : 0,
                'status' => $this->displayOrderStatus($order->status),
                'label' => $order->order_no
                    . ($order->tracking_ref ? ' · ' . $order->tracking_ref : '')
                    . ($order->receiver_name ? ' · ' . $order->receiver_name : ''),
            ];
        })->values();

        return $this->frontendOk($items);
    }

    public function deliveryAssign(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? null;
        $delivery = Delivery::find($id);

        if (! $delivery) {
            return $this->frontendError('Delivery not found', 404);
        }

        $driverId = $this->resolveDriverUserId($params);
        if (! $driverId) {
            return $this->frontendError('Driver not found');
        }

        $delivery->update([
            'driver_id' => $driverId,
            'status' => Delivery::STATUS_PENDING,
            'assigned_at' => now(),
        ]);

        return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Driver assigned');
    }

    private function resolveDriverUserId(array $params): ?int
    {
        if (! empty($params['driverId'])) {
            return (int) $params['driverId'];
        }

        if (! empty($params['driver_id'])) {
            return (int) $params['driver_id'];
        }

        if (! empty($params['driverName'])) {
            $userId = Driver::whereHas('user', function ($q) use ($params) {
                $q->where('name', 'like', '%' . $params['driverName'] . '%');
            })->value('user_id');

            if ($userId) {
                return (int) $userId;
            }

            return (int) User::whereHas('roles', function ($q) {
                $q->where('slug', 'driver');
            })->where('name', 'like', '%' . $params['driverName'] . '%')->value('id') ?: null;
        }

        return null;
    }

    private function syncDeliveryCod(Delivery $delivery, Order $order, array $params): void
    {
        $expected = (float) ($params['codExpected'] ?? ($delivery->codCollection?->expected_amount ?? 0));
        $collected = (float) ($params['codCollected'] ?? ($delivery->codCollection?->collected_amount ?? 0));

        if ($expected <= 0 && $collected <= 0) {
            return;
        }

        $settlement = $collected >= $expected
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
                'settlement_status' => $settlement,
                'collected_at' => $collected > 0 ? ($delivery->codCollection?->collected_at ?? now()) : null,
            ]
        );
    }

    private function deliveryRowPayload(Delivery $d): array
    {
        return [
            'id' => $d->id,
            'deliveryNo' => NumberGenerator::displayNo('DLV', $d),
            'orderNo' => $d->order?->order_no ?? 'N/A',
            'driverId' => $d->driver_id,
            'driverName' => $d->driver?->name ?? 'Unassigned',
            'receiverName' => $d->receiver_name,
            'receiverPhone' => $d->receiver_phone,
            'quantity' => $d->package?->quantity ?? 1,
            'codExpected' => (float) ($d->codCollection?->expected_amount ?? 0),
            'codCollected' => (float) ($d->codCollection?->collected_amount ?? 0),
            'codDifference' => (float) ($d->codCollection?->difference ?? 0),
            'status' => $this->displayDeliveryStatus($d->status),
            'note' => $d->notes,
            'signature' => $d->signature,
            'issueReason' => $d->issue_reason,
            'addTime' => $d->created_at ? strtotime($d->created_at) : time(),
        ];
    }

    private function normalizeDeliveryStatus(string $status): string
    {
        $map = [
            'ASSIGNED' => Delivery::STATUS_PENDING,
            'PENDING' => Delivery::STATUS_PENDING,
            'OUT FOR DELIVERY' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'DELIVERED' => Delivery::STATUS_DELIVERED,
            'PICKED UP' => Delivery::STATUS_PICKED_UP,
            'FAILED' => Delivery::STATUS_FAILED,
            'RETURNED' => Delivery::STATUS_FAILED,
        ];

        return $map[strtoupper($status)] ?? strtolower(str_replace(' ', '_', $status));
    }

    private function displayDeliveryStatus(string $status): string
    {
        $map = [
            Delivery::STATUS_PENDING => 'ASSIGNED',
            Delivery::STATUS_OUT_FOR_DELIVERY => 'OUT FOR DELIVERY',
            Delivery::STATUS_DELIVERED => 'DELIVERED',
            Delivery::STATUS_PICKED_UP => 'PICKED UP',
            Delivery::STATUS_FAILED => 'FAILED',
        ];

        return $map[$status] ?? strtoupper(str_replace('_', ' ', $status));
    }

    // ==================== COD ====================

    public function codList(Request $request)
    {
        $params = $this->getParams($request);

        $query = CodCollection::with(['order:id,order_no', 'collectedBy:id,name']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->whereHas('order', function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%");
            });
        }

        if ($settlement = ($params['settlementStatus'] ?? null)) {
            $query->where('settlement_status', strtolower($settlement));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($c) {
            return [
                'id' => $c->id,
                'codNo' => NumberGenerator::displayNo('COD', $c),
                'orderNo' => $c->order?->order_no ?? 'N/A',
                'type' => $c->collection_via === 'warehouse' ? 'Warehouse' : 'Delivery',
                'codExpected' => (float) $c->expected_amount,
                'codCollected' => (float) $c->collected_amount,
                'codDifference' => (float) $c->difference,
                'settlementStatus' => ucfirst($c->settlement_status),
                'collector' => $c->collectedBy?->name,
                'collectTime' => $c->collected_at ? strtotime($c->collected_at) : null,
                'note' => $c->notes,
                'addTime' => $c->created_at ? strtotime($c->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    // ==================== COST ====================

    public function costList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Cost::with(['order:id,order_no', 'shipment:id,shipment_no']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('description', 'like', "%{$keyword}%")
                    ->orWhere('category', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($category = ($params['category'] ?? null)) {
            $dbCategory = ['last-mile' => 'last_mile', 'last mile' => 'last_mile'][strtolower($category)] ?? strtolower($category);
            $query->where('category', $dbCategory);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($c) {
            return [
                'id' => $c->id,
                'costNo' => NumberGenerator::displayNo('CST', $c),
                'orderNo' => $c->order?->order_no ?? 'N/A',
                'category' => $c->category === 'last_mile' ? 'Last-Mile' : ucfirst($c->category),
                'amount' => (float) $c->amount,
                'currency' => $c->currency,
                'note' => $c->description,
                'costDate' => $c->cost_date ? strtotime($c->cost_date) : null,
                'addTime' => $c->created_at ? strtotime($c->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function costAdd(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'exists:orders,id'],
            'shipment_id' => ['nullable', 'exists:shipments,id'],
            'category' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
            'cost_date' => ['nullable', 'date'],
        ]);

        $cost = Cost::create($data);

        return $this->frontendOk([
            'id' => $cost->id,
            'code' => '1',
            'message' => 'Cost added',
        ]);
    }

    public function costDel(Request $request)
    {
        $id = $request->input('id');
        $cost = Cost::find($id);

        if (! $cost) {
            return $this->frontendError('Cost not found', 404);
        }

        $cost->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Cost deleted',
        ]);
    }

    // ==================== TRACKING ====================

    public function trackingList(Request $request)
    {
        $params = $this->getParams($request);

        $query = TrackingEvent::where('trackable_type', Order::class)
            ->with('trackable:id,order_no,tracking_ref')
            ->with('actor:id,name');

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('note', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%")
                    ->orWhereHas('trackable', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%")
                            ->orWhere('tracking_ref', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($t) {
            return [
                'id' => $t->id,
                'trackingNo' => $t->trackable?->tracking_ref ?? 'N/A',
                'packageNo' => '',
                'orderNo' => $t->trackable?->order_no ?? 'N/A',
                'status' => strtoupper(str_replace('_', ' ', $t->status)),
                'location' => $t->location,
                'operator' => $t->actor?->name ?? $t->actor_name,
                'note' => $t->note,
                'logTime' => $t->created_at ? strtotime($t->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    // ==================== CUSTOMS ====================

    public function customsList(Request $request)
    {
        $params = $this->getParams($request);

        $query = CustomsDeclaration::with(['order:id,order_no', 'package:id,package_no']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('english_item_name', 'like', "%{$keyword}%")
                    ->orWhere('hs_code', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeCustomsStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($c) {
            return [
                'id' => $c->id,
                'declarationNo' => NumberGenerator::displayNo('CUS', $c),
                'orderNo' => $c->order?->order_no ?? 'N/A',
                'englishItemName' => $c->english_item_name,
                'hsCode' => $c->hs_code,
                'receiverIdNo' => $c->receiver_id_number,
                'purpose' => $c->purpose,
                'material' => $c->material,
                'declaredValue' => (float) $c->declared_value,
                'currency' => $c->currency,
                'status' => ucfirst($c->status),
                'addTime' => $c->created_at ? strtotime($c->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    // ==================== PICKUP ====================

    public function pickupList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Delivery::where('type', Delivery::TYPE_PICKUP)
            ->with(['order:id,order_no', 'driver:id,name', 'package:id,quantity', 'codCollection']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('receiver_name', 'like', "%{$keyword}%")
                    ->orWhereHas('order', function ($oq) use ($keyword) {
                        $oq->where('order_no', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizePickupStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($p) {
            $displayStatus = match ($p->status) {
                'pending' => 'READY FOR PICKUP',
                'picked_up' => 'PICKED UP',
                default => strtoupper(str_replace('_', ' ', $p->status)),
            };

            return [
                'id' => $p->id,
                'pickupNo' => NumberGenerator::displayNo('PKP', $p),
                'orderNo' => $p->order?->order_no ?? 'N/A',
                'pickerName' => $p->receiver_name,
                'pickerIdNo' => $p->receiver_id_number,
                'quantity' => $p->package?->quantity ?? 1,
                'codCollected' => (float) ($p->codCollection?->collected_amount ?? 0),
                'operator' => $p->driver?->name ?? 'Warehouse Staff',
                'status' => $displayStatus,
                'pickupTime' => $p->delivered_at ? strtotime($p->delivered_at) : null,
                'addTime' => $p->created_at ? strtotime($p->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    // ==================== REPORT ====================

    public function reportList(Request $request)
    {
        $params = $this->getParams($request);
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        $query = Order::with(['customer:id,name', 'packages', 'costs'])
            ->withCount(['packages as packages_count'])
            ->withSum('packages', 'chargeable_weight')
            ->withSum('costs', 'amount');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [$dateFrom, $dateTo]);
        }

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%")
                    ->orWhereHas('customer', function ($cq) use ($keyword) {
                        $cq->where('name', 'like', "%{$keyword}%");
                    });
            });
        }

        if ($status = ($params['status'] ?? null)) {
            $query->where('status', $this->normalizeStatus($status));
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($o) {
            $fee = (float) $o->estimated_fee;
            $cod = $o->payment_method === 'cod' ? $fee : 0;
            $cost = (float) ($o->costs_sum_amount ?? 0);

            return [
                'id' => $o->id,
                'orderNo' => $o->order_no,
                'customerName' => $o->customer?->name ?? 'N/A',
                'origin' => $o->originWarehouse?->name ?? 'N/A',
                'destination' => $o->destinationWarehouse?->name ?? 'N/A',
                'transportMethod' => $o->transport_method ? ucfirst($o->transport_method) : null,
                'chargeableWeight' => (float) ($o->packages_sum_chargeable_weight ?? 0),
                'logisticsFee' => $fee,
                'codAmount' => $cod,
                'totalCost' => $cost,
                'profit' => round($fee - $cost, 2),
                'status' => $this->displayOrderStatus($o->status),
                'addTime' => $o->created_at ? strtotime($o->created_at) : time(),
            ];
        })->values();

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function reportStatistics(Request $request)
    {
        $totalOrders = Order::count();
        $totalRevenue = Order::sum('estimated_fee');
        $totalCost = Cost::sum('amount');
        $totalProfit = $totalRevenue - $totalCost;

        $ordersByStatus = Order::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return $this->frontendOk([
            'total_orders' => $totalOrders,
            'total_revenue' => round($totalRevenue, 2),
            'total_cost' => round($totalCost, 2),
            'total_profit' => round($totalProfit, 2),
            'orders_by_status' => $ordersByStatus,
        ]);
    }

    // ==================== STATEMENT (INVOICES PER ORDER) ====================

    public function statementList(Request $request)
    {
        $params = $this->getParams($request);

        $query = Order::with([
            'customer',
            'invoices',  // direct order invoices via order_id
            'originWarehouse:id,name',
            'destinationWarehouse:id,name',
            'packages',
        ]);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('order_no', 'like', "%{$keyword}%")
                    ->orWhereHas('customer', function ($cq) use ($keyword) {
                        $cq->where('name', 'like', "%{$keyword}%");
                    });
            });
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $paginator = $query->orderByDesc('id')->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->getCollection()->map(function ($o) {
            // Each order has invoices via order_id (one per order in our seeding)
            $invoice = $o->invoices->first();

            $fee = (float) $o->estimated_fee;
            $cod = $o->payment_method === 'cod' ? $fee : 0;

            return [
                'id' => $invoice?->id ?? $o->id,
                'statementNo' => $invoice?->invoice_no ?? NumberGenerator::displayNo('STM', $o),
                'orderNo' => $o->order_no,
                'customerName' => $o->customer?->name ?? 'N/A',
                'origin' => $o->originWarehouse?->name ?? 'N/A',
                'destination' => $o->destinationWarehouse?->name ?? 'N/A',
                'transportMethod' => $o->transport_method ? ucfirst($o->transport_method) : null,
                'chargeableWeight' => (float) $o->packages->sum('chargeable_weight'),
                'logisticsFee' => $fee,
                'cod' => $cod,
                'total' => $fee + $cod,
                'status' => $invoice ? ucfirst($invoice->status) : 'Draft',
                'addTime' => $o->created_at ? strtotime($o->created_at) : time(),
            ];
        });

        // Apply status filter in-memory for demo data (small dataset)
        if ($status = ($params['status'] ?? null)) {
            $filterStatus = strtolower($status);
            $items = $items->filter(function ($item) use ($filterStatus) {
                return strtolower($item['status']) === $filterStatus;
            })->values();
        }

        return $this->frontendOk([
            'data' => $items,
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }

    public function statementAdd(Request $request)
    {
        $params = $this->getParams($request);
        $data = array_merge($params, $request->all());

        $order = Order::where('order_no', $data['orderNo'])->first();
        if (! $order) {
            return $this->frontendError('Order not found');
        }

        $invoice = Invoice::create([
            'invoice_no' => $data['statementNo'] ?? Invoice::generateInvoiceNo(),
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'total_fee' => $data['logisticsFee'] ?? 0,
            'cod_total' => $data['cod'] ?? 0,
            'currency' => 'USD',
            'status' => strtolower($data['status'] ?? 'draft'),
            'notes' => $data['remark'] ?? null,
        ]);

        return $this->frontendOk([
            'id' => $invoice->id,
            'code' => '1',
            'message' => 'Statement created',
        ]);
    }

    public function statementEdit(Request $request)
    {
        $params = $this->getParams($request);
        $data = array_merge($params, $request->all());

        $invoice = Invoice::find($data['id'] ?? 0);
        if (! $invoice) {
            return $this->frontendError('Statement not found');
        }

        $invoice->update([
            'total_fee' => $data['logisticsFee'] ?? $invoice->total_fee,
            'cod_total' => $data['cod'] ?? $invoice->cod_total,
            'status' => strtolower($data['status'] ?? $invoice->status),
            'notes' => $data['remark'] ?? $invoice->notes,
        ]);

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Statement updated',
        ]);
    }

    public function statementDel(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $invoice = Invoice::find($id);

        if (! $invoice) {
            return $this->frontendError('Statement not found');
        }

        $invoice->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Statement deleted',
        ]);
    }

    // ==================== SYSTEM SETTING ====================

    public function systemsetList(Request $request)
    {
        $settings = config('app');

        return $this->frontendOk([
            'data' => collect($settings)->map(function ($value, $key) {
                return ['key' => $key, 'value' => $value];
            })->values(),
            'total' => count($settings),
        ]);
    }

    // ==================== HELPER ====================

    protected function transformOrderForFrontend(Order $order)
    {
        $statusMap = [
            'pending' => 'PENDING',
            'confirmed' => 'RECEIVED',
            'in_progress' => 'IN TRANSIT',
            'completed' => 'DELIVERED',
            'cancelled' => 'CANCELLED',
        ];

        $transportMap = [
            'air' => 'Air',
            'sea' => 'Sea',
            'land' => 'Land',
        ];

        $fulfillmentMap = [
            'delivery' => 'Delivery',
            'pickup' => 'Self-Pickup',
        ];

        return [
            'id' => $order->id,
            'orderNo' => $order->order_no,
            'trackingRef' => $order->tracking_ref,
            'customerName' => $order->customer?->name ?? 'N/A',
            'origin' => $order->originWarehouse?->name ?? 'N/A',
            'destination' => $order->destinationWarehouse?->name ?? 'N/A',
            'transportMethod' => $transportMap[$order->transport_method] ?? $order->transport_method,
            'fulfillmentMethod' => $fulfillmentMap[$order->fulfillment_method] ?? $order->fulfillment_method,
            'chargeableWeight' => $order->packages->sum('chargeable_weight'),
            'logisticsFee' => $order->estimated_fee,
            'codAmount' => $order->payment_method === 'cod' ? $order->estimated_fee : 0,
            'status' => $statusMap[$order->status] ?? strtoupper(str_replace('_', ' ', $order->status)),
            'remark' => $order->notes,
            'addTime' => $order->created_at ? strtotime($order->created_at) : time(),
            'created_at' => $order->created_at?->toDateTimeString(),
            'updated_at' => $order->updated_at?->toDateTimeString(),
        ];
    }

    // ==================== HELPER METHODS ====================

    protected function normalizeStatus(string $status): string
    {
        $map = [
            'PENDING' => 'pending',
            'RECEIVED' => 'confirmed',
            'IN WAREHOUSE' => 'in_warehouse',
            'CUSTOMS' => 'customs',
            'CLEARED' => 'cleared',
            'IN TRANSIT' => 'in_transit',
            'ARRIVED' => 'arrived',
            'READY FOR PICKUP' => 'ready_for_pickup',
            'OUT FOR DELIVERY' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'PICKED UP' => 'picked_up',
            'COMPLETED' => 'completed',
            'CANCELLED' => 'cancelled',
            'ASSIGNED' => 'pending',
            'FAILED' => 'failed',
            'RETURNED' => 'cancelled',
            'OVERDUE' => 'pending',
            'SCHEDULED' => 'draft',
            'DELAYED' => 'in_transit',
        ];
        return $map[strtoupper($status)] ?? strtolower(str_replace(' ', '_', $status));
    }

    protected function displayOrderStatus(string $status): string
    {
        $map = [
            'pending' => 'PENDING',
            'confirmed' => 'RECEIVED',
            'in_warehouse' => 'IN WAREHOUSE',
            'customs' => 'CUSTOMS',
            'cleared' => 'CLEARED',
            'in_transit' => 'IN TRANSIT',
            'in_progress' => 'IN TRANSIT',
            'arrived' => 'ARRIVED',
            'ready_for_pickup' => 'READY FOR PICKUP',
            'out_for_delivery' => 'OUT FOR DELIVERY',
            'delivered' => 'DELIVERED',
            'picked_up' => 'PICKED UP',
            'completed' => 'DELIVERED',
            'cancelled' => 'CANCELLED',
        ];
        return $map[$status] ?? strtoupper(str_replace('_', ' ', $status));
    }

    protected function normalizeShipmentStatus(string $status): string
    {
        $map = [
            'PENDING' => 'draft',
            'SCHEDULED' => 'draft',
            'IN TRANSIT' => 'in_transit',
            'DELAYED' => 'in_transit',
            'ARRIVED' => 'completed',
            'COMPLETED' => 'completed',
            'CANCELLED' => 'cancelled',
        ];
        return $map[strtoupper($status)] ?? strtolower(str_replace(' ', '_', $status));
    }

    protected function displayShipmentStatus(string $status): string
    {
        $map = [
            'draft' => 'SCHEDULED',
            'in_transit' => 'IN TRANSIT',
            'completed' => 'COMPLETED',
            'cancelled' => 'CANCELLED',
        ];
        return $map[$status] ?? strtoupper(str_replace('_', ' ', $status));
    }

    protected function normalizeCustomsStatus(string $status): string
    {
        $map = [
            'PENDING' => 'pending',
            'DECLARED' => 'declared',
            'CLEARED' => 'cleared',
        ];
        return $map[strtoupper($status)] ?? strtolower($status);
    }

    protected function normalizePickupStatus(string $status): string
    {
        $map = [
            'READY FOR PICKUP' => 'pending',
            'PICKED UP' => 'picked_up',
            'OVERDUE' => 'pending',
        ];
        return $map[strtoupper($status)] ?? strtolower(str_replace(' ', '_', $status));
    }

    protected function warehouseIdByName(?string $name): ?int
    {
        if (empty($name)) {
            return null;
        }
        return Warehouse::where('name', 'like', "%{$name}%")->value('id');
    }
}
