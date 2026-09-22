<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
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

        Warehouse::firstOrCreate(
            ['code' => 'TW-O01'],
            ['name' => 'Taiwan Origin Hub', 'city' => 'Taipei', 'country' => 'Taiwan', 'type' => 'origin', 'status' => 'active']
        );
        Warehouse::firstOrCreate(
            ['code' => 'KH-D01'],
            ['name' => 'Phnom Penh Destination Hub', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'type' => 'destination', 'status' => 'active']
        );
    }
}