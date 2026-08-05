<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Equipe de Vendas: gestor (manager) + membros. Materializa o escopo "Equipe" da
 * Política Comercial — quem vê o quê quando o perfil usa scope 'team'.
 */
class CrmSalesTeam extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'manager_id', 'active'];
    protected $casts = ['active' => 'boolean'];

    public function manager(): BelongsTo { return $this->belongsTo(User::class, 'manager_id'); }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'crm_sales_team_user', 'team_id', 'user_id')->withTimestamps();
    }

    /**
     * IDs de usuários visíveis para $u sob o escopo "Equipe":
     * ele próprio + membros/gestores das equipes que ele GERENCIA ou de que PARTICIPA.
     * Sem equipe → só ele mesmo (degrada para "own", nunca abre demais).
     */
    public static function visibleUserIds(User $u): Collection
    {
        $ids = collect([$u->id]);
        // Equipes que ele gerencia
        $managed = static::where('manager_id', $u->id)->where('active', true)->with('members:id')->get();
        foreach ($managed as $t) $ids = $ids->merge($t->members->pluck('id'))->push($t->manager_id);
        // Equipes de que ele participa (vê os colegas e o gestor)
        $member = static::where('active', true)->whereHas('members', fn ($q) => $q->where('users.id', $u->id))
            ->with('members:id')->get();
        foreach ($member as $t) $ids = $ids->merge($t->members->pluck('id'))->push($t->manager_id);
        return $ids->filter()->unique()->values();
    }
}
