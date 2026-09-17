<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Customer::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('id_number', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return $this->ok($query->orderBy('name')->get());
        }

        $customers = $query
            ->orderBy('id', 'desc')
            ->paginate($request->integer('per_page', 15));

        return $this->ok($customers);
    }

    public function show(Customer $customer)
    {
        return $this->ok($customer->load('orders'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'id_type' => ['nullable', 'string', 'in:national_id,passport,driver_license'],
            'id_number' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::create($data);

        return $this->created($customer, 'Customer created');
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:191'],
            'country' => ['nullable', 'string', 'max:191'],
            'id_type' => ['nullable', 'string', 'in:national_id,passport,driver_license'],
            'id_number' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer->update($data);

        return $this->ok($customer, 'Customer updated');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return $this->ok(null, 'Customer deleted');
    }
}