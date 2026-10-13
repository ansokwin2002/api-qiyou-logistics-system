<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\RateCard;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class V1CompletionSeeder extends Seeder
{
    /**
     * Route-shaped menu tree mirrored from the frontend `dynamicRoutes`.
     * Node = [label, path, routeName, icon, authorityPrefix, children]
     */
    private const TREE = [
        ['Dashboard', '/home', 'home', 'ele-HomeFilled', null, []],
        ['Customers', '/customers', 'customers', 'ele-User', 'customer', []],
        ['Operations', '/operations', 'operations', 'ele-Operation', null, [
            ['Orders', '/orders', 'orders', 'ele-Document', 'order', []],
            ['Packages', '/packages', 'packages', 'ele-Box', 'package', []],
            ['Warehouse', '/warehouse', 'warehouse', 'ele-OfficeBuilding', 'warehouse', []],
            ['Package Scan', '/scan', 'packageScan', 'ele-Search', 'package', []],
            ['Shipments', '/shipments', 'shipments', 'ele-Ship', 'shipment', []],
            ['Customs', '/customs', 'customs', 'ele-DocumentChecked', 'customs', []],
        ]],
        ['Delivery', '/dispatch', 'dispatch', 'ele-Van', null, [
            ['Deliveries', '/deliveries', 'deliveries', 'ele-Van', 'delivery', []],
            ['Tracking', '/tracking', 'tracking', 'ele-Position', 'tracking', []],
            ['Delivery Tracking', '/delivery-tracking', 'deliveryTracking', 'ele-Location', 'delivery', []],
            ['Live Delivery', '/live-delivery', 'liveDelivery', 'ele-MapLocation', 'delivery', []],
            ['Self Pickups', '/pickups', 'pickups', 'ele-TakeawayBox', 'pickup', []],
        ]],
        ['Finance', '/finance', 'financeGroup', 'ele-Money', null, [
            ['COD', '/cod', 'cod', 'ele-Money', 'cod', []],
            ['Costs', '/costs', 'costs', 'ele-Coin', 'cost', []],
            ['Statements', '/statements', 'statements', 'ele-Tickets', 'statement', []],
            ['Invoices', '/invoices', 'invoices', 'ele-Ticket', 'invoice', []],
            ['Rate Cards', '/rate-cards', 'rateCards', 'ele-PriceTag', 'ratecard', []],
            ['Reports', '/reports', 'reports', 'ele-DataAnalysis', 'report', []],
        ]],
        ['System', '/system', 'system', 'ele-Setting', null, [
            ['Users', '/system/users', 'systemUsers', 'ele-UserFilled', 'system:user', []],
            ['Roles', '/system/roles', 'systemRoles', 'ele-Lock', 'system:role', []],
            ['Menus', '/system/menus', 'systemMenus', 'ele-Menu', 'system:menu', []],
            ['Settings', '/system/settings', 'systemSettings', 'ele-Setting', 'system:systemset', []],
            ['Activity Logs', '/system/activity-logs', 'systemActivityLogs', 'ele-Memo', 'system:log', []],
        ]],
    ];

    private const ACTIONS = ['add' => 'Add', 'edit' => 'Edit', 'del' => 'Delete'];

    public function run(): void
    {
        $this->seedRoles();
        $this->seedMenus();
        $this->seedSettings();
        $this->seedRateCards();
    }

    private function seedRoles(): void
    {
        $defaults = [
            'admin' => ['code' => 'ADMIN', 'sort' => 1],
            'customer' => ['code' => 'CUST', 'sort' => 2],
            'warehouse' => ['code' => 'WH', 'sort' => 3],
            'driver' => ['code' => 'DRV', 'sort' => 4],
            'manager' => ['code' => 'MGR', 'sort' => 5],
            'finance' => ['code' => 'FIN', 'sort' => 6],
        ];

        foreach ($defaults as $slug => $extra) {
            Role::where('slug', $slug)->update($extra + ['is_enable' => true]);
        }
    }

    private function seedMenus(): void
    {
        foreach (self::TREE as $index => $node) {
            $parent = $this->upsertMenu($node, 0, $index * 10);

            foreach ($node[5] as $childIndex => $child) {
                $childRow = $this->upsertMenu($child, $parent->id, $childIndex * 10);

                $prefix = $child[4];
                if ($prefix && ! str_contains($prefix, ':')) {
                    foreach (self::ACTIONS as $action => $label) {
                        $this->upsertButton($prefix.' '.$label, $prefix.':'.$action, $childRow->id);
                    }
                }
            }
        }
    }

    private function upsertMenu(array $node, int $parentId, int $sort): Menu
    {
        [$label, $path, $routeName, $icon] = $node;

        return Menu::updateOrCreate(
            ['parent_id' => $parentId, 'name_key' => $label],
            [
                'menu_type' => Menu::TYPE_MENU,
                'authority' => null,
                'route_name' => $routeName,
                'path' => $path,
                'component' => $this->componentFor($path),
                'icon' => $icon,
                'sort' => $sort,
                'hide' => false,
                'is_keep_alive' => true,
                'is_affix' => false,
            ]
        );
    }

    private function upsertButton(string $label, string $authority, int $parentId): Menu
    {
        return Menu::updateOrCreate(
            ['parent_id' => $parentId, 'name_key' => $label],
            [
                'menu_type' => Menu::TYPE_BUTTON,
                'authority' => $authority,
                'route_name' => null,
                'path' => null,
                'component' => null,
                'icon' => null,
                'sort' => 0,
                'hide' => false,
                'is_keep_alive' => false,
                'is_affix' => false,
            ]
        );
    }

    private function componentFor(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $map = [
            '/operations' => null,
            '/dispatch' => null,
            '/finance' => null,
            '/system' => null,
            '/home' => 'views/home/index.vue',
            '/customers' => 'views/customer/index.vue',
            '/orders' => 'views/order/index.vue',
            '/packages' => 'views/package/index.vue',
            '/warehouse' => 'views/warehouse/index.vue',
            '/shipments' => 'views/shipment/index.vue',
            '/customs' => 'views/customs/index.vue',
            '/deliveries' => 'views/delivery/index.vue',
            '/tracking' => 'views/tracking/index.vue',
            '/delivery-tracking' => 'views/delivery/tracking.vue',
            '/live-delivery' => 'views/live-tracking/index.vue',
            '/pickups' => 'views/pickup/index.vue',
            '/cod' => 'views/cod/index.vue',
            '/costs' => 'views/cost/index.vue',
            '/statements' => 'views/statement/index.vue',
            '/reports' => 'views/report/index.vue',
            '/rate-cards' => 'views/ratecard/index.vue',
            '/invoices' => 'views/invoice/index.vue',
            '/scan' => 'views/package/scan.vue',
            '/system/users' => 'views/system/user/index.vue',
            '/system/roles' => 'views/system/role/index.vue',
            '/system/menus' => 'views/system/menu/index.vue',
            '/system/settings' => 'views/system/systemset/index.vue',
            '/system/activity-logs' => 'views/system/activitylog/index.vue',
        ];

        return $map[$path] ?? 'layout/routerView/parent.vue';
    }

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'system_name', 'value' => 'Qiyou Logistics', 'group' => 'general', 'label' => 'System name'],
            ['key' => 'support_email', 'value' => 'support@qiyou-kh.com', 'group' => 'general', 'label' => 'Support email'],
            ['key' => 'support_phone', 'value' => '', 'group' => 'general', 'label' => 'Support phone'],
            ['key' => 'default_currency', 'value' => 'USD', 'group' => 'finance', 'label' => 'Default currency'],
            ['key' => 'volumetric_factor', 'value' => '5000', 'group' => 'logistics', 'label' => 'Volumetric divisor', 'type' => 'number'],
            ['key' => 'login_logo', 'value' => '', 'group' => 'branding', 'label' => 'Login logo'],
            ['key' => 'system_logo', 'value' => '', 'group' => 'branding', 'label' => 'System logo'],
            ['key' => 'recharge_audit', 'value' => '0', 'group' => 'general', 'label' => 'Recharge audit', 'type' => 'boolean'],
        ];

        foreach ($settings as $setting) {
            $setting += ['type' => 'string'];
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }

    private function seedRateCards(): void
    {
        $cards = [
            ['name' => 'Taiwan -> Cambodia (Air)', 'transport_method' => 'air', 'origin_country' => 'Taiwan', 'destination_country' => 'Cambodia', 'first_kg' => 1, 'base_fee' => 12, 'rate_per_kg' => 6.5, 'min_fee' => 12],
            ['name' => 'Taiwan -> Cambodia (Sea)', 'transport_method' => 'sea', 'origin_country' => 'Taiwan', 'destination_country' => 'Cambodia', 'first_kg' => 1, 'base_fee' => 8, 'rate_per_kg' => 3.2, 'min_fee' => 8],
            ['name' => 'Taiwan -> Cambodia (Land)', 'transport_method' => 'land', 'origin_country' => 'Taiwan', 'destination_country' => 'Cambodia', 'first_kg' => 1, 'base_fee' => 9, 'rate_per_kg' => 4, 'min_fee' => 9],
            ['name' => 'Malaysia -> Cambodia (Air)', 'transport_method' => 'air', 'origin_country' => 'Malaysia', 'destination_country' => 'Cambodia', 'first_kg' => 1, 'base_fee' => 10, 'rate_per_kg' => 5.5, 'min_fee' => 10],
            ['name' => 'Domestic Cambodia (Land)', 'transport_method' => 'land', 'origin_country' => 'Cambodia', 'destination_country' => 'Cambodia', 'first_kg' => 1, 'base_fee' => 3, 'rate_per_kg' => 1.5, 'min_fee' => 3],
        ];

        foreach ($cards as $card) {
            RateCard::updateOrCreate(
                ['name' => $card['name']],
                $card + ['currency' => 'USD', 'status' => true, 'per_unit_fee' => 0]
            );
        }
    }
}
