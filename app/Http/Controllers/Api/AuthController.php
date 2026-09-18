<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('roles')->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->unauthorized('Invalid credentials');
        }

        if ($user->status !== 'active') {
            return $this->error('Account is inactive', 403);
        }

        $token = $user->createToken('logistics-api')->plainTextToken;

        return $this->ok([
            'token' => $token,
            'user' => $user,
            'roles' => $user->role_names,
        ], 'Login successful');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        try {
            [$customer, $user] = DB::transaction(function () use ($validated) {
                $customer = Customer::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'address' => $validated['address'] ?? null,
                    'city' => $validated['city'] ?? null,
                    'country' => $validated['country'] ?? 'Cambodia',
                    'status' => 'active',
                ]);

                $role = Role::where('name', 'customer')->firstOrFail();

                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => Hash::make($validated['password']),
                    'status' => 'active',
                    'customer_id' => $customer->id,
                ]);
                $user->roles()->syncWithoutDetaching([$role->id]);

                return [$customer, $user];
            });

            $user->load('roles');
            $token = $user->createToken('logistics-api')->plainTextToken;

            return $this->created([
                'token' => $token,
                'user' => $user,
                'roles' => $user->role_names,
            ], 'Registration successful');
        } catch (\Throwable $e) {
            Log::error('Registration failed', ['error' => $e->getMessage()]);

            return $this->error('Registration failed: '.$e->getMessage(), 500);
        }
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles');

        return $this->ok([
            'user' => $user,
            'roles' => $user->role_names,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logged out');
    }
}