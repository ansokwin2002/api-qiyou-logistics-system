<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Daily sequence numbers: PREFIX-YYYY-MM-DD-001
 * Same logic as tracking numbers — sequence resets each day.
 */
class NumberGenerator
{
    public static function next(string $modelClass, string $column, string $prefix, int $pad = 3): string
    {
        $datePart = date('Y-m-d');
        $fullPrefix = "{$prefix}-{$datePart}-";

        $max = 0;
        /** @var Model $model */
        $model = new $modelClass;
        $model::where($column, 'like', $fullPrefix . '%')
            ->pluck($column)
            ->each(function ($val) use (&$max) {
                $parts = explode('-', (string) $val);
                $seq = (int) end($parts);
                if ($seq > $max) {
                    $max = $seq;
                }
            });

        $num = $max + 1;
        $maxSeq = (10 ** $pad) - 1;
        if ($num > $maxSeq) {
            $num = 1;
        }

        return $fullPrefix . str_pad((string) $num, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Display-only number from a record id + created_at date.
     * Shape matches daily format: PREFIX-YYYY-MM-DD-{id}.
     */
    public static function displayNo(string $prefix, ?Model $record, int $pad = 3): string
    {
        $datePart = $record?->created_at
            ? $record->created_at->format('Y-m-d')
            : date('Y-m-d');
        $seq = str_pad((string) ($record?->id ?? 0), $pad, '0', STR_PAD_LEFT);

        return "{$prefix}-{$datePart}-{$seq}";
    }
}
