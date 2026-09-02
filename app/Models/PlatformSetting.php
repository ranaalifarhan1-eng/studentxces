<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value', 'group'];

    /**
     * Request-scoped memoization cache.
     *
     * @var array<string, mixed>
     */
    protected static array $memoized = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, static::$memoized)) {
            return static::$memoized[$key] ?? $default;
        }

        $value = static::where('key', $key)->value('value');
        static::$memoized[$key] = $value;

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        static::$memoized[$key] = $value;
    }

    public static function flushCache(): void
    {
        static::$memoized = [];
    }

    public static function allFor(string $group = null): array
    {
        $query = static::query();
        if ($group) {
            $query->where('group', $group);
        }
        return $query->pluck('value', 'key')->toArray();
    }
}
