<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

/**
 * Trait para cache de listagens.
 *
 * Estratégia em cascata:
 *  1. Redis com tags (se Redis disponível) — invalida por resource/user
 *  2. File cache simples (sempre disponível) — invalida por TTL ou chave
 *
 * Uso nos controllers:
 *   use ListCacheable;
 *
 *   // No index():
 *   return $this->cachedList($request, 'timesheets', fn() => $resultado);
 *
 *   // No store/update/destroy():
 *   $this->invalidateListCache('timesheets');
 */
trait ListCacheable
{
    protected function cachedList(Request $request, string $resource, callable $query, int $ttl = 60): mixed
    {
        $userId   = Auth::id() ?? 'guest';
        $cacheKey = "{$resource}:list:{$userId}:" . md5($request->getQueryString() ?? '');

        // 1. Redis com tags (cache compartilhado e invalidável por resource)
        if ($this->redisAvailable()) {
            try {
                return Cache::tags([$resource, "user:{$userId}"])
                    ->remember($cacheKey, $ttl, $query);
            } catch (\Throwable $e) {
                // Redis falhou durante a operação — cai no file cache
            }
        }

        // 2. File cache simples — sempre disponível, invalida por TTL ou chave versionada
        try {
            $versionKey  = "version:{$resource}";
            $version     = Cache::store('file')->get($versionKey, 1);
            $fileKey     = "{$cacheKey}:v{$version}";

            return Cache::store('file')->remember($fileKey, $ttl, $query);
        } catch (\Throwable $e) {
            // File cache indisponível (ex: permissões) — executa direto
            return $query();
        }
    }

    protected function invalidateListCache(string $resource): void
    {
        // Invalida Redis tags
        try {
            if ($this->redisAvailable()) {
                Cache::tags([$resource])->flush();
            }
        } catch (\Throwable) {}

        // Invalida file cache incrementando a versão do resource
        try {
            $versionKey = "version:{$resource}";
            Cache::store('file')->increment($versionKey);
        } catch (\Throwable) {}
    }

    private function redisAvailable(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;

        try {
            $store = Cache::getStore();
            if (!($store instanceof \Illuminate\Cache\TaggableStore)) {
                return $checked = false;
            }
            \Illuminate\Support\Facades\Redis::connection()->ping();
            return $checked = true;
        } catch (\Throwable) {
            return $checked = false;
        }
    }
}
