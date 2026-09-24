<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CodCollection;
use App\Models\Delivery;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\NumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class DriverAppController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('roles')->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        if ($user->status !== 'active') {
            return $this->error('Account is inactive', 403);
        }

        $driver = Driver::where('user_id', $user->id)->first();

        if (! $user->hasRole('driver') || ! $driver || $driver->status !== 'active') {
            return $this->forbidden('Driver access is not available for this account');
        }

        $token = $user->createToken('delivery-api')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'access_token' => $token,
            'refresh_token' => $token,
            'user' => $this->userPayload($user),
            'driver' => $this->driverPayload($driver),
            'roles' => $user->role_names,
        ], 'Login successful');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'license_number' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            [$driver, $user] = DB::transaction(function () use ($validated) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => Hash::make($validated['password']),
                    'status' => 'active',
                ]);

                $driver = Driver::create([
                    'user_id' => $user->id,
                    'name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'license_number' => $validated['license_number'] ?? null,
                    'status' => 'active',
                ]);

                $role = Role::where('slug', 'driver')->first();

                if (! $role) {
                    throw new \RuntimeException('Driver role is not configured');
                }

                $user->roles()->syncWithoutDetaching([$role->id]);

                return [$driver, $user];
            });

            $user->load('roles');

            return $this->created([
                'user' => $this->userPayload($user),
                'driver' => $this->driverPayload($driver),
                'roles' => $user->role_names,
            ], 'Registration successful');
        } catch (\Throwable $e) {
            Log::error('Driver registration failed', ['error' => $e->getMessage()]);

            return $this->error('Registration failed: '.$e->getMessage(), 500);
        }
    }

    public function profile(Request $request)
    {
        [$user, $driver] = $this->driverContext($request);

        if (! $driver) {
            return $this->error('No driver profile linked to this account', 404);
        }

        return $this->ok($this->profilePayload($user, $driver));
    }

    public function updateProfile(Request $request)
    {
        [$user, $driver] = $this->driverContext($request);

        if (! $driver) {
            return $this->error('No driver profile linked to this account', 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $driver->name = $validated['name'];
        $driver->phone = $validated['phone'] ?? null;
        $driver->save();

        $user->name = $validated['name'];
        $user->phone = $validated['phone'] ?? null;
        $user->save();

        return $this->ok($this->profilePayload($user, $driver->fresh()), 'Profile updated');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logged out');
    }

    public function myTasks(Request $request)
    {
        $driver = $this->driverContext($request)[1];

        if (! $driver) {
            return $this->error('No driver profile linked to this account', 404);
        }

        $page = $request->integer('page', 1);
        $pageSize = $request->integer('pageSize', $request->integer('per_page', 20));
        $pageSize = min(max($pageSize, 1), 100);

        $paginator = Delivery::query()
            ->where('driver_id', $driver->user_id)
            ->where('type', Delivery::TYPE_DELIVERY)
            ->where(function ($q) {
                $q->whereDate('assigned_at', today())
                    ->orWhereDate('ready_at', today())
                    ->orWhereDate('created_at', today())
                    ->orWhereDate('delivered_at', today())
                    ->orWhereIn('status', [Delivery::STATUS_PENDING, Delivery::STATUS_OUT_FOR_DELIVERY, Delivery::STATUS_FAILED]);
            })
            ->with([
                'order:id,order_no,tracking_ref,status,payment_method,estimated_fee,destination_warehouse_id,receiver_name,receiver_phone,receiver_address,notes',
                'order.destinationWarehouse:id,name,address,city,country',
                'package:id,package_no,barcode,quantity,description,weight,status',
                'codCollection:id,expected_amount,collected_amount,difference,settlement_status',
            ])
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $items = $paginator->items();

        return $this->ok([
            'data' => array_values(array_map(fn (Delivery $delivery) => $this->taskPayload($delivery), $items)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function taskDetail(Request $request, int $id)
    {
        $driver = $this->driverContext($request)[1];

        if (! $driver) {
            return $this->error('No driver profile linked to this account', 404);
        }

        $delivery = Delivery::query()
            ->where('id', $id)
            ->where('driver_id', $driver->user_id)
            ->where('type', Delivery::TYPE_DELIVERY)
            ->with([
                'order:id,order_no,tracking_ref,status,payment_method,estimated_fee,currency,receiver_name,receiver_phone,receiver_address,sender_name,sender_phone,destination_warehouse_id',
                'order.customer:id,name,email,phone',
                'order.destinationWarehouse:id,name,address,city,country',
                'package:id,package_no,barcode,description,weight,quantity,status,currency',
                'codCollection:id,expected_amount,collected_amount,difference,settlement_status',
            ])
            ->first();

        if (! $delivery) {
            return $this->error('Delivery task not found', 404);
        }

        return $this->ok($this->taskDetailPayload($delivery));
    }

    public function updateStatus(Request $request)
    {
        $driver = $this->driverContext($request)[1];

        if (! $driver) {
            return $this->error('No driver profile linked to this account', 404);
        }

        $validated = $request->validate([
            'id' => ['required', 'integer', 'exists:deliveries,id'],
            'status' => ['required', 'string', 'in:ASSIGNED,OUT FOR DELIVERY,DELIVERED,FAILED'],
            'codCollected' => ['nullable', 'numeric', 'min:0'],
            'signature' => ['nullable', 'string', 'max:60000'],
            'receiverIdType' => ['nullable', 'string', 'max:30'],
            'receiverIdNumber' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'issueReason' => ['nullable', 'string', 'max:255'],
        ]);

        $delivery = Delivery::query()
            ->where('id', $validated['id'])
            ->where('driver_id', $driver->user_id)
            ->with(['order', 'package', 'codCollection'])
            ->first();

        if (! $delivery) {
            return $this->error('Delivery task not found', 404);
        }

        if (in_array($validated['status'], ['ASSIGNED', 'OUT FOR DELIVERY'], true)) {
            if ($delivery->status === Delivery::STATUS_DELIVERED) {
                return $this->ok($this->taskPayload($delivery), 'Delivery already completed');
            }

            if ($delivery->status === Delivery::STATUS_OUT_FOR_DELIVERY) {
                return $this->ok($this->taskPayload($delivery), 'Delivery is already out for delivery');
            }

            return DB::transaction(function () use ($delivery) {
                $delivery->update([
                    'status' => Delivery::STATUS_OUT_FOR_DELIVERY,
                    'assigned_at' => now(),
                    'issue_reason' => null,
                ]);

                if ($delivery->package) {
                    $delivery->package->setStatus(
                        Package::STATUS_OUT_FOR_DELIVERY,
                        null,
                        'Driver started delivery'
                    );
                }

                if ($delivery->order) {
                    $delivery->order->update(['status' => Order::STATUS_IN_PROGRESS]);
                }

                return $this->ok($this->taskPayload($delivery->fresh()), 'Delivery started');
            });
        }

        if ($validated['status'] === 'FAILED') {
            $reason = trim($validated['issueReason'] ?? '');
            if ($reason === '') {
                return $this->error('Issue reason is required to report a problem', 422);
            }

            if ($delivery->status === Delivery::STATUS_DELIVERED) {
                return $this->error('Delivery already completed and cannot be marked failed', 422);
            }

            $delivery->update([
                'status' => Delivery::STATUS_FAILED,
                'issue_reason' => mb_substr($reason, 0, 255),
            ]);

            if ($delivery->order && $delivery->order->status === Order::STATUS_PENDING) {
                $delivery->order->update(['status' => Order::STATUS_IN_PROGRESS]);
            }

            return $this->ok($this->taskPayload($delivery->fresh()), 'Issue reported');
        }

        if ($validated['status'] === 'DELIVERED') {
            if ($delivery->status === Delivery::STATUS_DELIVERED) {
                return $this->ok($this->taskPayload($delivery), 'Delivery already completed');
            }

            $signature = trim($validated['signature'] ?? '');
            if ($signature === '') {
                return $this->error('Customer signature is required to complete delivery', 422);
            }

            $package = $delivery->package;
            if ($package && $package->barcode) {
                $scanned = trim($validated['barcode'] ?? '');
                if ($scanned === '') {
                    return $this->error('Package barcode scan is required to complete delivery', 422);
                }
                if (! hash_equals($package->barcode, $scanned) && ! hash_equals((string) $package->package_no, $scanned)) {
                    return $this->error('Scanned barcode does not match this package', 422);
                }
            }

            return DB::transaction(function () use ($delivery, $validated, $signature) {
                $delivery->update([
                    'status' => Delivery::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'signature' => $signature,
                    'receiver_id_type' => $validated['receiverIdType'] ?? $delivery->receiver_id_type,
                    'receiver_id_number' => $validated['receiverIdNumber'] ?? $delivery->receiver_id_number,
                    'issue_reason' => null,
                ]);

                $this->settleCod($delivery, $validated['codCollected'] ?? null);

                if ($delivery->package) {
                    $delivery->package->setStatus(
                        Package::STATUS_DELIVERED,
                        null,
                        'Delivered by driver'
                    );
                }

                if ($delivery->order) {
                    $delivery->order->update(['status' => Order::STATUS_COMPLETED]);
                }

                return $this->ok($this->taskPayload($delivery->fresh()), 'Delivery completed');
            });
        }

        return $this->ok($this->taskPayload($delivery), 'Delivery is already out for delivery');
    }

    private function driverContext(Request $request): array
    {
        $user = $request->user();
        $driver = Driver::where('user_id', $user->id)->first();

        if (! $user->hasRole('driver')) {
            $driver = null;
        }

        return [$user, $driver];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ];
    }

    private function driverPayload(Driver $driver): array
    {
        return [
            'id' => $driver->id,
            'user_id' => $driver->user_id,
            'name' => $driver->name,
            'email' => $driver->user?->email,
            'phone' => $driver->phone,
            'license_number' => $driver->license_number,
            'status' => $driver->status,
        ];
    }

    private function profilePayload(User $user, Driver $driver): array
    {
        return [
            'user' => $this->userPayload($user),
            'driver' => $this->driverPayload($driver),
        ];
    }

    private function taskPayload(Delivery $delivery): array
    {
        $order = $delivery->order;
        $package = $delivery->package;
        $cod = $delivery->codCollection;
        $warehouse = $order?->destinationWarehouse;
        $address = collect([
            $order?->receiver_address,
            $warehouse?->address,
            $warehouse?->city,
            $warehouse?->country,
        ])->filter()->implode(', ');

        return [
            'id' => $delivery->id,
            'deliveryNo' => NumberGenerator::displayNo('DLV', $delivery),
            'orderNo' => $order?->order_no,
            'trackingNo' => $order?->tracking_ref,
            'status' => $this->taskStatus($delivery->status),
            'receiverName' => $delivery->receiver_name ?? $order?->receiver_name,
            'receiverPhone' => $delivery->receiver_phone ?? $order?->receiver_phone,
            'receiverIdType' => $delivery->receiver_id_type,
            'receiverIdNumber' => $delivery->receiver_id_number,
            'address' => $address ?: ($order?->receiver_address ?? ''),
            'quantity' => $package?->quantity ?? 1,
            'packageNo' => $package?->package_no,
            'barcode' => $package?->barcode,
            'note' => $delivery->notes ?? $order?->notes,
            'issueReason' => $delivery->issue_reason,
            'signature' => $delivery->signature,
            'codExpected' => (float) ($cod?->expected_amount ?? ($order?->payment_method === 'cod' ? $order->estimated_fee : 0)),
            'codCollected' => (float) ($cod?->collected_amount ?? 0),
            'codDifference' => (float) ($cod?->difference ?? 0),
            'codSettlementStatus' => $cod?->settlement_status,
            'assignedAt' => $delivery->assigned_at?->toIso8601String(),
            'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
        ];
    }

    private function taskDetailPayload(Delivery $delivery): array
    {
        $order = $delivery->order;
        $package = $delivery->package;
        $cod = $delivery->codCollection;
        $customer = $order?->customer;
        $warehouse = $order?->destinationWarehouse;

        return array_merge($this->taskPayload($delivery), [
            'paymentMethod' => $order?->payment_method,
            'currency' => $order?->currency ?? $package?->currency ?? 'USD',
            'orderStatus' => $order?->status,
            'senderName' => $order?->sender_name,
            'senderPhone' => $order?->sender_phone,
            'customerName' => $customer?->name,
            'customerPhone' => $customer?->phone,
            'customerEmail' => $customer?->email,
            'warehouse' => $warehouse ? [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'address' => $warehouse->address,
                'city' => $warehouse->city,
                'country' => $warehouse->country,
            ] : null,
            'item' => $package ? [
                'id' => $package->id,
                'packageNo' => $package->package_no,
                'barcode' => $package->barcode,
                'description' => $package->description,
                'weight' => $package->weight,
                'quantity' => $package->quantity,
                'status' => $package->status,
            ] : null,
            'requiresBarcodeScan' => (bool) ($package?->barcode),
            'notes' => $delivery->notes ?? $order?->notes,
        ]);
    }

    private function taskStatus(string $status): string
    {
        return match ($status) {
            Delivery::STATUS_PENDING => 'ASSIGNED',
            Delivery::STATUS_OUT_FOR_DELIVERY => 'OUT FOR DELIVERY',
            Delivery::STATUS_DELIVERED => 'DELIVERED',
            Delivery::STATUS_FAILED => 'FAILED',
            default => strtoupper(str_replace('_', ' ', $status)),
        };
    }

    private function settleCod(Delivery $delivery, mixed $collectedValue): void
    {
        $order = $delivery->order;

        if (! $order || $order->payment_method !== 'cod') {
            return;
        }

        $expected = (float) $order->estimated_fee;
        $collected = $collectedValue === null ? $expected : (float) $collectedValue;
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
                'collection_via' => 'driver',
                'settlement_status' => $status,
                'collected_by_id' => auth()->id(),
                'collected_at' => $collected > 0 ? now() : null,
            ]
        );
    }
}
