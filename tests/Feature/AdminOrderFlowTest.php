<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CodCollection;
use App\Models\Delivery;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Package;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseBin;
use App\Models\WarehouseLevel;
use App\Models\WarehouseRack;
use App\Models\WarehouseZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $origin;

    private Warehouse $destination;

    private Customer $customer;

    private Order $order;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->origin = Warehouse::create([
            'name' => 'Phnom Penh Origin Hub',
            'code' => 'PNH-ORIGIN',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'type' => 'origin',
            'status' => 'active',
        ]);

        $this->destination = Warehouse::create([
            'name' => 'Siem Reap Destination Hub',
            'code' => 'REP-DEST',
            'city' => 'Siem Reap',
            'country' => 'Cambodia',
            'type' => 'destination',
            'status' => 'active',
        ]);

        $this->customer = Customer::create([
            'name' => 'Flow Customer',
            'email' => 'flow-customer@example.com',
            'status' => 'active',
        ]);

        $this->order = Order::create([
            'order_no' => 'ORD-FLOW-001',
            'tracking_ref' => 'KH-FLOW-001',
            'customer_id' => $this->customer->id,
            'origin_warehouse_id' => $this->origin->id,
            'destination_warehouse_id' => $this->destination->id,
            'transport_method' => 'air',
            'payment_method' => 'cod',
            'fulfillment_method' => 'delivery',
            'status' => Order::STATUS_PENDING,
            'estimated_fee' => 45,
            'currency' => 'USD',
        ]);

        $this->package = Package::create([
            'order_id' => $this->order->id,
            'package_no' => 'PKG-FLOW-001',
            'barcode' => 'FLOW-BARCODE-001',
            'description' => 'Flow test item',
            'weight' => 2,
            'quantity' => 1,
            'currency' => 'USD',
            'status' => Package::STATUS_PENDING,
            'current_warehouse_id' => $this->origin->id,
        ]);
    }

    public function test_delivery_order_flows_from_receive_to_cod_settlement(): void
    {
        // 1. Package received at the origin warehouse
        $this->postJson('/api/manageapi/package/receive', [
            'data' => [
                'id' => $this->package->id,
                'warehouseId' => $this->origin->id,
                'note' => 'Received at origin',
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame(Package::STATUS_RECEIVED, $this->package->fresh()->status);

        // 2. Package stored in a bin
        $bin = $this->makeBin($this->origin, 'ZONE-A', 'RACK-1', 'L1', 'A-01-01');

        $this->postJson('/api/manageapi/package/assignbin', [
            'data' => ['id' => $this->package->id, 'binLocation' => $bin->code],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame(Package::STATUS_IN_WAREHOUSE, $this->package->fresh()->status);
        $this->assertSame($bin->id, $this->package->fresh()->current_bin_id);

        // 3. Customs declared then cleared
        $created = $this->postJson('/api/manageapi/customs/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'receiverIdType' => 'National ID',
                'receiverIdNo' => '123456',
                'englishItemName' => 'T-Shirts',
                'hsCode' => '6109.10',
                'declaredValue' => 30,
                'currency' => 'USD',
            ],
        ]);
        $created->assertOk()->assertJsonPath('code', '1');
        $declarationId = $created->json('data.id');

        $this->assertSame(Package::STATUS_CUSTOMS, $this->package->fresh()->status);

        $this->postJson('/api/manageapi/customs/declared', ['data' => ['id' => $declarationId]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'Declared');

        $this->postJson('/api/manageapi/customs/cleared', ['data' => ['id' => $declarationId]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'Cleared');

        $this->assertSame(Package::STATUS_CLEARED, $this->package->fresh()->status);

        // 4. Shipment created, departed and arrived
        $shipment = $this->postJson('/api/manageapi/shipment/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'carrier' => 'AirAsia Cargo',
                'origin' => $this->origin->name,
                'destination' => $this->destination->name,
                'transportMethod' => 'Air',
            ],
        ]);
        $shipment->assertOk()->assertJsonPath('code', '1');
        $shipmentId = $shipment->json('data.id');
        $this->assertCount(1, $shipment->json('data.legs'));

        $this->postJson('/api/manageapi/shipment/depart', ['data' => ['id' => $shipmentId]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'IN TRANSIT');

        $this->assertSame(Package::STATUS_IN_TRANSIT, $this->package->fresh()->status);
        $this->assertSame($this->origin->id, $this->package->fresh()->current_warehouse_id);
        $this->assertSame(Order::STATUS_IN_PROGRESS, $this->order->fresh()->status);

        $this->postJson('/api/manageapi/shipment/arrive', ['data' => ['id' => $shipmentId]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(Package::STATUS_ARRIVED, $this->package->fresh()->status);
        $this->assertSame($this->destination->id, $this->package->fresh()->current_warehouse_id);
        $this->assertSame('completed', Shipment::find($shipmentId)->status);

        // 5. Delivery task created and started
        $role = Role::create(['name' => 'Driver', 'slug' => 'driver']);
        $driverUser = User::create([
            'name' => 'Flow Driver',
            'email' => 'flow-driver@example.com',
            'password' => bcrypt('secret12'),
            'status' => 'active',
        ]);
        $driverUser->roles()->attach($role);
        $driver = Driver::create([
            'user_id' => $driverUser->id,
            'name' => 'Flow Driver',
            'status' => 'active',
        ]);

        $delivery = $this->postJson('/api/manageapi/delivery/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'driverId' => $driverUser->id,
                'receiverName' => 'Chan Dara',
                'receiverPhone' => '+855 12 333 444',
            ],
        ]);
        $delivery->assertOk()->assertJsonPath('code', '1');
        $deliveryId = $delivery->json('data.id');

        $this->postJson('/api/manageapi/delivery/start', ['data' => ['id' => $deliveryId]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'OUT FOR DELIVERY');

        $this->assertSame(Package::STATUS_OUT_FOR_DELIVERY, $this->package->fresh()->status);

        // 6. Delivery completed with COD collected
        $this->postJson('/api/manageapi/delivery/complete', [
            'data' => [
                'id' => $deliveryId,
                'receiverName' => 'Chan Dara',
                'codCollected' => 45,
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame(Delivery::STATUS_DELIVERED, Delivery::find($deliveryId)->status);
        $this->assertSame(Package::STATUS_DELIVERED, $this->package->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, $this->order->fresh()->status);

        $cod = CodCollection::where('order_id', $this->order->id)->first();
        $this->assertNotNull($cod);
        $this->assertSame(CodCollection::STATUS_COLLECTED, $cod->settlement_status);

        // 7. COD settlement
        $listed = $this->postJson('/api/manageapi/cod/getlist', ['data' => ['page' => 1, 'pageSize' => 10]]);
        $listed->assertOk()->assertJsonPath('code', '1');
        $this->assertSame('Collected', $listed->json('data.data.0.settlementStatus'));

        $this->postJson('/api/manageapi/cod/settle', ['data' => ['id' => $cod->id]])
            ->assertOk()->assertJsonPath('code', '1')
            ->assertJsonPath('data.settlementStatus', 'Settled');

        $this->assertSame(CodCollection::STATUS_SETTLED, $cod->fresh()->settlement_status);

        // 8. Tracking page shows the full journey on the order timeline
        $tracking = $this->postJson('/api/manageapi/tracking/getlist', ['data' => ['page' => 1, 'pageSize' => 50]]);
        $tracking->assertOk()->assertJsonPath('code', '1');

        $statuses = collect($tracking->json('data.data'))->pluck('status')->all();
        foreach (['RECEIVED', 'IN WAREHOUSE', 'CUSTOMS', 'CLEARED', 'IN TRANSIT', 'ARRIVED', 'OUT FOR DELIVERY', 'DELIVERED'] as $expected) {
            $this->assertContains($expected, $statuses, "Missing tracking event: {$expected}");
        }

        $this->assertContains(
            $this->order->order_no,
            collect($tracking->json('data.data'))->pluck('orderNo')->all()
        );

        // 9. Package detail round trip for the edit dialog
        $this->getJson('/api/manageapi/package/get?id=' . $this->package->id)
            ->assertOk()
            ->assertJsonPath('code', '1')
            ->assertJsonPath('data.status', 'DELIVERED')
            ->assertJsonPath('data.orderNo', 'ORD-FLOW-001');

        $this->postJson('/api/manageapi/package/edit', [
            'data' => [
                'id' => $this->package->id,
                'barcode' => 'FLOW-BARCODE-001',
                'orderNo' => 'ORD-FLOW-001',
                'itemName' => 'Renamed item',
                'quantity' => 3,
                'weight' => 4.5,
                'dimensions' => '20x10x5',
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->package->refresh();
        $this->assertSame('Renamed item', $this->package->description);
        $this->assertSame(3, (int) $this->package->quantity);
        $this->assertSame('20.00', (string) $this->package->length);
        $this->assertEqualsWithDelta(4.5, (float) $this->package->chargeable_weight, 0.01);
    }

    public function test_self_pickup_order_flows_to_pickup_completion(): void
    {
        $this->order->update(['fulfillment_method' => 'pickup']);

        $pickup = $this->postJson('/api/manageapi/pickup/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'pickerName' => 'Sokha',
                'pickerIdType' => 'Passport',
                'pickerIdNo' => 'N123456',
                'quantity' => 1,
            ],
        ]);
        $pickup->assertOk()->assertJsonPath('code', '1');
        $pickupId = $pickup->json('data.id');

        $this->postJson('/api/manageapi/pickup/get?id=' . $pickupId)
            ->assertOk()
            ->assertJsonPath('data.status', 'READY FOR PICKUP');

        $this->assertSame(Package::STATUS_READY_FOR_PICKUP, $this->package->fresh()->status);

        $this->postJson('/api/manageapi/pickup/complete', [
            'data' => [
                'id' => $pickupId,
                'pickerName' => 'Sokha',
                'pickerIdType' => 'Passport',
                'pickerIdNo' => 'N123456',
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame(Delivery::STATUS_PICKED_UP, Delivery::find($pickupId)->status);
        $this->assertSame(Package::STATUS_PICKED_UP, $this->package->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, $this->order->fresh()->status);

        $cod = CodCollection::where('order_id', $this->order->id)->first();
        $this->assertNotNull($cod);
        $this->assertSame(CodCollection::STATUS_COLLECTED, $cod->settlement_status);
    }

    public function test_admin_can_create_and_edit_cost_and_tracking_records(): void
    {
        $this->postJson('/api/manageapi/package/receive', [
            'data' => ['id' => $this->package->id, 'warehouseId' => $this->origin->id],
        ])->assertOk()->assertJsonPath('code', '1');

        $cost = $this->postJson('/api/manageapi/cost/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'category' => 'Freight',
                'amount' => 12.5,
                'currency' => 'USD',
                'note' => 'Air freight surcharge',
            ],
        ]);
        $cost->assertOk()->assertJsonPath('code', '1');
        $costId = $cost->json('data.id');

        $this->getJson('/api/manageapi/cost/get?id=' . $costId)
            ->assertOk()
            ->assertJsonPath('data.category', 'Freight')
            ->assertJsonPath('data.orderNo', 'ORD-FLOW-001')
            ->assertJsonPath('data.amount', 12.5);

        $this->postJson('/api/manageapi/cost/edit', [
            'data' => ['id' => $costId, 'amount' => 20, 'category' => 'Last-Mile', 'note' => 'Updated'],
        ])->assertOk()->assertJsonPath('code', '1');

        $edited = $this->getJson('/api/manageapi/cost/get?id=' . $costId);
        $edited->assertJsonPath('data.amount', 20)
            ->assertJsonPath('data.category', 'Last-Mile');

        $event = $this->postJson('/api/manageapi/tracking/add', [
            'data' => [
                'orderNo' => 'ORD-FLOW-001',
                'status' => 'IN TRANSIT',
                'location' => 'Phnom Penh',
                'operator' => 'Ops Team',
                'note' => 'Manual note',
            ],
        ]);
        $event->assertOk()->assertJsonPath('code', '1');

        $listed = $this->postJson('/api/manageapi/tracking/getlist', ['data' => ['status' => 'IN TRANSIT']]);
        $listed->assertOk()->assertJsonPath('code', '1');
        $this->assertNotEmpty($listed->json('data.data'));

        $filtered = $this->postJson('/api/manageapi/tracking/getlist', ['data' => ['status' => 'RECEIVED']]);
        $filtered->assertOk()->assertJsonPath('code', '1');
        $this->assertNotEmpty($filtered->json('data.data'));
    }

    public function test_flow_actions_report_business_errors_with_http_200(): void
    {
        $this->postJson('/api/manageapi/package/receive', [
            'data' => ['barcode' => 'DOES-NOT-EXIST'],
        ])->assertOk()->assertJsonPath('code', '0');

        $this->postJson('/api/manageapi/package/add', [
            'data' => ['barcode' => 'X-1'],
        ])->assertOk()->assertJsonPath('code', '0');

        $this->postJson('/api/manageapi/shipment/add', [
            'data' => ['orderNo' => 'MISSING-ORDER'],
        ])->assertOk()->assertJsonPath('code', '0');

        $this->postJson('/api/manageapi/delivery/complete', [
            'data' => ['id' => 999999],
        ])->assertOk()->assertJsonPath('code', '0');

        $this->postJson('/api/manageapi/pickup/add', [
            'data' => ['orderNo' => 'ORD-FLOW-001'],
        ])->assertOk()->assertJsonPath('code', '0')
            ->assertJsonPath('message', 'Order ORD-FLOW-001 is set up for delivery, not self-pickup');

        $this->postJson('/api/manageapi/cod/settle', [
            'data' => ['id' => 999999],
        ])->assertOk()->assertJsonPath('code', '0');
    }

    private function makeBin(Warehouse $warehouse, string $zoneCode, string $rackCode, string $levelCode, string $binCode): WarehouseBin
    {
        $zone = WarehouseZone::create([
            'warehouse_id' => $warehouse->id,
            'name' => $zoneCode,
            'code' => $zoneCode,
        ]);

        $rack = WarehouseRack::create([
            'warehouse_zone_id' => $zone->id,
            'name' => $rackCode,
            'code' => $rackCode,
        ]);

        $level = WarehouseLevel::create([
            'warehouse_rack_id' => $rack->id,
            'name' => $levelCode,
            'code' => $levelCode,
        ]);

        return WarehouseBin::create([
            'warehouse_level_id' => $level->id,
            'name' => $binCode,
            'code' => $binCode,
            'status' => 'available',
        ]);
    }
}
