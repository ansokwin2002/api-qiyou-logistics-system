<?php

namespace App\Models;

use App\Support\NumberGenerator;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    const STATUS_DRAFT = 'draft';
    const STATUS_ISSUED = 'issued';
    const STATUS_PAID = 'paid';

    protected $fillable = [
        'invoice_no',
        'customer_id',
        'order_id',
        'period_start',
        'period_end',
        'total_fee',
        'cod_total',
        'currency',
        'status',
        'issued_at',
        'paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_fee' => 'decimal:2',
            'cod_total' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public static function generateInvoiceNo(): string
    {
        // INV-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'invoice_no', 'INV');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}