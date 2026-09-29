<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fonte Git autorizada de um cliente (Fase 0 — Solicitação de código-fonte).
 * "Remover" = desativar (active=false); SoftDeletes só p/ limpeza de config nunca usada.
 */
class ClientSourceRepo extends Model
{
    use SoftDeletes;

    public const TIPOS = ['protheus', 'fluig', 'integracoes', 'outros'];

    protected $fillable = [
        'customer_id', 'owner', 'repository', 'branch', 'base_path',
        'tipo', 'descricao', 'active', 'needs_review', 'created_by', 'updated_by',
    ];

    protected $casts = ['active' => 'boolean', 'needs_review' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** owner/repository (nome completo GitHub). */
    public function fullName(): string
    {
        return "{$this->owner}/{$this->repository}";
    }

    /** base_path normalizado sem barras nas pontas (''=raiz do repo). */
    public function normalizedBasePath(): string
    {
        return trim((string) $this->base_path, '/');
    }
}
