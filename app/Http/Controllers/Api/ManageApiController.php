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
use App\Models\ShipmentLeg;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseBin;
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

    /**
     * Response format compatible with frontend admin panel.
     * Business errors stay on HTTP 200 with code '0' so the panel can show the
     * message verbatim instead of the generic network error toast.
     */
    protected function frontendError(string $message = 'Error', int $status = 200)
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
            $variants = [
                'RECEIVED' => ['received', 'confirmed'],
                'IN TRANSIT' => ['in_transit', 'in_progress'],
                'DELIVERED' => ['delivered', 'completed'],
            ];
            $statuses = $variants[strtoupper($status)] ?? [$this->normalizeStatus($status)];
            $query->whereIn('status', $statuses);
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
            return $this->frontendError('Order not found');
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
            return $this->frontendError('Order not found');
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

    /**
     * One dropdown on the admin Orders screen drives the status the customer
     * sees on their home page: Order Placed -> Picked Up -> At Warehouse ->
     * In Transit -> Arrived -> Delivered. Keeps order, packages and the
     * customer timeline in sync.
     */
    public function orderSetStatus(Request $request)
    {
        $params = array_merge($request->all(), $this->getParams($request));

        $order = Order::find($params['id'] ?? null);

        if (! $order) {
            return $this->frontendError('Order not found');
        }

        $status = $this->normalizeCustomerStatus((string) ($params['status'] ?? ''));

        if (! $status) {
            return $this->frontendError('Unknown status. Choose one of: Order Placed, Picked Up, At Warehouse, In Transit, Arrived, Delivered');
        }

        $label = $this->customerStatusLabel($status);
        $note = trim((string) ($params['note'] ?? '')) ?: "Status updated to {$label}";

        DB::transaction(function () use ($order, $status, $note, $params) {
            $order->update(['status' => $status]);
            Package::where('order_id', $order->id)->update(['status' => $status]);

            $order->trackingEvents()->create([
                'status' => $status,
                'location' => $params['location'] ?? null,
                'note' => $note,
                'actor_name' => 'Admin',
            ]);
        });

        return $this->frontendOk([
            'status' => $status,
            'label' => $this->customerStatusLabel($status),
        ], 'Order status updated');
    }

    /**
     * The six customer-facing statuses, accepting both display labels and
     * stored keys.
     */
    protected function normalizeCustomerStatus(string $status): ?string
    {
        $map = [
            'ORDER PLACED' => 'pending',
            'PENDING' => 'pending',
            'PICKED UP' => 'received',
            'RECEIVED' => 'received',
            'AT WAREHOUSE' => 'in_warehouse',
            'IN WAREHOUSE' => 'in_warehouse',
            'IN TRANSIT' => 'in_transit',
            'ARRIVED' => 'arrived',
            'DELIVERED' => 'delivered',
        ];

        $key = strtoupper(trim($status));

        if ($key === '') {
            return null;
        }

        if (isset($map[$key])) {
            return $map[$key];
        }

        $canonical = strtolower(str_replace(' ', '_', $key));

        return in_array($canonical, ['pending', 'received', 'in_warehouse', 'in_transit', 'arrived', 'delivered'], true)
            ? $canonical
            : null;
    }

    protected function customerStatusLabel(string $status): string
    {
        $map = [
            'pending' => 'Order Placed',
            'received' => 'Picked Up',
            'in_warehouse' => 'At Warehouse',
            'in_transit' => 'In Transit',
            'arrived' => 'Arrived',
            'delivered' => 'Delivered',
        ];

        return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function orderDel(Request $request)
    {
        $params = $this->getParams($request);
        $id = $params['id'] ?? $request->query('id');
        $order = Order::find($id);

        if (! $order) {
            return $this->frontendError('Order not found');
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
            return $this->frontendError('Customer not found');
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
            return $this->frontendError('Warehouse not found');
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
            return $this->frontendError('Warehouse not found');
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
            $query->where('status', $this->normalizePackageStatus($status));
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

    public function packageGet(Request $request)
    {
        $params = $this->getParams($request);
        $package = $this->findPackage($params);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        return $this->frontendOk($this->packagePayload($package));
    }

    public function packagePayload(Package $package): array
    {
        $package->loadMissing(['order:id,order_no', 'currentWarehouse:id,name', 'currentBin:id,code']);
        $dims = array_values(array_filter([(float) $package->length, (float) $package->width, (float) $package->height], fn ($v) => $v > 0));

        return [
            'id' => $package->id,
            'barcode' => $package->barcode,
            'orderNo' => $package->order?->order_no ?? '',
            'itemName' => $package->description,
            'quantity' => (int) $package->quantity,
            'weight' => (float) $package->weight,
            'dimensions' => $dims ? implode('x', $dims) : '',
            'volumetricWeight' => (float) $package->volumetric_weight,
            'chargeableWeight' => (float) $package->chargeable_weight,
            'binLocation' => $package->currentBin?->code ?? $package->currentWarehouse?->name ?? '',
            'status' => strtoupper(str_replace('_', ' ', $package->status)),
        ];
    }

    public function packageAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }
        if (! $order) {
            return $this->frontendError('Order No. is required');
        }

        $barcode = trim((string) ($params['barcode'] ?? ''));
        if ($barcode === '') {
            $barcode = Package::generateBarcode();
        } elseif (Package::where('barcode', $barcode)->exists()) {
            return $this->frontendError('Barcode already exists: ' . $barcode);
        }

        [$length, $width, $height] = $this->parseDimensions($params['dimensions'] ?? null);

        $package = new Package([
            'order_id' => $order->id,
            'package_no' => Package::generatePackageNo(),
            'barcode' => $barcode,
            'description' => $params['itemName'] ?? ($params['description'] ?? null),
            'weight' => (float) ($params['weight'] ?? 0),
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'quantity' => (int) ($params['quantity'] ?? 1),
            'declared_value' => (float) ($params['declaredValue'] ?? ($params['declared_value'] ?? 0)),
            'currency' => $order->currency ?? 'USD',
            'status' => Package::STATUS_PENDING,
            'current_warehouse_id' => $order->origin_warehouse_id,
        ]);
        $package->calculateWeights();
        $package->save();

        if (! empty($params['binLocation'])) {
            $bin = $this->resolveBin($params['binLocation']);
            if ($bin) {
                $package->update(['current_bin_id' => $bin->id]);
                $bin->update(['status' => 'occupied']);
            }
        }

        return $this->frontendOk([
            'id' => $package->id,
            'code' => '1',
            'message' => 'Package created',
        ]);
    }

    public function packageEdit(Request $request)
    {
        $params = $this->getParams($request);
        $package = $this->findPackage($params);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        if (! empty($params['barcode']) && $params['barcode'] !== $package->barcode) {
            if (Package::where('barcode', $params['barcode'])->exists()) {
                return $this->frontendError('Barcode already exists: ' . $params['barcode']);
            }
            $package->barcode = $params['barcode'];
        }

        if (! empty($params['orderNo'])) {
            $order = Order::where('order_no', $params['orderNo'])->first();
            if (! $order) {
                return $this->frontendError('Order not found: ' . $params['orderNo']);
            }
            $package->order_id = $order->id;
        }

        if (array_key_exists('itemName', $params) || array_key_exists('description', $params)) {
            $package->description = $params['itemName'] ?? ($params['description'] ?? null);
        }
        if (array_key_exists('quantity', $params) && $params['quantity'] !== null && $params['quantity'] !== '') {
            $package->quantity = (int) $params['quantity'];
        }
        if (array_key_exists('weight', $params) && $params['weight'] !== null && $params['weight'] !== '') {
            $package->weight = (float) $params['weight'];
        }
        if (array_key_exists('dimensions', $params) && $params['dimensions'] !== null && $params['dimensions'] !== '') {
            [$length, $width, $height] = $this->parseDimensions($params['dimensions']);
            $package->length = $length;
            $package->width = $width;
            $package->height = $height;
        }

        $package->calculateWeights();

        $newStatus = isset($params['status']) && $params['status'] !== ''
            ? $this->normalizePackageStatus($params['status'])
            : null;

        if ($newStatus && $newStatus !== $package->status) {
            $package->save();
            $package->setStatus(
                $newStatus,
                $package->currentBin?->code ?? $package->currentWarehouse?->name,
                'Status updated from admin panel'
            );
        } else {
            $package->save();
        }

        return $this->frontendOk([
            'id' => $package->id,
            'code' => '1',
            'message' => 'Package updated',
        ]);
    }

    public function packageDel(Request $request)
    {
        $id = $request->input('id');
        $package = Package::find($id);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        $package->delete();

        return $this->frontendOk([
            'code' => '1',
            'message' => 'Package deleted',
        ]);
    }

    // ---------------- Package flow actions ----------------

    public function packageReceive(Request $request)
    {
        $params = $this->getParams($request);
        $package = $this->findPackage($params);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        $warehouse = $this->resolveWarehouse($params)
            ?: $package->currentWarehouse
            ?: Warehouse::find($package->order?->origin_warehouse_id);

        if (! $warehouse) {
            return $this->frontendError('Warehouse is required');
        }

        if (isset($params['quantity']) && $params['quantity'] !== '' && $params['quantity'] !== null) {
            $package->quantity = (int) $params['quantity'];
        }
        $package->current_warehouse_id = $warehouse->id;

        if (! empty($params['binLocation'])) {
            $bin = $this->resolveBin($params['binLocation']);
            if (! $bin) {
                return $this->frontendError('Bin not found: ' . $params['binLocation']);
            }
            $package->current_bin_id = $bin->id;
            $bin->update(['status' => 'occupied']);
        }

        $package->save();
        $package->setStatus(Package::STATUS_RECEIVED, $warehouse->name, $params['note'] ?? 'Package received at ' . $warehouse->name);

        return $this->frontendOk($this->packagePayload($package->fresh()), 'Package received');
    }

    public function packageAssignBin(Request $request)
    {
        $params = $this->getParams($request);
        $package = $this->findPackage($params);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        $bin = $this->resolveBin($params['binLocation'] ?? null);
        if (! $bin) {
            return $this->frontendError('Bin is required');
        }

        $warehouseId = $this->binWarehouseId($bin);
        $package->current_bin_id = $bin->id;
        $package->current_warehouse_id = $package->current_warehouse_id ?: $warehouseId;
        $package->save();

        $bin->update(['status' => 'occupied']);
        $package->setStatus(Package::STATUS_IN_WAREHOUSE, $bin->code, $params['note'] ?? 'Stored in bin ' . $bin->code);

        return $this->frontendOk($this->packagePayload($package->fresh()), 'Package stored in bin ' . $bin->code);
    }

    public function packageMove(Request $request)
    {
        $params = $this->getParams($request);
        $package = $this->findPackage($params);

        if (! $package) {
            return $this->frontendError('Package not found');
        }

        $bin = ! empty($params['binLocation']) ? $this->resolveBin($params['binLocation']) : null;
        if (! empty($params['binLocation']) && ! $bin) {
            return $this->frontendError('Bin not found: ' . $params['binLocation']);
        }

        $warehouse = $this->resolveWarehouse($params) ?: ($bin ? Warehouse::find($this->binWarehouseId($bin)) : null);

        if (! $bin && ! $warehouse) {
            return $this->frontendError('Warehouse or bin is required');
        }

        if ($bin) {
            $package->current_bin_id = $bin->id;
            $bin->update(['status' => 'occupied']);
        } else {
            $package->current_bin_id = null;
        }

        if ($warehouse) {
            $package->current_warehouse_id = $warehouse->id;
        } elseif ($bin) {
            $package->current_warehouse_id = $this->binWarehouseId($bin);
        }

        $package->save();

        $location = $bin?->code ?? $warehouse?->name;
        $package->setStatus(Package::STATUS_IN_WAREHOUSE, $location, $params['note'] ?? 'Package moved to ' . $location);

        return $this->frontendOk($this->packagePayload($package->fresh()), 'Package moved');
    }

    public function shipmentGet(Request $request)
    {
        $params = $this->getParams($request);
        $shipment = $this->findShipment($params);

        if (! $shipment) {
            return $this->frontendError('Shipment not found');
        }

        return $this->frontendOk($this->shipmentPayload($shipment));
    }

    public function shipmentPayload(Shipment $shipment): array
    {
        $shipment->loadMissing(['order:id,order_no', 'legs.originWarehouse:id,name', 'legs.destinationWarehouse:id,name']);
        $firstLeg = $shipment->legs->first();
        $lastLeg = $shipment->legs->last();

        return [
            'id' => $shipment->id,
            'shipmentNo' => $shipment->shipment_no,
            'orderNo' => $shipment->order?->order_no ?? 'N/A',
            'carrier' => $shipment->carrier,
            'origin' => $firstLeg?->originWarehouse?->name ?? 'N/A',
            'destination' => $lastLeg?->destinationWarehouse?->name ?? 'N/A',
            'transportMethod' => $firstLeg ? ucfirst($firstLeg->transport_method) : 'Air',
            'departureTime' => $shipment->departed_at
                ? strtotime($shipment->departed_at)
                : ($firstLeg?->departure_date ? strtotime($firstLeg->departure_date) : null),
            'arrivalEstimate' => $shipment->arrived_at
                ? strtotime($shipment->arrived_at)
                : ($lastLeg?->arrival_date ? strtotime($lastLeg->arrival_date) : null),
            'status' => $this->displayShipmentStatus($shipment->status),
            'remark' => $shipment->notes,
            'legs' => $shipment->legs->map(fn (ShipmentLeg $leg) => [
                'id' => $leg->id,
                'legNo' => $leg->leg_no,
                'origin' => $leg->originWarehouse?->name,
                'originId' => $leg->origin_warehouse_id,
                'destination' => $leg->destinationWarehouse?->name,
                'destinationId' => $leg->destination_warehouse_id,
                'transportMethod' => ucfirst($leg->transport_method),
                'status' => strtoupper(str_replace('_', ' ', $leg->status)),
                'departureTime' => $leg->departure_date ? strtotime($leg->departure_date) : null,
                'arrivalTime' => $leg->arrival_date ? strtotime($leg->arrival_date) : null,
                'notes' => $leg->notes,
            ])->values(),
        ];
    }

    public function shipmentAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }

        $shipmentNo = trim((string) ($params['shipmentNo'] ?? ''));
        if ($shipmentNo === '') {
            $shipmentNo = Shipment::generateShipmentNo();
        } elseif (Shipment::where('shipment_no', $shipmentNo)->exists()) {
            return $this->frontendError('Shipment No. already exists: ' . $shipmentNo);
        }

        $originError = null;
        $destinationError = null;
        $this->legWarehouseId($params, 'origin', $originError);
        $this->legWarehouseId($params, 'destination', $destinationError);
        if ($originError) {
            return $this->frontendError($originError);
        }
        if ($destinationError) {
            return $this->frontendError($destinationError);
        }

        $shipment = Shipment::create([
            'shipment_no' => $shipmentNo,
            'order_id' => $order?->id,
            'carrier' => $params['carrier'] ?? null,
            'status' => $this->normalizeShipmentStatus($params['status'] ?? 'PENDING'),
            'notes' => $params['remark'] ?? ($params['notes'] ?? null),
        ]);

        $legError = $this->upsertFirstLeg($shipment, $params, $order);
        if ($legError) {
            $shipment->delete();

            return $this->frontendError($legError);
        }

        return $this->frontendOk($this->shipmentPayload($shipment->fresh('legs')), 'Shipment created');
    }

    public function shipmentEdit(Request $request)
    {
        $params = $this->getParams($request);
        $shipment = $this->findShipment($params);

        if (! $shipment) {
            return $this->frontendError('Shipment not found');
        }

        if (! empty($params['shipmentNo']) && $params['shipmentNo'] !== $shipment->shipment_no) {
            if (Shipment::where('shipment_no', $params['shipmentNo'])->exists()) {
                return $this->frontendError('Shipment No. already exists: ' . $params['shipmentNo']);
            }
            $shipment->shipment_no = $params['shipmentNo'];
        }

        if (! empty($params['orderNo']) && $params['orderNo'] !== 'N/A') {
            $order = Order::where('order_no', $params['orderNo'])->first();
            if (! $order) {
                return $this->frontendError('Order not found: ' . $params['orderNo']);
            }
            $shipment->order_id = $order->id;
        }

        if (array_key_exists('carrier', $params)) {
            $shipment->carrier = $params['carrier'];
        }
        if (array_key_exists('remark', $params) || array_key_exists('notes', $params)) {
            $shipment->notes = $params['remark'] ?? ($params['notes'] ?? $shipment->notes);
        }
        if (! empty($params['status'])) {
            $shipment->status = $this->normalizeShipmentStatus($params['status']);
        }

        $shipment->save();

        $legError = $this->upsertFirstLeg($shipment, $params, $shipment->order);
        if ($legError) {
            return $this->frontendError($legError);
        }

        return $this->frontendOk($this->shipmentPayload($shipment->fresh('legs')), 'Shipment updated');
    }

    public function shipmentDel(Request $request)
    {
        $params = $this->getParams($request);
        $shipment = $this->findShipment($params);

        if (! $shipment) {
            return $this->frontendError('Shipment not found');
        }

        $shipment->delete();

        return $this->frontendOk(['code' => '1', 'message' => 'Shipment deleted'], 'Shipment deleted');
    }

    public function shipmentAddLeg(Request $request)
    {
        $params = $this->getParams($request);
        $shipment = $this->findShipment($params);

        if (! $shipment) {
            return $this->frontendError('Shipment not found');
        }

        $originId = $this->legWarehouseId($params, 'origin');
        $destinationId = $this->legWarehouseId($params, 'destination');

        if (! $originId || ! $destinationId) {
            return $this->frontendError('Origin and destination warehouses are required');
        }

        $leg = $shipment->legs()->create([
            'leg_no' => $shipment->legs()->count() + 1,
            'origin_warehouse_id' => $originId,
            'destination_warehouse_id' => $destinationId,
            'transport_method' => strtolower($params['transportMethod'] ?? 'air'),
            'status' => 'pending',
            'departure_date' => $this->normalizeDate($params['departureTime'] ?? ($params['departureDate'] ?? null)),
            'arrival_date' => $this->normalizeDate($params['arrivalEstimate'] ?? ($params['arrivalDate'] ?? null)),
            'notes' => $params['notes'] ?? null,
        ]);

        return $this->frontendOk($this->shipmentPayload($shipment->fresh('legs')), 'Leg added');
    }

    public function shipmentDepart(Request $request)
    {
        $params = $this->getParams($request);
        $shipment = $this->findShipment($params);

        if (! $shipment) {
            return $this->frontendError('Shipment not found');
        }

        return DB::transaction(function () use ($shipment) {
            $leg = $shipment->legs()->where('status', 'pending')->orderBy('leg_no')->first();

            if (! $leg) {
                return $this->frontendError('No pending legs to depart');
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
                    $package->update(['current_warehouse_id' => $leg->origin_warehouse_id]);
                    $package->setStatus(
                        Package::STATUS_IN_TRANSIT,
                        $leg->originWarehouse?->name,
                        "Shipment {$shipment->shipment_no} departed on leg {$leg->leg_no}"
                    );
                }
            }

            return $this->frontendOk($this->shipmentPayload($shipment->fresh('legs')), 'Shipment departed');
        });
    }

    public function shipmentArrive(Request $request)
    {
        $params = $this->getParams($request);

        $leg = null;
        if (! empty($params['legId'])) {
            $leg = ShipmentLeg::find($params['legId']);
        } elseif (! empty($params['id']) || ! empty($params['shipmentId'])) {
            $shipment = $this->findShipment($params);
            $leg = $shipment?->legs()->where('status', 'in_transit')->orderBy('leg_no')->first()
                ?: ($shipment?->legs()->where('status', 'pending')->orderBy('leg_no')->first());
        }

        if (! $leg) {
            return $this->frontendError('No shipment leg to arrive');
        }

        return DB::transaction(function () use ($leg) {
            $leg->update([
                'status' => 'arrived',
                'arrival_date' => now(),
            ]);

            $shipment = $leg->shipment;
            $isFinalLeg = $shipment->legs()->where('status', 'pending')->count() === 0;

            if ($isFinalLeg) {
                $shipment->update([
                    'status' => 'completed',
                    'arrived_at' => now(),
                ]);
            }

            if ($shipment->order) {
                foreach ($shipment->order->packages as $package) {
                    $package->update(['current_warehouse_id' => $leg->destination_warehouse_id]);

                    if (! $isFinalLeg) {
                        $package->setStatus(
                            Package::STATUS_IN_TRANSIT,
                            $leg->destinationWarehouse?->name,
                            "Arrived at transit warehouse " . ($leg->destinationWarehouse?->name ?? 'n/a') . ", awaiting next leg"
                        );
                        continue;
                    }

                    $package->setStatus(
                        $shipment->order->fulfillment_method === 'pickup'
                            ? Package::STATUS_READY_FOR_PICKUP
                            : Package::STATUS_ARRIVED,
                        $leg->destinationWarehouse?->name,
                        'Shipment leg arrived'
                    );
                }
            }

            return $this->frontendOk($this->shipmentPayload($shipment->fresh('legs')), 'Leg arrived');
        });
    }

    // ==================== CUSTOMS (detail / edit / flow) ====================

    public function customsGet(Request $request)
    {
        $params = $this->getParams($request);
        $customs = CustomsDeclaration::find($params['id'] ?? $request->query('id'));

        if (! $customs) {
            return $this->frontendError('Declaration not found');
        }

        return $this->frontendOk($this->customsPayload($customs));
    }

    protected function customsPayload(CustomsDeclaration $customs): array
    {
        $customs->loadMissing('order:id,order_no');

        return [
            'id' => $customs->id,
            'declarationNo' => NumberGenerator::displayNo('CUS', $customs),
            'orderNo' => $customs->order?->order_no ?? 'N/A',
            'receiverIdType' => $this->displayIdType($customs->receiver_id_type),
            'receiverIdNo' => $customs->receiver_id_number,
            'englishItemName' => $customs->english_item_name,
            'hsCode' => $customs->hs_code,
            'purpose' => $customs->purpose,
            'material' => $customs->material,
            'declaredValue' => (float) $customs->declared_value,
            'currency' => $customs->currency,
            'status' => ucfirst($customs->status),
            'note' => $customs->notes,
            'addTime' => $customs->created_at ? strtotime($customs->created_at) : time(),
        ];
    }

    public function customsAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }
        if (! $order) {
            return $this->frontendError('Order No. is required');
        }

        $package = $order->packages()->first();

        $customs = CustomsDeclaration::create([
            'order_id' => $order->id,
            'package_id' => $package?->id,
            'receiver_id_type' => $this->normalizeIdType($params['receiverIdType'] ?? null) ?? 'national_id',
            'receiver_id_number' => $params['receiverIdNo'] ?? null,
            'english_item_name' => $params['englishItemName'] ?? null,
            'hs_code' => $params['hsCode'] ?? null,
            'purpose' => $params['purpose'] ?? null,
            'material' => $params['material'] ?? null,
            'declared_value' => (float) ($params['declaredValue'] ?? 0),
            'currency' => $params['currency'] ?? 'USD',
            'status' => $this->normalizeCustomsStatus($params['status'] ?? 'Pending'),
            'notes' => $params['note'] ?? null,
        ]);

        if ($package && $package->status !== Package::STATUS_CUSTOMS) {
            $package->setStatus(Package::STATUS_CUSTOMS, null, 'Customs declaration created');
        }

        return $this->frontendOk([
            'id' => $customs->id,
            'code' => '1',
            'message' => 'Declaration created',
        ]);
    }

    public function customsEdit(Request $request)
    {
        $params = $this->getParams($request);
        $customs = CustomsDeclaration::find($params['id'] ?? null);

        if (! $customs) {
            return $this->frontendError('Declaration not found');
        }

        if (! empty($params['orderNo']) && $params['orderNo'] !== 'N/A') {
            $order = Order::where('order_no', $params['orderNo'])->first();
            if (! $order) {
                return $this->frontendError('Order not found: ' . $params['orderNo']);
            }
            $customs->order_id = $order->id;
            $customs->package_id = $order->packages()->value('id');
        }

        $fields = [
            'receiver_id_number' => $params['receiverIdNo'] ?? null,
            'english_item_name' => $params['englishItemName'] ?? null,
            'hs_code' => $params['hsCode'] ?? null,
            'purpose' => $params['purpose'] ?? null,
            'material' => $params['material'] ?? null,
            'declared_value' => $params['declaredValue'] ?? null,
            'currency' => $params['currency'] ?? null,
            'notes' => $params['note'] ?? null,
        ];
        foreach ($fields as $column => $value) {
            if (array_key_exists($column, $params) && $value !== null) {
                $customs->{$column} = $value;
            }
        }
        if (array_key_exists('receiverIdType', $params)) {
            $customs->receiver_id_type = $this->normalizeIdType($params['receiverIdType']) ?? $customs->receiver_id_type;
        }

        $customs->save();

        if (isset($params['status']) && $params['status'] !== '') {
            $status = $this->normalizeCustomsStatus($params['status']);
            if ($status !== $customs->status) {
                $this->applyCustomsStatus($customs, $status);
            }
        }

        return $this->frontendOk($this->customsPayload($customs->fresh()), 'Declaration updated');
    }

    public function customsDel(Request $request)
    {
        $params = $this->getParams($request);
        $customs = CustomsDeclaration::find($params['id'] ?? $request->query('id'));

        if (! $customs) {
            return $this->frontendError('Declaration not found');
        }

        $customs->delete();

        return $this->frontendOk(['code' => '1', 'message' => 'Declaration deleted'], 'Declaration deleted');
    }

    public function customsDeclared(Request $request)
    {
        $params = $this->getParams($request);
        $customs = CustomsDeclaration::find($params['id'] ?? null);

        if (! $customs) {
            return $this->frontendError('Declaration not found');
        }
        if ($customs->status === CustomsDeclaration::STATUS_CLEARED) {
            return $this->frontendError('Declaration is already cleared');
        }

        $this->applyCustomsStatus($customs, CustomsDeclaration::STATUS_DECLARED);

        return $this->frontendOk($this->customsPayload($customs->fresh()), 'Customs declared');
    }

    public function customsCleared(Request $request)
    {
        $params = $this->getParams($request);
        $customs = CustomsDeclaration::find($params['id'] ?? null);

        if (! $customs) {
            return $this->frontendError('Declaration not found');
        }

        $this->applyCustomsStatus($customs, CustomsDeclaration::STATUS_CLEARED);

        return $this->frontendOk($this->customsPayload($customs->fresh()), 'Customs cleared');
    }

    protected function applyCustomsStatus(CustomsDeclaration $customs, string $status): void
    {
        $customs->status = $status;

        if ($status === CustomsDeclaration::STATUS_DECLARED && ! $customs->declared_at) {
            $customs->declared_at = now();
        }
        if ($status === CustomsDeclaration::STATUS_CLEARED && ! $customs->cleared_at) {
            $customs->cleared_at = now();
        }

        $customs->save();

        $package = $customs->package_id ? Package::find($customs->package_id) : null;
        if (! $package) {
            return;
        }

        if ($status === CustomsDeclaration::STATUS_DECLARED && $package->status !== Package::STATUS_CUSTOMS) {
            $package->setStatus(Package::STATUS_CUSTOMS, null, 'Customs declared');
        }
        if ($status === CustomsDeclaration::STATUS_CLEARED && $package->status !== Package::STATUS_CLEARED) {
            $package->setStatus(Package::STATUS_CLEARED, null, 'Customs cleared');
        }
    }

    // ==================== PICKUP (detail / edit / flow) ====================

    public function pickupGet(Request $request)
    {
        $params = $this->getParams($request);
        $pickup = Delivery::find($params['id'] ?? $request->query('id'));

        if (! $pickup || $pickup->type !== Delivery::TYPE_PICKUP) {
            return $this->frontendError('Pickup task not found');
        }

        return $this->frontendOk($this->pickupPayload($pickup));
    }

    protected function pickupPayload(Delivery $pickup): array
    {
        $pickup->loadMissing(['order:id,order_no', 'driver:id,name', 'package:id,quantity', 'codCollection']);

        return [
            'id' => $pickup->id,
            'pickupNo' => NumberGenerator::displayNo('PKP', $pickup),
            'orderNo' => $pickup->order?->order_no ?? 'N/A',
            'pickerName' => $pickup->receiver_name,
            'pickerIdType' => $this->displayIdType($pickup->receiver_id_type),
            'pickerIdNo' => $pickup->receiver_id_number,
            'quantity' => $pickup->package?->quantity ?? 1,
            'codCollected' => (float) ($pickup->codCollection?->collected_amount ?? 0),
            'operator' => $pickup->driver?->name ?? '',
            'pickupTime' => $pickup->delivered_at ? strtotime($pickup->delivered_at) : null,
            'status' => $this->displayPickupStatus($pickup->status),
            'note' => $pickup->notes,
            'addTime' => $pickup->created_at ? strtotime($pickup->created_at) : time(),
        ];
    }

    public function pickupAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }
        if (! $order) {
            return $this->frontendError('Order No. is required');
        }
        if ($order->fulfillment_method !== 'pickup') {
            return $this->frontendError('Order ' . $order->order_no . ' is set up for delivery, not self-pickup');
        }
        if (Delivery::where('order_id', $order->id)->where('type', Delivery::TYPE_PICKUP)->exists()) {
            return $this->frontendError('A pickup task already exists for this order');
        }

        $status = $this->normalizePickupStatus($params['status'] ?? 'READY FOR PICKUP');
        $packageId = $order->packages()->value('id');
        $driverId = $this->resolveDriverUserId(['driverName' => $params['operator'] ?? null]);

        if ($status === Delivery::STATUS_PICKED_UP && empty($params['pickerName'])) {
            return $this->frontendError('Picker name is required to mark the task as picked up');
        }

        $pickup = Delivery::create([
            'order_id' => $order->id,
            'package_id' => $packageId,
            'type' => Delivery::TYPE_PICKUP,
            'driver_id' => $driverId,
            'status' => $status,
            'ready_at' => now(),
            'assigned_at' => $driverId ? now() : null,
            'delivered_at' => $status === Delivery::STATUS_PICKED_UP ? now() : $this->normalizeDate($params['pickupTime'] ?? null),
            'receiver_name' => $params['pickerName'] ?? $order->receiver_name ?? null,
            'receiver_id_type' => $this->normalizeIdType($params['pickerIdType'] ?? null),
            'receiver_id_number' => $params['pickerIdNo'] ?? null,
            'notes' => ! empty($params['operator']) ? 'Operator: ' . $params['operator'] : null,
        ]);

        if (! empty($params['quantity']) && $packageId) {
            Package::where('id', $packageId)->update(['quantity' => (int) $params['quantity']]);
        }

        if ($order->payment_method === 'cod' || (float) ($params['codCollected'] ?? 0) > 0) {
            $this->syncDeliveryCod($pickup, $order, [
                'codExpected' => $order->payment_method === 'cod' ? (float) $order->estimated_fee : (float) ($params['codCollected'] ?? 0),
                'codCollected' => (float) ($params['codCollected'] ?? 0),
            ]);
        }

        if ($status === Delivery::STATUS_PICKED_UP) {
            $result = $this->completeDeliveryTask($pickup, $params, 'warehouse');
            if (is_string($result)) {
                $pickup->delete();

                return $this->frontendError($result);
            }
        } elseif ($packageId) {
            $package = Package::find($packageId);
            if ($package && $package->status !== Package::STATUS_READY_FOR_PICKUP && $package->status !== Package::STATUS_PICKED_UP) {
                $package->setStatus(Package::STATUS_READY_FOR_PICKUP, null, 'Ready for pickup');
            }
        }

        return $this->frontendOk([
            'id' => $pickup->id,
            'code' => '1',
            'message' => 'Pickup task created',
        ]);
    }

    public function pickupEdit(Request $request)
    {
        $params = $this->getParams($request);
        $pickup = Delivery::find($params['id'] ?? null);

        if (! $pickup || $pickup->type !== Delivery::TYPE_PICKUP) {
            return $this->frontendError('Pickup task not found');
        }

        $fields = [
            'receiver_name' => $params['pickerName'] ?? null,
            'receiver_id_number' => $params['pickerIdNo'] ?? null,
            'notes' => $params['note'] ?? null,
        ];
        foreach ($fields as $column => $value) {
            if ($value !== null && $value !== '') {
                $pickup->{$column} = $value;
            }
        }
        if (array_key_exists('pickerIdType', $params)) {
            $pickup->receiver_id_type = $this->normalizeIdType($params['pickerIdType']) ?? $pickup->receiver_id_type;
        }
        if (! empty($params['operator'])) {
            $driverId = $this->resolveDriverUserId(['driverName' => $params['operator']]);
            if ($driverId) {
                $pickup->driver_id = $driverId;
            }
        }
        if (isset($params['pickupTime']) && $params['pickupTime'] !== '') {
            $pickup->delivered_at = $this->normalizeDate($params['pickupTime']);
        }

        $pickup->save();

        if (! empty($params['quantity']) && $pickup->package_id) {
            Package::where('id', $pickup->package_id)->update(['quantity' => (int) $params['quantity']]);
        }

        if ($pickup->order && (array_key_exists('codCollected', $params) || array_key_exists('codExpected', $params))) {
            $expected = (float) ($params['codExpected'] ?? ($pickup->codCollection?->expected_amount ?? ($pickup->order->payment_method === 'cod' ? (float) $pickup->order->estimated_fee : (float) ($params['codCollected'] ?? 0))));
            $this->syncDeliveryCod($pickup, $pickup->order, [
                'codExpected' => $expected,
                'codCollected' => (float) ($params['codCollected'] ?? ($pickup->codCollection?->collected_amount ?? 0)),
            ]);
        }

        $statusChanged = isset($params['status']) && $params['status'] !== '';
        if ($statusChanged) {
            $newStatus = $this->normalizePickupStatus($params['status']);
            if ($newStatus === Delivery::STATUS_PICKED_UP && $pickup->status !== Delivery::STATUS_PICKED_UP) {
                $result = $this->completeDeliveryTask($pickup, $params, 'warehouse');
                if (is_string($result)) {
                    return $this->frontendError($result);
                }
            } elseif ($newStatus !== $pickup->status) {
                $pickup->update(['status' => $newStatus]);
            }
        }

        return $this->frontendOk($this->pickupPayload($pickup->fresh()), 'Pickup updated');
    }

    public function pickupDel(Request $request)
    {
        $params = $this->getParams($request);
        $pickup = Delivery::find($params['id'] ?? $request->query('id'));

        if (! $pickup) {
            return $this->frontendError('Pickup task not found');
        }

        $pickup->delete();

        return $this->frontendOk(['code' => '1', 'message' => 'Pickup deleted'], 'Pickup deleted');
    }

    public function pickupComplete(Request $request)
    {
        $params = $this->getParams($request);
        $pickup = Delivery::find($params['id'] ?? null);

        if (! $pickup) {
            return $this->frontendError('Pickup task not found');
        }
        if ($pickup->type !== Delivery::TYPE_PICKUP) {
            return $this->frontendError('This is not a pickup task');
        }

        $result = $this->completeDeliveryTask($pickup, $params, 'warehouse');
        if (is_string($result)) {
            return $this->frontendError($result);
        }

        return $this->frontendOk($this->pickupPayload($pickup->fresh()), 'Pickup completed');
    }

    // ==================== COD (detail / edit / settle) ====================

    public function codGet(Request $request)
    {
        $params = $this->getParams($request);
        $cod = CodCollection::find($params['id'] ?? $request->query('id'));

        if (! $cod) {
            return $this->frontendError('COD record not found');
        }

        return $this->frontendOk($this->codPayload($cod));
    }

    protected function codPayload(CodCollection $cod): array
    {
        $cod->loadMissing('order:id,order_no');

        return [
            'id' => $cod->id,
            'codNo' => NumberGenerator::displayNo('COD', $cod),
            'orderNo' => $cod->order?->order_no ?? 'N/A',
            'type' => $cod->collection_via === 'warehouse' ? 'Warehouse' : 'Delivery',
            'codExpected' => (float) $cod->expected_amount,
            'codCollected' => (float) $cod->collected_amount,
            'codDifference' => (float) $cod->difference,
            'settlementStatus' => ucfirst($cod->settlement_status),
            'collectTime' => $cod->collected_at ? strtotime($cod->collected_at) : null,
            'note' => $cod->notes,
            'addTime' => $cod->created_at ? strtotime($cod->created_at) : time(),
        ];
    }

    public function codAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }
        if (! $order) {
            return $this->frontendError('Order No. is required');
        }

        $expected = (float) ($params['codExpected'] ?? ($order->payment_method === 'cod' ? (float) $order->estimated_fee : 0));
        $collected = (float) ($params['codCollected'] ?? 0);
        $settlement = $this->normalizeSettlementStatus($params['settlementStatus'] ?? null)
            ?? ($collected >= $expected && $collected > 0 ? CodCollection::STATUS_COLLECTED : CodCollection::STATUS_PENDING);

        $cod = CodCollection::create([
            'order_id' => $order->id,
            'package_id' => $order->packages()->value('id'),
            'delivery_id' => $order->deliveries()->value('id'),
            'expected_amount' => $expected,
            'collected_amount' => $collected,
            'difference' => round($expected - $collected, 2),
            'collection_via' => strtolower($params['type'] ?? 'delivery') === 'warehouse' ? 'warehouse' : 'driver',
            'settlement_status' => $settlement,
            'collected_at' => $this->normalizeDate($params['collectTime'] ?? null) ?? ($collected > 0 ? now() : null),
            'notes' => $params['note'] ?? null,
        ]);

        return $this->frontendOk([
            'id' => $cod->id,
            'code' => '1',
            'message' => 'COD record created',
        ]);
    }

    public function codEdit(Request $request)
    {
        $params = $this->getParams($request);
        $cod = CodCollection::find($params['id'] ?? null);

        if (! $cod) {
            return $this->frontendError('COD record not found');
        }

        if (! empty($params['orderNo']) && $params['orderNo'] !== 'N/A') {
            $order = Order::where('order_no', $params['orderNo'])->first();
            if (! $order) {
                return $this->frontendError('Order not found: ' . $params['orderNo']);
            }
            $cod->order_id = $order->id;
            $cod->package_id = $order->packages()->value('id');
        }

        if (array_key_exists('codExpected', $params) && $params['codExpected'] !== '' && $params['codExpected'] !== null) {
            $cod->expected_amount = (float) $params['codExpected'];
        }
        if (array_key_exists('codCollected', $params) && $params['codCollected'] !== '' && $params['codCollected'] !== null) {
            $cod->collected_amount = (float) $params['codCollected'];
            if (! $cod->collected_at) {
                $cod->collected_at = now();
            }
        }
        if (array_key_exists('type', $params) && $params['type'] !== null && $params['type'] !== '') {
            $cod->collection_via = strtolower($params['type']) === 'warehouse' ? 'warehouse' : 'driver';
        }
        if (array_key_exists('note', $params) && $params['note'] !== null) {
            $cod->notes = $params['note'];
        }
        if (array_key_exists('collectTime', $params) && $params['collectTime'] !== '' && $params['collectTime'] !== null) {
            $cod->collected_at = $this->normalizeDate($params['collectTime']);
        }
        if (isset($params['settlementStatus']) && $params['settlementStatus'] !== '') {
            $settlement = $this->normalizeSettlementStatus($params['settlementStatus']);
            if ($settlement) {
                $cod->settlement_status = $settlement;
            }
        }

        $cod->difference = round((float) $cod->expected_amount - (float) $cod->collected_amount, 2);
        $cod->save();

        return $this->frontendOk($this->codPayload($cod->fresh()), 'COD record updated');
    }

    public function codDel(Request $request)
    {
        $params = $this->getParams($request);
        $cod = CodCollection::find($params['id'] ?? $request->query('id'));

        if (! $cod) {
            return $this->frontendError('COD record not found');
        }

        $cod->delete();

        return $this->frontendOk(['code' => '1', 'message' => 'COD record deleted'], 'COD record deleted');
    }

    public function codSettle(Request $request)
    {
        $params = $this->getParams($request);
        $cod = CodCollection::find($params['id'] ?? null);

        if (! $cod) {
            return $this->frontendError('COD record not found');
        }
        if ($cod->settlement_status === CodCollection::STATUS_SETTLED) {
            return $this->frontendError('COD record is already settled');
        }

        $cod->update(['settlement_status' => CodCollection::STATUS_SETTLED]);

        return $this->frontendOk($this->codPayload($cod->fresh()), 'COD marked as settled');
    }

    // ==================== COST (detail / edit) ====================

    public function costGet(Request $request)
    {
        $params = $this->getParams($request);
        $cost = Cost::find($params['id'] ?? $request->query('id'));

        if (! $cost) {
            return $this->frontendError('Cost record not found');
        }

        return $this->frontendOk($this->costPayload($cost));
    }

    protected function costPayload(Cost $cost): array
    {
        $cost->loadMissing('order:id,order_no');

        return [
            'id' => $cost->id,
            'costNo' => NumberGenerator::displayNo('CST', $cost),
            'orderNo' => $cost->order?->order_no ?? 'N/A',
            'category' => $cost->category === 'last_mile' ? 'Last-Mile' : ucfirst($cost->category),
            'amount' => (float) $cost->amount,
            'currency' => $cost->currency,
            'note' => $cost->description,
            'costDate' => $cost->cost_date ? strtotime($cost->cost_date) : null,
            'addTime' => $cost->created_at ? strtotime($cost->created_at) : time(),
        ];
    }

    public function costEdit(Request $request)
    {
        $params = $this->getParams($request);
        $cost = Cost::find($params['id'] ?? null);

        if (! $cost) {
            return $this->frontendError('Cost record not found');
        }

        if (! empty($params['orderNo']) && $params['orderNo'] !== 'N/A') {
            $order = Order::where('order_no', $params['orderNo'])->first();
            if (! $order) {
                return $this->frontendError('Order not found: ' . $params['orderNo']);
            }
            $cost->order_id = $order->id;
        }

        if (array_key_exists('category', $params) && $params['category']) {
            $cost->category = $this->normalizeCostCategory($params['category']);
        }
        if (array_key_exists('amount', $params) && $params['amount'] !== '' && $params['amount'] !== null) {
            $cost->amount = (float) $params['amount'];
        }
        if (array_key_exists('currency', $params) && $params['currency']) {
            $cost->currency = $params['currency'];
        }
        if (array_key_exists('note', $params)) {
            $cost->description = $params['note'];
        }
        if (array_key_exists('costDate', $params) && $params['costDate'] !== '' && $params['costDate'] !== null) {
            $cost->cost_date = $this->normalizeDateOnly($params['costDate']);
        }

        $cost->save();

        return $this->frontendOk($this->costPayload($cost->fresh()), 'Cost updated');
    }

    // ==================== TRACKING (detail / create / edit) ====================

    public function trackingGet(Request $request)
    {
        $params = $this->getParams($request);
        $event = TrackingEvent::find($params['id'] ?? $request->query('id'));

        if (! $event) {
            return $this->frontendError('Tracking event not found');
        }

        return $this->frontendOk($this->trackingPayload($event));
    }

    protected function trackingPayload(TrackingEvent $event): array
    {
        $event->loadMissing(['actor:id,name', 'trackable']);

        $trackable = $event->trackable;
        $order = null;
        if ($trackable instanceof Package) {
            $order = $trackable->order;
        } elseif ($trackable instanceof Order) {
            $order = $trackable;
        }

        return [
            'id' => $event->id,
            'trackingNo' => $event->trackable instanceof Order
                ? ($event->trackable->tracking_ref ?? 'N/A')
                : ($event->trackable instanceof Package ? $event->trackable->package_no : 'N/A'),
            'packageNo' => $trackable instanceof Package ? $trackable->package_no : '',
            'orderNo' => $order?->order_no ?? 'N/A',
            'status' => strtoupper(str_replace('_', ' ', $event->status)),
            'location' => $event->location,
            'operator' => $event->actor?->name ?? $event->actor_name,
            'note' => $event->note,
            'logTime' => $event->created_at ? (int) round($event->created_at->getTimestamp() * 1000) : (int) round(now()->getTimestamp() * 1000),
        ];
    }

    public function trackingAdd(Request $request)
    {
        $params = $this->getParams($request);

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }

        $package = null;
        if (! $order && ! empty($params['packageNo'])) {
            $package = Package::where('package_no', $params['packageNo'])
                ->orWhere('barcode', $params['packageNo'])
                ->first();
            if (! $package) {
                return $this->frontendError('Package not found: ' . $params['packageNo']);
            }
            $order = $package->order;
        }

        if (! $order && ! $package) {
            return $this->frontendError('Order No. or package No. is required');
        }

        $payload = $this->trackingEventPayload($params);

        if ($order) {
            $event = $order->trackingEvents()->create($payload);
            if ($package) {
                $package->trackingEvents()->create($payload);
            }
        } else {
            $event = $package->trackingEvents()->create($payload);
        }

        if (isset($params['logTime']) && $params['logTime'] !== '' && $params['logTime'] !== null) {
            $event->created_at = $this->normalizeDate($params['logTime']);
            $event->save();
        }

        return $this->frontendOk([
            'id' => $event->id,
            'code' => '1',
            'message' => 'Tracking event created',
        ]);
    }

    public function trackingEdit(Request $request)
    {
        $params = $this->getParams($request);
        $event = TrackingEvent::find($params['id'] ?? null);

        if (! $event) {
            return $this->frontendError('Tracking event not found');
        }

        $event->status = $this->normalizeEventStatus($params['status'] ?? $event->status);
        if (array_key_exists('location', $params)) {
            $event->location = $params['location'];
        }
        if (array_key_exists('note', $params)) {
            $event->note = $params['note'];
        }
        if (array_key_exists('operator', $params) && $params['operator']) {
            $event->actor_name = $params['operator'];
            $event->actor_id = User::where('name', 'like', '%' . $params['operator'] . '%')->value('id');
        }
        if (isset($params['logTime']) && $params['logTime'] !== '' && $params['logTime'] !== null) {
            $event->created_at = $this->normalizeDate($params['logTime']);
        }
        $event->save();

        return $this->frontendOk($this->trackingPayload($event->fresh()), 'Tracking event updated');
    }

    public function trackingDel(Request $request)
    {
        $params = $this->getParams($request);
        $event = TrackingEvent::find($params['id'] ?? $request->query('id'));

        if (! $event) {
            return $this->frontendError('Tracking event not found');
        }

        $event->delete();

        return $this->frontendOk(['code' => '1', 'message' => 'Tracking event deleted'], 'Tracking event deleted');
    }

    // ==================== DELIVERY FLOW ACTIONS ====================

    public function deliveryStart(Request $request)
    {
        $params = $this->getParams($request);
        $delivery = Delivery::find($params['id'] ?? null);

        if (! $delivery) {
            return $this->frontendError('Delivery task not found');
        }
        if (! $delivery->driver_id) {
            return $this->frontendError('Assign a driver before starting the delivery');
        }
        if (! in_array($delivery->status, [Delivery::STATUS_PENDING, Delivery::STATUS_FAILED], true)) {
            return $this->frontendError('Delivery task is already ' . str_replace('_', ' ', $delivery->status));
        }

        $delivery->update([
            'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
            'assigned_at' => $delivery->assigned_at ?? now(),
        ]);

        if ($delivery->package && $delivery->package->status !== Package::STATUS_OUT_FOR_DELIVERY) {
            $delivery->package->setStatus(Package::STATUS_OUT_FOR_DELIVERY, null, 'Out for delivery');
        }

        if ($delivery->order && $delivery->order->status !== Order::STATUS_COMPLETED) {
            $delivery->order->update(['status' => Order::STATUS_IN_PROGRESS]);
        }

        return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Delivery started');
    }

    public function deliveryComplete(Request $request)
    {
        $params = $this->getParams($request);
        $delivery = Delivery::find($params['id'] ?? null);

        if (! $delivery) {
            return $this->frontendError('Delivery task not found');
        }
        if ($delivery->type !== Delivery::TYPE_DELIVERY) {
            return $this->frontendError('This is not a delivery task');
        }

        $result = $this->completeDeliveryTask($delivery, $params, 'driver');
        if (is_string($result)) {
            return $this->frontendError($result);
        }

        return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Delivery completed');
    }

    public function deliveryFail(Request $request)
    {
        $params = $this->getParams($request);
        $delivery = Delivery::find($params['id'] ?? null);

        if (! $delivery) {
            return $this->frontendError('Delivery task not found');
        }

        $reason = trim((string) ($params['issueReason'] ?? ($params['issue_reason'] ?? '')));
        if ($reason === '') {
            return $this->frontendError('Issue reason is required');
        }

        $delivery->update([
            'status' => Delivery::STATUS_FAILED,
            'issue_reason' => $reason,
            'notes' => $params['note'] ?? ($params['notes'] ?? $delivery->notes),
        ]);

        if ($delivery->package) {
            $delivery->package->setStatus(
                $delivery->type === Delivery::TYPE_PICKUP ? Package::STATUS_READY_FOR_PICKUP : Package::STATUS_ARRIVED,
                null,
                'Delivery failed: ' . $reason
            );
        }

        return $this->frontendOk($this->deliveryRowPayload($delivery->fresh(['order', 'driver', 'package', 'codCollection'])), 'Delivery marked as failed');
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
            return $this->frontendError('Delivery not found');
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
            return $this->frontendError('Delivery not found');
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
            return $this->frontendError('Delivery not found');
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
                    . ($order->tracking_ref ? ' Ãƒâ€šÃ‚Â· ' . $order->tracking_ref : '')
                    . ($order->receiver_name ? ' Ãƒâ€šÃ‚Â· ' . $order->receiver_name : ''),
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
            return $this->frontendError('Delivery not found');
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
        $params = $this->getParams($request);

        if (! isset($params['amount']) || $params['amount'] === '' || $params['amount'] === null) {
            return $this->frontendError('Amount is required');
        }

        $order = $this->resolveOrder($params);
        if (is_string($order)) {
            return $this->frontendError($order);
        }

        $cost = Cost::create([
            'order_id' => $order?->id,
            'shipment_id' => ! empty($params['shipmentId']) ? (int) $params['shipmentId'] : null,
            'category' => $this->normalizeCostCategory($params['category'] ?? 'other'),
            'amount' => (float) $params['amount'],
            'currency' => $params['currency'] ?? 'USD',
            'description' => $params['note'] ?? null,
            'cost_date' => $this->normalizeDateOnly($params['costDate'] ?? null),
        ]);

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
            return $this->frontendError('Cost not found');
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
            $query->whereIn('status', $this->normalizeEventStatuses($status));
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
            'RECEIVED' => 'received',
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

    // ==================== FLOW HELPERS ====================

    protected function findPackage(array $params): ?Package
    {
        if (! empty($params['id'])) {
            $package = Package::find($params['id']);
            if ($package) {
                return $package;
            }
        }

        foreach (['barcode', 'packageNo'] as $key) {
            if (! empty($params[$key])) {
                return Package::where('barcode', $params[$key])
                    ->orWhere('package_no', $params[$key])
                    ->first();
            }
        }

        return null;
    }

    protected function findShipment(array $params): ?Shipment
    {
        if (! empty($params['shipmentId'])) {
            return Shipment::find($params['shipmentId']);
        }
        if (! empty($params['id'])) {
            $shipment = Shipment::find($params['id']);
            if ($shipment) {
                return $shipment;
            }
        }
        if (! empty($params['shipmentNo'])) {
            return Shipment::where('shipment_no', $params['shipmentNo'])->first();
        }

        return null;
    }

    /**
     * Resolve an order from { orderNo, order_id }.
     * Returns the order, null when neither key was supplied, or an error string.
     */
    protected function resolveOrder(array $params): Order|string|null
    {
        $orderNo = trim((string) ($params['orderNo'] ?? ''));

        if ($orderNo !== '' && $orderNo !== 'N/A') {
            $order = Order::where('order_no', $orderNo)->first();

            return $order ?: 'Order not found: ' . $orderNo;
        }

        if (! empty($params['order_id'])) {
            $order = Order::find($params['order_id']);

            return $order ?: 'Order not found';
        }

        return null;
    }

    /**
     * Parse "10x20x30", "10*20*30" or [10, 20, 30] into [length, width, height].
     */
    protected function parseDimensions($value): array
    {
        if (is_array($value)) {
            $numbers = array_slice($value, 0, 3);
        } else {
            preg_match_all('/\d+(?:\.\d+)?/', (string) $value, $matches);
            $numbers = $matches[0] ?? [];
        }

        $numbers = array_map('floatval', array_slice($numbers, 0, 3));
        while (count($numbers) < 3) {
            $numbers[] = 0;
        }

        return $numbers;
    }

    protected function resolveWarehouse(array $params): ?Warehouse
    {
        if (! empty($params['warehouseId'])) {
            return Warehouse::find($params['warehouseId']);
        }

        foreach (['warehouse', 'warehouseName', 'currentWarehouse', 'location'] as $key) {
            if (! empty($params[$key])) {
                $warehouse = Warehouse::where('name', 'like', "%{$params[$key]}%")
                    ->orWhere('code', 'like', "%{$params[$key]}%")
                    ->first();
                if ($warehouse) {
                    return $warehouse;
                }
            }
        }

        return null;
    }

    protected function resolveBin(?string $value): ?WarehouseBin
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return WarehouseBin::with('level.rack.zone')
            ->where('code', 'like', "%{$value}%")
            ->orWhere('name', 'like', "%{$value}%")
            ->orderBy('id')
            ->first();
    }

    protected function binWarehouseId(WarehouseBin $bin): ?int
    {
        $bin->loadMissing('level.rack.zone');

        return $bin->level?->rack?->zone?->warehouse_id;
    }

    protected function legWarehouseId(array $params, string $side, ?string &$error = null): ?int
    {
        $idKey = $side . 'Id';
        $nameKey = $side;

        if (! empty($params[$idKey])) {
            return (int) $params[$idKey];
        }

        $name = $params[$nameKey] ?? null;
        if (! $name && isset($params[$side . 'Warehouse'])) {
            $name = $params[$side . 'Warehouse'];
        }
        if (! $name && isset($params[$side . 'WarehouseId'])) {
            return (int) $params[$side . 'WarehouseId'];
        }

        $name = is_string($name) ? trim($name) : null;
        if ($name === null || $name === '' || $name === 'N/A') {
            return null;
        }

        $warehouseId = $this->warehouseIdByName($name);
        if (! $warehouseId) {
            $error = ucfirst($side) . ' warehouse not found: ' . $name;
        }

        return $warehouseId;
    }

    protected function upsertFirstLeg(Shipment $shipment, array $params, ?Order $order): ?string
    {
        $hasLegInput = array_key_exists('origin', $params)
            || array_key_exists('destination', $params)
            || array_key_exists('originId', $params)
            || array_key_exists('destinationId', $params)
            || array_key_exists('transportMethod', $params)
            || array_key_exists('departureTime', $params)
            || array_key_exists('arrivalEstimate', $params);

        if (! $hasLegInput && $shipment->legs()->exists()) {
            return null;
        }

        $error = null;
        $originId = $this->legWarehouseId($params, 'origin', $error) ?? $order?->origin_warehouse_id;
        if ($error) {
            return $error;
        }

        $destinationError = null;
        $destinationId = $this->legWarehouseId($params, 'destination', $destinationError) ?? $order?->destination_warehouse_id;
        if ($destinationError) {
            return $destinationError;
        }

        if (! $originId && ! $destinationId) {
            return null;
        }

        $leg = $shipment->legs()->orderBy('leg_no')->first();

        $attributes = [
            'origin_warehouse_id' => $originId ?? $leg?->origin_warehouse_id,
            'destination_warehouse_id' => $destinationId ?? $leg?->destination_warehouse_id,
        ];

        if (array_key_exists('transportMethod', $params) && $params['transportMethod']) {
            $attributes['transport_method'] = strtolower($params['transportMethod']);
        }
        if (array_key_exists('departureTime', $params)) {
            $attributes['departure_date'] = $this->normalizeDate($params['departureTime']);
        }
        if (array_key_exists('arrivalEstimate', $params)) {
            $attributes['arrival_date'] = $this->normalizeDate($params['arrivalEstimate']);
        }

        if ($leg) {
            $leg->update(array_filter($attributes, fn ($value) => $value !== null));
        } else {
            $shipment->legs()->create($attributes + [
                'leg_no' => 1,
                'transport_method' => strtolower($params['transportMethod'] ?? ($order?->transport_method ?? 'air')),
                'status' => 'pending',
                'departure_date' => $this->normalizeDate($params['departureTime'] ?? null),
                'arrival_date' => $this->normalizeDate($params['arrivalEstimate'] ?? null),
            ]);
        }

        return null;
    }

    protected function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 100000000000) {
                $timestamp = intdiv($timestamp, 1000);
            }

            return date('Y-m-d H:i:s', $timestamp);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $parsed = strtotime((string) $value);

        return $parsed ? date('Y-m-d H:i:s', $parsed) : null;
    }

    protected function normalizeDateOnly($value): ?string
    {
        $date = $this->normalizeDate($value);

        return $date ? substr($date, 0, 10) : null;
    }

    protected function normalizePackageStatus(string $status): string
    {
        $map = [
            'PENDING' => Package::STATUS_PENDING,
            'RECEIVED' => Package::STATUS_RECEIVED,
            'IN WAREHOUSE' => Package::STATUS_IN_WAREHOUSE,
            'CUSTOMS' => Package::STATUS_CUSTOMS,
            'CLEARED' => Package::STATUS_CLEARED,
            'IN TRANSIT' => Package::STATUS_IN_TRANSIT,
            'ARRIVED' => Package::STATUS_ARRIVED,
            'READY FOR PICKUP' => Package::STATUS_READY_FOR_PICKUP,
            'OUT FOR DELIVERY' => Package::STATUS_OUT_FOR_DELIVERY,
            'DELIVERED' => Package::STATUS_DELIVERED,
            'PICKED UP' => Package::STATUS_PICKED_UP,
            'CANCELLED' => Package::STATUS_PENDING,
        ];

        return $map[strtoupper($status)] ?? strtolower(str_replace(' ', '_', $status));
    }

    protected function displayPickupStatus(string $status): string
    {
        $map = [
            Delivery::STATUS_PENDING => 'READY FOR PICKUP',
            Delivery::STATUS_OUT_FOR_DELIVERY => 'OUT FOR DELIVERY',
            Delivery::STATUS_PICKED_UP => 'PICKED UP',
            Delivery::STATUS_DELIVERED => 'PICKED UP',
            Delivery::STATUS_FAILED => 'FAILED',
        ];

        return $map[$status] ?? strtoupper(str_replace('_', ' ', $status));
    }

    protected function normalizeSettlementStatus(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        $map = [
            'PENDING' => CodCollection::STATUS_PENDING,
            'COLLECTED' => CodCollection::STATUS_COLLECTED,
            'PARTIAL' => CodCollection::STATUS_PARTIAL,
            'SETTLED' => CodCollection::STATUS_SETTLED,
            'DISPUTED' => 'disputed',
        ];

        return $map[strtoupper($status)] ?? strtolower($status);
    }

    protected function normalizeCostCategory(string $category): string
    {
        $map = [
            'LAST-MILE' => 'last_mile',
            'LAST MILE' => 'last_mile',
            'LAST_MILE' => 'last_mile',
            'WAREHOUSE' => 'warehouse',
            'FREIGHT' => 'freight',
            'CUSTOMS' => 'customs',
            'OTHER' => 'other',
        ];

        return $map[strtoupper($category)] ?? strtolower($category);
    }

    protected function normalizeIdType(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));

        if (in_array($value, ['national_id', 'passport', 'tax_id'], true)) {
            return $value;
        }
        if (str_contains($value, 'passport')) {
            return 'passport';
        }
        if (str_contains($value, 'tax')) {
            return 'tax_id';
        }

        return 'national_id';
    }

    protected function displayIdType(?string $value): ?string
    {
        $map = [
            'national_id' => 'National ID',
            'passport' => 'Passport',
            'tax_id' => 'Tax ID',
        ];

        return $value ? ($map[$value] ?? ucfirst(str_replace('_', ' ', $value))) : null;
    }

    protected function normalizeEventStatus(string $status): string
    {
        $map = [
            'ORDER CREATED' => 'pending',
            'RECEIVED' => 'received',
            'IN WAREHOUSE' => 'in_warehouse',
            'READY FOR PICKUP' => 'ready_for_pickup',
            'OUT FOR DELIVERY' => 'out_for_delivery',
            'PICKED UP' => 'picked_up',
            'IN TRANSIT' => 'in_transit',
            'CONFIRMED' => 'confirmed',
        ];

        $key = strtoupper(trim($status));

        return $map[$key] ?? strtolower(str_replace(' ', '_', $key));
    }

    /**
     * Tracking events are stored per package (and mirrored to the order), so a
     * display status can match more than one stored value.
     */
    protected function normalizeEventStatuses(string $status): array
    {
        $key = strtoupper(trim($status));
        $variants = [
            'RECEIVED' => ['received', 'confirmed'],
            'DELIVERED' => ['delivered', 'completed'],
            'COMPLETED' => ['completed', 'delivered'],
            'ASSIGNED' => ['pending', 'out_for_delivery'],
            'SCHEDULED' => ['draft'],
            'DELAYED' => ['in_transit'],
            'PENDING' => ['pending', 'received'],
        ];

        $statuses = array_merge([$this->normalizeEventStatus($status)], $variants[$key] ?? []);

        return array_values(array_unique($statuses));
    }

    protected function trackingEventPayload(array $params): array
    {
        $operator = $params['operator'] ?? null;

        return [
            'status' => $this->normalizeEventStatus($params['status'] ?? 'ORDER CREATED'),
            'location' => $params['location'] ?? null,
            'note' => $params['note'] ?? null,
            'actor_name' => $operator,
            'actor_id' => $operator ? User::where('name', 'like', '%' . $operator . '%')->value('id') : auth()->id(),
        ];
    }

    /**
     * Shared completion for both delivery and self-pickup tasks.
     * Returns true on success or an error message string.
     */
    protected function completeDeliveryTask(Delivery $delivery, array $params, string $via): bool|string
    {
        $isPickup = $delivery->type === Delivery::TYPE_PICKUP;

        $receiverName = trim((string) ($params['receiverName'] ?? ($params['pickerName'] ?? '')));
        if ($receiverName === '') {
            $receiverName = $delivery->receiver_name;
        }
        if ($receiverName === '') {
            return $isPickup ? 'Picker name is required' : 'Receiver name is required';
        }

        $idType = $this->normalizeIdType($params['receiverIdType'] ?? ($params['pickerIdType'] ?? null))
            ?? $delivery->receiver_id_type;
        $idNo = $params['receiverIdNumber'] ?? ($params['pickerIdNo'] ?? null) ?? $delivery->receiver_id_number;

        if ($isPickup) {
            if (! $idType) {
                return 'Picker ID type is required';
            }
            if (empty($idNo)) {
                return 'Picker ID number is required';
            }
        }

        $order = $delivery->order;

        $delivery->update([
            'status' => $isPickup ? Delivery::STATUS_PICKED_UP : Delivery::STATUS_DELIVERED,
            'delivered_at' => $delivery->delivered_at ?? now(),
            'receiver_name' => $receiverName,
            'receiver_phone' => $params['receiverPhone'] ?? $delivery->receiver_phone,
            'receiver_id_type' => $idType,
            'receiver_id_number' => $idNo,
            'signature' => $params['signature'] ?? $delivery->signature,
            'issue_reason' => null,
            'notes' => $params['note'] ?? ($params['notes'] ?? $delivery->notes),
        ]);

        $collectedInput = isset($params['codCollected']) && $params['codCollected'] !== '' && $params['codCollected'] !== null
            ? (float) $params['codCollected']
            : null;

        if ($order && ($order->payment_method === 'cod' || ($collectedInput !== null && $collectedInput > 0))) {
            $expected = $order->payment_method === 'cod'
                ? (float) $order->estimated_fee
                : ($delivery->codCollection?->expected_amount ?? $collectedInput ?? 0);
            $collected = $collectedInput ?? $expected;

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
                    'settlement_status' => $delivery->codCollection?->settlement_status === CodCollection::STATUS_SETTLED
                        ? CodCollection::STATUS_SETTLED
                        : $status,
                    'collected_by_id' => auth()->id() ?? $delivery->driver_id,
                    'collected_at' => $delivery->codCollection?->collected_at ?? now(),
                    'notes' => $params['note'] ?? ($params['notes'] ?? $delivery->notes),
                ]
            );
        }

        if ($delivery->package) {
            $delivery->package->setStatus(
                $isPickup ? Package::STATUS_PICKED_UP : Package::STATUS_DELIVERED,
                $delivery->package->currentWarehouse?->name,
                $isPickup ? 'Picked up by customer' : 'Delivered to ' . $receiverName
            );
        }

        if ($order) {
            $order->update(['status' => Order::STATUS_COMPLETED]);
        }

        return true;
    }

    public function warehouseBins(Request $request)
    {
        $params = $this->getParams($request);

        $query = WarehouseBin::query()->with(['level.rack.zone']);

        if ($keyword = ($params['keyword'] ?? null)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('code', 'like', "%{$keyword}%")
                    ->orWhere('name', 'like', "%{$keyword}%");
            });
        }

        if ($warehouseId = ($params['warehouseId'] ?? ($params['warehouse_id'] ?? null))) {
            $query->whereHas('level.rack.zone', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        }

        $items = $query->orderBy('code')->limit(300)->get()->map(fn (WarehouseBin $bin) => [
            'id' => $bin->id,
            'code' => $bin->code,
            'name' => $bin->name,
            'status' => $bin->status,
            'warehouseId' => $bin->level?->rack?->zone?->warehouse_id,
            'warehouseName' => $bin->level?->rack?->zone?->warehouse?->name,
        ])->values();

        return $this->frontendOk($items);
    }
}
