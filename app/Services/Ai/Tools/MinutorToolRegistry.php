<?php

namespace App\Services\Ai\Tools;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\MovideskTicket;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registro de tools disponíveis pro BOT consultar dados do Minutor.
 * Format compatível com Anthropic tool_use API.
 *
 * Cada tool tem um SCOPE pra controle de permissão granular:
 *  - customer   → cadastros e visão geral de clientes
 *  - project    → detalhes de projetos
 *  - contract   → contratos comerciais e pipeline
 *  - financial  → apontamentos/timesheets e despesas
 *  - billing    → faturamento (cliente)
 *  - payroll    → pagamento de consultor
 *  - bankhours  → banco de horas
 *  - approvals  → pendências de aprovação
 *  - overview   → resumos agregados
 *
 * Se um agent tiver `allowed_scopes` configurado, apenas tools dos escopos
 * permitidos são apresentadas ao LLM (em filterByScopes).
 */
class MinutorToolRegistry
{
    /**
     * Mapeia cada tool ao seu escopo.
     * @var array<string,string>
     */
    public const TOOL_SCOPE = [
        'search_customer'              => 'customer',
        'get_customer_overview'        => 'customer',
        'list_customer_projects'       => 'project',
        'get_project_details'          => 'project',
        'get_project_schedule'         => 'project',
        'list_customer_contracts'      => 'contract',
        'list_contracts_pipeline'      => 'contract',
        'get_consultant_summary'       => 'financial',
        'list_consultant_expenses'     => 'financial',
        'list_late_timesheets'         => 'financial',
        'list_pending_approvals'       => 'approvals',
        'list_pending_expense_payments' => 'approvals',
        'get_customer_billing_status'  => 'billing',
        'get_consultant_payroll'       => 'payroll',
        'get_consultant_payroll_breakdown' => 'payroll',
        'get_consultant_bank_hours'    => 'bankhours',
        'list_critical_bank_hours'     => 'bankhours',
        'get_consultant_capacity'      => 'financial',
        'get_financial_overview'       => 'overview',
        'get_movidesk_ticket'          => 'support',
        'list_consultant_tickets'      => 'support',
        'list_customer_tickets'        => 'support',
    ];

    public const ALL_SCOPES = [
        'customer', 'project', 'contract', 'financial',
        'billing', 'payroll', 'bankhours', 'approvals', 'overview', 'support',
    ];

