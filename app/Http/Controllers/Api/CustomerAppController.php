<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Endpoints used by the customer-facing mobile app.
 */
class CustomerAppController extends Controller
{
    use ApiResponse;

    protected function customerForUser($user): ?Customer
    {
        if ($user->customer_id) {
            return Customer::find($user->customer_id);
        }

        return Customer::where('email', $user->email)->first();
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $customer = $this->customerForUser($user);

        if (! $customer) {
            return $this->error('No customer profile linked to this account', 404);
        }

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'customer' => $customer,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $customer = $this->customerForUser($user);

        if (! $customer) {
            return $this->error('No customer profile linked to this account', 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $customer->name = $validated['name'];
        foreach (['phone', 'address', 'city', 'country'] as $field) {
            if (array_key_exists($field, $validated)) {
                $customer->{$field} = $validated[$field] ?? null;
            }
        }
        $customer->save();

        $user->name = $validated['name'];
        $user->save();

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'customer' => $customer->fresh(),
        ], 'Profile updated');
    }

    public function orders(Request $request)
    {
        $user = $request->user();
        $customer = $this->customerForUser($user);

        if (! $customer) {
            return $this->error('No customer profile linked to this account', 404);
        }

        $query = Order::with([
            'originWarehouse:id,name,code,city,country',
            'destinationWarehouse:id,name,code,city,country',
            'packages:id,order_id,package_no,barcode,status,current_warehouse_id,current_bin_id,description,weight,quantity,length,width,height,chargeable_weight,declared_value',
            'deliveries:id,order_id,driver_id,status,receiver_name,receiver_phone,delivered_at,signature,issue_reason',
            'deliveries.location',
        ])->where('customer_id', $customer->id)->orderByDesc('id');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($date = $request->input('date')) {
            $query->whereDate('created_at', $date);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_no', 'like', "%{$search}%")
                    ->orWhere('tracking_ref', 'like', "%{$search}%");
            });
        }

        $result = $query->paginate($request->integer('per_page', 20));
        $result->getCollection()->each(function ($order) {
            $order->deliveries?->each(function ($delivery) {
                if ($delivery->status !== Delivery::STATUS_OUT_FOR_DELIVERY) {
                    $delivery->unsetRelation('location');
                }
            });
        });

        return $this->ok($result);
    }

    public function show(Request $request, Order $order)
    {
        $customer = $this->customerForUser($request->user());

        if (! $customer || (int) $order->customer_id !== (int) $customer->id) {
            return $this->error('Order not found', 404);
        }

        $order->load([
            'customer',
            'originWarehouse',
            'destinationWarehouse',
            'packages' => fn ($q) => $q->orderBy('id')->with('currentWarehouse:id,name,code', 'currentBin.level.rack.zone'),
            'deliveries' => fn ($q) => $q->orderByDesc('id')->with('location'),
            'trackingEvents' => fn ($q) => $q->orderByDesc('created_at'),
        ]);

        $order->deliveries->each(function ($delivery) {
            if ($delivery->status !== Delivery::STATUS_OUT_FOR_DELIVERY) {
                $delivery->unsetRelation('location');
            }
        });

        return $this->ok($order);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $customer = $this->customerForUser($user);

        if (! $customer) {
            return $this->error('No customer profile linked to this account', 404);
        }

        $data = $request->validate([
            'origin_warehouse_id' => ['required', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'exists:warehouses,id', 'different:origin_warehouse_id'],
            'transport_method' => ['nullable', 'string', 'in:air,sea,land'],
            'payment_method' => ['nullable', 'string', 'in:prepaid,cod,both'],
            'fulfillment_method' => ['nullable', 'string', 'in:delivery,pickup'],
            'estimated_fee' => ['nullable', 'numeric', 'min:0'],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_phone' => ['nullable', 'string', 'max:30'],
            'receiver_name' => ['required', 'string', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:30'],
            'receiver_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'packages' => ['required', 'array', 'min:1'],
            'packages.*.description' => ['nullable', 'string'],
            'packages.*.weight' => ['required', 'numeric', 'min:0.1'],
            'packages.*.length' => ['nullable', 'numeric', 'min:0'],
            'packages.*.width' => ['nullable', 'numeric', 'min:0'],
            'packages.*.height' => ['nullable', 'numeric', 'min:0'],
            'packages.*.quantity' => ['nullable', 'integer', 'min:1'],
            'packages.*.declared_value' => ['nullable', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data, $customer) {
            $order = Order::create([
                'order_no' => Order::generateOrderNo(),
                'tracking_ref' => Order::generateTrackingNo(),
                'customer_id' => $customer->id,
                'sender_name' => $data['sender_name'] ?? $customer->name,
                'sender_phone' => $data['sender_phone'] ?? $customer->phone,
                'origin_warehouse_id' => $data['origin_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'transport_method' => $data['transport_method'] ?? 'sea',
                'payment_method' => $data['payment_method'] ?? 'prepaid',
                'fulfillment_method' => $data['fulfillment_method'] ?? 'delivery',
                'receiver_name' => $data['receiver_name'],
                'receiver_phone' => $data['receiver_phone'] ?? null,
                'receiver_address' => $data['receiver_address'] ?? null,
                'status' => Order::STATUS_PENDING,
                'estimated_fee' => $data['estimated_fee'] ?? 0,
                'currency' => 'USD',
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
                    'currency' => 'USD',
                    'status' => Package::STATUS_PENDING,
                    'current_warehouse_id' => $data['origin_warehouse_id'],
                ]);
                $package->calculateWeights();
                $order->packages()->save($package);
            }

            $order->load('packages', 'originWarehouse', 'destinationWarehouse');

            return $this->ok($order, 'Shipment created');
        }, 3);
    }

    public function warehouses()
    {
        $warehouses = Warehouse::select('id', 'name', 'code', 'city', 'country', 'type')
            ->orderBy('country')
            ->orderBy('city')
            ->get();

        return $this->ok($warehouses);
    }

    public function submitSupport(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'attachment' => ['nullable', 'string'],
        ]);

        $ticket = SupportTicket::create([
            'ticket_no' => SupportTicket::generateTicketNo(),
            'customer_id' => $this->customerForUser($request->user())?->id,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'attachment' => $data['attachment'] ?? null,
            'status' => 'open',
        ]);

        return $this->created($ticket, 'Support request submitted');
    }
}