<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates a demo customer account + orders so the customer-facing app
 * shows realistic data. Idempotent: safe to re-run.
 */
class CustomerDemoSeeder extends Seeder
{
    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['email' => 'chen@qiyou.logistics'],
            [
                'name' => 'Mr. Chen',
                'phone' => '855 12 345 678',
                'address' => 'No. 12, Street 271, Phnom Penh',
                'city' => 'Phnom Penh',
                'country' => 'Cambodia',
                'status' => 'active',
            ]
        );

        $role = Role::where('name', 'customer')->firstOrFail();

        $user = User::updateOrCreate(
            ['email' => 'chen@qiyou.logistics'],
            [
                'name' => 'Mr. Chen',
                'phone' => '855 12 345 678',
                'password' => Hash::make('password'),
                'status' => 'active',
                'customer_id' => $customer->id,
            ]
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $origin = Warehouse::where('code', 'TW-O01')->first()
            ?? Warehouse::where('type', 'origin')->first();
        $dest = Warehouse::where('type', 'destination')->first()
            ?? Warehouse::where('country', 'Cambodia')->first();

        if (! $origin || ! $dest) {
            $this->command?->warn('Origins/destinations missing - create warehouses first.');

            return;
        }

        $this->makeDemoOrder($customer, $origin, $dest, 'in_transit', [
            'tracking_ref' => '#KH' . now()->subDays(3)->format('Ymd') . '-007',
            'sender_name' => 'Mr. Chen',
            'receiver_name' => 'Bong Sreypov',
            'receiver_phone' => '855 97 111 222',
            'receiver_address' => 'Phsar Kandal, Phnom Penh',
            'maybe_create_package' => [
                'description' => 'Mixed cargo',
                'weight' => 16.0,
                'length' => 60,
                'width' => 45,
                'height' => 40,
                'quantity' => 3,
                'declared_value' => 214.0,
            ],
        ]);

        $this->makeDemoOrder($customer, $origin, $dest, 'cleared', [
            'tracking_ref' => '#KH' . now()->subDays(6)->format('Ymd') . '-011',
            'sender_name' => 'Mr. Chen',
            'receiver_name' => 'Dara',
            'receiver_phone' => '855 16 333 444',
            'receiver_address' => 'Olympic Market, Phnom Penh',
            'maybe_create_package' => [
                'description' => 'Fashion goods',
                'weight' => 1.5,
                'length' => 30,
                'width' => 25,
                'height' => 12,
                'quantity' => 1,
                'declared_value' => 89.0,
            ],
        ]);
    }

    protected function makeDemoOrder(Customer $customer, Warehouse $origin, Warehouse $dest, string $finalStatus, array $opts): void
    {
        $existing = Order::where('tracking_ref', $opts['tracking_ref'])->first();

        if ($existing && $existing->status === $finalStatus) {
            return;
        }

        if ($existing) {
            $existing->packages()->delete();
            $existing->trackingEvents()->delete();
            $existing->delete();
        }

        $pkg = $opts['maybe_create_package'];

        $order = Order::create([
            'order_no' => Order::generateOrderNo(),
            'tracking_ref' => $opts['tracking_ref'],
            'customer_id' => $customer->id,
            'sender_name' => $opts['sender_name'],
            'sender_phone' => $customer->phone,
            'origin_warehouse_id' => $origin->id,
            'destination_warehouse_id' => $dest->id,
            'transport_method' => 'sea',
            'payment_method' => 'cod',
            'fulfillment_method' => 'delivery',
            'receiver_name' => $opts['receiver_name'],
            'receiver_phone' => $opts['receiver_phone'],
            'receiver_address' => $opts['receiver_address'],
            'status' => $finalStatus,
            'estimated_fee' => 25.0,
            'currency' => 'USD',
            'notes' => 'Demo shipment for customer app',
        ]);

        $package = Package::create([
            'order_id' => $order->id,
            'package_no' => Package::generatePackageNo(),
            'barcode' => Package::generateBarcode(),
            'description' => $pkg['description'],
            'weight' => $pkg['weight'],
            'length' => $pkg['length'],
            'width' => $pkg['width'],
            'height' => $pkg['height'],
            'quantity' => $pkg['quantity'],
            'declared_value' => $pkg['declared_value'],
            'currency' => 'USD',
            'status' => $finalStatus,
            'current_warehouse_id' => $finalStatus === 'cleared' ? $dest->id : $origin->id,
            'notes' => null,
        ]);
        $package->calculateWeights()->save();

        $this->addTrackingFlow($order, $package, $finalStatus);
    }

    protected function addTrackingFlow(Order $order, Package $package, string $finalStatus): void
    {
        $origin = $order->originWarehouse;
        $dest = $order->destinationWarehouse;
        $daysAgo = fn (int $d) => now()->subDays($d);

        $events = [
            ['status' => 'pending', 'location' => $origin->name, 'note' => 'Order placed', 'at' => $daysAgo(9)],
            ['status' => 'received', 'location' => $origin->name, 'note' => 'Package received at origin', 'at' => $daysAgo(8)],
            ['status' => 'in_warehouse', 'location' => $origin->name, 'note' => 'Stored in warehouse', 'at' => $daysAgo(7)],
            ['status' => 'in_transit', 'location' => $origin->name, 'note' => 'Left origin warehouse', 'at' => $daysAgo(5)],
        ];

        if ($finalStatus === 'in_transit') {
            $events[] = ['status' => 'in_transit', 'location' => 'Pacific Ocean', 'note' => 'At sea', 'at' => $daysAgo(2)];
        } else {
            $events[] = ['status' => 'in_transit', 'location' => $dest->name, 'note' => 'Arrived at destination port', 'at' => $daysAgo(1)];
            $events[] = ['status' => 'cleared', 'location' => $dest->name, 'note' => 'Customs cleared', 'at' => $daysAgo(0)];
        }

        foreach ($events as $i => $event) {
            $order->trackingEvents()->create([
                'status' => $event['status'],
                'location' => $event['location'],
                'note' => $event['note'],
                'actor_name' => 'system',
                'created_at' => $event['at'],
                'updated_at' => $event['at'],
            ]);
            if ($i === count($events) - 1) {
                $package->trackingEvents()->create([
                    'status' => $event['status'],
                    'location' => $event['location'],
                    'note' => $event['note'],
                    'actor_name' => 'system',
                    'created_at' => $event['at'],
                    'updated_at' => $event['at'],
                ]);
            }
        }
    }
}