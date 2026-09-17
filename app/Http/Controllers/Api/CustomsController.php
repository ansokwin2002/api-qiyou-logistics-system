<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CustomsDeclaration;
use App\Models\Package;
use Illuminate\Http\Request;

class CustomsController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = CustomsDeclaration::query()->with([
            'order:id,order_no,customer_id',
            'order.customer:id,name',
            'package:id,package_no,barcode',
        ]);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('hs_code', 'like', "%{$search}%")
                    ->orWhere('english_item_name', 'like', "%{$search}%")
                    ->orWhere('receiver_id_number', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($o) use ($search) {
                        $o->where('order_no', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(CustomsDeclaration $customsDeclaration)
    {
        return $this->ok($customsDeclaration->load('order.customer', 'package'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'receiver_id_type' => ['required', 'string', 'in:national_id,passport,tax_id'],
            'receiver_id_number' => ['required', 'string'],
            'english_item_name' => ['required', 'string'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:50'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
        ]);

        $customs = CustomsDeclaration::create([
            'order_id' => $data['order_id'],
            'package_id' => $data['package_id'] ?? null,
            'receiver_id_type' => $data['receiver_id_type'],
            'receiver_id_number' => $data['receiver_id_number'],
            'english_item_name' => $data['english_item_name'],
            'hs_code' => $data['hs_code'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'material' => $data['material'] ?? null,
            'declared_value' => $data['declared_value'] ?? 0,
            'currency' => $data['currency'] ?? 'USD',
            'status' => CustomsDeclaration::STATUS_PENDING,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($customs->package_id) {
            $package = Package::find($customs->package_id);
            if ($package) {
                $package->setStatus(Package::STATUS_CUSTOMS, null, 'Customs declaration created');
            }
        }

        return $this->created($customs, 'Customs declaration created');
    }

    public function update(Request $request, CustomsDeclaration $customsDeclaration)
    {
        $data = $request->validate([
            'receiver_id_type' => ['sometimes', 'string', 'in:national_id,passport,tax_id'],
            'receiver_id_number' => ['sometimes', 'string'],
            'english_item_name' => ['sometimes', 'string'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            'purpose' => ['nullable', 'string', 'max:50'],
            'material' => ['nullable', 'string', 'max:50'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string'],
        ]);

        $customsDeclaration->update($data);

        return $this->ok($customsDeclaration, 'Customs declaration updated');
    }

    public function markDeclared(CustomsDeclaration $customsDeclaration)
    {
        $customsDeclaration->update([
            'status' => CustomsDeclaration::STATUS_DECLARED,
            'declared_at' => now(),
        ]);

        if ($customsDeclaration->package_id) {
            $package = Package::find($customsDeclaration->package_id);
            if ($package) {
                $package->setStatus(Package::STATUS_CUSTOMS, null, 'Customs declared');
            }
        }

        return $this->ok($customsDeclaration->fresh(), 'Customs declared');
    }

    public function markCleared(CustomsDeclaration $customsDeclaration)
    {
        $customsDeclaration->update([
            'status' => CustomsDeclaration::STATUS_CLEARED,
            'cleared_at' => now(),
        ]);

        if ($customsDeclaration->package_id) {
            $package = Package::find($customsDeclaration->package_id);
            if ($package) {
                $package->setStatus(Package::STATUS_CLEARED, null, 'Customs cleared');
            }
        }

        return $this->ok($customsDeclaration->fresh(), 'Customs cleared');
    }

    public function destroy(CustomsDeclaration $customsDeclaration)
    {
        $customsDeclaration->delete();

        return $this->ok(null, 'Customs declaration deleted');
    }
}