    /**
     * Definições das tools no formato Anthropic.
     * @return array<int,array{name:string,description:string,input_schema:array}>
     */
    public function definitions(?array $allowedScopes = null): array
    {
        $all = [
            // ── CUSTOMER ────────────────────────────────────────────
            [
                'name' => 'search_customer',
                'description' => 'Busca cliente por nome ou parte do nome. Retorna até 5 resultados com id, nome, cnpj e status ativo. Use isto antes de qualquer outra consulta para resolver o customer_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Nome ou parte do nome do cliente (case-insensitive)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_customer_overview',
                'description' => 'Retorna visão geral do cliente: total de projetos por status, total de horas vendidas/consumidas/saldo agregado, total de contratos. Use para perguntas de "como está o cliente X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'ID do cliente (obter via search_customer primeiro)'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],

            // ── PROJECT ─────────────────────────────────────────────
            [
                'name' => 'list_customer_projects',
                'description' => 'Lista todos os projetos de um cliente com nome, código, status, horas vendidas, horas consumidas e saldo. Use para perguntas como "quais os projetos do cliente X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'only_active'  => ['type' => 'boolean', 'description' => 'Se true, filtra apenas status=started ou awaiting_start. Default: false (todos).'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'get_project_details',
                'description' => 'Detalhes de um projeto específico: nome, status, datas, horas, contrato vinculado, executivo, vendedor, arquiteto. Use após listar projetos para aprofundar em um.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                    ],
                    'required' => ['project_id'],
                ],
            ],

            [
                'name' => 'get_project_schedule',
                'description' => 'Retorna cronograma/etapas do projeto: lista de stages, status de cada um, atividades pendentes e horas alocadas. Use para perguntas como "como está o cronograma do projeto X" ou "quais etapas estão atrasadas".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer'],
                    ],
                    'required' => ['project_id'],
                ],
            ],

            // ── CONTRACT ────────────────────────────────────────────
            [
                'name' => 'list_customer_contracts',
                'description' => 'Lista contratos de um cliente com tipo, status, valor, horas contratadas. Use para perguntas sobre contratos comerciais.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],
            [
                'name' => 'list_contracts_pipeline',
                'description' => 'Lista contratos no pipeline (kanban) por coluna: lead, proposta, negociacao, inicio_autorizado, alocado, em_andamento, encerrado. Use para perguntas sobre "como está o pipeline" ou "quais contratos estão em proposta".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'kanban_status' => ['type' => 'string', 'description' => 'Coluna específica do kanban; se omitido, lista todas as colunas.'],
                        'limit'         => ['type' => 'integer', 'description' => 'Limite total de contratos (default 50)'],
                    ],
                ],
            ],

            // ── FINANCIAL (apontamentos/despesas) ──────────────────
            [
                'name' => 'get_consultant_summary',
                'description' => 'Resumo do consultor no mês corrente: total de horas apontadas (aprovadas, pendentes, rejeitadas), número de projetos ativos, despesas totais e clientes atendidos. Use para perguntas como "como está o consultor X no mês".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer', 'description' => 'ID do consultor (user)'],
                        'month'   => ['type' => 'string', 'description' => 'Mês em formato YYYY-MM. Se omitido, usa o mês corrente.'],
                    ],
                    'required' => ['user_id'],
                ],
            ],

            [
                'name' => 'list_consultant_expenses',
                'description' => 'Lista despesas de um consultor no mês, com status (pendente, aprovada, paga), valor total e detalhe das 20 mais recentes. Use para perguntas como "quais despesas o Fulano tem em junho" ou "tem alguma despesa pendente do consultor X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'month'   => ['type' => 'string', 'description' => 'YYYY-MM (default: mês corrente)'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'list_late_timesheets',
                'description' => 'Lista apontamentos com status "late" (atrasados em relação à data) ou em conflito. Use pra investigar "quem está atrasado no apontamento" ou "tem apontamento com conflito".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id'     => ['type' => 'integer', 'description' => 'Filtra por consultor (opcional)'],
                        'customer_id' => ['type' => 'integer', 'description' => 'Filtra por cliente (opcional)'],
                        'limit'       => ['type' => 'integer', 'description' => 'Default 30, máx 100'],
                    ],
                ],
            ],
            [
                'name' => 'get_consultant_capacity',
                'description' => 'Capacidade do consultor: horas contratuais, horas alocadas em projetos, % de utilização. Use para perguntas como "o Fulano está disponível?" ou "quanto sobra de capacidade do consultor X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                    ],
                    'required' => ['user_id'],
                ],
            ],

            // ── APPROVALS ──────────────────────────────────────────
            [
                'name' => 'list_pending_approvals',
                'description' => 'Lista apontamentos e despesas pendentes de aprovação no sistema. Total agregado por tipo + amostra dos 20 mais antigos. Use para perguntas como "quantas aprovações pendentes" ou "o que está parado".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer', 'description' => 'Filtra pendências apenas desse cliente (opcional).'],
                    ],
                ],
            ],

            [
                'name' => 'list_pending_expense_payments',
                'description' => 'Despesas que já foram aprovadas mas ainda não foram pagas. Use para perguntas tipo "tem alguma despesa esperando pagamento" ou "o que está aberto no financeiro pra reembolso".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'days_since_approved' => ['type' => 'integer', 'description' => 'Filtra despesas aprovadas há mais de N dias (opcional).'],
                    ],
                ],
            ],

            // ── BILLING ────────────────────────────────────────────
            [
                'name' => 'get_customer_billing_status',
                'description' => 'Status de faturamento do cliente no mês corrente: total faturável, total já faturado, em ajuste e pendente de revisão. Use para perguntas sobre faturamento, cobrança ou "quanto a gente vai cobrar do cliente X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'month'       => ['type' => 'string', 'description' => 'Mês em YYYY-MM (default: mês corrente)'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],

            // ── PAYROLL ────────────────────────────────────────────
            [
                'name' => 'get_consultant_payroll',
                'description' => 'Resumo do pagamento de um consultor no mês corrente: total de horas pagáveis, valor estimado de pagamento, despesas reembolsáveis. Use para perguntas como "quanto vai ser pago pro consultor X esse mês".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'month'   => ['type' => 'string', 'description' => 'YYYY-MM. Default: mês corrente.'],
                    ],
                    'required' => ['user_id'],
                ],
            ],

            // ── BANK HOURS ─────────────────────────────────────────
            [
                'name' => 'get_consultant_bank_hours',
                'description' => 'Saldo de banco de horas do consultor: horas contratuais x trabalhadas no mês, saldo positivo/negativo. Use para perguntas como "qual o saldo do banco de horas do consultor X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'month'   => ['type' => 'string', 'description' => 'YYYY-MM (default: mês corrente)'],
                    ],
                    'required' => ['user_id'],
                ],
            ],

            [
                'name' => 'list_critical_bank_hours',
                'description' => 'Consultores com banco de horas crítico (saldo muito negativo ou positivo). Use pra perguntas como "quem está com saldo crítico" ou "tem alguém estourando o banco?".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'threshold' => ['type' => 'integer', 'description' => 'Considera crítico saldo |x| > threshold. Default 16 (horas).'],
                    ],
                ],
            ],

            // ── PAYROLL BREAKDOWN ──────────────────────────────────
            [
                'name' => 'get_consultant_payroll_breakdown',
                'description' => 'Breakdown detalhado do fechamento do consultor: horas aprovadas + pagáveis por projeto e cliente, com valores estimados por linha. Use após get_consultant_payroll quando precisar abrir os números por projeto ("o que ele fez de fato esse mês").',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'month'   => ['type' => 'string', 'description' => 'YYYY-MM (default: mês corrente)'],
                    ],
                    'required' => ['user_id'],
                ],
            ],

            // ── SUPPORT (Movidesk) ─────────────────────────────────
            [
                'name' => 'get_movidesk_ticket',
                'description' => 'Detalhes de um ticket Movidesk: status, urgência, categoria, responsável, datas (criado, resolvido, fechado), SLA de resposta/solução. Use para perguntas como "como está o ticket 1234" ou "qual o status do chamado X".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticket_id' => ['type' => 'integer', 'description' => 'Número do ticket no Movidesk'],
                    ],
                    'required' => ['ticket_id'],
                ],
            ],
            [
                'name' => 'list_consultant_tickets',
                'description' => 'Lista os tickets Movidesk atribuídos a um consultor. Resumo por status + lista detalhada. Use para perguntas como "quais tickets o Fulano tem em aberto".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'status'  => ['type' => 'string', 'description' => 'Filtra por status (opcional). Ex: Em atendimento, Aguardando, Resolvido.'],
                        'limit'   => ['type' => 'integer', 'description' => 'Default 30, máx 100'],
                    ],
                    'required' => ['user_id'],
                ],
            ],
            [
                'name' => 'list_customer_tickets',
                'description' => 'Resumo de tickets Movidesk do cliente: total + agregação por status + amostra dos 20 mais recentes. Use para perguntas como "quantos chamados o cliente X tem" ou "como está o suporte do cliente Y".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_id' => ['type' => 'integer'],
                        'only_open'   => ['type' => 'boolean', 'description' => 'Se true, ignora status Resolvido/Fechado/Cancelado.'],
                    ],
                    'required' => ['customer_id'],
                ],
            ],

            // ── OVERVIEW ───────────────────────────────────────────
            [
                'name' => 'get_financial_overview',
                'description' => 'Visão financeira global do mês corrente: total de horas apontadas, total faturável agregado, total de despesas, pendências de aprovação. Use para perguntas executivas como "como está o mês" ou "qual o resumo financeiro".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'month' => ['type' => 'string', 'description' => 'YYYY-MM (default: mês corrente)'],
                    ],
                ],
            ],
        ];

        if ($allowedScopes === null || empty($allowedScopes)) {
            return $all;
        }

        return array_values(array_filter($all, fn ($def) =>
            in_array(self::TOOL_SCOPE[$def['name']] ?? '', $allowedScopes, true)
        ));
    }

    /**
     * Executa uma tool pelo nome e retorna o resultado como array serializável.
     * Se $allowedScopes for passado e a tool não estiver nele, retorna erro.
     */
    public function execute(string $name, array $input, ?array $allowedScopes = null, ?\App\Models\User $user = null): array
    {
        if ($allowedScopes !== null) {
            $scope = self::TOOL_SCOPE[$name] ?? null;
            if (! $scope || ! in_array($scope, $allowedScopes, true)) {
                return ['error' => "Tool '$name' fora do escopo permitido (scope=$scope)"];
            }
        }

        // Camada de visibilidade (self/team/all) — força user_id/customer_id quando aplicável
        if ($user) {
            $acl = app(\App\Services\Ai\BotAccessControl::class)->applyToolFilters($user, $name, $input);
            if (! $acl['allowed']) {
                return ['error' => $acl['reason'] ?? 'Sem permissão.'];
            }
            $input = $acl['input'];
        }

        try {
            return $this->dispatchTool($name, $input);
        } catch (\Illuminate\Database\QueryException $e) {
            // Vaza zero detalhes de SQL pro usuário — só fala que precisa especificar.
            \Illuminate\Support\Facades\Log::error("[MinutorTool:{$name}] DB error", [
                'sql'   => $e->getSql() ?? null,
                'error' => $e->getMessage(),
                'input' => $input,
            ]);
            return [
                'error' => 'Não consegui montar essa consulta com as informações que você passou. '
                    . 'Pode me dar mais detalhes? (ex.: nome do cliente, mês, status, número do contrato).',
                'hint'  => 'Posso buscar por nome do cliente ou ID. Diga também o período (ex.: junho/2026) se for um fechamento.',
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[MinutorTool:{$name}] error", [
                'error' => $e->getMessage(),
                'input' => $input,
            ]);
            return [
                'error' => 'Não foi possível concluir essa consulta. Pode reformular o pedido com mais detalhes?',
            ];
        }
    }

    private function dispatchTool(string $name, array $input): array
    {
        return match ($name) {
            'search_customer'             => $this->searchCustomer((string) ($input['query'] ?? '')),
            'get_customer_overview'       => $this->customerOverview((int) ($input['customer_id'] ?? 0)),
            'list_customer_projects'      => $this->listProjects((int) ($input['customer_id'] ?? 0), (bool) ($input['only_active'] ?? false)),
            'get_project_details'         => $this->projectDetails((int) ($input['project_id'] ?? 0)),
            'list_customer_contracts'     => $this->listContracts((int) ($input['customer_id'] ?? 0)),
            'list_contracts_pipeline'     => $this->contractsPipeline($input['kanban_status'] ?? null, (int) ($input['limit'] ?? 50)),
            'get_consultant_summary'      => $this->consultantSummary((int) ($input['user_id'] ?? 0), (string) ($input['month'] ?? '')),
            'list_pending_approvals'      => $this->pendingApprovals(isset($input['customer_id']) ? (int) $input['customer_id'] : null),
            'get_customer_billing_status' => $this->customerBilling((int) ($input['customer_id'] ?? 0), (string) ($input['month'] ?? '')),
            'get_consultant_payroll'      => $this->consultantPayroll((int) ($input['user_id'] ?? 0), (string) ($input['month'] ?? '')),
            'get_consultant_bank_hours'   => $this->consultantBankHours((int) ($input['user_id'] ?? 0), (string) ($input['month'] ?? '')),
            'get_consultant_payroll_breakdown' => $this->consultantPayrollBreakdown((int) ($input['user_id'] ?? 0), (string) ($input['month'] ?? '')),
            'get_financial_overview'      => $this->financialOverview((string) ($input['month'] ?? '')),
            'get_movidesk_ticket'         => $this->movideskTicket((int) ($input['ticket_id'] ?? 0)),
            'list_consultant_tickets'     => $this->consultantTickets((int) ($input['user_id'] ?? 0), $input['status'] ?? null, (int) ($input['limit'] ?? 30)),
            'list_customer_tickets'       => $this->customerTickets((int) ($input['customer_id'] ?? 0), (bool) ($input['only_open'] ?? false)),
            'get_project_schedule'        => $this->projectSchedule((int) ($input['project_id'] ?? 0)),
            'list_consultant_expenses'    => $this->consultantExpenses((int) ($input['user_id'] ?? 0), (string) ($input['month'] ?? '')),
            'list_late_timesheets'        => $this->lateTimesheets(isset($input['user_id']) ? (int) $input['user_id'] : null, isset($input['customer_id']) ? (int) $input['customer_id'] : null, (int) ($input['limit'] ?? 30)),
            'get_consultant_capacity'     => $this->consultantCapacity((int) ($input['user_id'] ?? 0)),
            'list_pending_expense_payments' => $this->pendingExpensePayments(isset($input['days_since_approved']) ? (int) $input['days_since_approved'] : null),
            'list_critical_bank_hours'    => $this->criticalBankHours((int) ($input['threshold'] ?? 16)),
            default                       => ['error' => "Tool desconhecida: $name"],
        };
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers internos
    // ────────────────────────────────────────────────────────────────

    private function monthRange(string $month): array
    {
        try {
            $start = $month ? Carbon::parse($month . '-01')->startOfMonth() : Carbon::now()->startOfMonth();
        } catch (\Throwable) {
            $start = Carbon::now()->startOfMonth();
        }
        return [$start, $start->copy()->endOfMonth()];
    }

    private function searchCustomer(string $q): array
    {
        if (trim($q) === '') return ['error' => 'query vazia'];

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $rows = Customer::query()
            ->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                  ->orWhere('company_name', 'ilike', $like);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'company_name', 'cgc', 'active']);

        return ['count' => $rows->count(), 'customers' => $rows->map(fn ($c) => [
            'id'           => $c->id,
            'name'         => $c->name,
            'company_name' => $c->company_name,
            'cnpj'         => $c->cgc,
            'active'       => (bool) $c->active,
        ])->all()];
    }

    private function customerOverview(int $customerId): array
    {
        if ($customerId <= 0) return ['error' => 'customer_id inválido'];
        $customer = Customer::find($customerId);
        if (! $customer) return ['error' => 'cliente não encontrado'];

        $projects = Project::where('customer_id', $customerId)->get(['id', 'status', 'sold_hours']);
        $byStatus = $projects->groupBy('status')->map->count();
        $totalSold = $projects->sum(fn ($p) => (float) ($p->sold_hours ?? 0));

        $consumed = (float) Timesheet::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        return [
            'customer'         => ['id' => $customer->id, 'name' => $customer->name],
            'projects_by_status' => $byStatus,
            'projects_total'   => $projects->count(),
            'hours_sold'       => round($totalSold, 2),
            'hours_consumed'   => round($consumed, 2),
            'hours_balance'    => round($totalSold - $consumed, 2),
            'contracts_total'  => Contract::where('customer_id', $customerId)->count(),
        ];
    }

    private function listProjects(int $customerId, bool $onlyActive): array
    {
        if ($customerId <= 0) return ['error' => 'customer_id inválido'];

        $q = Project::query()->where('customer_id', $customerId);
        if ($onlyActive) {
            $q->whereIn('status', ['started', 'awaiting_start']);
        }

        $consumedByProject = Timesheet::query()
            ->whereIn('customer_id', [$customerId])
            ->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->select('project_id', DB::raw('SUM(effort_minutes)::numeric / 60 AS hours'))
            ->groupBy('project_id')
            ->pluck('hours', 'project_id');

        $projects = $q->orderBy('name')->get();

        return [
            'count' => $projects->count(),
            'projects' => $projects->map(function (Project $p) use ($consumedByProject) {
                $consumed = (float) ($consumedByProject[$p->id] ?? 0);
                $sold = (float) ($p->sold_hours ?? 0);
                return [
                    'id'              => $p->id,
                    'name'            => $p->name,
                    'code'            => $p->code,
                    'status'          => $p->status,
                    'hours_sold'      => round($sold, 2),
                    'hours_consumed'  => round($consumed, 2),
                    'hours_balance'   => round($sold - $consumed, 2),
                ];
            })->all(),
        ];
    }

    private function projectDetails(int $projectId): array
    {
        if ($projectId <= 0) return ['error' => 'project_id inválido'];

        $p = Project::with(['customer:id,name', 'contract:id,project_name,project_code_preview,status'])->find($projectId);
        if (! $p) return ['error' => 'projeto não encontrado'];

        $consumed = (float) Timesheet::where('project_id', $projectId)
            ->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        return [
            'id'             => $p->id,
            'name'           => $p->name,
            'code'           => $p->code,
            'status'         => $p->status,
            'customer'       => $p->customer ? ['id' => $p->customer->id, 'name' => $p->customer->name] : null,
            'contract'       => $p->contract ? [
                'id'           => $p->contract->id,
                'project_name' => $p->contract->project_name,
                'project_code' => $p->contract->project_code_preview,
                'status'       => $p->contract->status,
            ] : null,
            'hours_sold'     => round((float) ($p->sold_hours ?? 0), 2),
            'hours_consumed' => round($consumed, 2),
            'start_date'     => $p->start_date,
            'end_date'       => $p->end_date,
        ];
    }

    private function listContracts(int $customerId): array
    {
        if ($customerId <= 0) return ['error' => 'customer_id inválido'];

        $contracts = Contract::query()
            ->where('customer_id', $customerId)
            ->with('contractType:id,name')
            ->orderByDesc('id')
            ->get(['id', 'project_name', 'project_code_preview', 'status', 'kanban_status',
                   'tipo_faturamento', 'valor_projeto', 'horas_contratadas', 'contract_type_id']);

        return [
            'count' => $contracts->count(),
            'contracts' => $contracts->map(fn ($c) => [
                'id'                 => $c->id,
                'project_name'       => $c->project_name,
                'project_code'       => $c->project_code_preview,
                'type'               => $c->contractType?->name,
                'tipo_faturamento'   => $c->tipo_faturamento,
                'status'             => $c->status,
                'kanban_status'      => $c->kanban_status,
                'valor_projeto'      => (float) ($c->valor_projeto ?? 0),
                'horas_contratadas'  => (int)   ($c->horas_contratadas ?? 0),
            ])->all(),
        ];
    }

    private function contractsPipeline(?string $kanbanStatus, int $limit): array
    {
        $q = Contract::query()
            ->with(['customer:id,name', 'contractType:id,name'])
            ->whereNotNull('kanban_status')
            ->orderByDesc('updated_at')
            ->limit(max(1, min($limit, 200)));

        if ($kanbanStatus) {
            $q->where('kanban_status', $kanbanStatus);
        }

        $contracts = $q->get(['id', 'project_name', 'project_code_preview', 'kanban_status',
                              'status', 'tipo_faturamento', 'valor_projeto', 'horas_contratadas',
                              'customer_id', 'contract_type_id']);
        $byColumn = $contracts->groupBy('kanban_status')->map->count();

        return [
            'total'      => $contracts->count(),
            'by_column'  => $byColumn,
            'contracts'  => $contracts->map(fn ($c) => [
                'id'                => $c->id,
                'project_name'      => $c->project_name,
                'project_code'      => $c->project_code_preview,
                'customer'          => $c->customer?->name,
                'type'              => $c->contractType?->name,
                'tipo_faturamento'  => $c->tipo_faturamento,
                'kanban_status'     => $c->kanban_status,
                'status'            => $c->status,
                'valor_projeto'     => (float) ($c->valor_projeto ?? 0),
                'horas_contratadas' => (int)   ($c->horas_contratadas ?? 0),
            ])->all(),
        ];
    }

    private function consultantSummary(int $userId, string $month): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        [$start, $end] = $this->monthRange($month);

        $ts = Timesheet::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $byStatus = (clone $ts)
            ->select('status', DB::raw('SUM(effort_minutes)::numeric / 60 AS hours'), DB::raw('COUNT(*) AS n'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $approved  = (float) ($byStatus['approved']->hours ?? 0);
        $pending   = (float) ($byStatus['pending']->hours ?? 0);
        $rejected  = (float) ($byStatus['rejected']->hours ?? 0);

        $projects = (clone $ts)->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->distinct('project_id')->count('project_id');
        $customers = (clone $ts)->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->distinct('customer_id')->count('customer_id');

        $expenses = (float) Expense::query()
            ->where('user_id', $userId)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->sum('amount');

        return [
            'consultant'  => ['id' => $user->id, 'name' => $user->name],
            'month'       => $start->format('Y-m'),
            'hours_approved' => round($approved, 2),
            'hours_pending'  => round($pending, 2),
            'hours_rejected' => round($rejected, 2),
            'projects_count' => $projects,
            'customers_count' => $customers,
            'expenses_total'  => round($expenses, 2),
        ];
    }

    private function pendingApprovals(?int $customerId): array
    {
        $tsQ = Timesheet::query()->where('status', 'pending');
        $exQ = Expense::query()->where('status', 'pending');

        if ($customerId) {
            $tsQ->where('customer_id', $customerId);
            // Expense não tem customer_id direto — filtra pelo projeto vinculado ao cliente
            $exQ->whereHas('project', fn ($q) => $q->where('customer_id', $customerId));
        }

        $timesheetsCount = (clone $tsQ)->count();
        $expensesCount   = (clone $exQ)->count();

        $oldestTimesheets = (clone $tsQ)->with(['user:id,name', 'customer:id,name', 'project:id,name'])
            ->orderBy('date')->limit(20)->get();

        $oldestExpenses = (clone $exQ)->with(['user:id,name', 'project.customer:id,name'])
            ->orderBy('expense_date')->limit(20)->get();

        return [
            'timesheets_pending' => $timesheetsCount,
            'expenses_pending'   => $expensesCount,
            'oldest_timesheets'  => $oldestTimesheets->map(fn ($t) => [
                'id'        => $t->id,
                'date'      => $t->date?->format('Y-m-d'),
                'hours'     => round(((int) $t->effort_minutes) / 60, 2),
                'user'      => $t->user?->name,
                'customer'  => $t->customer?->name,
                'project'   => $t->project?->name,
            ])->all(),
            'oldest_expenses'    => $oldestExpenses->map(fn ($e) => [
                'id'       => $e->id,
                'date'     => $e->expense_date?->format('Y-m-d'),
                'amount'   => (float) $e->amount,
                'user'     => $e->user?->name,
                'customer' => $e->project?->customer?->name,
                'project'  => $e->project?->name,
            ])->all(),
        ];
    }

    private function customerBilling(int $customerId, string $month): array
    {
        if ($customerId <= 0) return ['error' => 'customer_id inválido'];
        [$start, $end] = $this->monthRange($month);

        $base = Timesheet::query()
            ->where('customer_id', $customerId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        $totalHours    = (float) (clone $base)->whereIn('status', ['approved', 'pending', 'conflicted'])
            ->sum(DB::raw('effort_minutes::numeric / 60'));
        $approvedHours = (float) (clone $base)->where('status', 'approved')
            ->sum(DB::raw('effort_minutes::numeric / 60'));
        $pendingHours  = (float) (clone $base)->where('status', 'pending')
            ->sum(DB::raw('effort_minutes::numeric / 60'));
        $adjustHours   = (float) (clone $base)->where('status', 'adjustment_requested')
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        return [
            'customer_id'       => $customerId,
            'month'             => $start->format('Y-m'),
            'hours_total'       => round($totalHours, 2),
            'hours_approved'    => round($approvedHours, 2),
            'hours_pending'     => round($pendingHours, 2),
            'hours_in_adjustment' => round($adjustHours, 2),
        ];
    }

    private function consultantPayroll(int $userId, string $month): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        [$start, $end] = $this->monthRange($month);

        $hours = (float) Timesheet::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        $hourly = (float) ($user->hourly_rate ?? 0);
        $estimatedPay = ($user->rate_type === 'monthly')
            ? $hourly
            : round($hours * $hourly, 2);

        $expenses = (float) Expense::query()
            ->where('user_id', $userId)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'approved')
            ->sum('amount');

        return [
            'consultant'      => ['id' => $user->id, 'name' => $user->name, 'rate_type' => $user->rate_type, 'hourly_rate' => $hourly],
            'month'           => $start->format('Y-m'),
            'hours_payable'   => round($hours, 2),
            'estimated_pay'   => $estimatedPay,
            'expenses_reimbursable' => round($expenses, 2),
        ];
    }

    private function consultantBankHours(int $userId, string $month): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        [$start, $end] = $this->monthRange($month);

        $worked = (float) Timesheet::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        $contractHours = (float) ($user->guaranteed_hours ?? ($user->daily_hours ? (float) $user->daily_hours * 22 : 0));

        return [
            'consultant'      => ['id' => $user->id, 'name' => $user->name, 'type' => $user->consultant_type],
            'month'           => $start->format('Y-m'),
            'contract_hours'  => round($contractHours, 2),
            'worked_hours'    => round($worked, 2),
            'balance'         => round($worked - $contractHours, 2),
        ];
    }

    private function consultantPayrollBreakdown(int $userId, string $month): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        [$start, $end] = $this->monthRange($month);
        $hourly = (float) ($user->hourly_rate ?? 0);
        $isMonthly = $user->rate_type === 'monthly';

        $rows = Timesheet::query()
            ->where('user_id', $userId)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->select(
                'project_id',
                'customer_id',
                DB::raw('SUM(effort_minutes)::numeric / 60 AS hours'),
                DB::raw('COUNT(*) AS entries'),
            )
            ->groupBy('project_id', 'customer_id')
            ->get();

        $projectIds  = $rows->pluck('project_id')->filter()->unique()->all();
        $customerIds = $rows->pluck('customer_id')->filter()->unique()->all();
        $projects    = Project::whereIn('id', $projectIds)->pluck('name', 'id');
        $customers   = Customer::whereIn('id', $customerIds)->pluck('name', 'id');

        $totalHours = 0.0;
        $breakdown = $rows->map(function ($r) use ($projects, $customers, $hourly, $isMonthly, &$totalHours) {
            $hrs = round((float) $r->hours, 2);
            $totalHours += $hrs;
            return [
                'project_id'    => $r->project_id,
                'project_name'  => $projects[$r->project_id] ?? null,
                'customer_id'   => $r->customer_id,
                'customer_name' => $customers[$r->customer_id] ?? null,
                'hours'         => $hrs,
                'entries'       => (int) $r->entries,
                'estimated_pay' => $isMonthly ? null : round($hrs * $hourly, 2),
            ];
        })->sortByDesc('hours')->values()->all();

        return [
            'consultant'    => ['id' => $user->id, 'name' => $user->name, 'rate_type' => $user->rate_type, 'hourly_rate' => $hourly],
            'month'         => $start->format('Y-m'),
            'total_hours'   => round($totalHours, 2),
            'estimated_total_pay' => $isMonthly ? $hourly : round($totalHours * $hourly, 2),
            'breakdown'     => $breakdown,
        ];
    }

    private function movideskTicket(int $ticketId): array
    {
        if ($ticketId <= 0) return ['error' => 'ticket_id inválido'];
        $t = MovideskTicket::where('ticket_id', $ticketId)->first();
        if (! $t) return ['error' => "ticket $ticketId não encontrado"];

        return [
            'ticket_id'             => (int) $t->ticket_id,
            'titulo'                => $t->titulo,
            'status'                => $t->status,
            'base_status'           => $t->base_status,
            'urgencia'              => $t->urgencia,
            'categoria'             => $t->categoria,
            'servico'               => $t->servico,
            'nivel'                 => $t->nivel,
            'solicitante'           => $t->solicitante,
            'responsavel'           => $t->responsavel,
            'owner_team'            => $t->owner_team,
            'created_date'          => $t->created_date,
            'resolved_in'           => $t->resolved_in,
            'closed_in'             => $t->closed_in,
            'sla_response_date'     => $t->sla_response_date,
            'sla_real_response_date' => $t->sla_real_response_date,
            'sla_solution_date'     => $t->sla_solution_date,
            'sla_response_time'     => $t->sla_response_time,
            'sla_solution_time'     => $t->sla_solution_time,
            'origin'                => $t->origin,
        ];
    }

    private function consultantTickets(int $userId, ?string $status, int $limit): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        $base = MovideskTicket::query()->where('user_id', $userId);
        if ($status) $base->where('status', $status);

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        $limit = max(1, min($limit, 100));
        $tickets = (clone $base)
            ->orderByDesc('created_date')
            ->limit($limit)
            ->get(['ticket_id', 'titulo', 'status', 'base_status', 'urgencia', 'categoria', 'created_date', 'sla_solution_date']);

        return [
            'consultant'    => ['id' => $user->id, 'name' => $user->name],
            'count_by_status' => $byStatus,
            'total'         => (clone $base)->count(),
            'tickets'       => $tickets->map(fn ($t) => [
                'ticket_id'         => (int) $t->ticket_id,
                'titulo'            => $t->titulo,
                'status'            => $t->status,
                'urgencia'          => $t->urgencia,
                'categoria'         => $t->categoria,
                'created_date'      => $t->created_date,
                'sla_solution_date' => $t->sla_solution_date,
            ])->all(),
        ];
    }

    private function customerTickets(int $customerId, bool $onlyOpen): array
    {
        if ($customerId <= 0) return ['error' => 'customer_id inválido'];
        $customer = Customer::find($customerId);
        if (! $customer) return ['error' => 'cliente não encontrado'];

        $base = MovideskTicket::query()->where('customer_id', $customerId);
        if ($onlyOpen) {
            $base->whereNotIn('base_status', ['Resolvido', 'Fechado', 'Cancelado']);
        }

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        $tickets = (clone $base)
            ->orderByDesc('created_date')
            ->limit(20)
            ->get(['ticket_id', 'titulo', 'status', 'urgencia', 'created_date', 'responsavel']);

        return [
            'customer'       => ['id' => $customer->id, 'name' => $customer->name],
            'total'          => (clone $base)->count(),
            'count_by_status' => $byStatus,
            'recent_tickets' => $tickets->map(fn ($t) => [
                'ticket_id'    => (int) $t->ticket_id,
                'titulo'       => $t->titulo,
                'status'       => $t->status,
                'urgencia'     => $t->urgencia,
                'responsavel'  => $t->responsavel,
                'created_date' => $t->created_date,
            ])->all(),
        ];
    }

    private function projectSchedule(int $projectId): array
    {
        if ($projectId <= 0) return ['error' => 'project_id inválido'];

        $project = Project::with(['customer:id,name'])->find($projectId);
        if (! $project) return ['error' => 'projeto não encontrado'];

        $stages = [];
        if (class_exists(\App\Models\ProjectStage::class)) {
            $stages = \App\Models\ProjectStage::query()
                ->where('project_id', $projectId)
                ->orderBy('order')
                ->get(['id', 'name', 'status', 'estimated_hours', 'consumed_hours', 'start_date', 'end_date'])
                ->map(fn ($s) => [
                    'id'              => $s->id,
                    'name'            => $s->name,
                    'status'          => $s->status,
                    'estimated_hours' => (float) ($s->estimated_hours ?? 0),
                    'consumed_hours'  => (float) ($s->consumed_hours ?? 0),
                    'start_date'      => $s->start_date,
                    'end_date'        => $s->end_date,
                ])
                ->all();
        }

        return [
            'project'     => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status],
            'customer'    => $project->customer ? ['id' => $project->customer->id, 'name' => $project->customer->name] : null,
            'stages_count' => count($stages),
            'stages'      => $stages,
            'note'        => empty($stages) ? 'Nenhuma etapa cadastrada no cronograma deste projeto.' : null,
        ];
    }

    private function consultantExpenses(int $userId, string $month): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        [$start, $end] = $this->monthRange($month);

        $base = Expense::query()
            ->where('user_id', $userId)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()]);

        $byStatus = (clone $base)
            ->select('status', DB::raw('COUNT(*) AS n'), DB::raw('SUM(amount) AS total'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $recent = (clone $base)
            ->with(['project.customer:id,name'])
            ->orderByDesc('expense_date')
            ->limit(20)
            ->get(['id', 'expense_date', 'amount', 'status', 'project_id', 'description']);

        return [
            'consultant' => ['id' => $user->id, 'name' => $user->name],
            'month'      => $start->format('Y-m'),
            'count_total' => (clone $base)->count(),
            'amount_total' => round((float) (clone $base)->sum('amount'), 2),
            'by_status'  => $byStatus->map(fn ($r) => ['n' => (int) $r->n, 'total' => round((float) $r->total, 2)])->all(),
            'recent'     => $recent->map(fn ($e) => [
                'id'          => $e->id,
                'date'        => $e->expense_date?->format('Y-m-d'),
                'amount'      => (float) $e->amount,
                'status'      => $e->status,
                'customer'    => $e->project?->customer?->name,
                'description' => $e->description,
            ])->all(),
        ];
    }

    private function lateTimesheets(?int $userId, ?int $customerId, int $limit): array
    {
        $base = Timesheet::query()
            ->where(function ($q) {
                $q->where('status', 'conflicted')->orWhere('status', 'late');
            });

        if ($userId) $base->where('user_id', $userId);
        if ($customerId) $base->where('customer_id', $customerId);

        $limit = max(1, min($limit, 100));
        $total = (clone $base)->count();
        $items = $base->with(['user:id,name', 'customer:id,name', 'project:id,name'])
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get();

        return [
            'total' => $total,
            'items' => $items->map(fn ($t) => [
                'id'       => $t->id,
                'date'     => $t->date?->format('Y-m-d'),
                'status'   => $t->status,
                'user'     => $t->user?->name,
                'customer' => $t->customer?->name,
                'project'  => $t->project?->name,
                'hours'    => round(((int) $t->effort_minutes) / 60, 2),
            ])->all(),
        ];
    }

    private function consultantCapacity(int $userId): array
    {
        if ($userId <= 0) return ['error' => 'user_id inválido'];
        $user = User::find($userId);
        if (! $user) return ['error' => 'consultor não encontrado'];

        $capacity = (float) ($user->capacity_hours ?? 0);
        $allocated = (float) ($user->allocated_hours ?? 0);
        $util = $capacity > 0 ? round(($allocated / $capacity) * 100, 1) : null;

        return [
            'consultant'  => ['id' => $user->id, 'name' => $user->name, 'type' => $user->consultant_type],
            'capacity_hours'  => round($capacity, 2),
            'allocated_hours' => round($allocated, 2),
            'available_hours' => round($capacity - $allocated, 2),
            'utilization_pct' => $util,
            'availability_status' => $user->availability_status,
            'availability_start_date' => $user->availability_start_date,
        ];
    }

    private function pendingExpensePayments(?int $daysSinceApproved): array
    {
        $q = Expense::query()->where('status', 'approved')->whereNull('paid_at');
        if ($daysSinceApproved) {
            $q->where(function ($w) use ($daysSinceApproved) {
                $w->where('reviewed_at', '<=', now()->subDays($daysSinceApproved))
                  ->orWhereNull('reviewed_at');
            });
        }
        $total = (clone $q)->count();
        $sum = round((float) (clone $q)->sum('amount'), 2);

        $items = $q->with(['user:id,name', 'project.customer:id,name'])
            ->orderBy('reviewed_at')
            ->limit(50)
            ->get();

        return [
            'pending_payments_count' => $total,
            'pending_payments_total' => $sum,
            'items' => $items->map(fn ($e) => [
                'id'          => $e->id,
                'amount'      => (float) $e->amount,
                'user'        => $e->user?->name,
                'customer'    => $e->project?->customer?->name,
                'reviewed_at' => $e->reviewed_at?->format('Y-m-d'),
                'days_old'    => $e->reviewed_at ? now()->diffInDays($e->reviewed_at) : null,
            ])->all(),
        ];
    }

    private function criticalBankHours(int $threshold): array
    {
        $threshold = max(1, min($threshold, 1000));
        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $consultants = User::query()
            ->whereIn('consultant_type', ['bh_fixo', 'bh_mensal'])
            ->where('enabled', true)
            ->whereNotNull('daily_hours')
            ->get(['id', 'name', 'consultant_type', 'daily_hours', 'guaranteed_hours']);

        $alerts = [];
        foreach ($consultants as $c) {
            $contractHours = (float) ($c->guaranteed_hours ?? ($c->daily_hours ? (float) $c->daily_hours * 22 : 0));
            $worked = (float) Timesheet::query()
                ->where('user_id', $c->id)
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('status', ['approved', 'pending'])
                ->sum(DB::raw('effort_minutes::numeric / 60'));
            $balance = round($worked - $contractHours, 2);

            if (abs($balance) >= $threshold) {
                $alerts[] = [
                    'user_id' => $c->id,
                    'name'    => $c->name,
                    'type'    => $c->consultant_type,
                    'contract_hours' => round($contractHours, 2),
                    'worked_hours'   => round($worked, 2),
                    'balance'        => $balance,
                    'severity'       => abs($balance) >= ($threshold * 2) ? 'critical' : 'high',
                ];
            }
        }

        usort($alerts, fn ($a, $b) => abs($b['balance']) <=> abs($a['balance']));

        return [
            'threshold'  => $threshold,
            'month'      => $start->format('Y-m'),
            'alerts_count' => count($alerts),
            'alerts'     => $alerts,
        ];
    }

    private function financialOverview(string $month): array
    {
        [$start, $end] = $this->monthRange($month);

        $approvedHours = (float) Timesheet::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'approved')
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        $pendingHours = (float) Timesheet::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where('status', 'pending')
            ->sum(DB::raw('effort_minutes::numeric / 60'));

        $totalExpenses = (float) Expense::query()
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['approved', 'pending'])
            ->sum('amount');

        $pendingTimesheets = Timesheet::query()->where('status', 'pending')->count();
        $pendingExpenses   = Expense::query()->where('status', 'pending')->count();

        return [
            'month'                => $start->format('Y-m'),
            'hours_approved'       => round($approvedHours, 2),
            'hours_pending'        => round($pendingHours, 2),
            'expenses_total'       => round($totalExpenses, 2),
            'timesheets_pending_count' => $pendingTimesheets,
            'expenses_pending_count'   => $pendingExpenses,
        ];
    }
}
