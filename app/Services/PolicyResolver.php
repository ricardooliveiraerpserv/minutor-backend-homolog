<?php

namespace App\Services;

use App\Models\PolicyAssignment;
use App\Models\PolicyOverride;
use App\Models\PolicyRole;
use App\Models\User;
use App\Support\PolicyCatalog;

/**
 * PONTO ÚNICO DE VERDADE do efetivo de políticas. Módulo-agnóstico.
 *   efetivo = defaults(perfil) ⊕ exceções(usuário) ; admin = bypass (máximo).
 * Memoizado por request.
 */
class PolicyResolver
{
    /** cache: "userId|module" => array<key,value> */
    private array $cache = [];

    /** Mapa efetivo de capacidades do usuário no módulo. */
    public function effective(?User $user, string $module): array
    {
        $caps = PolicyCatalog::keys($module);
        if (!$user) {
            return $this->fill($caps, fn ($c) => PolicyCatalog::fallback($c));
        }
        $ck = $user->id . '|' . $module;
        if (array_key_exists($ck, $this->cache)) return $this->cache[$ck];

        // Admin: acesso máximo em tudo (bypass).
        if ($user->isAdmin()) {
            return $this->cache[$ck] = $this->fill($caps, fn ($c) => PolicyCatalog::maxValue($c));
        }

        // Base IRRESTRITA quando não há perfil atribuído (INERTE até configurar). Overrides
        // aplicam por cima em qualquer caso. Só um perfil (role) torna as chaves não-definidas
        // restritivas (fallback) — é o comportamento esperado de um perfil "Personalizado" vazio.
        $assignment = PolicyAssignment::where('user_id', $user->id)->where('module', $module)->whereNotNull('role_id')->first();
        $hasRole = (bool) $assignment;
        $defaults = $hasRole ? (PolicyRole::whereKey($assignment->role_id)->value('defaults') ?? []) : [];

        $overrides = PolicyOverride::where('user_id', $user->id)->where('module', $module)->get()
            ->keyBy('key')->map(fn ($o) => $o->value['v'] ?? null);

        $out = [];
        foreach ($caps as $key => $cap) {
            if ($overrides->has($key)) $out[$key] = $overrides[$key];
            elseif ($hasRole) $out[$key] = array_key_exists($key, $defaults) ? $defaults[$key] : PolicyCatalog::fallback($cap);
            else $out[$key] = PolicyCatalog::maxValue($cap); // sem perfil → irrestrito
        }
        return $this->cache[$ck] = $out;
    }

    /** Uma capacidade específica do usuário. */
    public function value(?User $user, string $module, string $key, $default = null)
    {
        $eff = $this->effective($user, $module);
        return $eff[$key] ?? $default;
    }

    /** Toggle: true/false. */
    public function can(?User $user, string $module, string $key): bool
    {
        return (bool) $this->value($user, $module, $key, false);
    }

    /** Escopo: none|own|assigned|team|all. */
    public function scope(?User $user, string $module, string $key, string $default = 'none'): string
    {
        $v = $this->value($user, $module, $key, $default);
        return is_string($v) ? $v : $default;
    }

    private function fill(array $caps, callable $fn): array
    {
        $out = [];
        foreach ($caps as $key => $cap) $out[$key] = $fn($cap);
        return $out;
    }
}
