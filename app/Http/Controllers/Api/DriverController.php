<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Driver;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Driver::query()->with('user');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('license_number', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        return $this->ok($query->orderBy('id', 'desc')->paginate($request->integer('per_page', 15)));
    }

    public function show(Driver $driver)
    {
        return $this->ok($driver->load('user', 'deliveries'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'license_number' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $driver = Driver::create($data);

        return $this->created($driver, 'Driver created');
    }

    public function update(Request $request, Driver $driver)
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['sometimes', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'license_number' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $driver->update($data);

        return $this->ok($driver, 'Driver updated');
    }

    public function destroy(Driver $driver)
    {
        $driver->delete();

        return $this->ok(null, 'Driver deleted');
    }
}