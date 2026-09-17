<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\CodCollection;
use Illuminate\Http\Request;

class CodController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = CodCollection::query()->with([
            'order:id,order_no,payment_method',
            'package:id,package_no',
            'collectedBy:id,name',
        ]);

        if ($status = $request->input('status')) {
            $query->where('settlement_status', $status);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('order', function ($o) use ($search) {
                    $o->where('order_no', 'like', "%{$search}%");
                });
            });
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(CodCollection $cod)
    {
        return $this->ok($cod->load('order', 'package', 'collectedBy'));
    }

    /**
     * Mark a COD collection as settled (admin action).
     */
    public function settle(CodCollection $cod)
    {
        $cod->update([
            'settlement_status' => CodCollection::STATUS_SETTLED,
        ]);

        return $this->ok($cod->fresh(), 'COD marked as settled');
    }
}