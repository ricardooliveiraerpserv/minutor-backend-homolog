<?php

namespace App\Support;

use App\Models\Project;
use App\Models\StageDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Regra ÚNICA de acesso do perfil CLIENTE (users.type='cliente' efetivo) a projetos.
 *
 *  - Nunca enxerga projeto de OUTRO customer (em hipótese nenhuma).
 *  - Gestor do cliente (is_customer_manager) → todos os projetos do SEU customer.
 *  - Convidado (não-gestor) → só projetos em que PARTICIPA: convidado em
 *    project_client_viewers OU envolvido (client_involved+client_user_id) /
 *    responsável (responsible_user_id) por ≥1 atividade do cronograma.
 *  - Telas "da empresa" (Home/resumo, centros de custo, dashboards de contrato) → só gestor.
 *
 * Usuários internos NÃO passam por aqui (os métodos devolvem true / não filtram).
 */
final class ClientAccess
{
    public const MANAGER_ONLY_MESSAGE = 'Acesso restrito ao gestor do cliente.';

    public static function isClient($user): bool
    {
        return $user instanceof User && $user->isCliente();
    }

    /** Cliente gestor da própria empresa (precisa ter customer_id). */
    public static function isManager($user): bool
    {
        return self::isClient($user) && (bool) $user->is_customer_manager && (int) $user->customer_id > 0;
    }

    /** Cliente NÃO-gestor (convidado) — ou cliente sem customer_id (tratado como sem acesso à empresa). */
    public static function isNonManagerClient($user): bool
    {
        return self::isClient($user) && ! self::isManager($user);
    }

    /** Participa do projeto (convidado OU envolvido/responsável em atividade). Não checa customer. */
    public static function participates(Project $project, int $uid): bool
    {
        if ($project->clientViewers()->where('users.id', $uid)->exists()) {
            return true;
        }
        return StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
            ->where(function ($q) use ($uid) {
                $q->where(fn ($w) => $w->where('client_involved', true)->where('client_user_id', $uid))
                  ->orWhere('responsible_user_id', $uid);
            })
            ->exists();
    }

    /**
     * O usuário pode acessar este projeto? Interno → true (as regras internas continuam
     * nos controllers). Cliente → mesmo customer E (gestor OU participante).
     */
    public static function canAccessProject(?Project $project, $user): bool
    {
        if (! self::isClient($user)) {
            return true;
        }
        if (! $project || (int) $user->customer_id <= 0 || (int) $user->customer_id !== (int) $project->customer_id) {
            return false;
        }
        if (self::isManager($user)) {
            return true;
        }
        return self::participates($project, (int) $user->id);
    }

    /** Mesma regra, a partir de um project_id (null/inexistente → nega p/ cliente). */
    public static function canAccessProjectId($projectId, $user): bool
    {
        if (! self::isClient($user)) {
            return true;
        }
        if (! $projectId) {
            return false;
        }
        $project = Project::withoutGlobalScopes()->find((int) $projectId);
        return self::canAccessProject($project, $user);
    }

    /**
     * Restringe uma query de PROJETOS ao que o cliente pode ver. Interno → não mexe.
     * $prefix: alias/tabela da coluna (ex.: 'projects.').
     */
    public static function scopeProjects($query, $user, string $prefix = ''): void
    {
        if (! self::isClient($user)) {
            return;
        }
        $cid = (int) $user->customer_id;
        if ($cid <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where($prefix . 'customer_id', $cid);
        if (self::isManager($user)) {
            return;
        }
        $uid = (int) $user->id;
        $query->where(function ($q) use ($uid) {
            $q->whereHas('clientViewers', fn ($v) => $v->where('users.id', $uid))
              ->orWhereHas('stages.deliveries', function ($d) use ($uid) {
                  $d->where(fn ($w) => $w->where('client_involved', true)->where('client_user_id', $uid))
                    ->orWhere('responsible_user_id', $uid);
              });
        });
    }

    /** IDs dos projetos visíveis ao cliente (null = interno, sem restrição). */
    public static function visibleProjectIds($user): ?array
    {
        if (! self::isClient($user)) {
            return null;
        }
        $q = Project::query();
        self::scopeProjects($q, $user);
        return $q->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
    /**
     * Contrato (Demanda): cliente do MESMO customer E (gestor OU o projeto gerado pelo contrato
     * é acessível a ele). Convidado não vê demandas pré-projeto (mesma regra do kanban).
     */
    public static function canAccessContract($contract, $user): bool
    {
        if (! self::isClient($user)) {
            return true;
        }
        if (! $contract || (int) $user->customer_id <= 0 || (int) $user->customer_id !== (int) $contract->customer_id) {
            return false;
        }
        if (self::isManager($user)) {
            return true;
        }
        foreach (['project_id', 'aditivo_project_id'] as $col) {
            $pid = $contract->{$col} ?? null;
            if ($pid && self::canAccessProjectId($pid, $user)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Requisição: cliente do MESMO customer E (gestor OU quem abriu OU convidado da requisição
     * OU participa do projeto já gerado a partir dela). Mesma regra do kanban (requestCards).
     */
    public static function canAccessContractRequest($req, $user): bool
    {
        if (! self::isClient($user)) {
            return true;
        }
        if (! $req || (int) $user->customer_id <= 0 || (int) $user->customer_id !== (int) $req->customer_id) {
            return false;
        }
        if (self::isManager($user)) {
            return true;
        }
        $uid = (int) $user->id;
        if ((int) $req->created_by_id === $uid) {
            return true;
        }
        if ($req->clientViewers()->where('users.id', $uid)->exists()) {
            return true;
        }
        $pid = $req->linked_project_id ?? null;
        if ($pid && self::canAccessProjectId($pid, $user)) {
            return true;
        }
        return false;
    }
    /**
     * Subquery (select id) dos projetos visíveis ao cliente — p/ whereIn('x.project_id', ...).
     * Sem global scopes (CompanyScope etc.): o filtro aqui é só o de segurança do cliente.
     */
    public static function projectIdsSubquery($user)
    {
        $q = Project::withoutGlobalScopes()->select('projects.id');
        self::scopeProjects($q, $user, 'projects.');
        return $q;
    }

    /**
     * Aplica em queries de timesheets/expenses (coluna $projectCol): cliente NÃO-gestor só vê
     * registros dos projetos em que participa. Gestor/interno: não mexe.
     */
    public static function restrictNonManagerToParticipation($query, $user, string $projectCol): void
    {
        if (self::isNonManagerClient($user)) {
            $query->whereIn($projectCol, self::projectIdsSubquery($user));
        }
    }
}
