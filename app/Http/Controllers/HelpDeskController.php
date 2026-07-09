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
    /** Usuários internos atribuíveis (atendentes/membros de fila) — exclui cliente/parceiro. */
    public function agents(\Illuminate\Http\Request $request): JsonResponse
    {
        // Candidatos = todos os internos que PODEM virar agente (usado p/ montar equipes).
        if ($request->boolean('candidates')) {
            return response()->json(['data' => User::whereIn('type', ['admin', 'administrativo', 'coordenador', 'consultor'])
                ->orderBy('name')->get(['id', 'name', 'type'])]);
        }
        // AGENTES = usuários vinculados a ALGUMA equipe (promovidos automaticamente ao entrar na equipe).
        $memberIds = \Illuminate\Support\Facades\DB::table('helpdesk_team_user')->distinct()->pluck('user_id');
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
        ]]);
    }

    /**
     * Clientes elegíveis ao Help Desk: têm contrato de SUSTENTAÇÃO com a chave de
     * integração de horas LIGADA (helpdesk_integration_enabled). Usado na Regra de
     * Associação — só esses clientes fazem sentido vincular a um domínio.
     */
    public function integrationCustomers(): JsonResponse
    {
        $customerIds = \App\Models\Contract::query()
            ->where('helpdesk_integration_enabled', true)
            ->where('categoria', 'sustentacao')
            ->whereNotNull('customer_id')
            ->distinct()->pluck('customer_id');

        $customers = \App\Models\Customer::whereIn('id', $customerIds)
            ->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $customers]);
    }
}
