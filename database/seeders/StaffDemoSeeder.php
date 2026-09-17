<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates demo staff accounts (manager / warehouse / driver) so the staff
 * dashboard can be tested with role-based access. Idempotent.
 */
class StaffDemoSeeder extends Seeder
{
    public function run(): void
    {
        $managerRole = Role::firstOrCreate(['slug' => 'manager'], ['name' => 'Manager', 'description' => 'Operations manager']);
        $warehouseRole = Role::where('slug', 'warehouse')->firstOrFail();
        $driverRole = Role::where('slug', 'driver')->firstOrFail();
        $financeRole = Role::firstOrCreate(['slug' => 'finance'], ['name' => 'Finance', 'description' => 'Finance and accounting']);
        $staff = [
            [
                'email' => 'manager@qiyou.logistics',
                'name' => 'Mey Dara',
                'phone' => '855 12 999 001',
                'role' => $managerRole,
            ],
            [
                'email' => 'warehouse@qiyou.logistics',
                'name' => 'Kim Sokha',
                'phone' => '855 12 999 002',
                'role' => $warehouseRole,
            ],
            [
                'email' => 'driver@qiyou.logistics',
                'name' => 'Chan Rithy',
                'phone' => '855 12 999 003',
                'role' => $driverRole,
            ],
            [
                'email' => 'finance@qiyou.logistics',
                'name' => 'Sreymom Oun',
                'phone' => '855 12 999 004',
                'role' => $financeRole,
            ],
        ];

        foreach ($staff as $item) {
            $user = User::updateOrCreate(
                ['email' => $item['email']],
                [
                    'name' => $item['name'],
                    'phone' => $item['phone'],
                    'password' => Hash::make('password'),
                    'status' => 'active',
                    'customer_id' => null,
                ]
            );
            $user->roles()->syncWithoutDetaching([$item['role']->id]);
        }
    }
}