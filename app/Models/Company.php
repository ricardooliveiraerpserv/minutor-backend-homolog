<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Empresa do grupo (multi-empresa interno). `type` internal|external prepara o
 * futuro SaaS (empresa externa isolada); por ora só internal.
 */
class Company extends Model
{
    protected $fillable = ['name', 'slug', 'color', 'cnpj', 'type', 'status', 'allow_source_requests'];

    protected $casts = ['allow_source_requests' => 'boolean'];

    /** A empresa pode solicitar/enviar código-fonte (assistente de fontes + GMUD de projeto)? */
    public function allowsSourceRequests(): bool
    {
        return (bool) $this->allow_source_requests;
    }

    /**
     * A EMPRESA ATIVA (contexto da requisição) pode solicitar/enviar código-fonte?
     * Sem empresa resolvida → não bloqueia (compat).
     */
    public static function activeAllowsSourceRequests(): bool
    {
        $cid = app(\App\Services\CompanyContext::class)->id();
        if (! $cid) {
            return true;
        }
        return (bool) static::whereKey($cid)->value('allow_source_requests');
    }

    /**
     * Gate do módulo de código-fonte (assistente + GMUD). É capacidade do AGENTE, não da
     * empresa ativa: pode usar quem ATENDE ao menos uma empresa com o módulo habilitado.
     * Assim um agente ERPSERV+BIZIFY usa o módulo mesmo logado em BIZIFY; um agente só-BIZIFY
     * (ou cliente) não. A visibilidade por chamado/empresa é decidida no FE.
     */
    public static function userCanUseSourceModule(): bool
    {
        $user = auth()->user();
        return $user ? $user->companies()->where('allow_source_requests', true)->exists() : false;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Slug estável a partir do nome (para novas empresas criadas via UI). */
    public static function makeSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name) ?: 'empresa';
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
