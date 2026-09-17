<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function index(Request $request)
    {
        $query = Invoice::query()->with(['customer:id,name']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        if ($search = $request->input('search')) {
            $query->where('invoice_no', 'like', "%{$search}%")
                ->orWhereHas('customer', function ($c) use ($search) {
                    $c->where('name', 'like', "%{$search}%");
                });
        }

        return $this->ok($query->orderByDesc('id')->paginate($request->integer('per_page', 15)));
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['customer']);

        return $this->ok($invoice);
    }

    /**
     * Generate an invoice from a customer's completed orders within a period.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $query = Order::query()->where('customer_id', $data['customer_id'])
            ->where('status', Order::STATUS_COMPLETED);

        if (! empty($data['period_start'])) {
            $query->where('created_at', '>=', $data['period_start'] . ' 00:00:00');
        }
        if (! empty($data['period_end'])) {
            $query->where('created_at', '<=', $data['period_end'] . ' 23:59:59');
        }

        $totalFee = (float) $query->sum('estimated_fee');
        $codTotal = (float) $query->where('payment_method', 'cod')->sum('estimated_fee');

        $invoice = Invoice::create([
            'invoice_no' => Invoice::generateInvoiceNo(),
            'customer_id' => $data['customer_id'],
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'total_fee' => $totalFee,
            'cod_total' => $codTotal,
            'currency' => 'USD',
            'status' => Invoice::STATUS_DRAFT,
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->created($invoice->load('customer'), 'Invoice generated');
    }

    public function issue(Invoice $invoice)
    {
        $invoice->update([
            'status' => Invoice::STATUS_ISSUED,
            'issued_at' => now(),
        ]);

        return $this->ok($invoice->fresh(), 'Invoice issued');
    }

    public function markPaid(Invoice $invoice)
    {
        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
        ]);

        return $this->ok($invoice->fresh(), 'Invoice marked as paid');
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();

        return $this->ok(null, 'Invoice deleted');
    }
}