<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractContact;
use App\Models\ContractEvent;
use App\Models\ContractFlowSnapshot;
use App\Models\ContractKanbanLog;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\ProjectKanbanLog;
use App\Models\ContractType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\ProjectCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Events\ContractEventCreated;
use App\Jobs\ReplayContractEventsJob;
use App\Listeners\ContractEventListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Mail\ReajusteClienteMail;
use App\Models\ContractValueChange;
use App\Services\EconomicIndexService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class ContractController extends Controller
{
    /**
     * FASE 11.7 (PR 7b) — Map type-legado-pt → category-en (canônico). Vivia na
     * trait DualWritesEntityAttachments que foi removida junto com o legado.
     */
    private static function mapAttachmentTypeToCategory(string $legacyType): string
    {
        return match (strtolower($legacyType)) {
            'proposta'           => 'proposal',
            'contrato'           => 'contract',
            'logo'               => 'logo',
            'aprovacao_cliente'  => 'client_approval',
            'evidencia'          => 'evidence',
            'imagem'             => 'image',
            'outro'              => 'attachment',
            default              => 'attachment',
        };
    }

    public function index(Request $request): JsonResponse
    {
        $query = Contract::with([
            'customer:id,name',
            'contractType:id,name',
            'serviceType:id,name',
            'architect:id,name',
            'project:id,code,name,sold_hours,hour_contribution,status',
        ])
            ->when($request->query('status'), fn($q) => $q->where('status', $request->query('status')))
            ->when($request->query('customer_id'), fn($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->query('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->whereHas('customer', fn($c) => $c->where('name', 'ilike', $s));
            })
            ->orderBy('created_at', 'desc');

        $paginated = $query->paginate($request->query('per_page', 20));

        $projectIds = collect($paginated->items())->pluck('project_id')->filter()->values()->all();
        if (!empty($projectIds)) {
            $timesheetSums = Timesheet::whereIn('project_id', $projectIds)
                ->where('status', 'approved')
                ->groupBy('project_id')
                ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
                ->pluck('total_minutes', 'project_id');

            foreach ($paginated->items() as $contract) {
                if ($contract->project) {
                    // Saldo + consumido pela regra da GESTÃO DE PROJETOS (fonte da verdade) — conta os
                    // subprojetos (Fechado/BH-Fixo pelas contratadas, On Demand pelo apontado).
                    // Antes ignorava filhos (saldo inflado, ex.: AUSTER 9602 vs 1957 real).
                    $b = $contract->project->managementBreakdown();
                    $contract->project->setAttribute('consumed_hours', round($b['consumed'], 1));
                    $contract->project->setAttribute('general_hours_balance', round($b['balance'], 1));
                }
            }
        }

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'            => 'required|exists:customers,id',
            'project_name'           => 'nullable|string|max:255',
            'parent_project_id'      => 'nullable|exists:projects,id',
            'categoria'              => 'required|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'required|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_coordenacao'      => 'nullable|numeric|min:0|max:999999',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            // Subprojeto faturado: além do card do filho (Início Autorizado), gera um card de
            // aporte (Novo Contrato) no projeto pai, valorado pelas horas/valor-hora do filho.
            'sera_faturado'          => 'boolean',
            'contacts'               => 'nullable|array',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        // Aporte do subprojeto faturado (se houver) — notificado APÓS o commit, igual aos
        // demais avisos. [project, contribution] pra reusar o workflow padrão de aporte.
        $aporteToNotify = null;

        $contract = DB::transaction(function () use ($validated, $request, &$aporteToNotify) {
            // Subprojeto (tem parent_project_id): regra antiga — nasce em "Início Autorizado",
            // não em "Novo Contrato" (o contrato pai já está aprovado; o filho não passa pela
            // revisão de novo contrato). Demais contratos nascem em rascunho/backlog.
            $isSubproject = !empty($validated['parent_project_id']);
            // 'sera_faturado' não é coluna de Contract — é só o gatilho do aporte do filho.
            $data = collect($validated)->except(['contacts', 'sera_faturado'])->merge([
                'created_by_id' => auth()->id(),
                'status'        => $isSubproject ? Contract::STATUS_INICIO_AUTORIZADO : Contract::STATUS_RASCUNHO,
                'kanban_status' => $isSubproject ? Contract::KANBAN_INICIO_AUTORIZADO : Contract::KANBAN_BACKLOG,
            ])->toArray();

            if (empty($data['executivo_conta_id'])) {
                $customer = Customer::find($data['customer_id']);
                if ($customer?->executive_id) {
                    $data['executivo_conta_id'] = $customer->executive_id;
                }
            }

            $contract = Contract::create($data);

            foreach ($validated['contacts'] ?? [] as $c) {
                ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
            }

            // Contatos do contrato espelham no cadastro da empresa (upsert; nunca deleta).
            $this->syncContactsToCustomerRegistry((int) $validated['customer_id'], $validated['contacts'] ?? []);

            // Subprojeto FATURADO → nasce também um card de APORTE em "Novo Contrato".
            // O Kanban só exibe aporte de projeto PAI, então o aporte é anexado ao pai,
            // valorado pelas horas × valor-hora do filho (descrição referencia o subprojeto).
            // A notificação segue o MESMO workflow padrão de aporte (disparada pós-commit) —
            // por isso o subprojeto faturado gera DOIS avisos: início autorizado + aporte.
            if ($isSubproject && !empty($validated['sera_faturado'])) {
                $parent = Project::find($validated['parent_project_id']);
                $horas  = (float) ($validated['horas_contratadas'] ?? 0);
                $rate   = (float) ($validated['valor_hora'] ?? ($parent?->hourly_rate ?? 0));
                if ($parent && $horas > 0 && $rate > 0) {
                    $ref = trim(($validated['project_code_preview'] ?? '') . ' ' . ($validated['project_name'] ?? ''));
                    $contribution = $parent->hourContributions()->create([
                        'contributed_hours' => $horas,
                        'hourly_rate'       => $rate,
                        'motivo'            => 'aporte',
                        'description'       => 'Aporte ref. subprojeto faturado' . ($ref !== '' ? " ({$ref})" : ''),
                        'kanban_status'     => \App\Models\HourContribution::KANBAN_NOVO,
                        'contributed_by'    => auth()->id(),
                        'contributed_at'    => now(),
                    ]);
                    $aporteToNotify = ['project' => $parent, 'contribution' => $contribution];
                }
            }

            return $contract;
        });

        // Notificação conforme a COLUNA em que o contrato nasce:
        // - Novo Contrato (backlog): avisa a triagem administrativa (contract.created).
        // - Subprojeto (nasce em Início Autorizado): dispara o workflow de início
        //   autorizado — NÃO o de novo contrato (senão o administrativo recebe aviso
        //   indevido e com a fase errada). Cliente ainda NÃO recebe nessa fase.
        if ($contract->kanban_status === Contract::KANBAN_INICIO_AUTORIZADO) {
            $this->notifyInicioAutorizado($contract->load('customer'));
        } else {
            $this->notifyContractCreated($contract);
        }

        // Subprojeto faturado: dispara o workflow padrão de aporte (2º aviso).
        if ($aporteToNotify) {
            $this->notifyNewAporte($aporteToNotify['project'], $aporteToNotify['contribution']);
        }

        return response()->json($contract->load(['customer:id,name', 'contacts', 'attachments']), 201);
    }

    /**
     * Comunica um novo aporte de horas seguindo o mesmo padrão do HourContributionController:
     * aporte em projeto PAI usa o workflow 'contract.aporte' (cliente + internos); em filho,
     * 'contract.aporte.child' (só internos). Síncrono + best-effort (não bloqueia a criação).
     */
    private function notifyNewAporte(Project $project, \App\Models\HourContribution $contribution): void
    {
        try {
            $isChild = $project->parent_project_id !== null;
            $workflowKey = $isChild ? 'contract.aporte.child' : 'contract.aporte';
            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($workflowKey, [
                'project'      => $project,
                'contribution' => $contribution,
                'actor'        => \App\Models\User::find($contribution->contributed_by),
                'is_child'     => $isChild,
            ]);

            if (empty($rcpt['to'])) {
                return;
            }

            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify(new \App\Notifications\ContractAporteNotification($contribution, $project, $rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('Aporte (subprojeto faturado) notification falhou', [
                'project_id'      => $project->id,
                'contribution_id' => $contribution->id ?? null,
                'err'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Espelha os contatos informados no contrato no cadastro de contatos da EMPRESA
     * (`customer_contacts`). Faz UPSERT por cliente: casa por e-mail (se houver) ou por
     * nome (case-insensitive); cria os novos e atualiza os existentes. NUNCA deleta do
     * cadastro — é um registro compartilhado entre contratos/projetos do cliente.
     */
    private function syncContactsToCustomerRegistry(int $customerId, array $contacts): void
    {
        foreach ($contacts as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $email = trim((string) ($c['email'] ?? ''));

            $match = \App\Models\CustomerContact::where('customer_id', $customerId)
                ->when(
                    $email !== '',
                    fn ($q) => $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]),
                    fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]),
                )
                ->first();

            $data = [
                'name'  => $name,
                'cargo' => ($c['cargo'] ?? '') !== '' ? $c['cargo'] : null,
                'email' => $email !== '' ? $email : null,
                'phone' => ($c['phone'] ?? '') !== '' ? $c['phone'] : null,
            ];

            if ($match) {
                $match->update($data);
            } else {
                \App\Models\CustomerContact::create(array_merge($data, ['customer_id' => $customerId]));
            }
        }
    }

    /**
     * Projetos elegíveis para um aditivo: pai ou independente (parent_project_id null)
     * dos tipos On Demand / Cloud / Banco de Horas Mensal. Devolve o que cada tipo
     * permite alterar e os valores atuais (pra prefill no modal).
     */
    public function aditivoEligibleProjects(Request $request): JsonResponse
    {
        $allowedByCode = [
            'on_demand'     => ['valor_hora'],
            'cloud'         => ['valor_projeto'],
            'monthly_hours' => ['valor_hora', 'horas_contratadas'],
        ];

        $projects = Project::query()
            ->whereNull('parent_project_id')
            // Buckets internos de investimento (Comercial/Suporte/Projeto) não entram.
            ->where(fn ($q) => $q->where('is_investimento_comercial', false)->orWhereNull('is_investimento_comercial'))
            ->whereHas('contractType', fn ($q) => $q->whereIn('code', array_keys($allowedByCode)))
            ->with('contractType:id,name,code')
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->get('customer_id')))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'customer_id', 'contract_type_id', 'hourly_rate', 'sold_hours', 'project_value']);

        $data = $projects->map(function (Project $p) use ($allowedByCode) {
            $code = (string) ($p->contractType->code ?? '');
            return [
                'id'             => $p->id,
                'code'           => $p->code,
                'name'           => $p->name,
                'customer_id'    => $p->customer_id,
                'type_code'      => $code,
                'type_name'      => $p->contractType->name ?? null,
                'allowed_fields' => $allowedByCode[$code] ?? [],
                'hourly_rate'    => $p->hourly_rate !== null ? (float) $p->hourly_rate : null,
                'sold_hours'     => $p->sold_hours !== null ? (float) $p->sold_hours : null,
                'project_value'  => $p->project_value !== null ? (float) $p->project_value : null,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * Cria um contrato ADITIVO: altera (na hora) um projeto pai/independente reusando a
     * vigência (valor-hora/horas) ou direto (valor do contrato, Cloud). O card nasce em
     * "Novo Contrato" e só pode ir para a coluna "Aditivos".
     */
    public function storeAditivo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'aditivo_project_id'     => 'required|exists:projects,id',
            'aditivo_field'          => 'nullable|in:valor_hora,horas_contratadas,valor_projeto',
            'aditivo_value'          => 'nullable|numeric|min:0',
            // Multi-alteração (Banco de Horas Mensal): muda valor-hora E/OU horas no mesmo aditivo.
            'aditivo_changes'           => 'nullable|array|min:1',
            'aditivo_changes.*.field'   => 'required_with:aditivo_changes|in:valor_hora,horas_contratadas',
            'aditivo_changes.*.value'   => 'required_with:aditivo_changes|numeric|min:0',
            'aditivo_effective_from' => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'observacoes'            => 'required|string',
        ]);

        $project = Project::with('contractType')->find($validated['aditivo_project_id']);

        if ($project->parent_project_id !== null) {
            return response()->json(['message' => 'Aditivo só pode alterar projeto pai ou independente (projeto-filho não entra).'], 422);
        }
        if ($project->is_investimento_comercial) {
            return response()->json(['message' => 'Projetos de investimento (buckets internos) não recebem aditivo.'], 422);
        }

        $code = (string) ($project->contractType->code ?? '');
        $allowed = match ($code) {
            'on_demand'     => ['valor_hora'],
            'cloud'         => ['valor_projeto'],
            'monthly_hours' => ['valor_hora', 'horas_contratadas'],
            default         => [],
        };
        if (empty($allowed)) {
            return response()->json(['message' => 'Aditivo só se aplica a projetos On Demand, Cloud ou Banco de Horas Mensal.'], 422);
        }

        // ── Multi-alteração (Banco de Horas Mensal): valor-hora E/OU horas no mesmo aditivo,
        //    recalculando o valor do contrato (horas × valor-hora) automaticamente. ──
        if (!empty($validated['aditivo_changes'])) {
            if ($code !== 'monthly_hours') {
                return response()->json(['message' => 'Múltiplas alterações no mesmo aditivo só para Banco de Horas Mensal.'], 422);
            }
            foreach ($validated['aditivo_changes'] as $ch) {
                if (!in_array($ch['field'], $allowed, true)) {
                    return response()->json(['message' => 'Campo não permitido para este projeto: ' . $ch['field']], 422);
                }
            }
            $effMulti = $validated['aditivo_effective_from'] ?? null;
            $oldContractValue = (float) ($project->project_value ?? 0); // valor do contrato ANTES
            $oldRate  = (float) ($project->hourly_rate ?? 0);           // valor-hora ANTES
            $oldHoras = (float) ($project->sold_hours ?? 0);            // horas ANTES

            $contract = DB::transaction(function () use ($validated, $project, $effMulti, $oldContractValue, $oldRate, $oldHoras) {
                foreach ($validated['aditivo_changes'] as $ch) {
                    $this->applyAditivoToProject($project, $ch['field'], $ch['value'], $effMulti);
                }
                $project->refresh(); // pega hourly_rate/sold_hours/project_value recomputados

                // Breakdown SEMPRE com os DOIS campos (valor-hora E horas), de→para — mesmo
                // que só um tenha mudado (o que não mudou aparece com antes=depois).
                $changesDetail = [
                    ['field' => 'valor_hora',        'label' => 'Valor da Hora',       'old' => $oldRate,  'new' => (float) ($project->hourly_rate ?? 0)],
                    ['field' => 'horas_contratadas', 'label' => 'Quantidade de Horas', 'old' => $oldHoras, 'new' => (float) ($project->sold_hours ?? 0)],
                ];

                return Contract::create([
                    'aditivo_old_value'      => $oldContractValue,
                    'aditivo_changes'        => $changesDetail,
                    'customer_id'            => $project->customer_id,
                    'project_name'           => $project->name,
                    'categoria'              => 'projeto',
                    'contract_type_id'       => $project->contract_type_id,
                    'service_type_id'        => $project->service_type_id,
                    'horas_contratadas'      => (int) ($project->sold_hours ?? 0),
                    'valor_hora'             => $project->hourly_rate,
                    'valor_projeto'          => $project->project_value, // novo valor do contrato (recomputado)
                    'is_aditivo'             => true,
                    'aditivo_project_id'     => $project->id,
                    'aditivo_field'          => 'multiplo', // impacto exibido como "Valor do Contrato" (old→new)
                    'aditivo_effective_from' => $effMulti ? \Carbon\Carbon::parse($effMulti)->startOfMonth()->toDateString() : null,
                    'condicao_pagamento'     => $validated['condicao_pagamento'] ?? null,
                    'observacoes'            => $validated['observacoes'] ?? null,
                    'created_by_id'          => auth()->id(),
                    'status'                 => Contract::STATUS_ATIVO,
                    'kanban_status'          => Contract::KANBAN_NOVO_PROJETO,
                ]);
            });

            return response()->json($contract->load(['customer:id,name', 'aditivoProject:id,name,code']), 201);
        }

        // ── Alteração única (fluxo original) ──
        if (empty($validated['aditivo_field']) || $validated['aditivo_value'] === null) {
            return response()->json(['message' => 'Informe o campo e o valor do aditivo.'], 422);
        }
        if (!in_array($validated['aditivo_field'], $allowed, true)) {
            return response()->json(['message' => 'Esse campo não pode ser alterado para o tipo deste projeto.'], 422);
        }

        // valor_projeto (Cloud) não tem vigência mensal; demais usam o mês escolhido (ou atual).
        $eff = $validated['aditivo_field'] === 'valor_projeto'
            ? null
            : ($validated['aditivo_effective_from'] ?? null);

        // Valor ANTES do aditivo (pra exibir "antes → depois" no card).
        $oldValue = match ($validated['aditivo_field']) {
            'valor_hora'        => (float) ($project->hourly_rate ?? 0),
            'horas_contratadas' => (float) ($project->sold_hours ?? 0),
            'valor_projeto'     => (float) ($project->project_value ?? 0),
            default             => null,
        };

        $contract = DB::transaction(function () use ($validated, $project, $eff, $oldValue) {
            $this->applyAditivoToProject($project, $validated['aditivo_field'], $validated['aditivo_value'], $eff);

            return Contract::create([
                'aditivo_old_value'      => $oldValue,
                'customer_id'            => $project->customer_id,
                'project_name'           => $project->name,
                'categoria'              => 'projeto',
                'contract_type_id'       => $project->contract_type_id,
                'service_type_id'        => $project->service_type_id,
                'horas_contratadas'      => $validated['aditivo_field'] === 'horas_contratadas' ? (int) $validated['aditivo_value'] : 0,
                'valor_hora'             => $validated['aditivo_field'] === 'valor_hora' ? $validated['aditivo_value'] : null,
                'valor_projeto'          => $validated['aditivo_field'] === 'valor_projeto' ? $validated['aditivo_value'] : null,
                'is_aditivo'             => true,
                'aditivo_project_id'     => $project->id,
                'aditivo_field'          => $validated['aditivo_field'],
                'aditivo_effective_from' => $eff ? \Carbon\Carbon::parse($eff)->startOfMonth()->toDateString() : null,
                'condicao_pagamento'     => $validated['condicao_pagamento'] ?? null,
                'observacoes'            => $validated['observacoes'] ?? null,
                'created_by_id'          => auth()->id(),
                'status'                 => Contract::STATUS_ATIVO,
                'kanban_status'          => Contract::KANBAN_NOVO_PROJETO,
            ]);
        });

        return response()->json($contract->load(['customer:id,name', 'aditivoProject:id,name,code']), 201);
    }

    /**
     * Aplica a alteração de um aditivo no projeto alvo, reusando a MESMA mecânica de
     * vigência do ProjectController@update (project_change_logs.effective_from p/ valor-hora,
     * project_sold_hours_history p/ horas vendidas; project_value direto p/ Cloud).
     */
    private function applyAditivoToProject(Project $project, string $field, $value, ?string $effectiveFrom): void
    {
        $eff = $effectiveFrom
            ? \Carbon\Carbon::parse($effectiveFrom)->startOfMonth()->toDateString()
            : \Carbon\Carbon::now()->startOfMonth()->toDateString();

        if ($field === 'valor_hora') {
            $project->update(['hourly_rate' => $value]);
            if ($project->wasChanged('hourly_rate')) {
                // Dedup mesmo-dia + grava effective_from (espelha ProjectController@update).
                $todayLogs = \App\Models\ProjectChangeLog::where('project_id', $project->id)
                    ->where('field_name', 'hourly_rate')
                    ->whereDate('created_at', now()->toDateString())
                    ->orderBy('id')->get();
                if ($todayLogs->isNotEmpty()) {
                    $survivor = $todayLogs->first();
                    $survivor->new_value = (string) $project->hourly_rate;
                    $survivor->effective_from = $eff;
                    $survivor->save();
                    $dups = $todayLogs->slice(1)->pluck('id');
                    if ($dups->isNotEmpty()) {
                        \App\Models\ProjectChangeLog::whereIn('id', $dups)->delete();
                    }
                }
            }
            // Mensal: valor do contrato = horas × valor-hora — atualiza junto (só se tem horas).
            if ($project->isBankHoursMonthly() && (float) ($project->sold_hours ?? 0) > 0) {
                $project->update(['project_value' => round((float) $project->sold_hours * (float) ($project->hourly_rate ?? 0), 2)]);
            }
            return;
        }

        if ($field === 'horas_contratadas') {
            $previous = (float) ($project->sold_hours ?? 0);
            $new      = (float) $value;
            $project->update(['sold_hours' => $new]);
            if ($previous !== $new && $project->isBankHoursMonthly()) {
                if ($project->soldHoursHistory()->count() === 0 && $project->start_date) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $previous,
                        'effective_from' => \Carbon\Carbon::parse($project->start_date)->startOfMonth()->toDateString(),
                        'changed_by'     => null,
                    ]);
                }
                $exists = $project->soldHoursHistory()->where('effective_from', $eff)->exists();
                if (!$exists) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $new,
                        'effective_from' => $eff,
                        'changed_by'     => auth()->id(),
                    ]);
                } else {
                    $project->soldHoursHistory()->where('effective_from', $eff)
                        ->update(['sold_hours' => $new, 'changed_by' => auth()->id()]);
                }
                // Recalcula o acumulado DEPOIS de gravar a vigência (evita stale).
                $project->updateAccumulatedSoldHours(null, true);
                // Valor do contrato = horas × valor-hora — atualiza junto (só se tem rate,
                // pra não ZERAR o project_value de projetos sem valor-hora configurado).
                if ((float) ($project->hourly_rate ?? 0) > 0) {
                    $project->update(['project_value' => round($new * (float) $project->hourly_rate, 2)]);
                }
            }
            return;
        }

        if ($field === 'valor_projeto') {
            // Cloud: valor do contrato, sem vigência mensal (Observer loga a mudança).
            $project->update(['project_value' => $value]);
        }
    }

    private function notifyContractCreated(Contract $contract): void
    {
        try {
            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.created', [
                'contract' => $contract,
                'actor'    => $contract->created_by_id ? \App\Models\User::find($contract->created_by_id) : null,
            ]);
            if (empty($rcpt['to'])) return;
            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify((new \App\Notifications\ContractCreatedNotification($contract))->withCc($rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('ContractCreated notification falhou', [
                'contract_id' => $contract->id,
                'err'         => $e->getMessage(),
            ]);
        }
    }

    private function notifyInicioAutorizado(Contract $contract): void
    {
        try {
            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.inicio_autorizado', [
                'contract' => $contract,
            ]);
            if (empty($rcpt['to'])) return;
            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify((new \App\Notifications\ContractInicioAutorizadoNotification($contract))->withCc($rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('ContractInicioAutorizado notification falhou', [
                'contract_id' => $contract->id,
                'err'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Diretor de Projetos — recebe e-mail em TODA fase pós-Novo Contrato
     * (Início Autorizado, Projeto Gerado). Definido pela flag is_diretor_projetos
     * no cadastro do usuário (configurável). Em Novo Contrato só recebe se for o
     * executivo do cliente.
     */
    private function projectDirectorUserId(): ?int
    {
        $id = \App\Models\User::query()
            ->where('is_diretor_projetos', true)
            ->where('enabled', true)
            ->value('id');
        return $id ? (int) $id : null;
    }

    public function show(Contract $contract): JsonResponse
    {
        $contract->load(['customer:id,name', 'serviceType:id,name', 'contractType:id,name', 'architect:id,name', 'executivoConta:id,name', 'vendedor:id,name', 'contacts', 'attachments', 'project:id,code,name,status', 'aditivoProject:id,code,name']);

        // Flag p/ legenda verde "Gerou aporte automático": subprojeto faturado gera um aporte
        // no pai (ContractController@store). Vínculo pelo CÓDIGO do subprojeto na descrição.
        $contract->generated_aporte = null;
        if ($contract->parent_project_id && $contract->project_code_preview) {
            $ap = \App\Models\HourContribution::where('project_id', $contract->parent_project_id)
                ->where('description', 'ilike', '%ref. subprojeto faturado%(' . $contract->project_code_preview . '%')
                ->orderByDesc('id')->first(['id', 'project_id']);
            if ($ap) {
                $contract->generated_aporte = ['id' => $ap->id, 'parent_id' => $ap->project_id];
            }
        }

        return response()->json($contract);
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        if ($contract->project_id) {
            return response()->json(['message' => 'Contrato com projeto gerado não pode ser editado.'], 422);
        }

        $validated = $request->validate([
            'customer_id'            => 'sometimes|exists:customers,id',
            'project_name'           => 'nullable|string|max:255',
            'parent_project_id'      => 'nullable|exists:projects,id',
            'categoria'              => 'sometimes|in:projeto,sustentacao',
            'service_type_id'        => 'nullable|exists:service_types,id',
            'contract_type_id'       => 'nullable|exists:contract_types,id',
            'tipo_faturamento'       => 'nullable|in:on_demand,banco_horas_mensal,banco_horas_fixo,por_servico,saas',
            'cobra_despesa_cliente'  => 'boolean',
            'limite_despesa'         => 'nullable|numeric|min:0',
            'architect_id'           => 'nullable|exists:users,id',
            'tipo_alocacao'          => 'nullable|in:remoto,presencial,ambos',
            'horas_contratadas'      => 'sometimes|integer|min:0',
            'valor_projeto'          => 'nullable|numeric|min:0',
            'valor_hora'             => 'nullable|numeric|min:0',
            'hora_adicional'         => 'nullable|numeric|min:0',
            'pct_horas_coordenador'  => 'nullable|numeric|min:0|max:100',
            'horas_coordenacao'      => 'nullable|numeric|min:0|max:999999',
            'horas_consultor'        => 'nullable|integer|min:0',
            'expectativa_inicio'     => 'nullable|date',
            'condicao_pagamento'     => 'nullable|string',
            'executivo_conta_id'     => 'nullable|exists:users,id',
            'vendedor_id'            => 'nullable|exists:users,id',
            'observacoes'            => 'nullable|string',
            'project_code_preview'   => 'nullable|string|max:20',
            'contacts'               => 'nullable|array',
            'contacts.*.id'          => 'nullable|exists:contract_contacts,id',
            'contacts.*.name'        => 'required|string',
            'contacts.*.cargo'       => 'nullable|string',
            'contacts.*.email'       => 'nullable|email',
            'contacts.*.phone'       => 'nullable|string',
        ]);

        DB::transaction(function () use ($contract, $validated) {
            $contract->update(collect($validated)->except('contacts')->toArray());

            if (array_key_exists('contacts', $validated)) {
                $contract->contacts()->delete();
                foreach ($validated['contacts'] ?? [] as $c) {
                    ContractContact::create(array_merge($c, ['contract_id' => $contract->id]));
                }
                // Espelha no cadastro da empresa (upsert; nunca deleta do cadastro).
                $this->syncContactsToCustomerRegistry((int) $contract->customer_id, $validated['contacts'] ?? []);
            }
        });

        return response()->json($contract->fresh()->load(['customer:id,name', 'contacts', 'attachments']));
    }

    /**
     * Edita um ADITIVO. Para aditivo Mensal "multiplo" (valor-hora + horas), reaplica os
     * novos valores no projeto SOBRESCREVENDO a vigência do mês e recalculando o valor do
     * contrato — mas SÓ se for o aditivo MAIS RECENTE do projeto (editar um antigo bagunçaria
     * a vigência). Os demais campos (forma de pagamento / observação) sempre editáveis.
     */
    public function updateAditivo(Request $request, Contract $contract): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }
        if (!$contract->is_aditivo) {
            return response()->json(['message' => 'Não é um aditivo.'], 422);
        }
        $validated = $request->validate([
            'aditivo_changes'         => 'nullable|array',
            'aditivo_changes.*.field' => 'required_with:aditivo_changes|in:valor_hora,horas_contratadas',
            'aditivo_changes.*.value' => 'required_with:aditivo_changes|numeric|min:0',
            'condicao_pagamento'      => 'nullable|string',
            'observacoes'             => 'nullable|string',
        ]);

        $reapply = !empty($validated['aditivo_changes']) && $contract->aditivo_field === 'multiplo';

        if ($reapply) {
            $project = Project::with('contractType')->find($contract->aditivo_project_id);
            if (!$project || strtolower($project->contractType->code ?? '') !== 'monthly_hours') {
                return response()->json(['message' => 'Reaplicação só para Banco de Horas Mensal.'], 422);
            }
            // Só o aditivo MAIS RECENTE do projeto pode reaplicar.
            $isLatest = Contract::where('aditivo_project_id', $project->id)
                ->where('is_aditivo', true)->where('id', '>', $contract->id)->doesntExist();
            if (!$isLatest) {
                return response()->json(['message' => 'Só o aditivo mais recente do projeto pode ser editado. Crie um novo aditivo.'], 422);
            }

            $eff = $contract->aditivo_effective_from
                ? \Carbon\Carbon::parse($contract->aditivo_effective_from)->startOfMonth()->toDateString()
                : \Carbon\Carbon::now()->startOfMonth()->toDateString();

            $newRate = null; $newHoras = null;
            foreach ($validated['aditivo_changes'] as $ch) {
                if ($ch['field'] === 'valor_hora') $newRate = (float) $ch['value'];
                if ($ch['field'] === 'horas_contratadas') $newHoras = (float) $ch['value'];
            }
            $newRate  ??= (float) ($contract->valor_hora ?? $project->hourly_rate ?? 0);
            $newHoras ??= (float) ($contract->horas_contratadas ?? $project->sold_hours ?? 0);

            DB::transaction(function () use ($contract, $project, $eff, $newRate, $newHoras, $validated) {
                // valor-hora: sobrescreve a vigência do mês (por effective_from), não por dia.
                $project->update(['hourly_rate' => $newRate]);
                $log = \App\Models\ProjectChangeLog::where('project_id', $project->id)
                    ->where('field_name', 'hourly_rate')->where('effective_from', $eff)->first();
                if ($log) { $log->update(['new_value' => (string) $newRate]); }

                // horas: sobrescreve a vigência do mês.
                $project->update(['sold_hours' => $newHoras]);
                $h = $project->soldHoursHistory()->where('effective_from', $eff)->first();
                if ($h) { $h->update(['sold_hours' => $newHoras, 'changed_by' => auth()->id()]); }
                $project->updateAccumulatedSoldHours(null, true);

                // valor do contrato recomputado.
                $newValue = round($newHoras * $newRate, 2);
                $project->update(['project_value' => $newValue]);

                // atualiza o registro do aditivo (preserva os .old originais do breakdown).
                $changes = array_map(function ($c) use ($newRate, $newHoras) {
                    $c['new'] = $c['field'] === 'valor_hora' ? $newRate : $newHoras;
                    return $c;
                }, $contract->aditivo_changes ?? []);

                $contract->update([
                    'valor_hora'         => $newRate,
                    'horas_contratadas'  => (int) $newHoras,
                    'valor_projeto'      => $newValue,
                    'aditivo_changes'    => $changes,
                    'condicao_pagamento' => $validated['condicao_pagamento'] ?? $contract->condicao_pagamento,
                    'observacoes'        => $validated['observacoes'] ?? $contract->observacoes,
                ]);
            });
        } else {
            $contract->update([
                'condicao_pagamento' => $validated['condicao_pagamento'] ?? $contract->condicao_pagamento,
                'observacoes'        => $validated['observacoes'] ?? $contract->observacoes,
            ]);
        }

        return response()->json($contract->fresh()->load(['customer:id,name', 'aditivoProject:id,name,code']));
    }

    public function destroy(Contract $contract): JsonResponse
    {
        // Aditivo: excluir REVERTE a alteração no projeto (só o aditivo mais recente).
        if ($contract->is_aditivo) {
            return $this->destroyAditivo($contract);
        }

        if ($contract->project_id) {
            if (Expense::where('project_id', $contract->project_id)->exists()) {
                return response()->json(['message' => 'Contrato com despesas registradas não pode ser excluído.'], 422);
            }
            if (Timesheet::where('project_id', $contract->project_id)->exists()) {
                return response()->json(['message' => 'Contrato com apontamentos registrados não pode ser excluído.'], 422);
            }
        }

        // FASE 11.7 (PR 7b) — soft-delete dos anexos via camada Attachment.
        // Arquivo físico mantido (SoftDeletes na row em `attachments`); restore possível.
        foreach ($contract->attachments as $att) {
            $att->delete();
        }

        $contract->delete();
        return response()->json(null, 204);
    }

    /**
     * Exclui um ADITIVO REVERTENDO a alteração no projeto (valor-hora/horas/valor do contrato
     * voltam ao "antes" e a vigência daquele mês é removida). Só o aditivo MAIS RECENTE do
     * projeto pode ser excluído — reverter um antigo (com outros depois) bagunçaria a vigência.
     */
    private function destroyAditivo(Contract $contract): JsonResponse
    {
        $isLatest = Contract::where('aditivo_project_id', $contract->aditivo_project_id)
            ->where('is_aditivo', true)->where('id', '>', $contract->id)->doesntExist();
        if (!$isLatest) {
            return response()->json([
                'message' => 'Só o aditivo MAIS RECENTE do projeto pode ser excluído (a exclusão reverte a alteração). Exclua os mais novos primeiro.',
            ], 422);
        }

        $project = Project::with('contractType')->find($contract->aditivo_project_id);

        DB::transaction(function () use ($contract, $project) {
            if ($project) {
                $eff = $contract->aditivo_effective_from
                    ? \Carbon\Carbon::parse($contract->aditivo_effective_from)->startOfMonth()->toDateString()
                    : null;
                $field   = $contract->aditivo_field;
                $changes = collect($contract->aditivo_changes ?? []);

                if ($field === 'multiplo' && $changes->isNotEmpty()) {
                    $oldRate  = (float) ($changes->firstWhere('field', 'valor_hora')['old'] ?? $project->hourly_rate);
                    $oldHoras = (float) ($changes->firstWhere('field', 'horas_contratadas')['old'] ?? $project->sold_hours);
                    if ($eff) {
                        \App\Models\ProjectChangeLog::where('project_id', $project->id)->where('field_name', 'hourly_rate')->where('effective_from', $eff)->delete();
                        $project->soldHoursHistory()->where('effective_from', $eff)->delete();
                    }
                    $project->update(['hourly_rate' => $oldRate, 'sold_hours' => $oldHoras, 'project_value' => round($oldRate * $oldHoras, 2)]);
                    $project->updateAccumulatedSoldHours(null, true);
                } elseif ($field === 'valor_hora') {
                    $old = (float) ($contract->aditivo_old_value ?? $project->hourly_rate);
                    if ($eff) \App\Models\ProjectChangeLog::where('project_id', $project->id)->where('field_name', 'hourly_rate')->where('effective_from', $eff)->delete();
                    $project->update(['hourly_rate' => $old]);
                    if ($project->isBankHoursMonthly() && (float) $project->sold_hours > 0) {
                        $project->update(['project_value' => round((float) $project->sold_hours * $old, 2)]);
                    }
                } elseif ($field === 'horas_contratadas') {
                    $old = (float) ($contract->aditivo_old_value ?? $project->sold_hours);
                    if ($eff) $project->soldHoursHistory()->where('effective_from', $eff)->delete();
                    $project->update(['sold_hours' => $old]);
                    $project->updateAccumulatedSoldHours(null, true);
                    if ($project->isBankHoursMonthly() && (float) $project->hourly_rate > 0) {
                        $project->update(['project_value' => round($old * (float) $project->hourly_rate, 2)]);
                    }
                } elseif ($field === 'valor_projeto') {
                    $project->update(['project_value' => (float) ($contract->aditivo_old_value ?? $project->project_value)]);
                }
            }

            foreach ($contract->attachments as $att) {
                $att->delete();
            }
            $contract->delete();
        });

        return response()->json(null, 204);
    }

    public function updateStatus(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['status' => 'required|in:rascunho,aprovado,inicio_autorizado,ativo']);

        $newStatus = $request->input('status');

        if ($newStatus === Contract::STATUS_APROVADO && $contract->status !== Contract::STATUS_RASCUNHO) {
            return response()->json(['message' => 'Apenas contratos em Rascunho podem ser aprovados.'], 422);
        }
        if ($newStatus === Contract::STATUS_INICIO_AUTORIZADO && !in_array($contract->status, [Contract::STATUS_APROVADO, Contract::STATUS_RASCUNHO])) {
            return response()->json(['message' => 'Apenas contratos Aprovados podem ter início autorizado.'], 422);
        }

        $data = ['status' => $newStatus];

        if ($newStatus === Contract::STATUS_APROVADO) {
            $data['approved_by_id'] = auth()->id();
            $data['approved_at']    = now();
        }

        $contract->update($data);
        return response()->json($contract->fresh());
    }

    public function generateProject(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'coordinator_ids'   => 'nullable|array|max:1',
            'coordinator_ids.*' => 'integer|exists:users,id',
        ]);

        if (!in_array($contract->status, [Contract::STATUS_INICIO_AUTORIZADO, Contract::STATUS_APROVADO])) {
            return response()->json(['message' => 'Contrato precisa estar Aprovado ou com Início Autorizado.'], 422);
        }

        if ($contract->project_id) {
            return response()->json(['message' => 'Projeto já gerado para este contrato.', 'project_id' => $contract->project_id], 422);
        }

        $contract->load(['customer', 'contacts', 'attachments']);

        $coordinatorIds = $request->input('coordinator_ids', []);

        $project = DB::transaction(function () use ($contract, $coordinatorIds) {
            $codeService = new ProjectCodeService();
            $parentProject = $contract->parent_project_id ? Project::find($contract->parent_project_id) : null;
            $codeData    = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);

            $projectName = $contract->project_name
                ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

            $project = Project::create(array_merge($codeData, [
                'name'                  => $projectName,
                'parent_project_id'     => $contract->parent_project_id,
                'customer_id'           => $contract->customer_id,
                'service_type_id'       => $contract->service_type_id,
                'contract_type_id'      => $contract->contract_type_id,
                'sold_hours'            => $contract->horas_contratadas,
                'project_value'         => $contract->valor_projeto,
                'hourly_rate'           => $contract->resolvedHourlyRate(),
                'additional_hourly_rate' => $contract->hora_adicional,
                'coordinator_hours'     => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
                'coordination_hours'    => $contract->horas_coordenacao,
                'consultant_hours'      => $contract->horas_consultor,
                'start_date'            => $contract->expectativa_inicio,
                'status'                => Project::STATUS_AWAITING_START,
                'contract_id'           => $contract->id,
                'tipo_alocacao'         => $contract->tipo_alocacao,
                'architect_id'          => $contract->architect_id,
                'condicao_pagamento'    => $contract->condicao_pagamento,
                'observacoes_contrato'  => $contract->observacoes,
                'cobra_despesa_cliente' => $contract->cobra_despesa_cliente,
                'limite_despesa'        => $contract->limite_despesa,
                'executivo_conta_id'    => $contract->executivo_conta_id,
                'vendedor_id'           => $contract->vendedor_id,
            ]));

            // Auto-ativação da integração Movidesk para projetos de SUSTENTAÇÃO:
            // se o cliente ainda não tem nenhum projeto flagado, ativa neste
            // recém-criado (respeita a regra "máx 1 projeto flagado por cliente").
            $contract->loadMissing('serviceType');
            $svcCode = $contract->serviceType?->code;
            $svcName = strtolower(trim((string) $contract->serviceType?->name));
            $isSustentacao = $contract->categoria === 'sustentacao'
                || $svcCode === 'sustentacao'
                || str_contains($svcName, 'sustenta');
            if ($isSustentacao) {
                $hasFlagged = Project::where('customer_id', $project->customer_id)
                    ->where('id', '!=', $project->id)
                    ->where('movidesk_integration_enabled', true)
                    ->exists();
                if (!$hasFlagged) {
                    $project->update(['movidesk_integration_enabled' => true]);
                }
            }

            // Copiar contatos
            foreach ($contract->contacts as $c) {
                ProjectContact::create([
                    'project_id'          => $project->id,
                    'contract_contact_id' => $c->id,
                    'name'                => $c->name,
                    'cargo'               => $c->cargo,
                    'email'               => $c->email,
                    'phone'               => $c->phone,
                ]);
            }

            // FASE 11.7 (PR 7b) — anexos do contrato aparecem no projeto via
            // ProjectController::listAttachments (que une PROJECT + CONTRACT
            // do contract_id vinculado). Não precisa mais row de "shadow".

            // Vincular coordenadores: usa os selecionados no modal; fallback para o arquiteto do contrato
            if (empty($coordinatorIds) && $contract->architect_id) {
                $coordinatorIds = [$contract->architect_id];
            }
            if (!empty($coordinatorIds)) {
                $project->coordinators()->attach($coordinatorIds);
            }

            // Atualizar contrato
            $contract->update([
                'project_id'      => $project->id,
                'generated_at'    => now(),
                'generated_by_id' => auth()->id(),
                'status'          => Contract::STATUS_ATIVO,
            ]);

            return $project;
        });

        // Notifica executivo da conta + coordenadores atribuídos + contatos do cliente.
        $this->notifyProjectGenerated($contract->fresh(['customer', 'contacts']), $project, $coordinatorIds);

        return response()->json([
            'project_id'   => $project->id,
            'project_code' => $project->code,
            'message'      => 'Projeto gerado com sucesso.',
        ]);
    }

    private function notifyProjectGenerated(Contract $contract, Project $project, array $coordinatorIds): void
    {
        try {
            // Coordenadores selecionados no modal podem ainda não estar na relação
            // do projeto — garante que o resolver (audiência coordenador) os enxergue.
            if (!empty($coordinatorIds)) {
                $project->setRelation('coordinators', \App\Models\User::whereIn('id', $coordinatorIds)->where('enabled', true)->get());
            }

            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.project_generated', [
                'contract' => $contract,
                'project'  => $project,
            ]);
            if (empty($rcpt['to'])) return;
            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify((new \App\Notifications\ProjectFromContractGeneratedNotification($contract, $project))->withCc($rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('ProjectFromContractGenerated notification falhou', [
                'contract_id' => $contract->id,
                'project_id'  => $project->id,
                'err'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Modelo 2 — coordenador (re)atribuído num projeto JÁ gerado. Notifica
     * SÓ o coordenador da coluna (escopo via setRelation), pelo workflow
     * configurável `project.coordinator_assigned` na Central.
     */
    private function notifyCoordinatorAssigned(?Contract $contract, int $coordinatorId): void
    {
        if (!$contract || !$contract->project_id) return;
        try {
            $project = \App\Models\Project::find($contract->project_id);
            if (!$project) return;

            $project->setRelation('coordinators', \App\Models\User::whereIn('id', [$coordinatorId])->where('enabled', true)->get());

            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('project.coordinator_assigned', [
                'contract' => $contract,
                'project'  => $project,
            ]);
            if (empty($rcpt['to'])) return;
            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify((new \App\Notifications\ProjectCoordinatorAssignedNotification($contract, $project))->withCc($rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('ProjectCoordinatorAssigned notification falhou', [
                'contract_id'    => $contract->id,
                'coordinator_id' => $coordinatorId,
                'err'            => $e->getMessage(),
            ]);
        }
    }

    /**
     * Workflows de fase terminal do projeto (Fechado/Cancelado/Pausado),
     * configuráveis na Central (project.finished / cancelled / paused).
     */
    private function notifyProjectPhase(\App\Models\Project $project, string $workflowKey): void
    {
        try {
            $project->loadMissing('customer', 'coordinators');
            $contract = $project->contract_id ? \App\Models\Contract::find($project->contract_id) : null;

            $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($workflowKey, [
                'project'  => $project,
                'contract' => $contract,
                'customer' => $project->customer,
                'actor'    => auth()->user(),
            ]);
            if (empty($rcpt['to'])) return;
            \Illuminate\Support\Facades\Notification::route('mail', $rcpt['to'])
                ->notify((new \App\Notifications\ProjectPhaseChangedNotification($project, $workflowKey))->withCc($rcpt['cc']));
        } catch (\Throwable $e) {
            \Log::warning('ProjectPhase notification falhou', [
                'project_id'   => $project->id,
                'workflow_key' => $workflowKey,
                'err'          => $e->getMessage(),
            ]);
        }
    }

    public function uploadAttachment(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,txt,csv,zip',
            'type' => 'required|in:proposta,contrato,logo,aprovacao_cliente',
        ]);

        $file = $request->file('file');
        $path = $file->store("contracts/{$contract->id}/attachments");

        // FASE 11.7 (PR 7b) — persistência 100% na camada Attachment.
        $attachment = app(\App\Attachments\AttachmentService::class)->registerExisting(auth()->user(), [
            'entity_type'   => 'CONTRACT',
            'entity_id'     => $contract->id,
            'category'      => self::mapAttachmentTypeToCategory($request->input('type')),
            'storage_path'  => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
            'metadata'      => ['legacy_type' => $request->input('type')],
        ]);

        // Subprojeto faturado: a proposta/aprovação do contrato também alimenta o APORTE
        // gerado no pai (mantém "no filho e no aporte" mesmo antes do projeto-filho existir).
        try {
            if (
                in_array($request->input('type'), ['proposta', 'aprovacao_cliente'], true)
                && $contract->parent_project_id && $contract->project_code_preview
            ) {
                $aporte = \App\Models\HourContribution::where('project_id', $contract->parent_project_id)
                    ->where('description', 'ilike', '%ref. subprojeto faturado%(' . $contract->project_code_preview . '%')
                    ->orderByDesc('id')->first();
                if ($aporte) {
                    app(\App\Attachments\AttachmentService::class)->registerExisting(auth()->user(), [
                        'entity_type'   => 'HOUR_CONTRIBUTION',
                        'entity_id'     => $aporte->id,
                        'category'      => 'proposal',
                        'storage_path'  => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
                        'metadata'      => ['mirrored' => true, 'from' => 'contract', 'contract_id' => $contract->id],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('mirror proposta contrato->aporte falhou', ['contract_id' => $contract->id, 'err' => $e->getMessage()]);
        }

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Contract $contract, \App\Models\Attachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // FASE 11.7 (PR 7b) — valida vínculo polimórfico.
        abort_if(
            $attachment->entity_type !== 'CONTRACT' || (int) $attachment->entity_id !== (int) $contract->id,
            404
        );
        abort_unless(Storage::exists($attachment->storage_path), 404, 'Arquivo não encontrado.');

        return Storage::download($attachment->storage_path, $attachment->original_name);
    }

    public function deleteAttachment(Contract $contract, \App\Models\Attachment $attachment): JsonResponse
    {
        abort_if(
            $attachment->entity_type !== 'CONTRACT' || (int) $attachment->entity_id !== (int) $contract->id,
            404
        );

        // FASE 11.7 (PR 7b) — SoftDeletes; arquivo físico preservado pra recovery.
        $attachment->delete();

        return response()->json(null, 204);
    }

    // ─── Kanban Unificado ────────────────────────────────────────────────────

    public function kanban(Request $request): JsonResponse
    {
        $user      = auth()->user();
        $isConsultor = $user?->isConsultor();
        $isCliente   = $user?->isCliente();

        // ── Fase Demanda: contratos NÃO-sustentação (admin/coordenador vê todos; cliente vê subset)
        $demandCards = collect();
        if (!$isConsultor) {
            $demandQuery = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'kanbanCoordinator:id,name',
                'executivoConta:id,name',
                'project:id,code,name,status',
                'aditivoProject:id,code,name,hourly_rate,sold_hours,contract_type_id',
                'aditivoProject.contractType:id,code',
            ])->where(function ($q) {
                $q->whereIn('kanban_status', array_merge(Contract::DEMAND_COLUMNS, [Contract::KANBAN_INICIO_AUTORIZADO, Contract::KANBAN_ALOCADO, Contract::KANBAN_ADITIVO, 'novo', 'novo_contrato']))
                  ->orWhereNull('kanban_status');
              })
              ->whereNull('sustentacao_column')
              ->orderBy('kanban_order');

            if ($isCliente && $user->customer_id) {
                $demandQuery->where('customer_id', $user->customer_id);
            }

            $demandCards = $demandQuery->get()->map(fn($c) => $this->formatKanbanCard($c));
        }

        // ── Fase Transição: contratos status=inicio_autorizado sem projeto gerado
        $transitionCards = collect();
        if (!$isConsultor && !$isCliente) {
            $transitionCards = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'project:id,code,name,status',
            ])->where('status', Contract::STATUS_INICIO_AUTORIZADO)
              ->whereNull('project_id')
              ->orderBy('kanban_order')
              ->get()
              ->map(fn($c) => $this->formatKanbanCard($c));
        }

        // ── Fase Projeto: projetos gerados a partir de contratos
        // Inclui também projetos referenciados por contract.project_id (contratos 'alocado')
        $demandProjectIds = $demandCards->pluck('project_id')->filter()->unique()->values()->toArray();

        $projectQuery = \App\Models\Project::with([
            'customer:id,name,executive_id',
            'customer.executive:id,name',
            'contract:id,project_name',
            'coordinators:id,name',
            'consultants:id,name',
            'contractType:id,name,code',
            'serviceType:id,name,code',
            'executivoConta:id,name',
            'kanbanLogs:id,project_id,to_status,created_at',
        ])->where(function ($q) use ($demandProjectIds) {
            $q->where(function ($inner) {
                $inner->whereNotNull('contract_id')
                      ->whereHas('contract', fn($c) => $c->whereNull('sustentacao_column'));
            });
            if (!empty($demandProjectIds)) {
                $q->orWhereIn('id', $demandProjectIds);
            }
            // Projetos importados diretamente (sem contract_id):
            //  - com coordenador vinculado (fila de um coordenador de projeto), ou
            //  - de sustentação (entram sempre: vão p/ a coluna de sustentação do seu tipo,
            //    ou p/ a fila do coordenador quando há override) — exceto encerrados/pausados.
            $q->orWhere(function ($inner) {
                $inner->whereNull('contract_id')
                      ->whereHas('coordinators');
            });
            $q->orWhere(function ($inner) {
                $inner->whereNull('contract_id')
                      ->whereNotIn('status', ['finished', 'cancelled', 'paused'])
                      ->whereHas('serviceType', fn($sq) =>
                          // 'sustent' (não 'sustentac') p/ casar 'Sustentação' com cedilha
                          $sq->whereRaw("LOWER(name) SIMILAR TO '%(sustent|cloud|bizify)%'"));
            });
        })->orderBy('updated_at', 'desc');

        if ($isConsultor) {
            $projectQuery->whereHas('consultants', fn($q) => $q->where('users.id', $user->id));
        } elseif ($isCliente && $user->customer_id) {
            $projectQuery->where('customer_id', $user->customer_id);
        } elseif ($user?->isCoordenador()) {
            $projectQuery->where(function ($q) {
                $q->whereDoesntHave('serviceType')
                  ->orWhereHas('serviceType', fn($sq) => $sq->whereRaw("LOWER(name) NOT SIMILAR TO '%(sustentac|cloud)%'"));
            });
        }

        $projects    = $projectQuery->get();
        $projectIds  = $projects->pluck('id')->toArray();
        $timesheetSums = count($projectIds) > 0
            ? DB::table('timesheets')
                ->whereIn('project_id', $projectIds)
                ->where('status', '!=', 'rejected')
                ->groupBy('project_id')
                ->selectRaw('project_id, SUM(effort_minutes) as total_minutes')
                ->pluck('total_minutes', 'project_id')
                ->toArray()
            : [];

        // Regra de negócio: um projeto de SUSTENTAÇÃO só fica na fila de um coordenador
        // de projeto quando o override (kanban_coordinator_override_id) está preenchido.
        // Sem override, ele pertence à coluna de sustentação do seu tipo de contrato —
        // tenha ou não um Contract gerado. Particiona aqui; o merge nas colunas de
        // sustentação acontece logo abaixo (bloco $sustentacaoGroups).
        $projectCards         = collect();
        $sustProjectsByColumn = [];
        foreach ($projects as $p) {
            $logged  = (float) ($timesheetSums[$p->id] ?? 0);
            $sustCol = (!$isConsultor && !$isCliente) ? $this->sustColumnForProject($p) : null;
            if ($sustCol && $p->kanban_coordinator_override_id === null) {
                $formatted = $this->formatProjectCard($p, $logged);
                $formatted['sustentacao_column'] = $sustCol;
                $sustProjectsByColumn[$sustCol][] = $formatted;
            } else {
                $projectCards->push($this->formatProjectCard($p, $logged));
            }
        }

        // ── Coordenadores que viram COLUNA no kanban (arrastar contrato → gera projeto).
        // Inclui:
        //  - coordenadores com coordinator_type=projetos
        //  - coordenadores de sustentação (podem coordenar projetos pequenos pontualmente;
        //    o FE colore a coluna deles de laranja pra diferenciar — coordinator_type volta
        //    no payload). A sustentação continua com suas colunas de fila próprias.
        //  - admins definidos em algum projeto via project_coordinators (M2M)
        //  - usuários referenciados como kanban_coordinator_override_id em algum projeto
        $coordinators = User::where('enabled', true)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('type', 'coordenador')
                          ->whereIn('coordinator_type', ['projetos', 'sustentacao']);
                })->orWhere(function ($inner) {
                    $inner->where('type', 'admin')
                          ->whereHas('coordinatorProjects');
                })->orWhereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('projects')
                        ->whereColumn('projects.kanban_coordinator_override_id', 'users.id')
                        ->whereNull('projects.deleted_at');
                });
            })
            ->select('id', 'name', 'coordinator_type')
            ->orderBy('name')
            ->get();

        // ── Sustentação / Cloud / Bizify — colunas agrupadas
        $sustentacaoGroups = [
            'sust_bh_fixo'   => [],
            'sust_bh_mensal' => [],
            'sust_on_demand' => [],
            'sust_cloud'     => [],
            'sust_bizify'    => [],
        ];
        $sustentacaoAutoCards = collect(); // backward compat
        if (!$isConsultor && !$isCliente) {
            // Todos contratos alocados numa fila de sustentação aparecem na coluna correta
            $sustCards = Contract::with([
                'customer:id,name,executive_id',
                'customer.executive:id,name',
                'contractType:id,name',
                'serviceType:id,name',
                'executivoConta:id,name',
                'project.customer:id,name,executive_id',
                'project.customer.executive:id,name',
                'project.executivoConta:id,name',
                'project.coordinators',
                'project.consultants',
                'project.contractType:id,name',
                'project.serviceType:id,name',
            ])
            ->whereNotNull('sustentacao_column')
            ->orderBy('kanban_order')
            ->get();

            foreach ($sustCards as $c) {
                $col = $c->sustentacao_column;
                if (!$col) {
                    $svcCode      = $c->serviceType?->code ?? '';
                    $svcName      = strtolower($c->serviceType?->name ?? '');
                    $contractName = strtolower($c->contractType?->name ?? '');
                    if ($svcCode === 'bizify' || str_contains($svcName, 'bizify')) {
                        $col = 'sust_bizify';
                    } elseif (str_contains($svcName, 'cloud')) {
                        $col = 'sust_cloud';
                    } elseif ($c->tipo_faturamento === 'banco_horas_mensal') {
                        $col = 'sust_bh_mensal';
                    } elseif ($c->tipo_faturamento === 'on_demand') {
                        $col = 'sust_on_demand';
                    } else {
                        $col = 'sust_bh_fixo';
                    }
                }
                if ($c->project_id && $c->project) {
                    $formatted = $this->formatProjectCard($c->project);
                    $formatted['kanban_order'] = $c->kanban_order;
                } else {
                    $formatted = $this->formatKanbanCard($c);
                }
                $formatted['sustentacao_column'] = $col;
                $sustentacaoGroups[$col][] = $formatted;
                $sustentacaoAutoCards[] = $formatted;
            }

            // Projetos de sustentação sem override (incl. sem contrato) — colados na
            // coluna do seu tipo de contrato (regra: override vazio ⇒ fila de sustentação).
            foreach ($sustProjectsByColumn as $col => $cards) {
                foreach ($cards as $fc) {
                    $sustentacaoGroups[$col][] = $fc;
                    $sustentacaoAutoCards[] = $fc;
                }
            }
        }

        // ── Requisições pendentes (contract_requests sem contrato gerado)
        $requestCards = collect();
        if (!$isConsultor) {
            $reqQuery = \App\Models\ContractRequest::with(['customer:id,name', 'createdBy:id,name', 'linkedContract:id,project_id,project_name', 'linkedContract.project:id,code'])
                ->where(function ($q) {
                    $q->whereNull('contract_id')
                      ->orWhereIn('kanban_column', ['req_planejamento', 'req_inicio_autorizado', 'inicio_autorizado', 'req_em_andamento']);
                })
                ->whereIn('status', [\App\Models\ContractRequest::STATUS_PENDENTE, \App\Models\ContractRequest::STATUS_EM_ANALISE, \App\Models\ContractRequest::STATUS_APROVADO]);

            if ($isCliente && $user->customer_id) {
                $reqQuery->where('customer_id', $user->customer_id);
            }

            $requestCards = $reqQuery->orderBy('created_at', 'desc')->get()->map(fn($r) => [
                'card_type'              => 'request',
                'id'                     => $r->id,
                'customer_name'          => $r->customer?->name ?? '—',
                'customer_id'            => $r->customer_id,
                'area_requisitante'      => $r->area_requisitante,
                'project_name'           => $r->project_name,
                'product_owner'          => $r->product_owner,
                'modulo_tecnologia'      => $r->modulo_tecnologia,
                'tipo_necessidade'       => $r->tipo_necessidade,
                'tipo_necessidade_outro' => $r->tipo_necessidade_outro,
                'nivel_urgencia'         => $r->nivel_urgencia,
                'descricao'              => $r->descricao,
                'cenario_atual'          => $r->cenario_atual,
                'cenario_desejado'       => $r->cenario_desejado,
                'status'                 => $r->status,
                'kanban_column'          => $r->kanban_column ?? 'backlog',
                'req_decision'           => $r->req_decision,
                'linked_contract_id'     => $r->linked_contract_id,
                'linked_contract_code'   => $r->linkedContract?->project?->code,
                'linked_coordinator_id'  => $r->linked_coordinator_id,
                'created_at'             => $r->created_at?->toISOString(),
            ]);
        }

        // ── Coluna Aporte: lê hour_contributions e renderiza apenas as cujo destino
        // é um projeto PAI (parent_project_id IS NULL). Aportes em filhos continuam
        // existindo na tabela mas não viram card — consomem do saldo do pai como hoje.
        // Cliente vê apenas os próprios; consultor não tem coluna de aporte (lista vazia).
        $aporteCards = collect();
        if (!$isConsultor) {
            $aporteQuery = \App\Models\HourContribution::with([
                    'project:id,code,name,customer_id,parent_project_id,status',
                    'project.customer:id,name',
                    'contributedBy:id,name',
                ])
                ->whereHas('project', fn($q) => $q->whereNull('parent_project_id'))
                ->orderByDesc('contributed_at');

            if ($isCliente && $user->customer_id) {
                $aporteQuery->whereHas('project', fn($q) => $q->where('customer_id', $user->customer_id));
            }

            // FASE 11.7 — proposta agora vive na camada Attachment (HOUR_CONTRIBUTION.proposal).
            $aporteIds = $aporteQuery->pluck('id')->all();
            $propostaByHc = empty($aporteIds) ? collect() : \App\Models\Attachment::query()
                ->where('entity_type', 'HOUR_CONTRIBUTION')
                ->whereIn('entity_id', $aporteIds)
                ->where('category', 'proposal')
                ->whereNull('deleted_at')
                ->get(['entity_id', 'original_name'])
                ->keyBy('entity_id');
            $aporteCards = $aporteQuery->get()->map(function ($a) use ($propostaByHc) {
                $horas = (float) $a->contributed_hours;
                $valor = (float) $a->hourly_rate;
                $prop = $propostaByHc->get($a->id);
                return [
                    'id'              => $a->id,
                    'kind'            => 'aporte',
                    'customer_id'     => $a->project?->customer_id,
                    'customer_name'   => $a->project?->customer?->name,
                    'project_id'      => $a->project_id,
                    'project_code'    => $a->project?->code,
                    'project_name'    => $a->project?->name,
                    'project_status'  => $a->project?->status,
                    'horas'           => $horas,
                    'valor_hora'      => $valor,
                    'total'           => round($horas * $valor, 2),
                    'motivo'          => $a->motivo,
                    'description'     => $a->description,
                    'has_proposta'           => $prop !== null,
                    'proposta_original_name' => $prop?->original_name,
                    'kanban_status'   => $a->kanban_status ?? 'aporte',
                    'contributed_by'  => $a->contributedBy?->name,
                    'contributed_at'  => $a->contributed_at?->toISOString(),
                    'created_at'      => $a->created_at?->toISOString(),
                ];
            });
        }

        return response()->json([
            'demand_cards'          => $demandCards,
            'transition_cards'      => $transitionCards,
            'project_cards'         => $projectCards,
            'sustentacao_auto_cards'=> $sustentacaoAutoCards,
            'sustentacao_groups'    => $sustentacaoGroups,
            'request_cards'         => $requestCards,
            'aporte_cards'          => $aporteCards,
            'coordinators'          => $coordinators,
            'user_role'             => $user?->type ?? 'admin',
            'contracts'             => $demandCards,
        ]);
    }

    public function kanbanMove(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'to_column'      => 'required|string',
            'coordinator_id' => 'nullable|exists:users,id',
            'order'          => 'nullable|integer',
        ]);

        $toColumn      = $request->input('to_column');
        $coordinatorId = $request->input('coordinator_id');
        $fromColumn    = $this->resolveColumnName($contract);

        // Aditivo: card só transita entre "Novo Contrato" (demanda) e a coluna "Aditivos".
        if ($contract->is_aditivo) {
            if ($toColumn !== Contract::KANBAN_ADITIVO && !in_array($toColumn, Contract::DEMAND_COLUMNS, true)) {
                return response()->json(['message' => 'Aditivo só pode ir para a coluna Aditivos.'], 422);
            }
            $contract->update([
                'kanban_status' => $toColumn === Contract::KANBAN_ADITIVO ? Contract::KANBAN_ADITIVO : Contract::KANBAN_NOVO_PROJETO,
                'kanban_order'  => $request->input('order', 0),
            ]);
            return response()->json($contract->fresh());
        }
        // Não-aditivo não pode entrar na coluna Aditivos.
        if ($toColumn === Contract::KANBAN_ADITIVO) {
            return response()->json(['message' => 'Apenas contratos aditivos vão para a coluna Aditivos.'], 422);
        }

        $validDemandColumns = array_merge(Contract::DEMAND_COLUMNS, [Contract::KANBAN_ALOCADO]);

        // Mover para coluna de coordenador (legado) ou para "alocado" = gerar projeto
        if (str_starts_with($toColumn, 'coordinator:') || $toColumn === Contract::KANBAN_ALOCADO) {
            if (str_starts_with($toColumn, 'coordinator:')) {
                $coordinatorId = (int) str_replace('coordinator:', '', $toColumn);
            }

            if (!$contract->isKanbanComplete()) {
                return response()->json([
                    'message' => 'Contrato incompleto. Preencha: cliente, tipo de contrato e faturamento.',
                ], 422);
            }

            if ($contract->project_id) {
                $contract->update([
                    'kanban_status'         => Contract::KANBAN_ALOCADO,
                    'kanban_coordinator_id' => $coordinatorId,
                    'kanban_order'          => $request->input('order', 0),
                    'sustentacao_column'    => null,
                ]);

                // Projeto já existia: coordenador (re)atribuído ao mover para a
                // coluna dele. Notifica o coordenador da coluna (Modelo 2).
                if ($coordinatorId) {
                    $this->notifyCoordinatorAssigned($contract->fresh(['customer', 'contacts']), (int) $coordinatorId);
                }
            } else {
                if (!in_array($contract->status, [Contract::STATUS_INICIO_AUTORIZADO, Contract::STATUS_APROVADO])) {
                    $contract->update(['status' => Contract::STATUS_APROVADO]);
                }

                $contract->load(['customer', 'contacts', 'attachments']);

                DB::transaction(function () use ($contract, $coordinatorId, $request) {
                    $project = $this->createProjectFromContract($contract, $coordinatorId);

                    $contract->update([
                        'project_id'            => $project->id,
                        'generated_at'          => now(),
                        'generated_by_id'       => auth()->id(),
                        'status'                => Contract::STATUS_ATIVO,
                        'kanban_status'         => Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                        'kanban_order'          => $request->input('order', 0),
                        'sustentacao_column'    => null,
                    ]);
                });
            }

            // Card entrou em "Alocado" (qualquer coordenador) vindo de fora →
            // notifica executivo + coordenador + diretor + contatos. Skip se já
            // estava em alocado (reatribuição de coordenador não re-dispara).
            $wasAlocado = $fromColumn === Contract::KANBAN_ALOCADO
                || str_starts_with($fromColumn, 'coordinator:');
            if (!$wasAlocado) {
                $freshContract = $contract->fresh(['customer', 'contacts']);
                if ($freshContract && $freshContract->project_id) {
                    $project = \App\Models\Project::find($freshContract->project_id);
                    if ($project) {
                        $coordIds = $coordinatorId ? [$coordinatorId] : [];
                        $this->notifyProjectGenerated($freshContract, $project, $coordIds);
                    }
                }
            }
        } elseif (str_starts_with($toColumn, 'sust_')) {
            if ($err = $this->validateSustentacaoContractType($contract, $toColumn)) {
                return $err;
            }
            // Mover para fila de sustentação — define sustentacao_column
            $contract->update([
                'sustentacao_column' => $toColumn,
                'kanban_order'       => $request->input('order', 0),
            ]);
        } elseif ($toColumn === 'req_inicio_autorizado') {
            $contract->update([
                'kanban_status' => 'req_inicio_autorizado',
                'kanban_order'  => $request->input('order', 0),
            ]);
        } elseif ($toColumn === Contract::KANBAN_INICIO_AUTORIZADO) {
            $parentProjectId = $request->input('parent_project_id');
            // Projeto filho não exige completude pois herda do projeto pai
            if (!$parentProjectId && !$contract->isKanbanComplete()) {
                return response()->json([
                    'message' => 'Contrato incompleto. Preencha: cliente, tipo de contrato e faturamento.',
                ], 422);
            }
            $updateData = [
                'kanban_status' => Contract::KANBAN_INICIO_AUTORIZADO,
                'status'        => Contract::STATUS_INICIO_AUTORIZADO,
                'kanban_order'  => $request->input('order', 0),
            ];
            if ($parentProjectId) {
                $updateData['parent_project_id'] = $parentProjectId;
            }
            $contract->update($updateData);

            // Avança a requisição vinculada para 'inicio_autorizado' sempre que o contrato é autorizado.
            // Cobre dois caminhos: novo_projeto → inicio_autorizado E req_inicio_autorizado → inicio_autorizado.
            \App\Models\ContractRequest::where('linked_contract_id', $contract->id)
                ->where('kanban_column', 'req_inicio_autorizado')
                ->update(['kanban_column' => 'inicio_autorizado']);

            // Notifica executivo da conta (entrou em "Início Autorizado").
            if ($fromColumn !== Contract::KANBAN_INICIO_AUTORIZADO) {
                $this->notifyInicioAutorizado($contract->fresh(['customer']));
            }
        } elseif (in_array($toColumn, ['cancelado', 'pausado'])) {
            // Contrato sem projeto: cancelar ou pausar remove do kanban ativo
            $contract->update([
                'kanban_status'         => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_order'          => 0,
            ]);
        } elseif (in_array($toColumn, $validDemandColumns)) {
            $contract->update([
                'kanban_status'         => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_order'          => $request->input('order', 0),
            ]);

            // Card caiu em "Novo Contrato" (backlog) vindo de outra coluna →
            // re-notifica administrativos (mesmo template da criação inicial).
            if ($toColumn === Contract::KANBAN_BACKLOG && $fromColumn !== Contract::KANBAN_BACKLOG) {
                $this->notifyContractCreated($contract->fresh(['customer']));
            }
        }

        ContractKanbanLog::create([
            'contract_id'    => $contract->id,
            'from_column'    => $fromColumn,
            'to_column'      => $toColumn,
            'moved_by_id'    => auth()->id(),
            'coordinator_id' => $coordinatorId ?? null,
        ]);

        // Fase card-envolvidos: notifica envolvidos do projeto vinculado quando contrato
        // já tem project_id (movimentação visível pro time/cliente).
        $freshContract = $contract->fresh(['customer', 'contractType', 'serviceType', 'kanbanCoordinator', 'project']);
        if ($freshContract && $freshContract->project_id) {
            try {
                app(\App\Services\CardPhaseMovementDispatcher::class)->dispatch(
                    cardType:   \App\Models\CardEnvolvido::TYPE_PROJECT,
                    cardId:     $freshContract->project_id,
                    fromColumn: $this->prettyKanbanColumn($fromColumn),
                    toColumn:   $this->prettyKanbanColumn($toColumn),
                    movedBy:    auth()->user(),
                    note:       null,
                );
            } catch (\Throwable $e) {
                \Log::warning('phase notif kanbanMove falhou', ['contract_id' => $contract->id, 'err' => $e->getMessage()]);
            }
        }

        return response()->json($this->formatKanbanCard($freshContract));
    }

    /**
     * Converte slug interno do kanban em label legível para emails.
     */
    private function prettyKanbanColumn(string $col): string
    {
        return match ($col) {
            'backlog'                 => 'Backlog',
            'em_planejamento'         => 'Em planejamento',
            'inicio_autorizado'       => 'Início autorizado',
            'req_inicio_autorizado'   => 'Início autorizado',
            'em_execucao'             => 'Em execução',
            'em_entrega'              => 'Em entrega',
            'em_homologacao'          => 'Em homologação',
            'concluido'               => 'Concluído',
            'cancelado'               => 'Cancelado',
            'pausado'                 => 'Pausado',
            'alocado'                 => 'Alocado',
            'planning'                => 'Em planejamento',
            'awaiting_start'          => 'Aguardando início',
            'started'                 => 'Em execução',
            'liberado_para_testes'    => 'Liberado para testes',
            'finished'                => 'Concluído',
            'paused'                  => 'Pausado',
            'cancelled'               => 'Cancelado',
            'sust_bh_fixo'            => 'Sustentação · Banco de Horas Fixo',
            'sust_bh_mensal'          => 'Sustentação · Banco de Horas Mensal',
            'sust_on_demand'          => 'Sustentação · On Demand',
            'sust_cloud'              => 'Sustentação · Cloud',
            'sust_bizify'             => 'Sustentação · Bizify',
            default => ucfirst(str_replace('_', ' ', $col)),
        };
    }

    // Mover projeto de fase de execução (em_andamento → liberado_para_testes → encerrado)
    public function projectMove(Request $request, \App\Models\Project $project): JsonResponse
    {
        $validated = $request->validate([
            'status'              => 'nullable|string|in:backlog,awaiting_start,planning,started,liberado_para_testes,em_producao,paused,cancelled,finished',
            'coordinator_id'      => 'nullable|integer|exists:users,id',
            'from_coordinator_id' => 'nullable|integer|exists:users,id',
        ]);

        $user = auth()->user();
        if ($user?->isConsultor()) {
            return response()->json(['message' => 'Sem permissão para mover projetos.'], 403);
        }

        // Coordinator reassignment
        if ($validated['coordinator_id'] ?? null) {
            $newCoordId  = (int) $validated['coordinator_id'];
            $fromCoordId = isset($validated['from_coordinator_id']) ? (int) $validated['from_coordinator_id'] : null;

            if ($fromCoordId && $project->coordinators()->where('users.id', $fromCoordId)->exists()) {
                $project->coordinators()->detach($fromCoordId);
            }
            if (!$project->coordinators()->where('users.id', $newCoordId)->exists()) {
                $project->coordinators()->attach($newCoordId);
            }

            if ($project->contract_id) {
                \App\Models\Contract::where('id', $project->contract_id)
                    ->where('kanban_coordinator_id', $fromCoordId)
                    ->update(['kanban_coordinator_id' => $newCoordId]);
            }

            // Reativação: muda status junto com coordenador (ex: terminal → awaiting_start)
            if ($validated['status'] ?? null) {
                $fromStatus = $project->status;
                $project->update(['status' => $validated['status']]);
                ProjectKanbanLog::create([
                    'project_id'  => $project->id,
                    'from_status' => $fromStatus,
                    'to_status'   => $validated['status'],
                    'moved_by_id' => auth()->id(),
                ]);
            }

            // Modelo 2: coordenador (re)atribuído num projeto já gerado — notifica o NOVO coordenador.
            if ($project->contract_id) {
                $assignContract = \App\Models\Contract::find($project->contract_id);
                if ($assignContract) {
                    $this->notifyCoordinatorAssigned($assignContract, $newCoordId);
                }
            }

            return response()->json($this->formatProjectCard($project->fresh(['customer', 'contract', 'coordinators', 'consultants'])));
        }

        // Status move
        if (!($validated['status'] ?? null)) {
            return response()->json(['message' => 'status ou coordinator_id é obrigatório.'], 422);
        }

        $fromStatus  = $project->status;
        $newStatus   = $validated['status'];
        $project->update(['status' => $newStatus]);

        ProjectKanbanLog::create([
            'project_id'  => $project->id,
            'from_status' => $fromStatus,
            'to_status'   => $newStatus,
            'moved_by_id' => auth()->id(),
        ]);

        // Projeto de sustentação encerrado/pausado/cancelado → sai da fila de sustentação
        if (in_array($newStatus, ['paused', 'cancelled', 'finished']) && $project->contract_id) {
            \App\Models\Contract::where('id', $project->contract_id)
                ->whereNotNull('sustentacao_column')
                ->update(['sustentacao_column' => null]);
        }

        // Fase card-envolvidos: notifica envolvidos da movimentação de fase do projeto.
        try {
            app(\App\Services\CardPhaseMovementDispatcher::class)->dispatch(
                cardType:   \App\Models\CardEnvolvido::TYPE_PROJECT,
                cardId:     $project->id,
                fromColumn: $this->prettyKanbanColumn((string) $fromStatus),
                toColumn:   $this->prettyKanbanColumn((string) $newStatus),
                movedBy:    auth()->user(),
                note:       null,
            );
        } catch (\Throwable $e) {
            \Log::warning('phase notif projectMove falhou', ['project_id' => $project->id, 'err' => $e->getMessage()]);
        }

        // Workflows de fase terminal (Central): Fechado / Cancelado / Pausado.
        $phaseWorkflow = [
            'finished'  => 'project.finished',
            'cancelled' => 'project.cancelled',
            'paused'    => 'project.paused',
        ][$newStatus] ?? null;
        if ($phaseWorkflow) {
            $this->notifyProjectPhase($project, $phaseWorkflow);
        }

        return response()->json($this->formatProjectCard($project->fresh(['customer', 'contract', 'coordinators', 'consultants'])));
    }

    public function sustentacaoMove(Request $request, Contract $contract): JsonResponse
    {
        $request->validate([
            'to_column' => 'required|in:sust_bh_fixo,sust_bh_mensal,sust_on_demand,sust_cloud,sust_bizify',
        ]);

        $user = auth()->user();
        if (!$user?->isAdmin() && !($user?->isCoordenador() && $user?->coordinator_type === 'sustentacao')) {
            return response()->json(['message' => 'Apenas admin ou coordenador de sustentação pode mover este card.'], 403);
        }

        $toColumn  = $request->input('to_column');

        if ($err = $this->validateSustentacaoContractType($contract, $toColumn)) {
            return $err;
        }

        $projectId = $contract->project_id;

        if (!$projectId) {
            $contract->load(['customer', 'contacts', 'attachments']);
            DB::transaction(function () use ($contract, $toColumn, &$projectId) {
                $project   = $this->createProjectFromContract($contract, null);
                $projectId = $project->id;
                $contract->update([
                    'project_id'            => $project->id,
                    'generated_at'          => now(),
                    'generated_by_id'       => auth()->id(),
                    'sustentacao_column'    => $toColumn,
                    'kanban_coordinator_id' => null,
                    'kanban_status'         => Contract::KANBAN_INICIO_AUTORIZADO,
                ]);
            });
        } else {
            $contract->update([
                'sustentacao_column'    => $toColumn,
                'kanban_coordinator_id' => null,
                'kanban_status'         => Contract::KANBAN_INICIO_AUTORIZADO,
            ]);
        }

        return response()->json(['ok' => true, 'sustentacao_column' => $toColumn, 'project_id' => $projectId, 'project_created' => !$contract->getOriginal('project_id')]);
    }

    public function requestPlanDecision(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $data = $request->validate([
            'decision'          => 'required|in:novo_projeto,subprojeto',
            'project_id'        => 'nullable|exists:projects,id',
            'coordinator_id'    => 'nullable|exists:users,id',
            // contrato já criado externamente (via modal completo)
            'contract_id'       => 'nullable|exists:contracts,id',
            // campos para criar novo contrato (usado se contract_id não fornecido)
            'project_name'      => 'nullable|string|max:255',
            'categoria'         => 'nullable|in:projeto,sustentacao',
            'service_type_id'   => 'nullable|exists:service_types,id',
            'contract_type_id'  => 'nullable|exists:contract_types,id',
            'horas_contratadas' => 'nullable|integer|min:0',
            'tipo_faturamento'  => 'nullable|string',
            'valor_projeto'     => 'nullable|numeric|min:0',
        ]);

        $decision            = $data['decision'];
        $linkedContractId    = null;
        $linkedProjectId     = null;
        $linkedCoordinatorId = $data['coordinator_id'] ?? null;
        // novo_projeto: req aguarda em req_inicio_autorizado; subprojeto: vai direto para inicio_autorizado
        $toColumn = $decision === 'novo_projeto' ? 'req_inicio_autorizado' : 'inicio_autorizado';

        if ($decision === 'novo_projeto') {
            if (!empty($data['contract_id'])) {
                // Contrato já criado pelo modal completo — apenas vincular; mantém no kanban de contratos
                $contract = \App\Models\Contract::findOrFail($data['contract_id']);
                $contract->update(['kanban_status' => \App\Models\Contract::KANBAN_NOVO_PROJETO]);
                $linkedContractId = $contract->id;
            } else {
                $contract = \App\Models\Contract::create([
                    'customer_id'       => $contractRequest->customer_id,
                    'created_by_id'     => auth()->id(),
                    'status'            => \App\Models\Contract::STATUS_APROVADO,
                    'kanban_status'     => \App\Models\Contract::KANBAN_NOVO_PROJETO,
                    'project_name'      => $data['project_name'] ?? null,
                    'categoria'         => $data['categoria'] ?? 'projeto',
                    'service_type_id'   => $data['service_type_id'] ?? null,
                    'contract_type_id'  => $data['contract_type_id'] ?? null,
                    'horas_contratadas' => $data['horas_contratadas'] ?? 0,
                    'tipo_faturamento'  => $data['tipo_faturamento'] ?? null,
                    'valor_projeto'     => $data['valor_projeto'] ?? null,
                ]);
                $linkedContractId = $contract->id;
            }
        } else {
            // subprojeto: contrato aguarda em inicio_autorizado (igual ao novo_projeto)
            // O coordenador aloca no Kanban de Contratos; project_id é setado quando o projeto pai é gerado.
            $linkedProjectId = $data['project_id'] ?? null;
            if (!empty($data['contract_id'])) {
                $contract = \App\Models\Contract::findOrFail($data['contract_id']);
                $contract->update([
                    'kanban_status' => \App\Models\Contract::KANBAN_INICIO_AUTORIZADO,
                    'status'        => \App\Models\Contract::STATUS_INICIO_AUTORIZADO,
                    'parent_project_id' => $linkedProjectId,
                ]);
                $linkedContractId = $contract->id;
            }
        }

        $contractRequest->update([
            'req_decision'          => $decision,
            'req_decided_at'        => $contractRequest->req_decided_at ?? now(),
            'linked_contract_id'    => $linkedContractId,
            'linked_project_id'     => $linkedProjectId,
            'linked_coordinator_id' => $linkedCoordinatorId,
            'kanban_column'         => $toColumn,
        ]);

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => $contractRequest->getOriginal('kanban_column') ?? 'req_inicio_autorizado',
            'to_column'           => $toColumn,
            'moved_by_id'         => auth()->id(),
        ]);

        return response()->json(['ok' => true, 'linked_contract_id' => $linkedContractId, 'linked_project_id' => $linkedProjectId]);
    }

    public function requestFinalize(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $data = $request->validate([
            'coordinator_id' => 'nullable|exists:users,id',
        ]);

        $linkedContractId = $contractRequest->linked_contract_id;
        $coordinatorId    = $data['coordinator_id'] ?? $contractRequest->linked_coordinator_id;

        if ($contractRequest->req_decision === 'subprojeto') {
            // Subprojeto: apenas fecha a requisição; o projeto já existe
            $contractRequest->update(['kanban_column' => 'req_em_andamento']);
        } else {
            if (!$linkedContractId) {
                return response()->json(['message' => 'Requisição sem contrato vinculado.'], 422);
            }

            $contract = \App\Models\Contract::findOrFail($linkedContractId);
            $contract->load(['customer', 'contacts', 'attachments']);

            DB::transaction(function () use ($contract, $coordinatorId, $linkedContractId, $contractRequest) {
                if (!$contract->project_id) {
                    $codeService   = new \App\Services\ProjectCodeService();
                    $parentProject = $contract->parent_project_id ? \App\Models\Project::find($contract->parent_project_id) : null;
                    $codeData      = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);
                    $projectName   = $contract->project_name ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

                    $project = \App\Models\Project::create(array_merge($codeData, [
                        'name'                   => $projectName,
                        'parent_project_id'      => $contract->parent_project_id,
                        'customer_id'            => $contract->customer_id,
                        'service_type_id'        => $contract->service_type_id,
                        'contract_type_id'       => $contract->contract_type_id,
                        'sold_hours'             => $contract->horas_contratadas,
                        'project_value'          => $contract->valor_projeto,
                        'hourly_rate'            => $contract->resolvedHourlyRate(),
                        'additional_hourly_rate' => $contract->hora_adicional,
                        'coordinator_hours'      => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
                        'coordination_hours'     => $contract->horas_coordenacao,
                        'consultant_hours'       => $contract->horas_consultor,
                        'start_date'             => $contract->expectativa_inicio,
                        'status'                 => \App\Models\Project::STATUS_AWAITING_START,
                        'contract_id'            => $contract->id,
                        'contract_request_id'    => $contractRequest->id,
                        'tipo_alocacao'          => $contract->tipo_alocacao,
                        'architect_id'           => $contract->architect_id,
                        'condicao_pagamento'     => $contract->condicao_pagamento,
                        'observacoes_contrato'   => $contract->observacoes,
                        'cobra_despesa_cliente'  => $contract->cobra_despesa_cliente,
                        'limite_despesa'         => $contract->limite_despesa,
                        'executivo_conta_id'     => $contract->executivo_conta_id,
                        'vendedor_id'            => $contract->vendedor_id,
                    ]));

                    foreach ($contract->contacts as $c) {
                        \App\Models\ProjectContact::create(['project_id' => $project->id, 'contract_contact_id' => $c->id, 'name' => $c->name, 'cargo' => $c->cargo, 'email' => $c->email, 'phone' => $c->phone]);
                    }
                    // FASE 11.7 (PR 7b) — sem shadow ProjectAttachment;
                    // listAttachments do projeto une os do CONTRACT vinculado.
                    if ($coordinatorId) {
                        $project->coordinators()->attach($coordinatorId);
                    }

                    $contract->update([
                        'project_id'            => $project->id,
                        'generated_at'          => now(),
                        'generated_by_id'       => auth()->id(),
                        'status'                => \App\Models\Contract::STATUS_ATIVO,
                        'kanban_status'         => \App\Models\Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                    ]);
                } else {
                    $contract->update([
                        'kanban_status'         => \App\Models\Contract::KANBAN_ALOCADO,
                        'kanban_coordinator_id' => $coordinatorId,
                    ]);
                }

                $contractRequest->update([
                    'contract_id'   => $linkedContractId,
                    'kanban_column' => 'req_em_andamento',
                ]);
            });
        }

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => 'req_inicio_autorizado',
            'to_column'           => 'req_em_andamento',
            'moved_by_id'         => auth()->id(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function requestKanbanMove(Request $request, \App\Models\ContractRequest $contractRequest): JsonResponse
    {
        $request->validate(['kanban_column' => 'required|string']);

        $fromColumn = $contractRequest->kanban_column ?? 'backlog';
        $toColumn   = $request->input('kanban_column');

        $contractRequest->update(['kanban_column' => $toColumn]);

        \App\Models\ContractRequestKanbanLog::create([
            'contract_request_id' => $contractRequest->id,
            'from_column'         => $fromColumn,
            'to_column'           => $toColumn,
            'moved_by_id'         => auth()->id(),
        ]);

        // Notifica cliente + executivo da conta + watchers a cada movimentação,
        // até a requisição virar projeto/contrato (req_decided_at preenchido).
        try {
            app(\App\Services\ContractRequestNotifier::class)
                ->moved($contractRequest->fresh(['customer', 'createdBy', 'watchers.user']), $fromColumn, $toColumn);
        } catch (\Throwable $e) {
            \Log::warning('req lifecycle (moved) falhou', ['req_id' => $contractRequest->id, 'err' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    private const SUST_COLUMN_CONTRACT_TYPE = [
        'sust_bh_fixo'   => 'fixed_hours',
        'sust_bh_mensal' => 'monthly_hours',
        'sust_on_demand' => 'on_demand',
        'sust_cloud'     => 'cloud',
    ];

    private function validateSustentacaoContractType(Contract $contract, string $toColumn): ?\Illuminate\Http\JsonResponse
    {
        // Coluna Bizify aceita qualquer contract_type, mas exige service_type = Bizify
        if ($toColumn === 'sust_bizify') {
            $contract->loadMissing('serviceType');
            $svcCode = $contract->serviceType?->code;
            $svcName = strtolower($contract->serviceType?->name ?? '');
            if ($svcCode !== 'bizify' && !str_contains($svcName, 'bizify')) {
                $actual = $contract->serviceType?->name ?? 'não definido';
                return response()->json([
                    'message' => "A coluna \"Bizify\" aceita apenas contratos com Tipo de Serviço \"Bizify\". Este contrato tem serviço \"{$actual}\".",
                ], 422);
            }
            return null;
        }

        $expectedCode = self::SUST_COLUMN_CONTRACT_TYPE[$toColumn] ?? null;
        if (!$expectedCode) return null;

        $contract->loadMissing('contractType');
        $actualCode = $contract->contractType?->code;

        // Fechado, cancelado e pausado transitam livremente entre colunas
        if ($actualCode === 'closed') return null;
        if ($contract->project_id) {
            $contract->loadMissing('project');
            $projectStatus = $contract->project?->status;
            if (in_array($projectStatus, ['cancelled', 'paused'])) return null;
        }

        if ($actualCode !== $expectedCode) {
            $labels = [
                'sust_bh_fixo'   => 'Banco de Horas Fixo',
                'sust_bh_mensal' => 'Banco de Horas Mensal',
                'sust_on_demand' => 'On Demand',
                'sust_cloud'     => 'Cloud',
            ];
            $actual = $contract->contractType?->name ?? 'não definido';
            return response()->json([
                'message' => "Esta coluna aceita apenas contratos do tipo \"{$labels[$toColumn]}\". Este contrato é do tipo \"{$actual}\".",
            ], 422);
        }

        return null;
    }

    private function resolveColumnName(Contract $contract): string
    {
        if ($contract->kanban_status === Contract::KANBAN_ALOCADO && $contract->kanban_coordinator_id) {
            return 'coordinator:' . $contract->kanban_coordinator_id;
        }
        return $contract->kanban_status ?? Contract::KANBAN_BACKLOG;
    }

    private function formatKanbanCard(Contract $contract): array
    {
        return [
            'card_type'        => 'contract',
            'id'               => $contract->id,
            'customer_name'    => $contract->customer?->name,
            'customer_id'      => $contract->customer_id,
            'project_name'     => $contract->project_name,
            'categoria'        => $contract->categoria,
            'contract_type'    => $contract->contractType?->name,
            'contract_type_id' => $contract->contract_type_id,
            'service_type'     => $contract->serviceType?->name,
            'tipo_faturamento' => $contract->tipo_faturamento,
            'horas_contratadas'=> $contract->horas_contratadas,
            'valor_projeto'    => $contract->valor_projeto,
            'kanban_status'    => $contract->kanban_status ?? Contract::KANBAN_BACKLOG,
            'kanban_coordinator_id' => $contract->kanban_coordinator_id,
            'kanban_coordinator'    => $contract->kanbanCoordinator?->name,
            'executivo_conta_name'  => $contract->executivoConta?->name ?? $contract->customer?->executive?->name,
            'kanban_order'     => $contract->kanban_order,
            'status'           => $contract->status,
            'project_id'       => $contract->project_id,
            'project_code'     => $contract->project?->code,
            'project_status'   => $contract->project?->status,
            // Subprojeto faturado que gerou aporte automático no pai → badge "Gerou aporte" na capa.
            'gerou_aporte'     => ($contract->parent_project_id && $contract->project_code_preview)
                ? \App\Models\HourContribution::where('project_id', $contract->parent_project_id)
                    ->where('description', 'ilike', '%ref. subprojeto faturado%(' . $contract->project_code_preview . '%')
                    ->exists()
                : false,
            'is_complete'      => $contract->isKanbanComplete(),
            'created_at'       => $contract->created_at,
            'is_aditivo'       => (bool) $contract->is_aditivo,
            'aditivo_field'    => $contract->aditivo_field,
            'aditivo_changes'  => $contract->aditivo_changes, // breakdown [{field,label,old,new}] (multi Mensal)
            'aditivo_old_value'   => $contract->aditivo_old_value !== null ? (float) $contract->aditivo_old_value : null,
            'aditivo_new_value'   => $contract->is_aditivo ? (float) match ($contract->aditivo_field) {
                'valor_hora'        => $contract->valor_hora,
                'horas_contratadas' => $contract->horas_contratadas,
                'valor_projeto'     => $contract->valor_projeto,
                'multiplo'          => $contract->valor_projeto, // novo valor do contrato
                default             => 0,
            } : null,
            'aditivo_project_code' => $contract->aditivoProject?->code,
            'aditivo_project_name' => $contract->aditivoProject?->name ?? $contract->project_name,
            // Valor do contrato (Mensal = horas × valor-hora) impactado pelo aditivo — de → para.
            'aditivo_contract_old' => $this->aditivoContractValue($contract, 'old'),
            'aditivo_contract_new' => $this->aditivoContractValue($contract, 'new'),
            'aditivo_effective_from' => $contract->aditivo_effective_from?->format('Y-m-d') ?? (is_string($contract->aditivo_effective_from) ? $contract->aditivo_effective_from : null),
            'aditivo_cond_pagamento' => $contract->condicao_pagamento,
            'aditivo_obs'            => $contract->observacoes,
        ];
    }

    /**
     * Valor do CONTRATO (Mensal = horas × valor-hora) antes/depois do aditivo. Só faz
     * sentido pra Banco de Horas Mensal; On Demand/Cloud retornam null (não é horas×hora).
     */
    private function aditivoContractValue(Contract $contract, string $which): ?float
    {
        if (!$contract->is_aditivo) return null;
        $ap = $contract->aditivoProject;
        if (!$ap || strtolower($ap->contractType->code ?? '') !== 'monthly_hours') return null;

        if ($contract->aditivo_field === 'horas_contratadas') {
            $rate = (float) ($ap->hourly_rate ?? 0);
            if ($rate <= 0) return null;
            $hours = $which === 'old'
                ? ($contract->aditivo_old_value !== null ? (float) $contract->aditivo_old_value : null)
                : (float) $contract->horas_contratadas;
            return $hours === null ? null : round($hours * $rate, 2);
        }
        if ($contract->aditivo_field === 'valor_hora') {
            $hours = (float) ($ap->sold_hours ?? 0);
            if ($hours <= 0) return null; // sem horas não há "valor do contrato"
            $rate  = $which === 'old'
                ? ($contract->aditivo_old_value !== null ? (float) $contract->aditivo_old_value : null)
                : (float) $contract->valor_hora;
            return $rate === null ? null : round($hours * $rate, 2);
        }
        if ($contract->aditivo_field === 'multiplo') {
            // Multi (Mensal): old = valor do contrato antes (gravado em aditivo_old_value),
            // new = valor do contrato recomputado (gravado em valor_projeto).
            return $which === 'old'
                ? ($contract->aditivo_old_value !== null ? (float) $contract->aditivo_old_value : null)
                : ($contract->valor_projeto !== null ? (float) $contract->valor_projeto : null);
        }
        return null;
    }

    private function formatProjectCard(\App\Models\Project $project, float $loggedMinutes = 0): array
    {
        // Saldo + consumido pela regra da GESTÃO DE PROJETOS (fonte da verdade) — conta os subprojetos.
        $b        = $project->managementBreakdown();
        $consumed = round($b['consumed'], 1);
        $saldo    = round($b['balance'], 1);

        return [
            'card_type'             => 'project',
            'id'                    => $project->id,
            'contract_id'           => $project->contract_id,
            'contract_request_id'   => $project->contract_request_id,
            'customer_name'         => $project->customer?->name,
            'customer_id'           => $project->customer_id,
            'project_name'          => $project->name,
            'code'                  => $project->code,
            'status'                => $project->status,
            'sold_hours'            => $project->sold_hours,
            'consumed_hours'        => $consumed,
            // Cliente só acompanha horas se o projeto estiver com o acompanhamento ligado (senão o FE esconde).
            'client_follows_timesheets' => $project->client_follows_timesheets,
            'general_hours_balance' => $saldo,
            'project_value'         => $project->project_value,
            'start_date'            => $project->start_date,
            'expected_end_date'     => $project->expected_end_date,
            'coordinator_ids'       => $project->coordinators->pluck('id'),
            'coordinators'          => $project->coordinators->pluck('name'),
            // Banco de coordenação — pra lente do coordenador no card (vendidas = banco).
            'coordination_hours'          => $project->coordination_hours,
            // "Horas Apontáveis consumidas" = consumo real (reusa o breakdown já calculado).
            'coordination_consumed_hours' => $b['consumed'],
            'kanban_coordinator_override_id' => $project->kanban_coordinator_override_id,
            'consultants'           => $project->consultants->pluck('name'),
            'executivo_conta_name'  => $project->executivoConta?->name ?? $project->customer?->executive?->name,
            'contract_type'         => $project->contractType?->name,
            'service_type'          => $project->serviceType?->name,
            'is_complete'           => true,
            'created_at'            => $project->created_at,
            // Dias na coluna atual do board (recomeça a contagem se o card voltar de etapa).
            'days_in_current_column' => $project->daysInCurrentColumn(),
        ];
    }

    /**
     * Coluna de sustentação a que um PROJETO pertence pelo seu tipo de serviço/contrato.
     * Usada quando o projeto não tem override de coordenador (regra: override vazio ⇒
     * o card fica na fila de sustentação do tipo, não na fila de um coordenador).
     * Retorna null se o projeto não for de sustentação (segue fluxo normal de projeto).
     */
    private function sustColumnForProject(\App\Models\Project $project): ?string
    {
        $svcCode = strtolower($project->serviceType?->code ?? '');
        $svcName = strtolower($project->serviceType?->name ?? '');
        $ctCode  = strtolower($project->contractType?->code ?? '');
        $ctName  = strtolower($project->contractType?->name ?? '');

        $isSust = $svcCode === 'sustentacao'
            || str_contains($svcName, 'sustent')
            || $svcCode === 'bizify' || str_contains($svcName, 'bizify')
            || str_contains($svcName, 'cloud');
        if (!$isSust) {
            return null;
        }

        if ($svcCode === 'bizify' || str_contains($svcName, 'bizify') || str_contains($ctName, 'bizify')) {
            return 'sust_bizify';
        }
        if (str_contains($svcName, 'cloud') || $ctCode === 'cloud' || str_contains($ctName, 'cloud')) {
            return 'sust_cloud';
        }
        if ($ctCode === 'monthly_hours' || str_contains($ctName, 'mensal')) {
            return 'sust_bh_mensal';
        }
        if ($ctCode === 'on_demand' || str_contains($ctName, 'on demand') || str_contains($ctName, 'on-demand')) {
            return 'sust_on_demand';
        }
        return 'sust_bh_fixo';
    }

    private function createProjectFromContract(Contract $contract, ?int $coordinatorId): Project
    {
        $codeService   = new ProjectCodeService();
        $parentProject = $contract->parent_project_id ? Project::find($contract->parent_project_id) : null;
        $codeData      = $codeService->resolveForStore($contract->project_code_preview, $contract->customer, $parentProject);
        $projectName   = $contract->project_name ?: ($contract->customer->name . ' — ' . now()->format('m/Y'));

        $project = Project::create(array_merge($codeData, [
            'name'                   => $projectName,
            'parent_project_id'      => $contract->parent_project_id,
            'customer_id'            => $contract->customer_id,
            'service_type_id'        => $contract->service_type_id,
            'contract_type_id'       => $contract->contract_type_id,
            'sold_hours'             => $contract->horas_contratadas,
            'project_value'          => $contract->valor_projeto,
            'hourly_rate'            => $contract->resolvedHourlyRate(),
            'additional_hourly_rate' => $contract->hora_adicional,
            'coordinator_hours'      => $contract->pct_horas_coordenador !== null ? (int) $contract->pct_horas_coordenador : null,
            'coordination_hours'     => $contract->horas_coordenacao,
            'consultant_hours'       => $contract->horas_consultor,
            'start_date'             => $contract->expectativa_inicio,
            'status'                 => Project::STATUS_AWAITING_START,
            'contract_id'            => $contract->id,
            'tipo_alocacao'          => $contract->tipo_alocacao,
            'architect_id'           => $contract->architect_id,
            'condicao_pagamento'     => $contract->condicao_pagamento,
            'observacoes_contrato'   => $contract->observacoes,
            'cobra_despesa_cliente'  => $contract->cobra_despesa_cliente,
            'limite_despesa'         => $contract->limite_despesa,
            'executivo_conta_id'     => $contract->executivo_conta_id,
            'vendedor_id'            => $contract->vendedor_id,
        ]));

        foreach ($contract->contacts as $c) {
            ProjectContact::create(['project_id' => $project->id, 'contract_contact_id' => $c->id, 'name' => $c->name, 'cargo' => $c->cargo, 'email' => $c->email, 'phone' => $c->phone]);
        }
        // FASE 11.7 (PR 7b) — sem shadow ProjectAttachment;
        // listAttachments do projeto une os do CONTRACT vinculado.

        if ($coordinatorId) {
            $project->coordinators()->attach($coordinatorId);
        }

        return $project;
    }

    public function events(Contract $contract): JsonResponse
    {
        $events = ContractEvent::where('contract_id', $contract->id)
            ->with('triggeredBy:id,name')
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $events]);
    }

    public function snapshot(Contract $contract): JsonResponse
    {
        $cached = Cache::get(ContractEventListener::cacheKey($contract->id));

        if ($cached !== null) {
            return response()->json(['data' => $cached]);
        }

        $snap = ContractFlowSnapshot::where('contract_id', $contract->id)
            ->with('updatedBy:id,name')
            ->first();

        // Nunca cachear null
        if ($snap !== null) {
            Cache::put(ContractEventListener::cacheKey($contract->id), $snap, ContractEventListener::CACHE_TTL);
        }

        return response()->json(['data' => $snap]);
    }

    public function replay(Request $request, Contract $contract): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $snap = ContractFlowSnapshot::where('contract_id', $contract->id)->first();

        if ($snap && $snap->replay_in_progress) {
            return response()->json(['message' => 'Replay já em andamento para este contrato'], 409);
        }

        // Marcar como em andamento antes de despachar
        if ($snap) {
            $snap->update(['replay_in_progress' => true]);
        }

        Cache::forget(ContractEventListener::cacheKey($contract->id));

        $fromSequence = (int) $request->input('from_sequence', 0);

        dispatch(new ReplayContractEventsJob($contract->id, $fromSequence));

        return response()->json([
            'status'        => 'queued',
            'contract_id'   => $contract->id,
            'from_sequence' => $fromSequence,
        ], 202);
    }

    public function consistencyReport(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $totalSnapshots = ContractFlowSnapshot::count();

        $withInconsistencies = ContractFlowSnapshot::where('inconsistency_count', '>', 0)->count();

        $topProblematic = ContractFlowSnapshot::where('inconsistency_count', '>', 0)
            ->with('contract:id,project_name')
            ->orderByDesc('inconsistency_count')
            ->limit(10)
            ->get(['contract_id', 'inconsistency_count', 'version', 'last_sequence']);

        $queueDepth   = DB::table('jobs')->count();
        $failedLast24 = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours(24))
            ->count();

        $contractsWithoutSnapshot = \App\Models\Contract::whereNotIn(
            'id', ContractFlowSnapshot::select('contract_id')
        )->count();

        return response()->json([
            'total_snapshots'             => $totalSnapshots,
            'with_inconsistencies'        => $withInconsistencies,
            'contracts_without_snapshot'  => $contractsWithoutSnapshot,
            'top_problematic'             => $topProblematic,
            'queue_depth'                 => $queueDepth,
            'failed_jobs_last_24h'        => $failedLast24,
        ]);
    }

    public function recorrentes(Request $request): JsonResponse
    {
        $rows = Contract::query()
            ->whereNotNull('data_assinatura')
            ->whereNotNull('data_vencimento')
            ->with(['customer:id,name', 'contractType:id,name', 'project:id,code'])
            ->orderBy('data_vencimento')
            ->get()
            ->map(function (Contract $c) {
                $isOnDemand   = $c->tipo_faturamento === 'on_demand';
                $valorAtual   = $isOnDemand ? $c->valor_hora : $c->valor_projeto;
                // Base do reajuste: valor_inicial salvo, ou o valor atual do contrato como ponto de partida.
                $valorInicial = $c->valor_inicial !== null ? (float) $c->valor_inicial : (float) ($valorAtual ?? 0);
                $pct          = $c->pct_reajuste !== null ? (float) $c->pct_reajuste : 0.0;
                $valorAjustado = round($valorInicial * (1 + $pct / 100), 2);

                // Período explícito do reajuste (início = último reajuste/base, fim = mês fechado).
                [$ps, $pe] = $this->reajustePeriodo($c);

                return [
                    'id'              => $c->id,
                    'cliente'         => $c->customer?->name,
                    'codigo'          => $c->project?->code ?? $c->project_code_preview,
                    'tipo'            => $c->contractType?->name ?? $c->tipo_faturamento,
                    'valor_field'     => $isOnDemand ? 'valor_hora' : 'valor_projeto',
                    'valor_inicial'   => $valorInicial,
                    'taxa_reajuste'   => $c->taxa_reajuste,
                    'pct_reajuste'    => $pct,
                    'valor_ajustado'  => $valorAjustado,
                    'data_assinatura' => optional($c->data_assinatura)->toDateString(),
                    'data_vencimento' => optional($c->data_vencimento)->toDateString(),
                    'data_ultimo_reajuste' => optional($c->data_ultimo_reajuste)->toDateString(),
                    'status'          => $c->status,
                    'periodo'         => [
                        'inicio' => $ps->toDateString(),
                        'fim'    => $pe->toDateString(),
                        'label'  => $this->periodoFormatado($ps, $pe),
                    ],
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** Atualização parcial pela tela de recorrentes (gestão/reajuste). Reflete no contrato. */
    public function updateRecorrente(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'data_assinatura'      => 'nullable|date',
            'data_vencimento'      => 'nullable|date',
            'data_ultimo_reajuste' => 'nullable|date',
            'valor_inicial'        => 'nullable|numeric|min:0',
            'taxa_reajuste'        => 'nullable|string|in:IPCA,IGPM,IGP-M',
            'pct_reajuste'         => 'nullable|numeric',
        ]);

        // Normaliza a taxa para o rótulo canônico (IPCA | IGPM) — o FE manda IGPM.
        if (!empty($validated['taxa_reajuste'])) {
            $validated['taxa_reajuste'] = EconomicIndexService::canonical($validated['taxa_reajuste']);
        }

        $contract->update($validated);

        // Rotina de reajuste (edição manual do cadastro): a alteração de valor reflete
        // no operacional — projeto e contrato sempre iguais. valor_inicial é a base/
        // "valor atual" do reajuste; on_demand → valor_hora/hourly_rate, demais →
        // valor_projeto/project_value.
        if (array_key_exists('valor_inicial', $validated) && $validated['valor_inicial'] !== null) {
            $novo          = (float) $validated['valor_inicial'];
            $isOnDemand    = $contract->tipo_faturamento === 'on_demand'
                || optional($contract->contractType)->code === 'on_demand';
            $contractField = $isOnDemand ? 'valor_hora' : 'valor_projeto';
            $projField     = $isOnDemand ? 'hourly_rate' : 'project_value';
            $contract->update([$contractField => $novo]);
            if ($contract->project_id) {
                \App\Models\Project::where('id', $contract->project_id)->update([$projField => $novo]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Amarra a planilha "ANIVERSÁRIO - CLIENTES.xlsx" aos contratos cadastrados:
     * casa pelo CÓDIGO extraído da coluna "Contrato" (ex.: NRC001-24) contra
     * projects.code OU contracts.project_code_preview e popula data_assinatura,
     * data_vencimento, valor_inicial. Quando há "Valor Reajustado", deriva o
     * percentual = (reajustado/inicial − 1)×100 (valor ajustado recompõe o reajustado).
     * A taxa (IPCA/IGP-M) não está na planilha → fica para preencher na tela.
     */
    public function importAniversario(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:8192',
        ]);

        $ss    = \PhpOffice\PhpSpreadsheet\IOFactory::load($request->file('file')->getRealPath());
        $sheet = $ss->getSheetByName('Planilha1') ?? $ss->getActiveSheet();
        $data  = $sheet->toArray(null, true, false, false);

        // Localiza a linha de cabeçalho (que tem "contrato" e "valor") e mapeia colunas.
        $norm = function ($s) {
            $s = mb_strtolower(trim((string) $s));
            return strtr($s, ['á'=>'a','â'=>'a','ã'=>'a','à'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        };
        $headerIdx = null;
        $col = [];
        foreach ($data as $i => $row) {
            $names = array_map($norm, $row);
            if (in_array('contrato', $names, true) && in_array('valor', $names, true)) {
                $headerIdx = $i;
                foreach ($names as $ci => $n) {
                    $col[$n] = $ci;
                }
                break;
            }
        }
        if ($headerIdx === null) {
            return response()->json(['message' => 'Cabeçalho não encontrado (colunas "Contrato"/"Valor").'], 422);
        }

        $cContrato = $col['contrato'] ?? null;
        $cCliente  = $col['cliente'] ?? 0;
        $cValor    = $col['valor'] ?? null;
        $cAss      = $col['dt assinatura'] ?? ($col['assinatura'] ?? null);
        $cVenc     = $col['dt vencimento'] ?? ($col['vencimento'] ?? null);
        $cReaj     = $col['valor reajustado'] ?? null;
        $cUltReaj  = $col['ultimo reajuste'] ?? ($col['ult reaj'] ?? null);

        $toDate = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) {
                try { return \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v))->startOfDay(); }
                catch (\Throwable $e) { return null; }
            }
            foreach (['d/m/Y', 'Y-m-d', 'd-m-Y'] as $fmt) {
                try { return \Carbon\Carbon::createFromFormat($fmt, trim((string) $v))->startOfDay(); } catch (\Throwable $e) {}
            }
            return null;
        };
        $toNum = function ($v) {
            if ($v === null || $v === '') return null;
            if (is_numeric($v)) return (float) $v;
            $v = str_replace(['R$', ' ', '.'], '', (string) $v);
            $v = str_replace(',', '.', $v);
            return is_numeric($v) ? (float) $v : null;
        };

        $matched = 0;
        $unmatched = [];
        $semData = [];
        $valorSuspeito = [];

        foreach (array_slice($data, $headerIdx + 1) as $row) {
            $contratoRaw = trim((string) ($row[$cContrato] ?? ''));
            $cliente     = trim((string) ($row[$cCliente] ?? ''));
            if ($contratoRaw === '' || !preg_match('/([A-Z]{3}\d{3}-\d{2})/', strtoupper($contratoRaw), $m)) {
                continue;
            }
            $code = $m[1];

            $contract = Contract::whereHas('project', fn ($q) => $q->where('code', $code))->first()
                ?? Contract::where('project_code_preview', $code)->first();
            if (!$contract) {
                $unmatched[] = "{$code} · {$cliente}";
                continue;
            }

            $ass     = $cAss  !== null ? $toDate($row[$cAss] ?? null) : null;
            $venc    = $cVenc !== null ? $toDate($row[$cVenc] ?? null) : null;
            $valor   = $cValor !== null ? $toNum($row[$cValor] ?? null) : null;
            $reaj    = $cReaj !== null ? $toNum($row[$cReaj] ?? null) : null;
            $ultReaj = $cUltReaj !== null ? $toDate($row[$cUltReaj] ?? null) : null;

            if (!$ass && !$venc) {
                $semData[] = "{$code} · {$cliente}";
            }

            $upd = [];
            if ($ass)     $upd['data_assinatura'] = $ass->toDateString();
            if ($venc)    $upd['data_vencimento'] = $venc->toDateString();
            if ($ultReaj) $upd['data_ultimo_reajuste'] = $ultReaj->toDateString();
            if ($valor !== null) $upd['valor_inicial'] = $valor;
            // % derivado do "Valor Reajustado" (taxa/índice fica para preencher na tela).
            if ($valor !== null && $valor > 0 && $reaj !== null && $reaj > 0) {
                $upd['pct_reajuste'] = round(($reaj / $valor - 1) * 100, 3);
            }

            if (!empty($upd)) {
                $contract->update($upd);
                $matched++;
            }

            // Sincroniza o projeto ligado com o valor carregado (projeto = contrato).
            // Blindagem: para On Demand, valor-hora acima do teto plausível (R$ 2.000)
            // é provável valor mensal/total na coluna errada (ex.: 19.600) → NÃO
            // sobrescreve o projeto; o caso fica em $valorSuspeito para revisão manual.
            if ($valor !== null && $valor > 0 && $contract->project_id) {
                $isOnDemand = $contract->tipo_faturamento === 'on_demand'
                    || optional($contract->contractType)->code === 'on_demand';
                if ($isOnDemand) {
                    if ($valor <= 2000) {
                        Project::where('id', $contract->project_id)->update(['hourly_rate' => $valor]);
                    } else {
                        $valorSuspeito[] = "{$code} · {$cliente} · R$ " . number_format($valor, 2, ',', '.');
                    }
                } else {
                    Project::where('id', $contract->project_id)->update(['project_value' => $valor]);
                }
            }
        }

        return response()->json([
            'matched'             => $matched,
            'unmatched'           => $unmatched,
            'unmatched_count'     => count($unmatched),
            'sem_data'            => $semData,
            'valor_suspeito'      => $valorSuspeito,
            'valor_suspeito_count'=> count($valorSuspeito),
        ]);
    }

    /**
     * Prévia do reajuste: busca o índice (IPCA/IGP-M) no BCB para o período do
     * contrato e sugere o novo valor. NÃO aplica nada (regra: sempre mostrar antes).
     * GET /contracts/{id}/adjustment-preview?index_type=IPCA[&start_date&end_date]
     */
    public function adjustmentPreview(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'index_type' => 'required|string',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);
        if (!EconomicIndexService::supports($validated['index_type'])) {
            return response()->json(['message' => 'Índice não suportado. Use IPCA ou IGP-M.'], 422);
        }

        [$start, $end] = $this->reajustePeriodo($contract, $validated['start_date'] ?? null, $validated['end_date'] ?? null);

        $base = $this->valorBaseReajuste($contract);
        if ($base <= 0) {
            return response()->json(['message' => 'Contrato sem valor-base para reajuste. Preencha o valor inicial.'], 422);
        }

        try {
            $idx = app(EconomicIndexService::class)->accumulated($validated['index_type'], $start, $end);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $pct        = (float) $idx['percentual_total'];
        $valorNovo  = round($base * (1 + $pct / 100), 2);
        $isOnDemand = $contract->tipo_faturamento === 'on_demand';
        $periodoFmt = $this->periodoFormatado($start, $end);

        return response()->json([
            'indice'            => $idx['index_type'],
            'percentual'        => $pct,
            'percentual_total'  => $pct,
            'meses_utilizados'  => $idx['meses_utilizados'],
            'valor_atual'       => round($base, 2),
            'valor_novo'        => $valorNovo,
            'valor_field'       => $isOnDemand ? 'valor_hora' : 'valor_projeto',
            'cliente_emails'    => $this->clienteEmailsContrato($contract),
            'periodo_inicio'    => $start->toDateString(),
            'periodo_fim'       => $end->toDateString(),
            'periodo_formatado' => $periodoFmt,
            'periodo'           => [
                'inicio' => $start->toDateString(),
                'fim'    => $end->toDateString(),
                'label'  => $periodoFmt,
            ],
        ]);
    }

    /**
     * Aplica o reajuste (ação MANUAL do usuário): atualiza o valor do contrato,
     * recalcula o outro campo monetário, avança o vencimento e GRAVA histórico.
     * POST /contracts/{id}/apply-adjustment
     */
    public function applyAdjustment(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'indice'         => 'required|string',
            'percentual'     => 'required|numeric',
            'periodo_inicio' => 'nullable|date',
            'periodo_fim'    => 'nullable|date',
        ]);
        if (!EconomicIndexService::supports($validated['indice'])) {
            return response()->json(['message' => 'Índice não suportado. Use IPCA ou IGP-M.'], 422);
        }

        $base = $this->valorBaseReajuste($contract);
        if ($base <= 0) {
            return response()->json(['message' => 'Contrato sem valor-base para reajuste.'], 422);
        }

        $pct        = (float) $validated['percentual'];
        $valorNovo  = round($base * (1 + $pct / 100), 2);
        $isOnDemand = $contract->tipo_faturamento === 'on_demand';
        $field      = $isOnDemand ? 'valor_hora' : 'valor_projeto';
        $other      = $isOnDemand ? 'valor_projeto' : 'valor_hora';

        $pInicio = $validated['periodo_inicio'] ?? null;
        $pFim    = $validated['periodo_fim'] ?? null;
        $pLabel  = ($pInicio && $pFim)
            ? $this->periodoFormatado(Carbon::parse($pInicio), Carbon::parse($pFim))
            : null;

        DB::transaction(function () use ($contract, $field, $other, $valorNovo, $pct, $validated, $base, $request, $pInicio, $pFim, $pLabel) {
            $updates = [
                $field          => $valorNovo,
                'valor_inicial' => $valorNovo, // nova base p/ o próximo reajuste
                'taxa_reajuste' => EconomicIndexService::canonical($validated['indice']),
                'pct_reajuste'  => null,       // reajuste consumido (sem pendência)
            ];
            // Recalcula o outro campo monetário (se houver) pelo mesmo percentual.
            if ($contract->{$other} !== null) {
                $updates[$other] = round((float) $contract->{$other} * (1 + $pct / 100), 2);
            }
            // Marca o fim do período como "último reajuste" → próximo período continua daqui.
            if ($pFim) {
                $updates['data_ultimo_reajuste'] = $pFim;
            }
            // Avança o vencimento p/ o próximo aniversário.
            if ($contract->data_vencimento) {
                $updates['data_vencimento'] = Carbon::parse($contract->data_vencimento)->addYear()->toDateString();
            }
            $contract->update($updates);

            // Rotina de reajuste mantém o PROJETO sincronizado com o contrato
            // (projeto e contrato sempre iguais). on_demand: valor_hora → hourly_rate;
            // demais: valor_projeto → project_value. Sobrescreve o valor atual — sem
            // vigência (operação recente, sem histórico a preservar).
            if ($contract->project_id) {
                $projField = $field === 'valor_hora' ? 'hourly_rate' : 'project_value';
                Project::where('id', $contract->project_id)->update([$projField => $valorNovo]);
            }

            ContractValueChange::create([
                'contract_id'       => $contract->id,
                'valor_anterior'    => round($base, 2),
                'valor_novo'        => $valorNovo,
                'percentual'        => $pct,
                'indice'            => EconomicIndexService::canonical($validated['indice']),
                'periodo_inicio'    => $pInicio,
                'periodo_fim'       => $pFim,
                'periodo_formatado' => $pLabel,
                'user_id'           => $request->user()?->id,
            ]);
        });

        return response()->json([
            'ok'             => true,
            'valor_anterior' => round($base, 2),
            'valor_novo'     => $valorNovo,
            'percentual'     => $pct,
        ]);
    }

    /**
     * Renovação SEM reajuste: avança a data de vencimento em +1 ano, mantendo
     * o valor inalterado. Registra na história dos reajustes (indice='RENOVACAO')
     * pra ficar auditável. POST /contracts/{id}/renew-no-adjustment.
     */
    public function renewWithoutAdjustment(Request $request, Contract $contract): JsonResponse
    {
        if (!$contract->data_vencimento) {
            return response()->json(['message' => 'Contrato sem data de vencimento para renovar.'], 422);
        }

        $novoVencimento = Carbon::parse($contract->data_vencimento)->addYear()->toDateString();

        DB::transaction(function () use ($contract, $novoVencimento, $request) {
            $contract->update([
                'data_vencimento' => $novoVencimento,
                'pct_reajuste'    => null,
            ]);

            // Auditoria: registra na MESMA história dos reajustes que houve uma
            // renovação SEM reajuste (valor inalterado). indice='RENOVACAO' é o
            // marcador (a história trata esse caso com rótulo próprio).
            $isOnDemand = $contract->tipo_faturamento === 'on_demand';
            $valorAtual = $isOnDemand ? $contract->valor_hora : $contract->valor_projeto;
            $valorAtual = $valorAtual !== null ? round((float) $valorAtual, 2) : 0;
            ContractValueChange::create([
                'contract_id'       => $contract->id,
                'valor_anterior'    => $valorAtual,
                'valor_novo'        => $valorAtual,
                'percentual'        => 0,
                'indice'            => 'RENOVACAO',
                'periodo_formatado' => 'Renovado sem reajuste (+1 ano)',
                'user_id'           => $request->user()?->id,
            ]);
        });

        return response()->json([
            'ok'              => true,
            'data_vencimento' => $novoVencimento,
        ]);
    }

    /**
     * Comunica o cliente sobre o reajuste aplicado (último registro do histórico).
     * POST /contracts/{id}/notify-client-adjustment  (body: email? p/ sobrescrever).
     */
    public function notifyClientAdjustment(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'emails'   => 'nullable|array',
            'emails.*' => 'email',
            'email'    => 'nullable|email', // compat (envio único)
            'salvar'   => 'nullable|boolean', // grava os e-mails na lista do cliente
            'mensagem' => 'nullable|string',  // corpo editável do e-mail
        ]);

        $change = $contract->valueChanges()->latest('created_at')->first();
        if (!$change) {
            return response()->json(['message' => 'Nenhum reajuste aplicado para comunicar.'], 422);
        }

        $emails = $validated['emails'] ?? ($validated['email'] ? [$validated['email']] : $this->clienteEmailsContrato($contract));
        $emails = collect($emails)->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if (!$emails) {
            return response()->json(['message' => 'Informe ao menos um e-mail de destino.'], 422);
        }

        $contract->loadMissing(['customer:id,name', 'project:id,code']);

        // Salva os e-mails na lista administrativa do cliente (mesma usada no fechamento).
        if (!empty($validated['salvar']) && $contract->customer) {
            $merged = array_values(array_unique(array_merge($contract->customer->adminEmails(), $emails)));
            $contract->customer->setAdminEmails($merged);
            $contract->customer->save();
        }
        $mail = new ReajusteClienteMail(
            cliente: $contract->customer?->name ?? 'Cliente',
            contrato: $contract->project?->code ?? $contract->project_code_preview,
            valorAnterior: (float) $change->valor_anterior,
            valorNovo: (float) $change->valor_novo,
            percentual: (float) $change->percentual,
            indice: $change->indice,
            periodoFormatado: $change->periodo_formatado,
            vigencia: optional($change->created_at)->format('d/m/Y') ?? now()->format('d/m/Y'),
            mensagem: $validated['mensagem'] ?? null,
        );

        // Destinatários escolhidos no envio + papéis configurados na Central (cópia/extra).
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste', ['contract' => $contract]);
        $to = array_values(array_unique(array_merge($emails, $rcpt['to'])));
        $cc = array_values(array_diff($rcpt['cc'], $to));

        // Envia COMO o usuário logado (Send As via Graph, igual ao fechamento);
        // fallback p/ o mailbox configurado / mailer default se o Graph estiver off.
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        } else {
            Mail::to($to)->cc($cc)->send($mail);
        }

        return response()->json(['ok' => true, 'emails' => $emails, 'salvos' => !empty($validated['salvar'])]);
    }

    /** E-mails administrativos do cliente p/ comunicados (lista). Fallback: contatos do contrato. */
    private function clienteEmailsContrato(Contract $c): array
    {
        $c->loadMissing('customer');
        $list = $c->customer ? $c->customer->adminEmails() : [];
        if (!$list) {
            $list = $c->contacts()->whereNotNull('email')->where('email', '!=', '')
                ->pluck('email')->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        }
        return $list;
    }

    /** Valor-base do reajuste: valor_inicial salvo, ou o valor atual do contrato. */
    private function valorBaseReajuste(Contract $c): float
    {
        if ($c->valor_inicial !== null) {
            return (float) $c->valor_inicial;
        }
        $field = $c->tipo_faturamento === 'on_demand' ? 'valor_hora' : 'valor_projeto';
        return (float) ($c->{$field} ?? 0);
    }

    /**
     * Período EXPLÍCITO do reajuste:
     *  - Início: dia seguinte ao ÚLTIMO reajuste (continua de onde parou); na falta,
     *            a data-base (data_assinatura). Ancorado no 1º dia do mês.
     *  - Fim:    último mês FECHADO (fim do mês anterior ao atual).
     * Ex.: assinatura 02/07/2024, hoje Jul/2025 → Jul/2024 → Jun/2025.
     *
     * @return array{0:\Carbon\Carbon,1:\Carbon\Carbon}
     */
    private function reajustePeriodo(Contract $c, ?string $start = null, ?string $end = null): array
    {
        if ($start && $end) {
            return [Carbon::parse($start)->startOfMonth(), Carbon::parse($end)->endOfMonth()];
        }

        if ($c->data_ultimo_reajuste) {
            // Continua no mês SEGUINTE ao do último reajuste (sem reincidir o mês já contado).
            $startM = Carbon::parse($c->data_ultimo_reajuste)->startOfMonth()->addMonthNoOverflow();
        } elseif ($c->data_assinatura) {
            $startM = Carbon::parse($c->data_assinatura)->startOfMonth();
        } else {
            $startM = Carbon::now()->subMonthsNoOverflow(12)->startOfMonth();
        }

        $endM = Carbon::now()->subMonthNoOverflow()->endOfMonth(); // último mês fechado

        // Reajuste recente: ainda não há mês fechado novo → período = o próprio mês de início.
        if ($startM->greaterThan($endM)) {
            $endM = $startM->copy()->endOfMonth();
        }

        return [$startM, $endM];
    }

    /** Rótulo do período "Mmm/AAAA → Mmm/AAAA". */
    private function periodoFormatado(Carbon $start, Carbon $end): string
    {
        return $this->mesAno($start) . ' → ' . $this->mesAno($end);
    }

    private function mesAno(Carbon $d): string
    {
        $m = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'][$d->month - 1];
        return $m . '/' . $d->year;
    }

    // ─── Dashboard de reajustes ──────────────────────────────────────────────

    /** Dados de reajuste (linhas) — reusado por summary, list e pelo comando de alerta. */
    public function reajustesData(?int $clienteId = null, ?string $indexType = null): \Illuminate\Support\Collection
    {
        [$ipca, $igpm] = [$this->estimativaIndice('IPCA'), $this->estimativaIndice('IGPM')];
        return $this->reajusteElegiveis($clienteId, $indexType)
            ->map(fn (Contract $c) => $this->reajusteRow($c, $ipca, $igpm))
            ->values();
    }

    /** GET /contracts/reajustes/summary — KPIs do dashboard de reajustes. */
    public function reajustesSummary(Request $request): JsonResponse
    {
        $rows = $this->reajustesData();

        $venc  = $rows->where('status_reajuste', 'vencido');
        $prox  = $rows->where('status_reajuste', 'proximo');
        $emDia = $rows->whereIn('status_reajuste', ['em_dia', 'recente']);

        return response()->json([
            'total_contratos'       => $rows->count(),
            'contratos_em_dia'      => $emDia->count(),
            'contratos_vencidos'    => $venc->count(),
            'contratos_proximos'    => $prox->count(),
            'valor_total_reajustar' => round($venc->sum('valor_estimado_reajuste') + $prox->sum('valor_estimado_reajuste'), 2),
            'valor_total_contratos' => round($rows->sum('valor_atual'), 2),
            // Acumulado desde a assinatura (toda a vida dos contratos).
            'valor_total_acumulado' => round($rows->sum(fn ($r) => $r['valor_acumulado'] ?? $r['valor_atual']), 2),
            'defasagem_acumulada'   => round($rows->sum(fn ($r) => $r['valor_acumulado'] !== null ? $r['valor_acumulado'] - $r['valor_atual'] : 0), 2),
            'indices'               => ['IPCA' => $this->estimativaIndice('IPCA'), 'IGPM' => $this->estimativaIndice('IGPM')],
        ]);
    }

    /** GET /contracts/reajustes — lista priorizada (vencidos → próximos → maior impacto). */
    public function reajustesList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status'     => 'nullable|in:em_dia,proximo,vencido,recente',
            'index_type' => 'nullable|string',
            'cliente_id' => 'nullable|integer',
        ]);

        $rows = $this->reajustesData($validated['cliente_id'] ?? null, $validated['index_type'] ?? null);

        // Inclusões MANUAIS (sem contrato) — só rastreio; aparecem na lista com
        // flag `manual` + `empresa` (ERPSERV|BIZIFY). Não entram no summary/KPIs.
        $rows = $rows->concat(
            \App\Models\ManualReajuste::query()
                ->with('project:id,code,name,hourly_rate')
                ->withCount([
                    'valueChanges as active_changes_count'   => fn ($x) => $x->whereNull('reversed_at'),
                    'valueChanges as reversed_changes_count' => fn ($x) => $x->whereNotNull('reversed_at'),
                ])
                ->withMax(['valueChanges as last_change_at' => fn ($x) => $x->whereNull('reversed_at')], 'created_at')
                ->orderBy('cliente_nome')->get()
                ->map(fn ($m) => $this->manualRow($m))
        )->values();

        if (!empty($validated['status'])) {
            $rows = $rows->where('status_reajuste', $validated['status']);
        }

        // Ordenação: vencido → proximo → em_dia/recente; dentro do grupo, mais urgente; depois maior impacto.
        $ordem = ['vencido' => 0, 'proximo' => 1, 'em_dia' => 2, 'recente' => 3];
        $rows = $rows->sort(function ($a, $b) use ($ordem) {
            $oa = $ordem[$a['status_reajuste']] ?? 9;
            $ob = $ordem[$b['status_reajuste']] ?? 9;
            if ($oa !== $ob) return $oa <=> $ob;
            $da = $a['dias_para_vencimento'] ?? 99999;
            $db = $b['dias_para_vencimento'] ?? 99999;
            if ($da !== $db) return $da <=> $db;
            return $b['valor_estimado_reajuste'] <=> $a['valor_estimado_reajuste'];
        })->values();

        return response()->json(['data' => $rows]);
    }

    /** GET /contracts/{id}/value-changes — histórico de reajustes (Ver histórico). */
    public function valueChanges(Request $request, Contract $contract): JsonResponse
    {
        $rows = $contract->valueChanges()->whereNull('reversed_at')->with('user:id,name')->latest('created_at')->get()
            ->map(fn ($h) => [
                'id'                => $h->id,
                'valor_anterior'    => (float) $h->valor_anterior,
                'valor_novo'        => (float) $h->valor_novo,
                'percentual'        => (float) $h->percentual,
                'indice'            => $h->indice,
                'periodo_formatado' => $h->periodo_formatado,
                'usuario'           => $h->user?->name,
                'data'              => optional($h->created_at)->toDateTimeString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** Contratos sujeitos a reajuste (recorrentes): com assinatura + vencimento. */
    // ─── Inclusão manual de reajuste (sem contrato) ─────────────────────────
    // Itens que não têm contrato cadastrado (licenças, Bizify, sustentação sem
    // contrato). Só rastreio: saldo + datas. Não passam por aplicar/notificar.

    /** Mapeia uma ManualReajuste pro mesmo shape de linha da lista de reajustes. */
    private function manualRow(\App\Models\ManualReajuste $m): array
    {
        $hoje = Carbon::today();
        $proj = $m->project_id ? ($m->relationLoaded('project') ? $m->project : \App\Models\Project::find($m->project_id)) : null;
        $projectBacked = $proj !== null;
        $prox = $m->data_vencimento
            ? Carbon::parse($m->data_vencimento)->startOfDay()
            : ($m->data_ultimo_reajuste ? Carbon::parse($m->data_ultimo_reajuste)->addYear()->startOfDay() : null);
        $dias = $prox ? $hoje->diffInDays($prox, false) : null;

        $recente = $m->data_ultimo_reajuste && Carbon::parse($m->data_ultimo_reajuste)->gte($hoje->copy()->subDays(30));
        if ($recente)            $status = 'recente';
        elseif ($dias === null)  $status = 'em_dia';
        elseif ($dias < 0)       $status = 'vencido';
        elseif ($dias <= 30)     $status = 'proximo';
        else                     $status = 'em_dia';

        $valor = (float) ($m->valor_inicial ?? 0);
        $taxa  = $m->taxa_reajuste ? EconomicIndexService::canonical($m->taxa_reajuste) : 'IPCA';

        return [
            'id'                      => $m->id,
            'manual'                  => true,
            'project_backed'          => $projectBacked,
            'project_id'              => $m->project_id,
            'project_name'            => $proj?->name,
            'can_reverse'             => (int) ($m->active_changes_count ?? 0) > 0
                                          && $m->last_change_at
                                          && Carbon::parse($m->last_change_at)->gte(Carbon::now()->subDays(30)),
            'can_resend'              => (int) ($m->active_changes_count ?? 0) > 0,
            'can_resend_estorno'      => (int) ($m->reversed_changes_count ?? 0) > 0,
            'empresa'                 => $m->empresa,
            'customer_id'             => $m->customer_id,
            'cliente_emails'          => $this->manualClienteEmails($m),
            'cliente_nome'            => $m->cliente_nome,
            'codigo'                  => $projectBacked ? ($proj->code ?? $m->descricao) : $m->descricao,
            'valor_atual'             => round($valor, 2),
            'data_assinatura'         => optional($m->data_assinatura)->toDateString(),
            'valor_inicial'           => round($valor, 2),
            'pct_reajuste'            => $m->pct_reajuste !== null ? (float) $m->pct_reajuste : null,
            'data_ultimo_reajuste'    => optional($m->data_ultimo_reajuste)->toDateString(),
            'data_proximo_reajuste'   => optional($prox)->toDateString(),
            'data_aviso'              => $prox ? $prox->copy()->subMonthNoOverflow()->toDateString() : null,
            'dias_para_vencimento'    => $dias,
            'status_reajuste'         => $status,
            'taxa_reajuste'           => $taxa,
            'percentual_estimado'     => $m->pct_reajuste !== null ? (float) $m->pct_reajuste : 0,
            'valor_estimado_reajuste' => 0,
            'periodo'                 => null,
            'percentual_acumulado'    => null,
            'valor_acumulado'         => null,
            'periodo_acumulado'       => null,
        ];
    }

    private function validateManual(Request $request): array
    {
        $data = $request->validate([
            'cliente_nome'         => 'required|string|max:180',
            'customer_id'          => 'nullable|integer|exists:customers,id',
            'project_id'           => 'nullable|integer|exists:projects,id',
            'descricao'            => 'nullable|string|max:200',
            'empresa'              => 'required|in:ERPSERV,BIZIFY',
            'valor_inicial'        => 'nullable|numeric|min:0',
            'data_assinatura'      => 'nullable|date',
            'data_ultimo_reajuste' => 'nullable|date',
            'data_vencimento'      => 'nullable|date',
            'taxa_reajuste'        => 'nullable|string|in:IPCA,IGPM,IGP-M',
            'pct_reajuste'         => 'nullable|numeric',
        ]);
        if (!empty($data['taxa_reajuste'])) {
            $data['taxa_reajuste'] = EconomicIndexService::canonical($data['taxa_reajuste']);
        }
        // Próximo = último + 1 ano quando não informado explicitamente.
        if (empty($data['data_vencimento']) && !empty($data['data_ultimo_reajuste'])) {
            $data['data_vencimento'] = Carbon::parse($data['data_ultimo_reajuste'])->addYear()->toDateString();
        }
        return $data;
    }

    /** POST /contracts/reajustes/manual */
    public function manualReajusteStore(Request $request): JsonResponse
    {
        $m = \App\Models\ManualReajuste::create($this->validateManual($request));
        return response()->json($this->manualRow($m), 201);
    }

    /** PATCH /contracts/reajustes/manual/{manual} */
    public function manualReajusteUpdate(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $manual->update($this->validateManual($request));
        return response()->json($this->manualRow($manual->fresh()));
    }

    /** DELETE /contracts/reajustes/manual/{manual} */
    public function manualReajusteDestroy(\App\Models\ManualReajuste $manual): JsonResponse
    {
        $manual->delete();
        return response()->json(['ok' => true]);
    }

    /** E-mails do manual: salvos (notify_emails), senão do cliente vinculado. */
    private function manualClienteEmails(\App\Models\ManualReajuste $m): array
    {
        if (is_array($m->notify_emails) && count($m->notify_emails)) {
            return array_values(array_filter(array_map('trim', $m->notify_emails)));
        }
        if ($m->customer_id) {
            $m->loadMissing('customer');
            return $m->customer ? $m->customer->adminEmails() : [];
        }
        return [];
    }

    /** Período do reajuste manual: do último reajuste (ou assinatura) ao último mês fechado. */
    private function manualPeriodo(\App\Models\ManualReajuste $m): array
    {
        $fim   = Carbon::now()->subMonthNoOverflow()->endOfMonth();
        $ancora = $m->data_ultimo_reajuste ?? $m->data_assinatura;
        $inicio = $ancora
            ? Carbon::parse($ancora)->startOfMonth()->addMonthNoOverflow()
            : $fim->copy()->subMonthsNoOverflow(11)->startOfMonth();
        if ($inicio->gt($fim)) $inicio = $fim->copy()->startOfMonth();
        return [$inicio, $fim];
    }

    /** GET /contracts/reajustes/manual/{manual}/adjustment-preview */
    public function manualAdjustmentPreview(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $validated = $request->validate(['index_type' => 'required|string']);
        if (!EconomicIndexService::supports($validated['index_type'])) {
            return response()->json(['message' => 'Índice não suportado. Use IPCA ou IGP-M.'], 422);
        }
        $base = (float) ($manual->valor_inicial ?? 0);
        if ($base <= 0) {
            return response()->json(['message' => 'Sem valor-base para reajuste. Preencha o saldo inicial.'], 422);
        }
        [$start, $end] = $this->manualPeriodo($manual);
        try {
            $idx = app(EconomicIndexService::class)->accumulated($validated['index_type'], $start, $end);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        $pct       = (float) $idx['percentual_total'];
        $valorNovo = round($base * (1 + $pct / 100), 2);
        $periodoFmt = $this->periodoFormatado($start, $end);
        return response()->json([
            'indice'            => $idx['index_type'],
            'percentual'        => $pct,
            'percentual_total'  => $pct,
            'meses_utilizados'  => $idx['meses_utilizados'],
            'valor_atual'       => round($base, 2),
            'valor_novo'        => $valorNovo,
            'valor_field'       => 'valor',
            'cliente_emails'    => $this->manualClienteEmails($manual),
            'periodo_inicio'    => $start->toDateString(),
            'periodo_fim'       => $end->toDateString(),
            'periodo_formatado' => $periodoFmt,
            'periodo'           => ['inicio' => $start->toDateString(), 'fim' => $end->toDateString(), 'label' => $periodoFmt],
        ]);
    }

    /** POST /contracts/reajustes/manual/{manual}/apply-adjustment */
    public function manualApplyAdjustment(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $validated = $request->validate([
            'indice'         => 'required|string',
            'percentual'     => 'required|numeric',
            'periodo_inicio' => 'nullable|date',
            'periodo_fim'    => 'nullable|date',
        ]);
        if (!EconomicIndexService::supports($validated['indice'])) {
            return response()->json(['message' => 'Índice não suportado.'], 422);
        }
        $base = (float) ($manual->valor_inicial ?? 0);
        if ($base <= 0) return response()->json(['message' => 'Sem valor-base para reajuste.'], 422);

        $pct       = (float) $validated['percentual'];
        $valorNovo = round($base * (1 + $pct / 100), 2);
        $pInicio = $validated['periodo_inicio'] ?? null;
        $pFim    = $validated['periodo_fim'] ?? null;
        $pLabel  = ($pInicio && $pFim) ? $this->periodoFormatado(Carbon::parse($pInicio), Carbon::parse($pFim)) : null;

        DB::transaction(function () use ($manual, $valorNovo, $pct, $validated, $base, $request, $pInicio, $pFim, $pLabel) {
            $updates = [
                'valor_inicial'        => $valorNovo, // nova base p/ o próximo
                'taxa_reajuste'        => EconomicIndexService::canonical($validated['indice']),
                'pct_reajuste'         => null,
            ];
            if ($pFim) {
                $updates['data_ultimo_reajuste'] = $pFim;
                $updates['data_vencimento'] = Carbon::parse($pFim)->addYear()->toDateString();
            } elseif ($manual->data_vencimento) {
                $updates['data_vencimento'] = Carbon::parse($manual->data_vencimento)->addYear()->toDateString();
            }
            $manual->update($updates);

            // Projeto sem contrato: o reajuste incide no hourly_rate do projeto.
            if ($manual->project_id) {
                $proj = \App\Models\Project::find($manual->project_id);
                if ($proj && $proj->hourly_rate !== null) {
                    $proj->update(['hourly_rate' => round((float) $proj->hourly_rate * (1 + $pct / 100), 2)]);
                }
            }

            \App\Models\ManualReajusteValueChange::create([
                'manual_reajuste_id' => $manual->id,
                'valor_anterior'     => round($base, 2),
                'valor_novo'         => $valorNovo,
                'percentual'         => $pct,
                'indice'             => EconomicIndexService::canonical($validated['indice']),
                'periodo_inicio'     => $pInicio,
                'periodo_fim'        => $pFim,
                'periodo_formatado'  => $pLabel,
                'user_id'            => $request->user()?->id,
            ]);
        });

        return response()->json(['ok' => true, 'valor_anterior' => round($base, 2), 'valor_novo' => $valorNovo, 'percentual' => $pct]);
    }

    /** POST /contracts/{contract}/reverse-adjustment — estorna o último reajuste. */
    public function reverseAdjustment(Request $request, Contract $contract): JsonResponse
    {
        $change = $contract->valueChanges()->whereNull('reversed_at')->latest('id')->first();
        if (!$change) return response()->json(['message' => 'Nenhum reajuste para estornar.'], 422);
        if ($change->created_at && $change->created_at->lt(now()->subDays(30))) {
            return response()->json(['message' => 'Estorno não permitido: o reajuste foi aplicado há mais de 30 dias.'], 422);
        }
        $prev = $contract->valueChanges()->whereNull('reversed_at')->where('id', '<', $change->id)->latest('id')->first();
        $pct  = (float) $change->percentual;
        $isOnDemand = $contract->tipo_faturamento === 'on_demand';
        $field = $isOnDemand ? 'valor_hora' : 'valor_projeto';
        $other = $isOnDemand ? 'valor_projeto' : 'valor_hora';

        DB::transaction(function () use ($contract, $change, $prev, $pct, $field, $other) {
            $updates = ['valor_inicial' => (float) $change->valor_anterior];
            if ($change->indice !== 'RENOVACAO') {
                $updates[$field] = (float) $change->valor_anterior;
                if ($contract->{$other} !== null && (1 + $pct / 100) != 0) {
                    $updates[$other] = round((float) $contract->{$other} / (1 + $pct / 100), 2);
                }
                $updates['data_ultimo_reajuste'] = $prev?->periodo_fim ? Carbon::parse($prev->periodo_fim)->toDateString() : null;
            }
            if ($contract->data_vencimento) {
                $updates['data_vencimento'] = Carbon::parse($contract->data_vencimento)->subYear()->toDateString();
            }
            $contract->update($updates);
            $change->update(['reversed_at' => now()]); // marca estornado (some do histórico, fica p/ reenvio)
        });

        // Comunica o ESTORNO ao cliente (opcional; renovação sem reajuste não comunica).
        $sent = false;
        if ($change->indice !== 'RENOVACAO' && $request->boolean('notificar', true)) {
            $contract->loadMissing(['customer:id,name', 'project:id,code']);
            $sent = $this->sendEstornoMail(
                $request,
                $contract->customer?->name ?? 'Cliente',
                $contract->project?->code ?? $contract->project_code_preview,
                $this->clienteEmailsContrato($contract),
                ['contract' => $contract, 'customer' => $contract->customer, 'actor' => $request->user()],
                $change,
                $request->input('mensagem')
            );
        }
        return response()->json(['ok' => true, 'valor_atual' => (float) $change->valor_anterior, 'email_sent' => $sent]);
    }

    /** Envia o comunicado de estorno (cliente + cópias internas da Central) como o usuário logado. */
    private function sendEstornoMail(Request $request, string $cliente, ?string $contrato, array $toEmails, array $ccContext, $change, ?string $mensagem = null): bool
    {
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste.estorno', $ccContext);
        $to = array_values(array_unique(array_merge($toEmails, $rcpt['to'])));
        $cc = array_values(array_diff($rcpt['cc'], $to));
        if (!$to) return false;
        $mail = $this->buildReajusteMailFromChange($cliente, $contrato, $change, $mensagem, true);
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        } else {
            Mail::to($to)->cc($cc)->send($mail);
        }
        return true;
    }

    /** POST /contracts/reajustes/manual/{manual}/reverse-adjustment — estorna o último reajuste. */
    public function manualReverseAdjustment(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $change = $manual->valueChanges()->whereNull('reversed_at')->latest('id')->first();
        if (!$change) return response()->json(['message' => 'Nenhum reajuste para estornar.'], 422);
        if ($change->created_at && $change->created_at->lt(now()->subDays(30))) {
            return response()->json(['message' => 'Estorno não permitido: o reajuste foi aplicado há mais de 30 dias.'], 422);
        }
        $prev = $manual->valueChanges()->whereNull('reversed_at')->where('id', '<', $change->id)->latest('id')->first();

        DB::transaction(function () use ($manual, $change, $prev) {
            $updates = [
                'valor_inicial'        => (float) $change->valor_anterior,
                'data_ultimo_reajuste' => $prev?->periodo_fim ? Carbon::parse($prev->periodo_fim)->toDateString() : null,
            ];
            if ($manual->data_vencimento) {
                $updates['data_vencimento'] = Carbon::parse($manual->data_vencimento)->subYear()->toDateString();
            }
            $manual->update($updates);

            // Projeto sem contrato: desfaz o reajuste no hourly_rate do projeto.
            if ($manual->project_id && (float) $change->percentual != 0.0) {
                $proj = \App\Models\Project::find($manual->project_id);
                if ($proj && $proj->hourly_rate !== null) {
                    $proj->update(['hourly_rate' => round((float) $proj->hourly_rate / (1 + (float) $change->percentual / 100), 2)]);
                }
            }

            $change->update(['reversed_at' => now()]); // marca estornado
        });

        // Comunica o ESTORNO ao cliente (opcional; destinatários salvos/do cliente + cópias internas).
        $sent = false;
        if ($request->boolean('notificar', true)) {
            $manual->loadMissing('customer:id,name');
            $sent = $this->sendEstornoMail(
                $request,
                $manual->cliente_nome,
                $manual->descricao ?? '—',
                $this->manualClienteEmails($manual),
                ['customer' => $manual->customer],
                $change,
                $request->input('mensagem')
            );
        }
        return response()->json(['ok' => true, 'valor_atual' => (float) $change->valor_anterior, 'email_sent' => $sent]);
    }

    /** GET /contracts/reajustes/manual/{manual}/value-changes */
    public function manualValueChanges(\App\Models\ManualReajuste $manual): JsonResponse
    {
        $rows = $manual->valueChanges()->whereNull('reversed_at')->with('user:id,name')->latest('created_at')->get()
            ->map(fn ($h) => [
                'id'                => $h->id,
                'valor_anterior'    => (float) $h->valor_anterior,
                'valor_novo'        => (float) $h->valor_novo,
                'percentual'        => (float) $h->percentual,
                'indice'            => $h->indice,
                'periodo_formatado' => $h->periodo_formatado,
                'usuario'           => $h->user?->name,
                'data'              => optional($h->created_at)->toDateTimeString(),
            ]);
        return response()->json(['data' => $rows]);
    }

    /** POST /contracts/reajustes/manual/{manual}/notify-client-adjustment */
    public function manualNotify(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $validated = $request->validate([
            'emails'   => 'nullable|array',
            'emails.*' => 'email',
            'salvar'   => 'nullable|boolean',
            'mensagem' => 'nullable|string',
        ]);
        $change = $manual->valueChanges()->latest('created_at')->first();
        if (!$change) return response()->json(['message' => 'Nenhum reajuste aplicado para comunicar.'], 422);

        $emails = collect($validated['emails'] ?? $this->manualClienteEmails($manual))
            ->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if (!$emails) return response()->json(['message' => 'Informe ao menos um e-mail de destino.'], 422);

        // Salva os e-mails na própria inclusão manual p/ o próximo reajuste.
        if (!empty($validated['salvar'])) {
            $manual->update(['notify_emails' => $emails]);
        }

        $mail = $this->buildReajusteMailFromChange($manual->cliente_nome, $manual->descricao ?? '—', $change, $validated['mensagem'] ?? null);

        // Cópias internas configuradas na Central de Workflows (mesmo workflow do contrato).
        $manual->loadMissing('customer:id,name');
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste', ['customer' => $manual->customer]);
        $to = array_values(array_unique(array_merge($emails, $rcpt['to'])));
        $cc = array_values(array_diff($rcpt['cc'], $to));

        // Envia COMO o usuário logado (Send As via Graph, igual ao fechamento).
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        } else {
            Mail::to($to)->cc($cc)->send($mail);
        }
        return response()->json(['ok' => true, 'emails' => $emails, 'salvos' => !empty($validated['salvar'])]);
    }

    /** Monta o ReajusteClienteMail a partir de um registro de mudança (contrato ou manual). */
    private function buildReajusteMailFromChange(string $cliente, ?string $contrato, $change, ?string $mensagem = null, bool $estorno = false): ReajusteClienteMail
    {
        // No estorno, os valores são invertidos: sai do reajustado e volta ao anterior.
        $valorAnt = $estorno ? (float) $change->valor_novo : (float) $change->valor_anterior;
        $valorNovo = $estorno ? (float) $change->valor_anterior : (float) $change->valor_novo;
        return new ReajusteClienteMail(
            cliente: $cliente ?: 'Cliente',
            contrato: $contrato ?: '—',
            valorAnterior: $valorAnt,
            valorNovo: $valorNovo,
            percentual: (float) $change->percentual,
            indice: $change->indice,
            periodoFormatado: $change->periodo_formatado,
            vigencia: now()->format('d/m/Y'),
            mensagem: $mensagem,
            estorno: $estorno,
        );
    }

    /** GET /contracts/{contract}/adjustment-email-preview — HTML do e-mail (prévia + corpo editável). */
    public function contractAdjustmentEmailPreview(Request $request, Contract $contract): JsonResponse
    {
        $tipo = (string) $request->input('tipo', $request->boolean('estorno') ? 'estorno' : 'reajuste');
        $change = $tipo === 'estorno_resend'
            ? $contract->valueChanges()->whereNotNull('reversed_at')->latest('id')->first()
            : $contract->valueChanges()->whereNull('reversed_at')->latest('id')->first();
        if (!$change) return response()->json(['message' => 'Nada para pré-visualizar.'], 422);
        $contract->loadMissing(['customer:id,name', 'project:id,code']);
        $contrato = $contract->project?->code ?? $contract->project_code_preview;
        $mensagem = $request->filled('mensagem') ? (string) $request->input('mensagem') : null;
        $mail = $this->buildReajusteMailFromChange($contract->customer?->name ?? 'Cliente', $contrato, $change, $mensagem, $tipo !== 'reajuste');
        return response()->json([
            'subject'        => $mail->envelope()->subject,
            'html'           => $mail->render(),
            'mensagem_padrao'=> ReajusteClienteMail::defaultMensagem($contrato, (float) $change->percentual, $change->indice, $change->periodo_formatado),
            'cliente_emails' => $this->clienteEmailsContrato($contract),
        ]);
    }

    /** GET /contracts/reajustes/manual/{manual}/adjustment-email-preview — HTML do e-mail (prévia + corpo editável). */
    public function manualAdjustmentEmailPreview(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $tipo = (string) $request->input('tipo', $request->boolean('estorno') ? 'estorno' : 'reajuste');
        $change = $tipo === 'estorno_resend'
            ? $manual->valueChanges()->whereNotNull('reversed_at')->latest('id')->first()
            : $manual->valueChanges()->whereNull('reversed_at')->latest('id')->first();
        if (!$change) return response()->json(['message' => 'Nada para pré-visualizar.'], 422);
        $contrato = $manual->descricao ?? '—';
        $mensagem = $request->filled('mensagem') ? (string) $request->input('mensagem') : null;
        $mail = $this->buildReajusteMailFromChange($manual->cliente_nome, $contrato, $change, $mensagem, $tipo !== 'reajuste');
        return response()->json([
            'subject'        => $mail->envelope()->subject,
            'html'           => $mail->render(),
            'mensagem_padrao'=> ReajusteClienteMail::defaultMensagem($contrato, (float) $change->percentual, $change->indice, $change->periodo_formatado),
            'cliente_emails' => $this->manualClienteEmails($manual),
        ]);
    }

    /** POST /contracts/{contract}/resend-adjustment — reenvia comunicado (reajuste|estorno). */
    public function resendAdjustment(Request $request, Contract $contract): JsonResponse
    {
        return $this->resendReajusteEmail($request, $contract, 'contract');
    }

    /** POST /contracts/reajustes/manual/{manual}/resend-adjustment */
    public function manualResendAdjustment(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        return $this->resendReajusteEmail($request, $manual, 'manual');
    }

    /** Reenvio do comunicado de reajuste OU estorno, com destinatários editáveis (novos). */
    private function resendReajusteEmail(Request $request, $model, string $kind): JsonResponse
    {
        $validated = $request->validate([
            'tipo'     => 'required|in:reajuste,estorno',
            'emails'   => 'nullable|array',
            'emails.*' => 'email',
            'salvar'   => 'nullable|boolean',
            'mensagem' => 'nullable|string',
        ]);
        $estorno = $validated['tipo'] === 'estorno';
        $change = $estorno
            ? $model->valueChanges()->whereNotNull('reversed_at')->latest('id')->first()
            : $model->valueChanges()->whereNull('reversed_at')->latest('id')->first();
        if (!$change) return response()->json(['message' => 'Nada para reenviar.'], 422);

        if ($kind === 'contract') {
            $model->loadMissing(['customer:id,name', 'project:id,code']);
            $cliente  = $model->customer?->name ?? 'Cliente';
            $contrato = $model->project?->code ?? $model->project_code_preview;
            $defaults = $this->clienteEmailsContrato($model);
            $ctx = ['contract' => $model, 'customer' => $model->customer, 'actor' => $request->user()];
        } else {
            $model->loadMissing('customer:id,name');
            $cliente  = $model->cliente_nome;
            $contrato = $model->descricao ?? '—';
            $defaults = $this->manualClienteEmails($model);
            $ctx = ['customer' => $model->customer];
        }
        $emails = collect($validated['emails'] ?? $defaults)->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if (!$emails) return response()->json(['message' => 'Informe ao menos um e-mail de destino.'], 422);

        if (!empty($validated['salvar'])) {
            if ($kind === 'contract' && $model->customer) {
                $merged = array_values(array_unique(array_merge($model->customer->adminEmails(), $emails)));
                $model->customer->setAdminEmails($merged);
                $model->customer->save();
            } elseif ($kind === 'manual') {
                $model->update(['notify_emails' => $emails]);
            }
        }

        $wfKey = $estorno ? 'contract.reajuste.estorno' : 'contract.reajuste';
        $rcpt  = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($wfKey, $ctx);
        $to = array_values(array_unique(array_merge($emails, $rcpt['to'])));
        $cc = array_values(array_diff($rcpt['cc'], $to));

        $mail = $this->buildReajusteMailFromChange($cliente, $contrato, $change, $validated['mensagem'] ?? null, $estorno);
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) {
            \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        } else {
            Mail::to($to)->cc($cc)->send($mail);
        }
        return response()->json(['ok' => true, 'emails' => $emails]);
    }

    // ─── Aviso prévio de reajuste (comunicado "próximo mês", estimativa) ─────
    private function buildAvisoMail(string $cliente, ?string $contrato, float $base, float $pct, string $indice, ?string $periodoFmt, ?string $mensagem): ReajusteClienteMail
    {
        return new ReajusteClienteMail(
            cliente: $cliente ?: 'Cliente',
            contrato: $contrato ?: '—',
            valorAnterior: round($base, 2),
            valorNovo: round($base * (1 + $pct / 100), 2),
            percentual: round($pct, 4),
            indice: EconomicIndexService::canonical($indice),
            periodoFormatado: $periodoFmt,
            vigencia: Carbon::now()->addMonthNoOverflow()->startOfMonth()->format('d/m/Y'),
            mensagem: $mensagem,
            aviso: true,
        );
    }

    /** GET /contracts/{contract}/aviso-preview?index_type=IPCA|IGPM[&mensagem=] */
    public function contractAvisoPreview(Request $request, Contract $contract): JsonResponse
    {
        $v = $request->validate(['index_type' => 'required|string']);
        if (!EconomicIndexService::supports($v['index_type'])) return response()->json(['message' => 'Índice não suportado.'], 422);
        $base = $this->valorBaseReajuste($contract);
        if ($base <= 0) return response()->json(['message' => 'Contrato sem valor-base para estimar.'], 422);
        [$start, $end] = $this->reajustePeriodo($contract);
        try { $idx = app(EconomicIndexService::class)->accumulated($v['index_type'], $start, $end); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        $pct = (float) $idx['percentual_total']; $pf = $this->periodoFormatado($start, $end);
        $contract->loadMissing(['customer:id,name', 'project:id,code']);
        $contrato = $contract->project?->code ?? $contract->project_code_preview;
        $mensagem = $request->filled('mensagem') ? (string) $request->input('mensagem') : null;
        $mail = $this->buildAvisoMail($contract->customer?->name ?? 'Cliente', $contrato, $base, $pct, $v['index_type'], $pf, $mensagem);
        return response()->json([
            'subject' => $mail->envelope()->subject, 'html' => $mail->render(),
            'mensagem_padrao' => ReajusteClienteMail::defaultMensagem($contrato, $pct, EconomicIndexService::canonical($v['index_type']), $pf, 'aviso'),
            'cliente_emails' => $this->clienteEmailsContrato($contract),
            'percentual' => round($pct, 4), 'valor_estimado' => round($base * (1 + $pct / 100), 2),
        ]);
    }

    /** POST /contracts/{contract}/aviso-send */
    public function contractAvisoSend(Request $request, Contract $contract): JsonResponse
    {
        $v = $request->validate(['index_type' => 'required|string', 'emails' => 'nullable|array', 'emails.*' => 'email', 'mensagem' => 'nullable|string', 'salvar' => 'nullable|boolean']);
        if (!EconomicIndexService::supports($v['index_type'])) return response()->json(['message' => 'Índice não suportado.'], 422);
        $base = $this->valorBaseReajuste($contract);
        if ($base <= 0) return response()->json(['message' => 'Contrato sem valor-base.'], 422);
        [$start, $end] = $this->reajustePeriodo($contract);
        try { $idx = app(EconomicIndexService::class)->accumulated($v['index_type'], $start, $end); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        $pct = (float) $idx['percentual_total']; $pf = $this->periodoFormatado($start, $end);
        $contract->loadMissing(['customer:id,name', 'project:id,code']);
        $emails = collect($v['emails'] ?? $this->clienteEmailsContrato($contract))->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if (!$emails) return response()->json(['message' => 'Informe ao menos um e-mail.'], 422);
        if (!empty($v['salvar']) && $contract->customer) {
            $contract->customer->setAdminEmails(array_values(array_unique(array_merge($contract->customer->adminEmails(), $emails))));
            $contract->customer->save();
        }
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste', ['contract' => $contract, 'customer' => $contract->customer, 'actor' => $request->user()]);
        $to = array_values(array_unique(array_merge($emails, $rcpt['to']))); $cc = array_values(array_diff($rcpt['cc'], $to));
        $mail = $this->buildAvisoMail($contract->customer?->name ?? 'Cliente', $contract->project?->code ?? $contract->project_code_preview, $base, $pct, $v['index_type'], $pf, $v['mensagem'] ?? null);
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        else Mail::to($to)->cc($cc)->send($mail);
        return response()->json(['ok' => true, 'emails' => $emails]);
    }

    /** GET /contracts/reajustes/manual/{manual}/aviso-preview */
    public function manualAvisoPreview(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $v = $request->validate(['index_type' => 'required|string']);
        if (!EconomicIndexService::supports($v['index_type'])) return response()->json(['message' => 'Índice não suportado.'], 422);
        $base = (float) ($manual->valor_inicial ?? 0);
        if ($base <= 0) return response()->json(['message' => 'Sem valor-base para estimar.'], 422);
        [$start, $end] = $this->manualPeriodo($manual);
        try { $idx = app(EconomicIndexService::class)->accumulated($v['index_type'], $start, $end); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        $pct = (float) $idx['percentual_total']; $pf = $this->periodoFormatado($start, $end);
        $contrato = $manual->descricao ?? '—';
        $mensagem = $request->filled('mensagem') ? (string) $request->input('mensagem') : null;
        $mail = $this->buildAvisoMail($manual->cliente_nome, $contrato, $base, $pct, $v['index_type'], $pf, $mensagem);
        return response()->json([
            'subject' => $mail->envelope()->subject, 'html' => $mail->render(),
            'mensagem_padrao' => ReajusteClienteMail::defaultMensagem($contrato, $pct, EconomicIndexService::canonical($v['index_type']), $pf, 'aviso'),
            'cliente_emails' => $this->manualClienteEmails($manual),
            'percentual' => round($pct, 4), 'valor_estimado' => round($base * (1 + $pct / 100), 2),
        ]);
    }

    /** POST /contracts/reajustes/manual/{manual}/aviso-send */
    public function manualAvisoSend(Request $request, \App\Models\ManualReajuste $manual): JsonResponse
    {
        $v = $request->validate(['index_type' => 'required|string', 'emails' => 'nullable|array', 'emails.*' => 'email', 'mensagem' => 'nullable|string', 'salvar' => 'nullable|boolean']);
        if (!EconomicIndexService::supports($v['index_type'])) return response()->json(['message' => 'Índice não suportado.'], 422);
        $base = (float) ($manual->valor_inicial ?? 0);
        if ($base <= 0) return response()->json(['message' => 'Sem valor-base.'], 422);
        [$start, $end] = $this->manualPeriodo($manual);
        try { $idx = app(EconomicIndexService::class)->accumulated($v['index_type'], $start, $end); }
        catch (\Throwable $e) { return response()->json(['message' => $e->getMessage()], 502); }
        $pct = (float) $idx['percentual_total']; $pf = $this->periodoFormatado($start, $end);
        $manual->loadMissing('customer:id,name');
        $emails = collect($v['emails'] ?? $this->manualClienteEmails($manual))->map(fn ($e) => trim((string) $e))->filter()->unique()->values()->all();
        if (!$emails) return response()->json(['message' => 'Informe ao menos um e-mail.'], 422);
        if (!empty($v['salvar'])) $manual->update(['notify_emails' => $emails]);
        $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve('contract.reajuste', ['customer' => $manual->customer]);
        $to = array_values(array_unique(array_merge($emails, $rcpt['to']))); $cc = array_values(array_diff($rcpt['cc'], $to));
        $mail = $this->buildAvisoMail($manual->cliente_nome, $manual->descricao ?? '—', $base, $pct, $v['index_type'], $pf, $v['mensagem'] ?? null);
        $graphFrom = $request->user()?->email ?: config('services.graph.mailbox');
        if (\App\Services\GraphMailer::enabled() && $graphFrom) \App\Services\GraphMailer::sendAs($graphFrom, $to, $cc, $mail->envelope()->subject, $mail->render());
        else Mail::to($to)->cc($cc)->send($mail);
        return response()->json(['ok' => true, 'emails' => $emails]);
    }

    private function reajusteElegiveis(?int $clienteId = null, ?string $indexType = null): \Illuminate\Support\Collection
    {
        $q = Contract::query()
            ->whereNotNull('data_assinatura')
            ->whereNotNull('data_vencimento')
            ->withCount([
                'valueChanges as active_changes_count'   => fn ($x) => $x->whereNull('reversed_at'),
                'valueChanges as reversed_changes_count' => fn ($x) => $x->whereNotNull('reversed_at'),
            ])
            ->withMax(['valueChanges as last_change_at' => fn ($x) => $x->whereNull('reversed_at')], 'created_at')
            ->with(['customer:id,name', 'project:id,code']);

        if ($clienteId) {
            $q->where('customer_id', $clienteId);
        }
        if ($indexType && EconomicIndexService::supports($indexType)) {
            $q->where('taxa_reajuste', EconomicIndexService::canonical($indexType));
        }

        return $q->get();
    }

    /** Linha de reajuste de um contrato (status, prazos, impacto estimado). */
    private function reajusteRow(Contract $c, float $ipca, float $igpm): array
    {
        $valorAtual = $this->valorBaseReajuste($c);
        $hoje       = Carbon::today();
        $prox       = $c->data_vencimento ? Carbon::parse($c->data_vencimento)->startOfDay() : null;
        $dias       = $prox ? $hoje->diffInDays($prox, false) : null; // >0 futuro, <0 vencido

        $taxaCanon = $c->taxa_reajuste ? EconomicIndexService::canonical($c->taxa_reajuste) : 'IPCA';
        $pctEst    = $c->pct_reajuste !== null ? (float) $c->pct_reajuste : ($taxaCanon === 'IGPM' ? $igpm : $ipca);
        $impacto   = round($valorAtual * $pctEst / 100, 2);

        // recém-reajustado (últimos 30 dias) → não alertar.
        $recente = $c->data_ultimo_reajuste && Carbon::parse($c->data_ultimo_reajuste)->gte($hoje->copy()->subDays(30));
        if ($recente) {
            $status = 'recente';
        } elseif ($dias === null) {
            $status = 'em_dia';
        } elseif ($dias < 0) {
            $status = 'vencido';
        } elseif ($dias <= 30) {
            $status = 'proximo';
        } else {
            $status = 'em_dia';
        }

        [$ps, $pe] = $this->reajustePeriodo($c);

        // Acumulado "desde o início": índice do contrato da assinatura até o último mês fechado.
        $pctAcum   = $this->acumuladoDesdeInicio($c, $taxaCanon);
        $valorIni  = $c->valor_inicial !== null ? (float) $c->valor_inicial : $valorAtual;
        $valorAcum = $pctAcum !== null ? round($valorIni * (1 + $pctAcum / 100), 2) : null;
        $aIni      = $c->data_assinatura ? Carbon::parse($c->data_assinatura)->startOfMonth() : null;
        $aFim      = Carbon::now()->subMonthNoOverflow()->endOfMonth();

        return [
            'id'                      => $c->id,
            'can_reverse'             => (int) ($c->active_changes_count ?? 0) > 0
                                          && $c->last_change_at
                                          && Carbon::parse($c->last_change_at)->gte(Carbon::now()->subDays(30)),
            'can_resend'              => (int) ($c->active_changes_count ?? 0) > 0,
            'can_resend_estorno'      => (int) ($c->reversed_changes_count ?? 0) > 0,
            'cliente_nome'            => $c->customer?->name,
            'codigo'                  => $c->project?->code ?? $c->project_code_preview,
            'valor_atual'             => round($valorAtual, 2),
            // Campos editáveis (cadastro) — usados pelo modal "Editar" na dashboard.
            'data_assinatura'         => optional($c->data_assinatura)->toDateString(),
            'valor_inicial'           => $c->valor_inicial !== null ? (float) $c->valor_inicial : round($valorAtual, 2),
            'pct_reajuste'            => $c->pct_reajuste !== null ? (float) $c->pct_reajuste : null,
            'data_ultimo_reajuste'    => optional($c->data_ultimo_reajuste)->toDateString(),
            'data_proximo_reajuste'   => optional($prox)->toDateString(),
            'data_aviso'              => $prox ? $prox->copy()->subMonthNoOverflow()->toDateString() : null,
            'dias_para_vencimento'    => $dias,
            'status_reajuste'         => $status,
            'taxa_reajuste'           => $taxaCanon,
            'percentual_estimado'     => round($pctEst, 4),
            'valor_estimado_reajuste' => $impacto,
            'periodo'                 => [
                'inicio' => $ps->toDateString(),
                'fim'    => $pe->toDateString(),
                'label'  => $this->periodoFormatado($ps, $pe),
            ],
            // Reajuste acumulado desde a assinatura (toda a vida do contrato).
            'percentual_acumulado'    => $pctAcum,
            'valor_acumulado'         => $valorAcum,
            'periodo_acumulado'       => ($pctAcum !== null && $aIni) ? [
                'inicio' => $aIni->toDateString(),
                'fim'    => $aFim->toDateString(),
                'label'  => $this->periodoFormatado($aIni, $aFim),
            ] : null,
        ];
    }

    /** Estimativa do índice = acumulado dos últimos 12 meses fechados (cache 12h). */
    private function estimativaIndice(string $tipo): float
    {
        $canon = EconomicIndexService::canonical($tipo);
        return cache()->remember("reajuste_idx_{$canon}_12m", now()->addHours(12), function () use ($canon) {
            try {
                $end   = Carbon::now()->subMonthNoOverflow()->endOfMonth();
                $start = $end->copy()->subMonthsNoOverflow(11)->startOfMonth();
                return (float) app(EconomicIndexService::class)->accumulated($canon, $start, $end)['percentual_total'];
            } catch (\Throwable $e) {
                return 0.0;
            }
        });
    }

    /** Série mensal do índice (map 'AAAA-MM' => variação %), cacheada 12h (1 chamada BCB por índice). */
    private function serieMensal(string $canon): array
    {
        return cache()->remember("reajuste_serie_{$canon}", now()->addHours(12), function () use ($canon) {
            try {
                $end   = Carbon::now()->subMonthNoOverflow()->endOfMonth();
                $start = Carbon::create(2010, 1, 1);
                $res   = app(EconomicIndexService::class)->accumulated($canon, $start, $end);
                $map = [];
                foreach ($res['meses'] as $m) {
                    if (!empty($m['mes'])) {
                        [, $mo, $y] = explode('/', $m['mes']);
                        $map["{$y}-{$mo}"] = $m['valor'];
                    }
                }
                return $map;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    /** % acumulado do índice do contrato da assinatura até o último mês fechado (composto). */
    private function acumuladoDesdeInicio(Contract $c, string $canon): ?float
    {
        if (!$c->data_assinatura) {
            return null;
        }
        $serie = $this->serieMensal($canon);
        if (!$serie) {
            return null;
        }
        $startYm = Carbon::parse($c->data_assinatura)->format('Y-m');
        $endYm   = Carbon::now()->subMonthNoOverflow()->format('Y-m');
        $fator = 1.0; $achou = false;
        foreach ($serie as $ym => $v) {
            if (strcmp($ym, $startYm) >= 0 && strcmp($ym, $endYm) <= 0) {
                $fator *= (1 + $v / 100);
                $achou = true;
            }
        }
        return $achou ? round(($fator - 1) * 100, 4) : null;
    }

}
