<?php

namespace App\Models;

use App\Support\NumberGenerator;
use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_no',
        'customer_id',
        'subject',
        'message',
        'attachment',
        'status',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public static function generateTicketNo(): string
    {
        // SUP-2026-09-24-001 — daily sequence, resets each day
        return NumberGenerator::next(self::class, 'ticket_no', 'SUP');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}