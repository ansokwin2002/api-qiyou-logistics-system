<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOrderKeyTest extends TestCase
{
    use RefreshDatabase;

    private function base62(int $value): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

        if ($value === 0) {
            return $chars[0];
        }

        $hash = '';
        while ($value > 0) {
            $hash = $chars[$value % 62] . $hash;
            $value = intdiv($value, 62);
        }

        return $hash;
    }

    public function test_customer_can_open_order_by_order_number_tracking_or_legacy_hash(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Key Hub',
            'code' => 'KEY-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Key Customer',
            'email' => 'key-customer@example.com',
            'status' => 'active',
        ]);
        $user = User::create([
            'name' => 'Key Customer',
            'email' => 'key-customer@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-KEY-TEST',
            'tracking_ref' => 'KH-KEY-TEST',
            'customer_id' => $customer->id,
            'origin_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'payment_method' => 'prepaid',
            'fulfillment_method' => 'delivery',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 0,
            'currency' => 'USD',
        ]);
        $token = $user->createToken('order-key-test')->plainTextToken;

        foreach (['ORD-KEY-TEST', 'KH-KEY-TEST', (string) $order->id, $this->base62($order->id)] as $key) {
            $this->withToken($token)
                ->getJson('/api/v1/customer/orders/' . $key)
                ->assertOk()
                ->assertJsonPath('data.id', $order->id)
                ->assertJsonPath('data.order_no', 'ORD-KEY-TEST');
        }

        $this->withToken($token)
            ->getJson('/api/v1/customer/orders/ORD-DOES-NOT-EXIST')
            ->assertNotFound();
    }

    public function test_customer_cannot_open_another_customers_order_by_key(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Private Hub',
            'code' => 'PRIVATE-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $owner = Customer::create([
            'name' => 'Owner Customer',
            'email' => 'owner-customer@example.com',
            'status' => 'active',
        ]);
        $intruder = Customer::create([
            'name' => 'Intruder Customer',
            'email' => 'intruder-customer@example.com',
            'status' => 'active',
        ]);
        $intruderUser = User::create([
            'name' => 'Intruder Customer',
            'email' => 'intruder-customer@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-PRIVATE-TEST',
            'customer_id' => $owner->id,
            'origin_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'payment_method' => 'cod',
            'fulfillment_method' => 'pickup',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 0,
            'currency' => 'USD',
        ]);
        $token = $intruderUser->createToken('intruder-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/customer/orders/ORD-PRIVATE-TEST')
            ->assertNotFound();

        $this->withToken($token)
            ->getJson('/api/v1/customer/orders/' . $order->id)
            ->assertNotFound();
    }
}
