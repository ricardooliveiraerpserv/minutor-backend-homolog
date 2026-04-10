<?php

namespace App\Http\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

/**
 * Trait para cache de listagens com Redis Tags.
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
    /**
     * Executa a query e armazena o resultado em cache com Redis Tags.
     *
     * Tags geradas: ["{resource}", "user:{user_id}"]
     * TTL: 60 segundos (ajustável via $ttl)
     */
    protected function cachedList(Request $request, string $resource, callable $query, int $ttl = 60): mixed
    {
        // Se o driver não suporta tags, executa a query diretamente sem cache
        if (!($this->cacheStoreSupportsTagging())) {
            return $query();
        }

        $userId = Auth::id() ?? 'guest';
        $cacheKey = "{$resource}:list:{$userId}:" . md5($request->getQueryString() ?? '');

        try {
            return Cache::tags([$resource, "user:{$userId}"])
                ->remember($cacheKey, $ttl, $query);
        } catch (\Throwable $e) {
            // Fallback seguro: qualquer falha de cache não deve derrubar a request
            return $query();
        }
    }

    private function cacheStoreSupportsTagging(): bool
    {
        try {
            $store = Cache::getStore();
            return $store instanceof \Illuminate\Cache\TaggableStore;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Invalida todo o cache de listagem de um resource.
     * Chamado em store/update/destroy.
     */
    protected function invalidateListCache(string $resource): void
    {
        try {
            Cache::tags([$resource])->flush();
        } catch (\Exception $e) {
            // Se Redis não disponível, ignora silenciosamente
        }
    }
}
