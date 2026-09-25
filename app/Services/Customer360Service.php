<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\CrmOpportunity;
use App\Models\CrmProposal;
use App\Models\Customer;
use App\Models\HelpDeskTicket;
use App\Models\Project;
use App\Models\Timesheet;
use Illuminate\Support\Carbon;

/**
 * Customer 360 — NÚCLEO de dados da plataforma.
 *
 * Diretriz arquitetural: o SERVIÇO responde pelo DADO; cada módulo responde pela
 * EXPERIÊNCIA (via presenters: HelpDesk, CRM, Serviços, Portal, IA…). Este serviço NÃO
 * aplica permissão/redação nem layout — devolve o modelo canônico completo. Valores
 * financeiros vêm sob a chave `financeiro` de cada bloco, para o presenter decidir
 * expor ou não conforme o perfil. Reutiliza tabelas/queries existentes; não duplica dados.
 *
 * Não altera o Customer360Controller atual (CRM/Serviços seguem como estão).
 */
class Customer360Service
{
    private const PROJ_ATIVOS = ['started', 'liberado_para_testes', 'paused', 'awaiting_start'];

    /** Bloco CLIENTE. */
    public function cliente(Customer $c, ?HelpDeskTicket $t = null): array
    {
        $c->loadMissing('executive:id,name', 'crmProfile');
        $contract = $this->relevantContract($c, $t);
        $tecnico = $contract?->loadMissing('architect:id,name')->architect?->name;
        if (!$tecnico && $t?->project_id) {
            $proj = Project::with('coordinators:id,name', 'architect:id,name')->find($t->project_id);
            $tecnico = $proj?->coordinators->first()?->name ?? $proj?->architect?->name;
        }
        $contato = $t?->relationLoaded('contact') ? $t->contact : ($t?->contact_id ? optional($t->contact()->first()) : null);

        return [
            'empresa'               => $c->name,
            'cnpj'                  => $c->cgc,
            'segmento'              => $c->crmProfile?->segment,
            'classificacao'         => $c->crm_status,   // lead | prospect | cliente | em_renovacao | inativo
            'responsavel_comercial' => $c->executive?->name,
            'responsavel_tecnico'   => $tecnico,
            'solicitante'           => $contato ? ['nome' => $contato->name, 'email' => $contato->email] : null,
            'ref'                   => ['type' => 'customer', 'id' => $c->id],
        ];
    }

    /** Bloco CONTRATO (do chamado, ou o contrato ativo mais recente do cliente). */
    public function contrato(Customer $c, ?HelpDeskTicket $t = null): array
    {
        $contract = $this->relevantContract($c, $t);
        $bh = $this->bancoHoras($c);

        $base = [
            'tem_contrato'        => $contract !== null,
            'nome'                => $contract ? ($contract->project_name ?: 'Contrato #' . $contract->id) : null,
            'tipo'                => $contract?->loadMissing('contractType:id,name')->contractType?->name ?? $contract?->categoria,
            'situacao'            => $contract?->status,
            'data_vencimento'     => optional($contract?->data_vencimento)->toDateString(),
            'sla'                 => $t ? app(HelpDeskSlaService::class)->summary($t) : null,
            'banco_horas'         => $bh,   // contratadas/consumidas/saldo (operacional)
            'ref'                 => $contract ? ['type' => 'contract', 'id' => $contract->id] : null,
            'financeiro'          => [
                'valor_hora'    => $contract ? (float) ($contract->valor_hora ?? 0) : null,
                'valor_projeto' => $contract ? (float) ($contract->valor_projeto ?? 0) : null,
            ],
        ];
        return $base;
    }

