<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'method',
        'path',
        'ip_address',
        'payload',
        'status_code',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an important operation. Never throws — logging must not break the request.
     */
    public static function record(string $action, ?string $description = null, array $context = []): void
    {
        try {
            $user = auth()->user();

            static::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'action' => $action,
                'description' => $description,
                'subject_type' => $context['subject_type'] ?? null,
                'subject_id' => $context['subject_id'] ?? null,
                'method' => $context['method'] ?? null,
                'path' => $context['path'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'payload' => isset($context['payload']) ? json_encode($context['payload'], JSON_UNESCAPED_UNICODE) : null,
                'status_code' => $context['status_code'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Activity log failed', ['error' => $e->getMessage()]);
        }
    }
}
