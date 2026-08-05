<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ServiceType;

/**
 * Filtro de "projetos de sustentação" usado pelo Portal de Sustentação.
 * Centraliza a regra (incluindo override de coordenador) que antes vivia só
 * no SustentacaoController e agora também é aplicada nos endpoints originais
 * (timesheets/expenses/approvals/timesheet-logs) via `?scope=sustentacao`.
 */
class SustentacaoScopeService
{
    /**
     * IDs dos service_types cujo code/nome representa sustentação.
     */
    public function serviceTypeIds(): array
    {
        return ServiceType::where('code', 'sustentacao')
            ->orWhereRaw('LOWER(TRIM(name)) IN (?, ?)', ['sustentação', 'sustentacao'])
            ->pluck('id')
            ->all();
    }

    /**
     * IDs dos projetos elegíveis ao Portal de Sustentação.
     * - service_type pertence à lista de sustentação
     * - projeto não está soft-deleted
     * - projeto não tem override de coordenador (kanban_coordinator_override_id IS NULL).
     *   Projetos com override são gerenciados pelo coord override e não aparecem
     *   nas abas Apontamentos/Despesas/Aprovações/Auditoria do portal.
     */
    public function projectIds(?int $customerId = null): array
    {
        $stIds = $this->serviceTypeIds();

        // Regra (pedido Ricardo): o Portal pega SOMENTE sustentação, EXCETO se o coordenador
        // de sustentação logado coordena algum projeto — aí esse projeto (de QUALQUER
        // serviceType) também entra.
        $user        = auth()->user();
        $isSustCoord = $user && $user->coordinator_type === 'sustentacao';
        $uid         = $user?->id;

        $q = Project::whereNull('deleted_at')->where(function ($w) use ($stIds, $isSustCoord, $uid) {
            // (1) Base do Portal: service_type de sustentação (ou "Investimento Suporte",
            //     service_type 'Projeto' mas operacionalmente suporte) SEM override de coord.
            $w->where(function ($sub) use ($stIds) {
                $sub->where(function ($s) use ($stIds) {
                        $s->whereRaw("LOWER(TRIM(name)) = 'investimento suporte'");
                        if (!empty($stIds)) $s->orWhereIn('service_type_id', $stIds);
                    })
                    ->whereNull('kanban_coordinator_override_id');
            });
            // (2) EXCEÇÃO: projetos que ESTE coord de sustentação coordena — override dele,
            //     OU coordenador do projeto (sem override) — de qualquer serviceType.
            if ($isSustCoord && $uid) {
                $w->orWhere('kanban_coordinator_override_id', $uid)
                  ->orWhere(function ($s) use ($uid) {
                      $s->whereNull('kanban_coordinator_override_id')
                        ->whereHas('coordinators', fn ($c) => $c->where('users.id', $uid));
                  });
            }
        });

        if ($customerId) $q->where('customer_id', $customerId);

        return $q->pluck('id')->all();
    }

    /**
     * Conveniência: aplica o filtro num query builder de Eloquent já existente,
     * filtrando pela coluna `project_id` (default) ou outra coluna informada.
     * Quando não há projetos elegíveis, força resultado vazio.
     */
    public function applyToQuery($query, ?int $customerId = null, string $column = 'project_id')
    {
        $ids = $this->projectIds($customerId);
        if (empty($ids)) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereIn($column, $ids);
    }
}
