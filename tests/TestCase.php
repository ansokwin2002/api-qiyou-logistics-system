<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?string $cachedAdminToken = null;

    /**
     * Auth guards are cached on the container, so the user resolved for one
     * request would otherwise leak into the next one within the same test.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if ($this->app) {
            $this->app->make('auth')->forgetGuards();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * A staff token for the authenticated manageapi/* routes.
     */
    protected function adminToken(): string
    {
        if ($this->cachedAdminToken) {
            return $this->cachedAdminToken;
        }

        $role = Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => 'Full system access']
        );

        $user = User::firstOrCreate(
            ['email' => 'admin@testing.local'],
            ['name' => 'Admin', 'password' => 'password', 'status' => 'active']
        );
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->cachedAdminToken = $user->createToken('feature-test')->plainTextToken;

        return $this->cachedAdminToken;
    }
}
