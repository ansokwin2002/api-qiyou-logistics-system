<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Driver;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_register_login_and_update_profile(): void
    {
        Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
        ]);

        $response = $this->postJson('/api/v1/delivery/register', [
            'name' => 'Sovann Driver',
            'email' => 'driver@example.com',
            'phone' => '+855 12 345 678',
            'password' => 'secret12',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'driver@example.com');

        $user = User::where('email', 'driver@example.com')->first();
        $this->assertTrue($user->hasRole('driver'));
        $this->assertDatabaseHas('drivers', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        $login = $this->postJson('/api/v1/delivery/login', [
            'email' => 'driver@example.com',
            'password' => 'secret12',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.user.name', 'Sovann Driver');

        $token = $login->json('data.token');

        $profile = $this->withToken($token)->getJson('/api/v1/delivery/profile');
        $profile->assertOk()
            ->assertJsonPath('data.driver.name', 'Sovann Driver');

        $updated = $this->withToken($token)->putJson('/api/v1/delivery/profile', [
            'name' => 'Sovann Updated',
            'phone' => '+855 99 123 456',
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.driver.name', 'Sovann Updated')
            ->assertJsonPath('data.user.phone', '+855 99 123 456');
    }

    public function test_driver_can_list_and_complete_assigned_task(): void
    {
        $role = Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
        ]);
        $user = User::create([
            'name' => 'Task Driver',
            'email' => 'task-driver@example.com',
            'phone' => '+855 12 345 678',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $user->roles()->attach($role);
        $driver = Driver::create([
            'user_id' => $user->id,
            'name' => 'Task Driver',
            'phone' => '+855 12 345 678',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Destination Hub',
            'code' => 'DEST-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-DRIVER-TEST',
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
            'package_no' => 'PKG-DRIVER-TEST',
            'barcode' => 'DRIVER-TEST-BARCODE',
            'quantity' => 2,
            'currency' => 'USD',
            'status' => Package::STATUS_READY_FOR_PICKUP,
        ]);
        $delivery = Delivery::create([
            'order_id' => $order->id,
            'package_id' => $package->id,
            'type' => Delivery::TYPE_DELIVERY,
            'driver_id' => $driver->user_id,
            'status' => Delivery::STATUS_PENDING,
            'receiver_name' => 'Test Receiver',
            'receiver_phone' => '+855 11 222 333',
            'ready_at' => now(),
        ]);
        $token = $user->createToken('driver-test')->plainTextToken;

        $tasks = $this->withToken($token)->postJson('/api/v1/delivery/mytasks', [
            'page' => 1,
            'pageSize' => 10,
        ]);

        $tasks->assertOk()
            ->assertJsonPath('data.data.0.id', $delivery->id)
            ->assertJsonPath('data.data.0.status', 'ASSIGNED');

        $detail = $this->withToken($token)->getJson('/api/v1/delivery/task/'.$delivery->id);
        $detail->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.receiverName', 'Test Receiver')
            ->assertJsonPath('data.item.barcode', 'DRIVER-TEST-BARCODE')
            ->assertJsonPath('data.requiresBarcodeScan', true);

        $missingProof = $this->withToken($token)->postJson('/api/v1/delivery/updatestatus', [
            'id' => $delivery->id,
            'status' => 'DELIVERED',
            'codCollected' => 0,
        ]);
        $missingProof->assertStatus(422);

        $completed = $this->withToken($token)->postJson('/api/v1/delivery/updatestatus', [
            'id' => $delivery->id,
            'status' => 'DELIVERED',
            'codCollected' => 0,
            'signature' => 'data:image/jpeg;base64,TESTSIG',
            'barcode' => 'DRIVER-TEST-BARCODE',
        ]);

        $completed->assertOk()
            ->assertJsonPath('data.status', 'DELIVERED');
        $this->assertSame(Delivery::STATUS_DELIVERED, $delivery->fresh()->status);
        $this->assertSame('data:image/jpeg;base64,TESTSIG', $delivery->fresh()->signature);
        $this->assertSame(Package::STATUS_DELIVERED, $package->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_driver_can_report_issue_on_task(): void
    {
        $role = Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
        ]);
        $user = User::create([
            'name' => 'Issue Driver',
            'email' => 'issue-driver@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $user->roles()->attach($role);
        $driver = Driver::create([
            'user_id' => $user->id,
            'name' => 'Issue Driver',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Issue Hub',
            'code' => 'DEST-ISSUE-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Issue Customer',
            'email' => 'issue-customer@example.com',
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-ISSUE-TEST',
            'customer_id' => $customer->id,
            'origin_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'payment_method' => 'prepaid',
            'fulfillment_method' => 'delivery',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 0,
            'currency' => 'USD',
        ]);
        $delivery = Delivery::create([
            'order_id' => $order->id,
            'type' => Delivery::TYPE_DELIVERY,
            'driver_id' => $driver->user_id,
            'status' => Delivery::STATUS_PENDING,
            'receiver_name' => 'Issue Receiver',
        ]);
        $token = $user->createToken('issue-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/delivery/updatestatus', [
                'id' => $delivery->id,
                'status' => 'FAILED',
            ])
            ->assertStatus(422);

        $reported = $this->withToken($token)->postJson('/api/v1/delivery/updatestatus', [
            'id' => $delivery->id,
            'status' => 'FAILED',
            'issueReason' => 'Recipient unavailable',
        ]);

        $reported->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.issueReason', 'Recipient unavailable');
        $this->assertSame(Delivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertSame('Recipient unavailable', $delivery->fresh()->issue_reason);
    }

    public function test_driver_cannot_access_another_drivers_task(): void
    {
        $role = Role::create([
            'name' => 'Driver',
            'slug' => 'driver',
        ]);
        $user = User::create([
            'name' => 'Unauthorized Driver',
            'email' => 'unauthorized-driver@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $user->roles()->attach($role);
        $owner = User::create([
            'name' => 'Task Owner',
            'email' => 'task-owner@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $owner->roles()->attach($role);
        $otherDriver = Driver::create([
            'user_id' => $owner->id,
            'name' => 'Task Owner',
            'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Other Customer',
            'email' => 'other-customer@example.com',
            'status' => 'active',
        ]);
        $warehouse = Warehouse::create([
            'name' => 'Other Destination Hub',
            'code' => 'DEST-OTHER-001',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);
        $order = Order::create([
            'order_no' => 'ORD-OTHER-DRIVER-TEST',
            'customer_id' => $customer->id,
            'origin_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'payment_method' => 'prepaid',
            'fulfillment_method' => 'delivery',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 0,
            'currency' => 'USD',
        ]);
        $delivery = Delivery::create([
            'order_id' => $order->id,
            'type' => Delivery::TYPE_DELIVERY,
            'driver_id' => $otherDriver->user_id,
            'status' => Delivery::STATUS_PENDING,
        ]);
        $token = $user->createToken('other-driver-test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/delivery/updatestatus', [
                'id' => $delivery->id,
                'status' => 'DELIVERED',
            ])
            ->assertNotFound();
    }
}
