<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomsDeclaration extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_DECLARED = 'declared';
    const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'order_id',
        'package_id',
        'receiver_id_type',
        'receiver_id_number',
        'english_item_name',
        'hs_code',
        'purpose',
        'material',
        'declared_value',
        'currency',
        'status',
        'declared_at',
        'cleared_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'declared_value' => 'decimal:2',
            'declared_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return str_replace('_', ' ', ucfirst($this->status));
    }
}