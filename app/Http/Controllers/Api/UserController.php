<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = User::query()->with('roles');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->whereHas('roles', function ($r) use ($role) {
                $r->where('name', $role);
            });
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(User $user)
    {
        return $this->ok($user->load('roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'customer_id' => ['nullable', 'exists:customers,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'status' => 'active',
            'customer_id' => $data['customer_id'] ?? null,
        ]);

        $user->roles()->attach(Role::where('name', $data['role'])->firstOrFail()->id);

        return $this->created($user->load('roles'), 'User created');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
            'customer_id' => ['nullable', 'exists:customers,id'],
        ]);

        $user->update([
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
            'password' => ! empty($data['password']) ? Hash::make($data['password']) : $user->password,
            'status' => $data['status'] ?? $user->status,
            'customer_id' => array_key_exists('customer_id', $data) ? $data['customer_id'] : $user->customer_id,
        ]);

        if (! empty($data['role'])) {
            $user->roles()->sync([Role::where('name', $data['role'])->firstOrFail()->id]);
        }

        return $this->ok($user->fresh()->load('roles'), 'User updated');
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return $this->error('You cannot delete your own account', 422);
        }

        $user->delete();

        return $this->ok(null, 'User deleted');
    }
}