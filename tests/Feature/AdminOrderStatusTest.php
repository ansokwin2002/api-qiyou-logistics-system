<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): array
    {
        $warehouse = Warehouse::create([
            'name' => 'Status Hub',
            'code' => 'STATUS-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Status Customer',
            'email' => 'status-customer@example.com',
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-STATUS-TEST',
            'tracking_ref' => 'KH-STATUS-TEST',
            'customer_id' => $customer->id,
            'origin_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'payment_method' => 'prepaid',
            'fulfillment_method' => 'delivery',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 0,
            'currency' => 'USD',
        ]);
        $package = Package::create([
            'order_id' => $order->id,
            'package_no' => 'PKG-STATUS-TEST',
            'barcode' => 'STATUS-TEST-BARCODE',
            'quantity' => 1,
            'currency' => 'USD',
            'status' => Package::STATUS_PENDING,
        ]);

        return [$order, $package, $customer];
    }

    public function test_admin_can_update_customer_facing_order_status(): void
    {
        [$order, $package] = $this->makeOrder();

        $updated = $this->postJson('/api/manageapi/order/setstatus', [
            'data' => [
                'id' => $order->id,
                'status' => 'At Warehouse',
            ],
        ]);

        $updated->assertOk()->assertJsonPath('code', '1');
        $this->assertSame('in_warehouse', $order->fresh()->status);
        $this->assertSame('in_warehouse', $package->fresh()->status);
        $this->assertDatabaseHas('tracking_events', [
            'trackable_type' => Order::class,
            'trackable_id' => $order->id,
            'status' => 'in_warehouse',
            'actor_name' => 'Admin',
        ]);

        $this->postJson('/api/manageapi/order/setstatus', [
            'data' => ['id' => $order->id, 'status' => 'IN TRANSIT'],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame('in_transit', $order->fresh()->status);
        $this->assertSame('in_transit', $package->fresh()->status);
    }

    public function test_updated_status_is_what_the_customer_sees(): void
    {
        [, , $customer] = $this->makeOrder();
        $user = User::create([
            'name' => 'Status Customer',
            'email' => 'status-customer@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $token = $user->createToken('status-test')->plainTextToken;

        $this->postJson('/api/manageapi/order/setstatus', [
            'data' => ['id' => Order::where('order_no', 'ORD-STATUS-TEST')->value('id'), 'status' => 'arrived'],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->withToken($token)
            ->getJson('/api/v1/customer/orders/ORD-STATUS-TEST')
            ->assertOk()
            ->assertJsonPath('data.status', 'arrived')
            ->assertJsonPath('data.packages.0.status', 'arrived');

        $this->withToken($token)
            ->getJson('/api/v1/customer/orders?status=arrived')
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'arrived');
    }

    public function test_invalid_status_and_unknown_order_are_rejected(): void
    {
        [$order] = $this->makeOrder();

        $this->postJson('/api/manageapi/order/setstatus', [
            'data' => ['id' => $order->id, 'status' => 'Teleported'],
        ])
            ->assertOk()
            ->assertJsonPath('code', '0');

        $this->postJson('/api/manageapi/order/setstatus', [
            'data' => ['id' => 999999, 'status' => 'Arrived'],
        ])
            ->assertOk()
            ->assertJsonPath('code', '0');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertDatabaseMissing('tracking_events', [
            'trackable_type' => Order::class,
            'trackable_id' => $order->id,
            'status' => 'arrived',
        ]);
    }
}
