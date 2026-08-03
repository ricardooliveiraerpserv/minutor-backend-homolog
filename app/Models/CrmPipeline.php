<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** CRM — funil (pipeline). */
class CrmPipeline extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = ['name', 'code', 'ordem', 'active', 'descricao', 'cor', 'bloqueado', 'tipo', 'arquivado', 'tipos_empresa'];
    protected $casts = ['active' => 'boolean', 'ordem' => 'integer', 'bloqueado' => 'boolean', 'arquivado' => 'boolean', 'tipos_empresa' => 'array'];

    public function stages(): HasMany
    {
        return $this->hasMany(CrmPipelineStage::class, 'pipeline_id')->orderBy('ordem');
    }

    /** Usuários liberados a ver este pipeline (admin é bypass, não precisa estar aqui). */
    public function visibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_pipeline_user', 'pipeline_id', 'user_id');
    }

    /** Regra: admin vê tudo; demais só se estiverem na lista. Sem lista = só admin. */
    public function canBeSeenBy(User $user): bool
    {
        return $user->isAdmin() || $this->visibleUsers()->where('users.id', $user->id)->exists();
    }

    /** Restringe a query aos pipelines que o usuário pode ver (admin não filtra). */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }
        return $query->whereHas('visibleUsers', fn ($q) => $q->where('users.id', $user->id));
    }
}
