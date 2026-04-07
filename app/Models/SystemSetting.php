<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache key prefix
     */
    const CACHE_PREFIX = 'system_setting_';

    /**
     * Cache duration in seconds (1 hour)
     */
    const CACHE_DURATION = 3600;

    /**
     * Buscar uma configuração por chave
     */
    public static function get(string $key, $default = null)
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        return Cache::remember($cacheKey, self::CACHE_DURATION, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Definir uma configuração
     */
    public static function set(string $key, $value, string $type = 'string', string $group = 'general', ?string $description = null): self
    {
        // Converter valor para string para armazenamento
        $valueString = self::valueToString($value, $type);

        $setting = self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $valueString,
                'type' => $type,
                'group' => $group,
                'description' => $description,
            ]
        );

        // Limpar cache
        Cache::forget(self::CACHE_PREFIX . $key);

        return $setting;
    }

    /**
     * Limpar todo o cache de configurações
     */
    public static function clearCache(): void
    {
        $settings = self::all();
        foreach ($settings as $setting) {
            Cache::forget(self::CACHE_PREFIX . $setting->key);
        }
    }

    /**
     * Converter valor para string para armazenamento
     */
    protected static function valueToString($value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => json_encode($value),
            'integer' => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Converter valor do tipo correto
     */
    protected static function castValue($value, string $type)
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Scope para filtrar por grupo
     */
    public function scopeByGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Buscar todas as configurações de um grupo
     */
    public static function getGroup(string $group): array
    {
        $settings = self::where('group', $group)->get();

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = self::castValue($setting->value, $setting->type);
        }

        return $result;
    }

    /**
     * Atualizar múltiplas configurações de uma vez
     */
    public static function setMultiple(array $settings): void
    {
        foreach ($settings as $key => $data) {
            if (is_array($data)) {
                self::set(
                    $key,
                    $data['value'] ?? null,
                    $data['type'] ?? 'string',
                    $data['group'] ?? 'general',
                    $data['description'] ?? null
                );
            } else {
                self::set($key, $data);
            }
        }
    }
}

