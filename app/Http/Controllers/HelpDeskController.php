<?php

namespace App\Http\Controllers;

use App\Models\HelpDeskCategory;
use App\Models\HelpDeskService;
use App\Models\HelpDeskTicketJustification;
use App\Models\HelpDeskSlaPolicy;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTeam;
use App\Models\HelpDeskTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Help Desk — metadados para formulários/filtros (status, prioridades, canais, filas, categorias, SLA). */
class HelpDeskController extends Controller
{
    use \App\Http\Traits\FiltersByActiveCompany;

    /** Usuários internos atribuíveis (atendentes/membros de fila) — exclui cliente/parceiro. */
    public function agents(\Illuminate\Http\Request $request): JsonResponse
    {
        // Candidatos = todos os internos que PODEM virar agente (usado p/ montar equipes).
        if ($request->boolean('candidates')) {
            return response()->json(['data' => User::whereIn('type', ['admin', 'administrativo', 'coordenador', 'consultor'])
                ->orderBy('name')->get(['id', 'name', 'type'])]);
        }
        // AGENTES = usuários vinculados a ALGUMA equipe (promovidos automaticamente ao entrar na equipe).
        $memberIds = \Illuminate\Support\Facades\DB::table('helpdesk_team_user')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q
                ->join('helpdesk_teams', 'helpdesk_teams.id', '=', 'helpdesk_team_user.helpdesk_team_id')
                ->where('helpdesk_teams.company_id', $cid))
            ->distinct()->pluck('helpdesk_team_user.user_id');
        $agents = User::whereIn('id', $memberIds)->orderBy('name')->get(['id', 'name', 'type']);
        return response()->json(['data' => $agents]);
    }

    public function meta(): JsonResponse
    {
        return response()->json(['data' => [
            'priorities' => HelpDeskTicket::PRIORITIES,
            'channels'   => HelpDeskTicket::CHANNELS,
            'statuses'   => HelpDeskStatus::where('active', true)->orderBy('sort_order')->get(),
            'categories' => HelpDeskCategory::where('active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'color', 'default_team_id', 'sla_policy_id']),
            'teams'      => HelpDeskTeam::where('active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color']),
            'sla_policies' => HelpDeskSlaPolicy::where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default']),
            // Árvore completa (pais + filhos) ativa; o FE monta a hierarquia e desabilita os não-selecionáveis.
            'services'     => HelpDeskService::where('active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'code', 'selectable_by_agent']),
            'justifications' => HelpDeskTicketJustification::where('active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'status_id', 'name']),
            'priority_labels' => ['baixa' => 'Baixa', 'normal' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'],
            // Perfil de acesso do agente logado: o que pode informar na abertura + se pode abrir.
            'my_inform'  => app(\App\Services\HelpDeskAccessPolicy::class)->informMap(auth()->user(), ['service', 'category', 'urgency', 'subject', 'tags']),
            'can_open'   => app(\App\Services\HelpDeskAccessPolicy::class)->canOpen(auth()->user()),
            // Fila (Kanban): exibir a coluna "Novo" (tickets ainda não distribuídos) p/ este perfil?
            'see_new_column' => app(\App\Services\HelpDeskAccessPolicy::class)->seeNewColumn(auth()->user()),
            // Busca global (lupa) liberada para este perfil?
            'can_search'     => app(\App\Services\HelpDeskAccessPolicy::class)->canGlobalSearch(auth()->user()),
            // Escopo de visão na fila: 'all' vê os de outros; 'assigned' só os próprios → esconde o
            // toggle "apenas meus chamados" (redundante), 'none' não vê nada.
            'view_scope'     => app(\App\Services\HelpDeskAccessPolicy::class)->viewScope(auth()->user()),
        ]]);
    }

    /**
     * Clientes elegíveis ao Help Desk = têm contrato de SUSTENTAÇÃO. Usado na Regra de
     * Associação para mostrar as PENDÊNCIAS: cliente de sustentação sem domínio vinculado.
     * (Roteamento de e-mail → cliente vale para qualquer cliente de sustentação; a chave de
     * integração de HORAS é outra funcionalidade e não filtra aqui.)
     */
    public function integrationCustomers(): JsonResponse
    {
        // Sustentação = contrato com categoria 'sustentacao' OU já colocado no kanban de sustentação
        // (sustentacao_column preenchido: On Demand/Cloud/Bizify/BH). O move p/ o kanban seta a coluna
        // mas NÃO a categoria — então filtrar só por categoria deixava clientes de fora.
        $customerIds = \App\Models\Contract::query()
            ->where(fn ($q) => $q->where('categoria', 'sustentacao')->orWhereNotNull('sustentacao_column'))
            ->whereNotNull('customer_id')
            ->distinct()->pluck('customer_id');

        $customers = \App\Models\Customer::whereIn('id', $customerIds)
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $customers]);
    }
}
