<?php

namespace Database\Seeders;

use App\Models\CodCollection;
use App\Models\Cost;
use App\Models\CustomsDeclaration;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShipmentLeg;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LogisticsFlowSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding full logistics flow...');

        // ============================================================
        // 1. WAREHOUSES (already exist from DatabaseSeeder)
        // ============================================================
        $twOrigin = Warehouse::where('code', 'TW-O01')->first();
        $khDest = Warehouse::where('code', 'KH-D01')->first();

        if (! $twOrigin || ! $khDest) {
            $this->command->error('Required warehouses missing. Run DatabaseSeeder first.');
            return;
        }

        // ============================================================
        // 2. CUSTOMERS + USERS
        // ============================================================
        $customerData = [
            [
                'name'  => 'Sok Chenda',
                'email' => 'chenda@demo.kh',
                'phone' => '+855 12 111 222',
                'city'  => 'Phnom Penh',
            ],
            [
                'name'  => 'Mao Sreyneang',
                'email' => 'sreyneang@demo.kh',
                'phone' => '+855 15 333 444',
                'city'  => 'Siem Reap',
            ],
            [
                'name'  => 'Li Wei',
                'email' => 'liwei@demo.tw',
                'phone' => '+886 9 555 666',
                'city'  => 'Taipei',
            ],
            [
                'name'  => 'Chen Meiling',
                'email' => 'meiling@demo.tw',
                'phone' => '+886 9 777 888',
                'city'  => 'Kaohsiung',
            ],
            [
                'name'  => 'Heng Piseth',
                'email' => 'piseth@demo.kh',
                'phone' => '+855 16 999 000',
                'city'  => 'Battambang',
            ],
        ];

        $customers = [];
        $customerRole = Role::where('slug', 'customer')->first();

        foreach ($customerData as $c) {
            $customer = Customer::firstOrCreate(
                ['email' => $c['email']],
                [
                    'name'   => $c['name'],
                    'phone'  => $c['phone'],
                    'city'   => $c['city'],
                    'country' => 'Cambodia',
                    'status' => 'active',
                ]
            );

            $user = User::firstOrCreate(
                ['email' => $c['email']],
                [
                    'name'     => $c['name'],
                    'phone'    => $c['phone'],
                    'password' => Hash::make('password'),
                    'status'   => 'active',
                    'customer_id' => $customer->id,
                ]
            );
            if ($customerRole && ! $user->roles->contains($customerRole->id)) {
                $user->roles()->syncWithoutDetaching([$customerRole->id]);
            }

            $customer->load('user');
            $customers[] = $customer;
        }

        // Existing customer "new" (id 49) - link to a user if not already
        $existingCustomer = Customer::find(49);
        if ($existingCustomer && ! $existingCustomer->user) {
            $u = User::where('email', 'new@gmail.com')->first();
            if ($u) {
                $u->update(['customer_id' => $existingCustomer->id]);
            }
        }

        $allCustomers = Customer::all()->keyBy('id');

        // ============================================================
        // 3. RELINK EXISTING ORDERS (1-7) TO REAL CUSTOMERS
        // ============================================================
        // Orders 1,2,3 -> customer 1 (Sok Chenda)
        // Orders 4,6    -> customer 2 (Mao Sreyneang)
        // Order 7       -> customer 3 (Li Wei)
        $relinkMap = [
            1 => $customers[0]->id,
            2 => $customers[0]->id,
            3 => $customers[0]->id,
            4 => $customers[1]->id,
            6 => $customers[1]->id,
            7 => $customers[2]->id,
        ];

        foreach ($relinkMap as $orderId => $custId) {
            Order::where('id', $orderId)->update([
                'customer_id' => $custId,
                'status'      => $this->normalizeExistingStatus(Order::find($orderId)?->status),
                'fulfillment_method' => strtolower(Order::find($orderId)?->fulfillment_method ?? 'delivery'),
            ]);
        }

        // Normalize order 7 fulfillment 'self-pickup' -> 'pickup'
        Order::where('id', 7)->update(['fulfillment_method' => 'pickup']);

        // ============================================================
        // 4. NEW ORDERS (8-15) WITH FULL LIFECYCLE SPREAD
        // ============================================================
        $now = Carbon::now();
        $baseDate = $now->copy()->subDays(30);

        $newOrders = [
            // Completed (delivered, COD collected) - 2 weeks ago
            [
                'customer_id' => $customers[0]->id,
                'origin'      => $twOrigin->id,
                'dest'        => $khDest->id,
                'transport'   => 'air',
                'payment'     => 'cod',
                'fulfillment' => 'delivery',
                'fee'         => 85.00,
                'receiver'    => 'Sok Chenda',
                'recvPhone'   => '+855 12 111 222',
                'recvAddr'    => 'Phnom Penh, Cambodia',
                'status'      => 'completed',
                'daysAgo'     => 14,
                'pkgs'        => [['desc' => 'Electronics', 'wt' => 2.5, 'qty' => 1]],
            ],
            [
                'customer_id' => $customers[1]->id,
                'origin'      => $khDest->id,
                'dest'        => $twOrigin->id,
                'transport'   => 'sea',
                'payment'     => 'prepaid',
                'fulfillment' => 'delivery',
                'fee'         => 42.00,
                'receiver'    => 'Mao Sreyneang',
                'recvPhone'   => '+855 15 333 444',
                'recvAddr'    => 'Siem Reap, Cambodia',
                'status'      => 'completed',
                'daysAgo'     => 12,
                'pkgs'        => [['desc' => 'Clothing', 'wt' => 1.8, 'qty' => 3]],
            ],
            // In progress (in transit, cleared customs) - 1 week ago
            [
                'customer_id' => $customers[2]->id,
                'origin'      => $twOrigin->id,
                'dest'        => $khDest->id,
                'transport'   => 'sea',
                'payment'     => 'cod',
                'fulfillment' => 'delivery',
                'fee'         => 55.00,
                'receiver'    => 'Li Wei',
                'recvPhone'   => '+886 9 555 666',
                'recvAddr'    => 'Taipei, Taiwan',
                'status'      => 'in_transit',
                'daysAgo'     => 7,
                'pkgs'        => [['desc' => 'Machinery parts', 'wt' => 15.0, 'qty' => 1]],
            ],
            [
                'customer_id' => $customers[3]->id,
                'origin'      => $khDest->id,
                'dest'        => $twOrigin->id,
                'transport'   => 'air',
                'payment'     => 'prepaid',
                'fulfillment' => 'pickup',
                'fee'         => 120.00,
                'receiver'    => 'Chen Meiling',
                'recvPhone'   => '+886 9 777 888',
                'recvAddr'    => 'Kaohsiung, Taiwan',
                'status'      => 'cleared',
                'daysAgo'     => 5,
                'pkgs'        => [['desc' => 'Fashion accessories', 'wt' => 3.2, 'qty' => 2]],
            ],
            // Confirmed (received at origin) - 3 days ago
            [
                'customer_id' => $customers[4]->id,
                'origin'      => $twOrigin->id,
                'dest'        => $khDest->id,
                'transport'   => 'land',
                'payment'     => 'prepaid',
                'fulfillment' => 'delivery',
                'fee'         => 28.00,
                'receiver'    => 'Heng Piseth',
                'recvPhone'   => '+855 16 999 000',
                'recvAddr'    => 'Battambang, Cambodia',
                'status'      => 'confirmed',
                'daysAgo'     => 3,
                'pkgs'        => [['desc' => 'Documents', 'wt' => 0.5, 'qty' => 1]],
            ],
            // Pending (new today) - 2 orders
            [
                'customer_id' => $customers[0]->id,
                'origin'      => $twOrigin->id,
                'dest'        => $khDest->id,
                'transport'   => 'sea',
                'payment'     => 'cod',
                'fulfillment' => 'delivery',
                'fee'         => 38.00,
                'receiver'    => 'Sok Chenda',
                'recvPhone'   => '+855 12 111 222',
                'recvAddr'    => 'Phnom Penh, Cambodia',
                'status'      => 'pending',
                'daysAgo'     => 0,
                'pkgs'        => [['desc' => 'Home goods', 'wt' => 8.0, 'qty' => 2]],
            ],
            [
                'customer_id' => $customers[1]->id,
                'origin'      => $khDest->id,
                'dest'        => $twOrigin->id,
                'transport'   => 'air',
                'payment'     => 'prepaid',
                'fulfillment' => 'pickup',
                'fee'         => 95.00,
                'receiver'    => 'Mao Sreyneang',
                'recvPhone'   => '+855 15 333 444',
                'recvAddr'    => 'Siem Reap, Cambodia',
                'status'      => 'pending',
                'daysAgo'     => 0,
                'pkgs'        => [['desc' => 'Artwork', 'wt' => 4.5, 'qty' => 1]],
            ],
        ];

        $createdOrders = [];
        foreach ($newOrders as $i => $o) {
            $createdAt = $baseDate->copy()->addDays($o['daysAgo']);
            $order = Order::create([
                'order_no'             => Order::generateOrderNo(),
                'tracking_ref'         => '#KH' . $createdAt->format('Ymd') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
                'customer_id'          => $o['customer_id'],
                'sender_name'          => $allCustomers[$o['customer_id']]?->name ?? 'Customer',
                'sender_phone'         => $allCustomers[$o['customer_id']]?->phone,
                'origin_warehouse_id'  => $o['origin'],
                'destination_warehouse_id' => $o['dest'],
                'transport_method'     => $o['transport'],
                'payment_method'       => $o['payment'],
                'fulfillment_method'   => $o['fulfillment'],
                'receiver_name'        => $o['receiver'],
                'receiver_phone'       => $o['recvPhone'],
                'receiver_address'     => $o['recvAddr'],
                'status'               => $o['status'],
                'estimated_fee'        => $o['fee'],
                'currency'             => 'USD',
                'notes'                => 'Seeded order for demo flow',
                'created_at'           => $createdAt,
                'updated_at'           => $createdAt,
            ]);

            // Packages
            foreach ($o['pkgs'] as $pi => $pkg) {
                $p = new Package([
                    'package_no'  => Package::generatePackageNo(),
                    'barcode'     => Package::generateBarcode(),
                    'description' => $pkg['desc'],
                    'weight'      => $pkg['wt'],
                    'quantity'    => $pkg['qty'],
                    'declared_value' => 0,
                    'currency'    => 'USD',
                    'status'      => $this->packageStatusForOrder($o['status']),
                    'current_warehouse_id' => $o['origin'],
                ]);
                $p->calculateWeights();
                $order->packages()->save($p);
            }

            // COD expectation
            if ($o['payment'] === 'cod' && $o['fee'] > 0) {
                CodCollection::create([
                    'order_id'         => $order->id,
                    'expected_amount'  => $o['fee'],
                    'collected_amount' => 0,
                    'difference'       => 0,
                    'settlement_status'=> CodCollection::STATUS_PENDING,
                ]);
            }

            // Initial tracking event
            $order->trackingEvents()->create([
                'status'      => $order->status,
                'location'    => $twOrigin->name,
                'note'        => 'Order created (seeded)',
                'actor_name'  => 'System',
                'created_at'  => $createdAt,
            ]);

            $createdOrders[] = $order;
        }

        // Merge all orders for flow processing
        $allOrderIds = array_merge(range(1, 7), array_map(fn($o) => $o->id, $createdOrders));
        $allOrders = Order::whereIn('id', $allOrderIds)->get();

        // ============================================================
        // 5. SHIPMENTS + LEGS (for in_transit, cleared, completed)
        // ============================================================
        $driverUser = User::where('email', 'driver@qiyou.logistics')->first();

        foreach ($allOrders as $order) {
            if (! in_array($order->status, ['in_transit', 'cleared', 'completed'])) {
                continue;
            }

            $isCompleted = $order->status === 'completed';
            $daysOffset = match ($order->status) {
                'completed' => -2,
                'cleared'   => -1,
                'in_transit'=> 0,
                default     => 0,
            };

            $shipment = Shipment::create([
                'shipment_no' => Shipment::generateShipmentNo(),
                'order_id'    => $order->id,
                'carrier'     => 'Qiyou Express',
                'status'      => $isCompleted ? 'completed' : 'in_transit',
                'departed_at' => $order->created_at->copy()->addDays($daysOffset)->subDays(1)->setTime(8, 0),
                'arrived_at'  => $isCompleted ? $order->created_at->copy()->addDays($daysOffset + 2)->setTime(14, 0) : null,
                'notes'       => 'Auto-generated from order flow',
            ]);

            // Leg: origin -> destination
            ShipmentLeg::create([
                'shipment_id'            => $shipment->id,
                'leg_no'                 => 1,
                'origin_warehouse_id'    => $order->origin_warehouse_id,
                'destination_warehouse_id' => $order->destination_warehouse_id,
                'transport_method'       => $order->transport_method,
                'status'                 => $isCompleted ? 'arrived' : 'in_transit',
                'departure_date'         => $shipment->departed_at,
                'arrival_date'           => $isCompleted ? $shipment->arrived_at : null,
                'notes'                  => 'Main leg',
            ]);

            // Update packages to current warehouse at destination (for completed/in_transit)
            $order->packages->each(function ($p) use ($order, $shipment, $isCompleted) {
                $p->update([
                    'current_warehouse_id' => $order->destination_warehouse_id,
                    'status'               => $isCompleted ? Package::STATUS_ARRIVED : Package::STATUS_IN_TRANSIT,
                ]);
            });
        }

        // ============================================================
        // 6. CUSTOMS DECLARATIONS (all international orders)
        // ============================================================
        foreach ($allOrders as $order) {
            $pkgs = $order->packages;
            if ($pkgs->isEmpty()) continue;

            $pkg = $pkgs->first();
            $cusStatus = match ($order->status) {
                'completed', 'cleared', 'delivered', 'in_transit' => 'cleared',
                'in_warehouse', 'arrived' => 'declared',
                default => 'pending',
            };

            CustomsDeclaration::create([
                'order_id'           => $order->id,
                'package_id'         => $pkg->id,
                'receiver_id_type'   => 'passport',
                'receiver_id_number' => 'P' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
                'english_item_name'  => $pkg->description,
                'hs_code'            => $this->hsCodeFor($pkg->description),
                'purpose'            => 'commercial',
                'material'           => 'various',
                'declared_value'     => round($order->estimated_fee * 1.5, 2),
                'currency'           => 'USD',
                'status'             => $cusStatus,
                'declared_at'        => $order->created_at->copy()->addDay(),
                'cleared_at'         => in_array($cusStatus, ['cleared']) ? $order->created_at->copy()->addDays(2) : null,
                'notes'              => 'Auto-declared for demo',
            ]);
        }

        // ============================================================
        // 7. DELIVERIES (delivery + pickup)
        // ============================================================
        foreach ($allOrders as $order) {
            if (! in_array($order->status, ['completed', 'in_transit', 'cleared', 'arrived'])) {
                continue;
            }

            $isPickup = $order->fulfillment_method === 'pickup';
            $delType = $isPickup ? Delivery::TYPE_PICKUP : Delivery::TYPE_DELIVERY;

            $delStatus = match ($order->status) {
                'completed' => $isPickup ? Delivery::STATUS_PICKED_UP : Delivery::STATUS_DELIVERED,
                'in_transit', 'cleared' => $delType === Delivery::TYPE_DELIVERY ? Delivery::STATUS_OUT_FOR_DELIVERY : Delivery::STATUS_PENDING,
                'arrived' => $delType === Delivery::TYPE_PICKUP ? Delivery::STATUS_PENDING : Delivery::STATUS_OUT_FOR_DELIVERY,
                default => Delivery::STATUS_PENDING,
            };

            $pkg = $order->packages->first();
            $delivery = Delivery::create([
                'order_id'       => $order->id,
                'package_id'     => $pkg?->id,
                'type'           => $delType,
                'driver_id'      => $delType === Delivery::TYPE_DELIVERY ? $driverUser?->id : null,
                'status'         => $delStatus,
                'ready_at'       => $order->created_at->copy()->addDays(2),
                'assigned_at'    => $driverUser ? $order->created_at->copy()->addDays(2)->addHours(2) : null,
                'delivered_at'   => in_array($delStatus, [Delivery::STATUS_DELIVERED, Delivery::STATUS_PICKED_UP])
                    ? $order->created_at->copy()->addDays(4) : null,
                'receiver_name'  => $order->receiver_name,
                'receiver_phone' => $order->receiver_phone,
                'receiver_id_type' => 'national_id',
                'receiver_id_number' => 'ID' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
                'signature'      => in_array($delStatus, [Delivery::STATUS_DELIVERED, Delivery::STATUS_PICKED_UP]) ? 'data:image/png;base64,fake' : null,
                'notes'          => $isPickup ? 'Self-pickup at warehouse' : 'Door delivery',
            ]);

            // Update package status for delivered/picked_up
            if ($pkg && in_array($delStatus, [Delivery::STATUS_DELIVERED, Delivery::STATUS_PICKED_UP])) {
                $pkg->update(['status' => $isPickup ? Package::STATUS_PICKED_UP : Package::STATUS_DELIVERED]);
            }

            // COD collection for COD orders
            if ($order->payment_method === 'cod') {
                $cod = CodCollection::where('order_id', $order->id)->first();
                if ($cod) {
                    $collected = in_array($delStatus, [Delivery::STATUS_DELIVERED, Delivery::STATUS_PICKED_UP])
                        ? (float) $cod->expected_amount
                        : 0.00;
                    $cod->update([
                        'delivery_id'       => $delivery->id,
                        'package_id'        => $pkg?->id,
                        'collected_amount'  => $collected,
                        'difference'        => round($cod->expected_amount - $collected, 2),
                        'collection_via'    => $isPickup ? 'warehouse' : 'driver',
                        'collected_by_id'   => $driverUser?->id,
                        'collected_at'      => $collected > 0 ? $delivery->delivered_at : null,
                        'settlement_status' => $collected >= $cod->expected_amount ? CodCollection::STATUS_COLLECTED : CodCollection::STATUS_PENDING,
                    ]);
                }
            }
        }

        // ============================================================
        // 8. COSTS (freight, customs, last_mile)
        // ============================================================
        foreach ($allOrders as $order) {
            $fee = (float) $order->estimated_fee;
            $base = $order->created_at;

            // Freight cost (50-65% of fee)
            Cost::create([
                'order_id'      => $order->id,
                'category'      => 'freight',
                'amount'        => round($fee * random_int(50, 65) / 100, 2),
                'currency'      => 'USD',
                'description'   => 'Freight: ' . $order->transport_method . ' transport',
                'cost_date'     => $base->copy()->addDay(),
            ]);

            // Customs fee for international (all are)
            if ($order->status !== 'pending') {
                Cost::create([
                    'order_id'      => $order->id,
                    'category'      => 'customs',
                    'amount'        => random_int(5, 15) + 0.00,
                    'currency'      => 'USD',
                    'description'   => 'Customs clearance fees',
                    'cost_date'     => $base->copy()->addDays(2),
                ]);
            }

            // Last mile for delivered/picked_up
            if (in_array($order->status, ['completed', 'delivered', 'picked_up'])) {
                Cost::create([
                    'order_id'      => $order->id,
                    'category'      => 'last_mile',
                    'amount'        => random_int(3, 8) + 0.00,
                    'currency'      => 'USD',
                    'description'   => 'Last mile delivery',
                    'cost_date'     => $base->copy()->addDays(4),
                ]);
            }

            // Warehouse handling
            Cost::create([
                'order_id'      => $order->id,
                'category'      => 'warehouse',
                'amount'        => random_int(2, 5) + 0.00,
                'currency'      => 'USD',
                'description'   => 'Warehouse handling & storage',
                'cost_date'     => $base->copy()->addDay(),
            ]);
        }

        // ============================================================
        // 9. INVOICES (one per order, linked via order_id)
        // ============================================================
        foreach ($allOrders as $order) {
            $invStatus = match ($order->status) {
                'completed', 'delivered', 'picked_up' => 'paid',
                'in_transit', 'cleared', 'arrived' => 'issued',
                default => 'draft',
            };

            $fee = (float) $order->estimated_fee;
            $cod = $order->payment_method === 'cod' ? $fee : 0;

            Invoice::create([
                'invoice_no'   => Invoice::generateInvoiceNo(),
                'customer_id'  => $order->customer_id,
                'order_id'     => $order->id,
                'period_start' => $order->created_at->copy()->startOfMonth()->toDateString(),
                'period_end'   => $order->created_at->copy()->endOfMonth()->toDateString(),
                'total_fee'    => $fee,
                'cod_total'    => $cod,
                'currency'     => 'USD',
                'status'       => $invStatus,
                'issued_at'    => in_array($invStatus, ['issued', 'paid']) ? $order->created_at->copy()->addDays(5) : null,
                'paid_at'      => $invStatus === 'paid' ? $order->created_at->copy()->addDays(7) : null,
                'notes'        => 'Auto-generated from order',
            ]);
        }

        // ============================================================
        // 10. TRACKING EVENTS (full journey per order)
        // ============================================================
        $statusJourney = [
            'pending'      => ['pending'],
            'confirmed'    => ['pending', 'confirmed'],
            'in_warehouse' => ['pending', 'confirmed', 'in_warehouse'],
            'customs'      => ['pending', 'confirmed', 'in_warehouse', 'customs'],
            'cleared'      => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared'],
            'in_transit'   => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit'],
            'arrived'      => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived'],
            'ready_for_pickup' => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived', 'ready_for_pickup'],
            'out_for_delivery' => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived', 'out_for_delivery'],
            'delivered'    => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived', 'out_for_delivery', 'delivered'],
            'picked_up'    => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived', 'ready_for_pickup', 'picked_up'],
            'completed'    => ['pending', 'confirmed', 'in_warehouse', 'customs', 'cleared', 'in_transit', 'arrived', 'out_for_delivery', 'delivered'],
        ];

        $locationMap = [
            'pending'            => 'Order placed',
            'confirmed'          => 'Order confirmed',
            'in_warehouse'       => 'Received at origin warehouse',
            'customs'            => 'Customs processing',
            'cleared'            => 'Customs cleared',
            'in_transit'         => 'In transit',
            'arrived'            => 'Arrived at destination warehouse',
            'ready_for_pickup'   => 'Ready for pickup',
            'out_for_delivery'   => 'Out for delivery',
            'delivered'          => 'Delivered to receiver',
            'picked_up'          => 'Picked up by customer',
            'completed'          => 'Order completed',
        ];

        foreach ($allOrders as $order) {
            $journey = $statusJourney[$order->status] ?? ['pending'];
            $base = $order->created_at;
            $dayOffset = 0;

            foreach ($journey as $step) {
                // Skip initial 'pending' if already created by orderAdd
                if ($step === 'pending' && $order->trackingEvents()->where('status', 'pending')->exists()) {
                    continue;
                }

                $loc = $this->locationForStep($step, $order);

                TrackingEvent::create([
                    'trackable_type' => Order::class,
                    'trackable_id'   => $order->id,
                    'status'         => $step,
                    'location'       => $loc,
                    'note'           => $locationMap[$step] ?? $step,
                    'actor_name'     => $this->actorForStep($step),
                    'created_at'     => $base->copy()->addDays($dayOffset)->setTime(9 + $dayOffset, 0),
                ]);
                $dayOffset++;
            }
        }

        $this->command->info('LogisticsFlowSeeder completed successfully!');
        $this->command->info('Orders: ' . Order::count() . ', Packages: ' . Package::count() . ', Shipments: ' . Shipment::count() . ', Deliveries: ' . Delivery::count() . ', COD: ' . CodCollection::count() . ', Costs: ' . Cost::count() . ', Customs: ' . CustomsDeclaration::count() . ', Invoices: ' . Invoice::count() . ', Tracking: ' . TrackingEvent::count());
    }

    protected function normalizeExistingStatus(?string $status): string
    {
        $map = [
            'PENDING' => 'pending',
            'IN TRANSIT' => 'in_transit',
            'CLEARED' => 'cleared',
            'DELIVERED' => 'completed',
            'PICKED UP' => 'picked_up',
        ];
        return $map[strtoupper($status)] ?? strtolower($status ?? 'pending');
    }

    protected function packageStatusForOrder(string $orderStatus): string
    {
        return match ($orderStatus) {
            'pending', 'confirmed'        => Package::STATUS_PENDING,
            'in_warehouse'                => Package::STATUS_IN_WAREHOUSE,
            'customs'                     => Package::STATUS_CUSTOMS,
            'cleared'                     => Package::STATUS_CLEARED,
            'in_transit', 'in_progress'   => Package::STATUS_IN_TRANSIT,
            'arrived'                     => Package::STATUS_ARRIVED,
            'ready_for_pickup'            => Package::STATUS_READY_FOR_PICKUP,
            'out_for_delivery'            => Package::STATUS_OUT_FOR_DELIVERY,
            'delivered', 'completed'      => Package::STATUS_DELIVERED,
            'picked_up'                   => Package::STATUS_PICKED_UP,
            default                       => Package::STATUS_PENDING,
        };
    }

    protected function hsCodeFor(string $desc): string
    {
        $codes = [
            'electronics'      => '8517',
            'clothing'         => '6204',
            'machinery'        => '8479',
            'fashion'          => '7117',
            'documents'        => '4907',
            'home goods'       => '9403',
            'artwork'          => '9701',
        ];
        foreach ($codes as $kw => $code) {
            if (stripos($desc, $kw) !== false) return $code;
        }
        return '9999';
    }

    protected function locationForStep(string $step, Order $order): string
    {
        return match ($step) {
            'pending', 'confirmed', 'in_warehouse'       => $order->originWarehouse?->name ?? 'Origin Warehouse',
            'customs', 'cleared'                         => 'Customs Office - ' . ($order->destinationWarehouse?->city ?? 'Destination'),
            'in_transit', 'arrived'                      => $order->destinationWarehouse?->name ?? 'Destination Warehouse',
            'ready_for_pickup'                           => $order->destinationWarehouse?->name . ' - Pickup Counter',
            'out_for_delivery', 'delivered'              => $order->receiver_address ?? 'Delivery Address',
            'picked_up'                                  => $order->destinationWarehouse?->name ?? 'Warehouse',
            'completed'                                  => 'Destination',
            default                                      => 'Warehouse',
        };
    }

    protected function actorForStep(string $step): string
    {
        return match ($step) {
            'pending', 'confirmed'        => 'System',
            'in_warehouse', 'customs'     => 'Warehouse Staff',
            'cleared'                     => 'Customs Officer',
            'in_transit'                  => 'Driver: Chan Rithy',
            'arrived'                     => 'Warehouse Staff',
            'ready_for_pickup'            => 'Warehouse Staff',
            'out_for_delivery'            => 'Driver: Chan Rithy',
            'delivered', 'picked_up'      => 'Driver: Chan Rithy',
            'completed'                   => 'System',
            default                       => 'System',
        };
    }
}