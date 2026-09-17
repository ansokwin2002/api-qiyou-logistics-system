<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Admin', 'slug' => 'admin', 'description' => 'Full system access'],
            ['name' => 'Customer', 'slug' => 'customer', 'description' => 'Creates orders and tracks packages'],
            ['name' => 'Warehouse Staff', 'slug' => 'warehouse', 'description' => 'Receives, stores and ships packages'],
            ['name' => 'Driver', 'slug' => 'driver', 'description' => 'Delivers packages and collects COD'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@qiyou.logistics'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'status' => 'active',
            ]
        );
        $admin->roles()->sync([Role::where('slug', 'admin')->first()->id]);

        $this->call([
            StaffDemoSeeder::class,
            CustomerDemoSeeder::class,
        ]);
    }
}