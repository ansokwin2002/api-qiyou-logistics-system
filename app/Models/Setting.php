<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    const CACHE_KEY = 'app.settings';

    protected $fillable = ['key', 'value', 'group', 'type', 'label'];

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * All settings as a flat key => typed value map.
     */
    public static function allMap(): array
    {
        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->get(['key', 'value', 'type'])->toArray());

        $map = [];
        foreach ($rows as $row) {
            $map[$row['key']] = static::cast($row['value'], $row['type'] ?? 'string');
        }

        return $map;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allMap()[$key] ?? $default;
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }
            static::updateOrCreate(
                ['key' => $key],
                ['value' => is_array($value) ? json_encode($value) : (string) $value, 'group' => $group]
            );
        }
    }

    protected static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($value) ? $value + 0 : 0,
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