    /** Bloco PROJETOS (ativos + o do chamado + % por horas). Entregas do Cronograma = integração futura. */
    public function projetos(Customer $c, ?HelpDeskTicket $t = null): array
    {
        $projects = Project::where('customer_id', $c->id)->whereNull('deleted_at')->orderByDesc('id')->limit(50)->get();
        $ids = $projects->pluck('id');
        $consumo = Timesheet::whereIn('project_id', $ids)->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'pending'])
            ->selectRaw('project_id, SUM(effort_minutes) as min')->groupBy('project_id')->pluck('min', 'project_id');

        $map = fn (Project $p) => [
            'id'               => $p->id,
            'nome'             => $p->name,
            'status'           => $p->status,
            'ativo'            => in_array($p->status, self::PROJ_ATIVOS, true),
            'horas_contratadas' => (float) $p->sold_hours,
            'horas_consumidas' => round((float) ($consumo[$p->id] ?? 0) / 60, 2),
            'evolucao_pct'     => $p->sold_hours > 0 ? min(100, (int) round(((float) ($consumo[$p->id] ?? 0) / 60) / (float) $p->sold_hours * 100)) : null,
            'ref'              => ['type' => 'project', 'id' => $p->id],
        ];

        $doChamado = $t?->project_id ? $projects->firstWhere('id', $t->project_id) : null;

        return [
            'projeto_do_chamado' => $doChamado ? $map($doChamado) : null,
            'ativos'             => $projects->filter(fn ($p) => in_array($p->status, self::PROJ_ATIVOS, true))->map($map)->values(),
            'total'              => Project::where('customer_id', $c->id)->whereNull('deleted_at')->count(),
            'cronograma_pendente' => true,   // próximas/últimas entregas = ponto de integração (Cronograma WIP)
        ];
    }

    /** Bloco HELP DESK (histórico do cliente). */
    public function helpDesk(Customer $c): array
    {
        $base = HelpDeskTicket::where('customer_id', $c->id);
        $abertos = (clone $base)->whereHas('status', fn ($s) => $s->where('is_open', true));

        $avgSeconds = (clone $base)->whereNotNull('resolved_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (resolved_at - created_at))) as s')->value('s');

        $ultimos = (clone $base)->with('status:id,label,color')->orderByDesc('updated_at')->limit(5)->get()
            ->map(fn ($t) => [
                'id'         => $t->id,
                'numero'     => $t->ticket_number,
                'assunto'    => $t->subject,
                'prioridade' => $t->priority,
                'status'     => $t->status ? ['label' => $t->status->label, 'cor' => $t->status->color] : null,
                'atualizado_em' => optional($t->updated_at)->toIso8601String(),
                'ref'        => ['type' => 'ticket', 'id' => $t->id],
            ]);

        return [
            'abertos'   => (int) (clone $abertos)->count(),
            'criticos'  => (int) (clone $abertos)->whereIn('priority', ['alta', 'urgente'])->count(),
            'vencidos'  => (int) (clone $abertos)->where(fn ($w) => $w->where('first_response_breached', true)->orWhere('resolution_breached', true))->count(),
            'tempo_medio_horas' => $avgSeconds ? round($avgSeconds / 3600, 1) : null,
            'ultimos'   => $ultimos,
            'ref'       => ['type' => 'tickets_list', 'id' => $c->id],
        ];
    }

    /** Bloco COMERCIAL. Valores monetários sob `financeiro`. */
    public function comercial(Customer $c): array
    {
        $oppIds = CrmOpportunity::where('customer_id', $c->id)->pluck('id');
        $abertas = CrmOpportunity::where('customer_id', $c->id)->where('status', 'aberto')
            ->with('stage:id,name')->orderByDesc('id')->limit(5)->get();

        $ultimaProposta = $oppIds->isNotEmpty()
            ? CrmProposal::whereIn('opportunity_id', $oppIds)->whereNull('deleted_at')->orderByDesc('id')->first()
            : null;

        $ultimoContato = collect([
            CrmOpportunity::where('customer_id', $c->id)->max('ultima_interacao_at'),
            $c->crmProfile?->ultima_interacao_at,
        ])->filter()->max();

        return [
            'situacao_relacionamento' => $c->crm_status,
            'oportunidades_abertas'   => $abertas->map(fn ($o) => [
                'id'    => $o->id,
                'titulo' => $o->title,
                'etapa' => $o->stage?->name,
                'ref'   => ['type' => 'opportunity', 'id' => $o->id],
                'financeiro' => ['valor' => (float) $o->valor],
            ])->values(),
            'oportunidades_total' => (int) $oppIds->count(),
            'ultima_proposta'     => $ultimaProposta ? [
                'codigo'     => $ultimaProposta->codigo,
                'status'     => $ultimaProposta->status,
                'data'       => optional($ultimaProposta->created_at)->toDateString(),
                'financeiro' => ['valor' => (float) ($ultimaProposta->total ?? 0)],
            ] : null,
            'ultimo_contato_comercial' => $ultimoContato ? Carbon::parse($ultimoContato)->toDateString() : null,
        ];
    }

    /** Bloco OPERAÇÃO (apontamentos recentes, consultores, histórico). */
    public function operacao(Customer $c): array
    {
        $projIds = Project::where('customer_id', $c->id)->whereNull('deleted_at')->pluck('id');
        $tsBase = Timesheet::whereIn('project_id', $projIds)->whereNull('deleted_at')->whereIn('status', ['approved', 'pending']);

        $recentes = (clone $tsBase)->with('user:id,name', 'project:id,name')->orderByDesc('date')->limit(8)->get()
            ->map(fn ($t) => [
                'data'      => $t->date ? Carbon::parse($t->date)->toDateString() : null,
                'consultor' => $t->user?->name,
                'projeto'   => $t->project?->name,
                'horas'     => round((float) $t->effort_minutes / 60, 2),
                'obs'       => $t->observation,
            ]);

        $consultores = (clone $tsBase)->with('user:id,name')->get()->pluck('user.name')->filter()->unique()->values();

        return [
            'ultimos_apontamentos' => $recentes->values(),
            'consultores_envolvidos' => $consultores,
            'total_horas'          => round((float) (clone $tsBase)->sum('effort_minutes') / 60, 2),
            'ref'                  => ['type' => 'timesheets', 'id' => $c->id],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function relevantContract(Customer $c, ?HelpDeskTicket $t): ?Contract
    {
        if ($t?->contract_id) {
            $byTicket = Contract::find($t->contract_id);
            if ($byTicket) return $byTicket;
        }
        return Contract::where('customer_id', $c->id)->where('status', 'ativo')->latest('id')->first()
            ?? Contract::where('customer_id', $c->id)->latest('id')->first();
    }

    /** Banco de horas do cliente (contratadas/consumidas/saldo) — mesmo cálculo do Customer360 adm(). */
    private function bancoHoras(Customer $c): array
    {
        $projIds = Project::where('customer_id', $c->id)->whereNull('deleted_at')->pluck('id');
        $contratadas = (float) Project::whereIn('id', $projIds)->sum('sold_hours');
        $consumidas = (float) Timesheet::whereIn('project_id', $projIds)
            ->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])->sum('effort_minutes') / 60;
        return [
            'contratadas' => round($contratadas, 2),
            'consumidas'  => round($consumidas, 2),
            'saldo'       => round($contratadas - $consumidas, 2),
        ];
    }
}
