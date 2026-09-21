<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{

    public function index(Request $request)
    {
        $query = User::query()->with('roles');

        // Accept both 'search' and 'keyword' for search
        $search = $request->input('search') ?? $request->input('keyword');
        if ($search) {
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

        // Accept both 'per_page' and 'pageSize'
        $perPage = $request->input('per_page') ?? $request->input('pageSize') ?? 15;
        $page = $request->input('page', 1);
        
        $paginator = $query->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        
        return response()->json([
            'code' => '1',
            'data' => [
                'total' => $paginator->total(),
                'data' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ]
        ]);
    }

    public function show(Request $request)
    {
        $user = User::findOrFail($request->input('id'));
        return response()->json([
            'code' => '1',
            'data' => $user->load('roles')
        ]);
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

        return response()->json([
            'code' => '1',
            'message' => 'User created',
            'data' => $user->load('roles')
        ]);
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

        return response()->json([
            'code' => '1',
            'message' => 'User updated',
            'data' => $user->fresh()->load('roles')
        ]);
    }

    public function destroy(Request $request)
    {
        $user = User::findOrFail($request->input('id'));
        
        // Prevent deleting demo users
        $demoEmails = [
            'admin@qiyou.logistics',
            'manager@qiyou.logistics',
            'warehouse@qiyou.logistics',
            'driver@qiyou.logistics',
            'finance@qiyou.logistics',
            'demo@qiyou.logistics',
        ];
        
        if (in_array($user->email, $demoEmails)) {
            return response()->json([
                'code' => '0',
                'message' => 'Cannot delete demo user: ' . $user->email
            ], 422);
        }
        
        if ($user->id === auth()->id()) {
            return response()->json([
                'code' => '0',
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'code' => '1',
            'message' => 'User deleted'
        ]);
    }

    public function editInfo(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::findOrFail($request->input('id'));
        $user->update($data);

        return response()->json([
            'code' => '1',
            'message' => 'User info updated',
            'data' => $user->fresh()->load('roles')
        ]);
    }

    public function editPass(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user = User::findOrFail($request->input('id'));
        $user->password = Hash::make($data['password']);
        $user->save();

        return response()->json([
            'code' => '1',
            'message' => 'Password updated'
        ]);
    }

    public function resetPass(Request $request)
    {
        $data = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::findOrFail($request->input('id'));
        $user->password = Hash::make('123456'); // default password
        $user->save();

        return response()->json([
            'code' => '1',
            'message' => 'Password reset to 123456'
        ]);
    }

    public function batchDelete(Request $request)
    {
        $ids = $request->input('data', []);
        
        // Also check if data is sent directly as array
        if (empty($ids) && $request->isJson()) {
            $jsonData = $request->json()->all();
            if (isset($jsonData['data'])) {
                $ids = $jsonData['data'];
            } elseif (is_array($jsonData)) {
                $ids = $jsonData;
            }
        }

        if (empty($ids)) {
            return response()->json([
                'code' => '0',
                'message' => 'No users selected'
            ], 422);
        }

        // Prevent deleting demo users
        $demoEmails = [
            'admin@qiyou.logistics',
            'manager@qiyou.logistics',
            'warehouse@qiyou.logistics',
            'driver@qiyou.logistics',
            'finance@qiyou.logistics',
            'demo@qiyou.logistics',
        ];

        $demoUsers = User::whereIn('id', $ids)->whereIn('email', $demoEmails)->get();
        if ($demoUsers->isNotEmpty()) {
            $emails = $demoUsers->pluck('email')->implode(', ');
            return response()->json([
                'code' => '0',
                'message' => 'Cannot delete demo users: ' . $emails
            ], 422);
        }

        // Prevent deleting self
        $authId = auth()->id();
        if (in_array($authId, $ids)) {
            return response()->json([
                'code' => '0',
                'message' => 'You cannot delete your own account'
            ], 422);
        }

        User::whereIn('id', $ids)->delete();

        return response()->json([
            'code' => '1',
            'message' => 'Users deleted'
        ]);
    }
}