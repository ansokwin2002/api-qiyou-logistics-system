<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\RateCard;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V1CompletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoute(): array
    {
        $origin = Warehouse::create([
            'name' => 'Taiwan Origin', 'code' => 'TW-X', 'city' => 'Taipei',
            'country' => 'Taiwan', 'type' => 'origin', 'status' => 'active',
        ]);
        $destination = Warehouse::create([
            'name' => 'Phnom Penh Destination', 'code' => 'KH-X', 'city' => 'Phnom Penh',
            'country' => 'Cambodia', 'type' => 'destination', 'status' => 'active',
        ]);
        $customer = Customer::create([
            'name' => 'Fee Customer', 'email' => 'fee@example.com', 'status' => 'active',
        ]);

        return [$origin, $destination, $customer];
    }

    public function test_manageapi_requires_authentication(): void
    {
        $this->postJson('/api/manageapi/order/getlist', ['data' => []])
            ->assertStatus(401);

        $this->withToken($this->adminToken())
            ->postJson('/api/manageapi/order/getlist', ['data' => ['page' => 1, 'pageSize' => 5]])
            ->assertOk()
            ->assertJsonPath('code', '1');
    }

    public function test_rate_card_priced_estimate_uses_chargeable_weight(): void
    {
        RateCard::create([
            'name' => 'TW -> KH Air', 'transport_method' => 'air',
            'origin_country' => 'Taiwan', 'destination_country' => 'Cambodia',
            'first_kg' => 1, 'base_fee' => 12, 'rate_per_kg' => 6,
            'min_fee' => 12, 'currency' => 'USD', 'status' => true,
        ]);

        $response = $this->withToken($this->adminToken())->postJson('/api/v1/estimate', [
            'transport_method' => 'air',
            'weight' => 4,
            'length' => 50, 'width' => 40, 'height' => 30,
            'quantity' => 1,
            'origin_country' => 'Taiwan',
            'destination_country' => 'Cambodia',
        ])->assertOk();

        // volumetric = 50*40*30/5000 = 12kg -> chargeable 12kg
        $this->assertEqualsWithDelta(12.0, (float) $response->json('data.chargeable_weight'), 0.001);
        // 12 (base, first 1kg) + 11 * 6 = 78
        $this->assertSame(78.0, (float) $response->json('data.amount'));
    }

    public function test_order_creation_derives_fee_from_rate_card_when_not_supplied(): void
    {
        [$origin, $destination, $customer] = $this->makeRoute();

        RateCard::create([
            'name' => 'TW -> KH Air', 'transport_method' => 'air',
            'origin_country' => 'Taiwan', 'destination_country' => 'Cambodia',
            'first_kg' => 1, 'base_fee' => 10, 'rate_per_kg' => 5,
            'min_fee' => 10, 'currency' => 'USD', 'status' => true,
        ]);

        $order = $this->withToken($this->adminToken())->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'origin_warehouse_id' => $origin->id,
            'destination_warehouse_id' => $destination->id,
            'transport_method' => 'air',
            'packages' => [
                ['weight' => 3, 'length' => 20, 'width' => 20, 'height' => 20, 'quantity' => 1],
            ],
        ])->assertCreated()->json('data');

        // chargeable weight 3kg -> 10 + 2*5 = 20
        $this->assertSame(20.0, (float) $order['estimated_fee']);
    }

    public function test_settings_can_be_read_and_persisted(): void
    {
        $token = $this->adminToken();

        $before = $this->withToken($token)->getJson('/api/manageapi/systemset/get')
            ->assertOk()
            ->assertJsonPath('code', '1');
        $this->assertIsArray($before->json('data'));

        $this->withToken($token)->postJson('/api/manageapi/systemset/edit', [
            'data' => ['loginlogo' => '/storage/settings/login.png', 'rechargeAudit' => true],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->withToken($token)->getJson('/api/manageapi/systemset/get')
            ->assertOk()
            ->assertJsonPath('data.loginLogo', '/storage/settings/login.png')
            ->assertJsonPath('data.rechargeAudit', true);

        $this->withToken($token)->putJson('/api/v1/settings', [
            'settings' => ['support_phone' => '+85512345678'],
        ])->assertOk()->assertJsonPath('data.support_phone', '+85512345678');
    }

    public function test_role_crud_round_trip(): void
    {
        $token = $this->adminToken();

        $created = $this->withToken($token)->postJson('/api/manageapi/sysrole/add', [
            'data' => [
                'name' => 'Operations Supervisor',
                'code' => 'OPS',
                'description' => 'Oversees warehouse and dispatch',
                'sort' => 7,
                'isEnable' => true,
                'permissions' => ['order:add', 'order:edit'],
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $roleId = $created->json('data.id');
        $this->assertNotNull($roleId);

        $this->withToken($token)->postJson('/api/manageapi/sysrole/getlist', [
            'data' => ['page' => 1, 'pageSize' => 10],
        ])->assertOk()->assertJsonPath('code', '1');

        $fetched = $this->withToken($token)->getJson('/api/manageapi/sysrole/get?id='.$roleId)
            ->assertOk()
            ->assertJsonPath('data.name', 'Operations Supervisor')
            ->assertJsonPath('data.code', 'OPS');

        $this->assertContains('order:add', $fetched->json('data.permissions'));

        $this->withToken($token)->postJson('/api/manageapi/sysrole/edit', [
            'data' => ['id' => $roleId, 'description' => 'Updated', 'isEnable' => false],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->withToken($token)->getJson('/api/manageapi/sysrole/get?id='.$roleId)
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated')
            ->assertJsonPath('data.isEnable', false);

        $this->withToken($token)->postJson('/api/manageapi/sysrole/del', ['data' => ['id' => $roleId]])
            ->assertOk()->assertJsonPath('code', '1');

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_admin_role_cannot_be_deleted(): void
    {
        $adminRole = Role::where('slug', 'admin')->first() ?? Role::create([
            'name' => 'Admin', 'slug' => 'admin', 'description' => 'Full system access',
        ]);

        $this->withToken($this->adminToken())->postJson('/api/manageapi/sysrole/del', [
            'data' => ['id' => $adminRole->id],
        ])->assertOk()->assertJsonPath('code', '0');

        $this->assertDatabaseHas('roles', ['id' => $adminRole->id]);
    }

    public function test_menu_tree_and_crud(): void
    {
        $token = $this->adminToken();

        $tree = $this->withToken($token)->getJson('/api/manageapi/sysmenu/getmenus')
            ->assertOk()
            ->assertJsonPath('code', '1');

        $this->assertIsArray($tree->json('data'));

        $created = $this->withToken($token)->postJson('/api/manageapi/sysmenu/add', [
            'data' => [
                'parent' => [],
                'parentId' => 0,
                'menuType' => 0,
                'menuNameKey' => 'Customs Broker',
                'path' => '/brokers',
                'routeName' => 'brokers',
                'component' => 'views/brokers/index.vue',
                'menuIcon' => 'ele-User',
                'sortNumber' => 99,
            ],
        ])->assertOk()->assertJsonPath('code', '1');

        $menuId = $created->json('data.id');

        $this->withToken($token)->getJson('/api/manageapi/sysmenu/get?id='.$menuId)
            ->assertOk()
            ->assertJsonPath('data.menuNameKey', 'Customs Broker')
            ->assertJsonPath('data.path', '/brokers');

        $this->withToken($token)->postJson('/api/manageapi/sysmenu/edit', [
            'data' => ['id' => $menuId, 'menuNameKey' => 'Brokers', 'parentId' => 0, 'sortNumber' => 100],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->withToken($token)->postJson('/api/manageapi/sysmenu/del', ['data' => ['id' => $menuId]])
            ->assertOk()->assertJsonPath('code', '1');

        $this->assertDatabaseMissing('menus', ['id' => $menuId]);
    }

    public function test_activity_log_records_important_operations(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/manageapi/sysrole/add', [
            'data' => ['name' => 'Audited Role', 'sort' => 50],
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'role.create']);

        $logs = $this->withToken($token)->postJson('/api/manageapi/activitylog/getlist', [
            'data' => ['page' => 1, 'pageSize' => 20],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertGreaterThan(0, $logs->json('data.total'));
        $this->assertSame('Admin', $logs->json('data.data.0.userName'));

        $this->assertTrue(
            ActivityLog::where('action', 'sysrole.post')->exists()
        );
    }

    public function test_warehouse_structure_endpoints_expose_bins(): void
    {
        $token = $this->adminToken();
        $warehouse = Warehouse::create([
            'name' => 'Structure Hub', 'code' => 'STR-01', 'city' => 'PP',
            'country' => 'Cambodia', 'type' => 'both', 'status' => 'active',
        ]);

        $zone = $this->withToken($token)->postJson('/api/v1/warehouses/'.$warehouse->id.'/zones', [
            'name' => 'Zone A', 'code' => 'A',
        ])->assertCreated()->json('data');

        $rack = $this->withToken($token)->postJson('/api/v1/warehouse-zones/'.$zone['id'].'/racks', [
            'name' => 'Rack 1', 'code' => 'A-1',
        ])->assertCreated()->json('data');

        $level = $this->withToken($token)->postJson('/api/v1/warehouse-racks/'.$rack['id'].'/levels', [
            'name' => 'Level 1', 'code' => 'A-1-1',
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/warehouse-levels/'.$level['id'].'/bins', [
            'name' => 'Bin 1', 'code' => 'A-1-1-1',
        ])->assertCreated();

        $structure = $this->withToken($token)->getJson('/api/v1/warehouses/'.$warehouse->id.'/structure')
            ->assertOk();

        $this->assertSame('A-1-1-1', $structure->json('data.0.racks.0.levels.0.bins.0.code'));

        $bins = $this->withToken($token)->postJson('/api/manageapi/warehouse/bins', [
            'data' => ['warehouseId' => $warehouse->id],
        ])->assertOk()->assertJsonPath('code', '1');

        $this->assertSame('A-1-1-1', $bins->json('data.0.code'));
    }

    public function test_menu_seeder_populates_the_navigation_tree(): void
    {
        $this->seed(\Database\Seeders\V1CompletionSeeder::class);

        $this->assertGreaterThan(10, Menu::count());
        $this->assertGreaterThan(0, Menu::where('menu_type', Menu::TYPE_BUTTON)->count());
        $this->assertNotNull(Menu::where('path', '/rate-cards')->first());
        $this->assertSame(5, RateCard::count());
    }
}
