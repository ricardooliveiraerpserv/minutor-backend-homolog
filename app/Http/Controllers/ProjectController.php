<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Customer;
use App\Models\ServiceType;
use App\Models\ContractType;
use App\Models\User;
use App\Models\ProjectChangeLog;
use App\Models\ProjectMonthlyConsumption;
use App\Services\ProjectCodeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Projects",
 *     description="Gerenciamento de Projetos"
 * )
 */
class ProjectController extends Controller
{
    use \App\Http\Traits\ListCacheable;
    use \App\Http\Traits\FiltersByActiveCompany;

    /**
     * FASE 11.7 (PR 7b) — Map type-legado-pt → category-en (canônico).
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

    /**
     * Mês de corte do extrato mensal de banco de horas (formato YYYY-MM).
     * Meses < corte: consumo manual/editável (persistido em project_monthly_consumptions).
     * Meses >= corte: consumo vem dos apontamentos (timesheets).
     */
    const MONTHLY_CONSUMPTION_CUTOFF = '2026-05';
    /**
     * @OA\Get(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="Listar projetos",
     *     description="Lista projetos com paginação, filtros e ordenação",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=15),
     *         description="Registros por página (padrão: 15, máximo: 100)"
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="Website"),
     *         description="Busca por name, code ou description",
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="started"),
     *         description="Filtrar por status (active, awaiting_start, started, paused, cancelled, finished)"
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1),
     *         description="Filtrar por cliente"
     *     ),
     *     @OA\Parameter(
     *         name="approver_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", example=1),
     *         description="Filtrar por aprovador"
     *     ),
     *     @OA\Parameter(
     *         name="contract_type_name",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="Banco de Horas Mensal"),
     *         description="Filtrar por nome do tipo de contrato"
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="name,-created_at"),
     *         description="Ordenação (ex: name,-created_at)",
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de projetos",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=true),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="code", type="string"),
     *                     @OA\Property(property="description", type="string"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="status_display", type="string"),
     *                     @OA\Property(property="contract_type", type="string"),
     *                     @OA\Property(property="contract_type_display", type="string"),
     *                     @OA\Property(property="customer", type="object"),
     *                     @OA\Property(property="service_type", type="object"),
     *                     @OA\Property(property="created_at", type="string"),
     *                     @OA\Property(property="updated_at", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    /**
     * Retorna apenas os projetos do usuário logado (sem permissão projects.view).
     * Usado pelo meu-painel para popular dropdowns de cliente/projeto.
     */
    public function myProjects(Request $request): JsonResponse
    {
        // Inclui IC: projetos de Investimento Interno onde o consultor está
        // alocado também aparecem em "Meus Projetos". A restrição "alocado"
        // já é aplicada via consultant_only + filtro de IC do index().
        $request->merge([
            'consultant_only'                => 'true',
            'include_investimento_comercial' => 'true',
        ]);
        return $this->index($request);
    }

    public function index(Request $request): JsonResponse
    {
        // Cap maior para listagem de Investimento Interno: cada cliente tem ~3 projetos
        // (Comercial/Suporte/Projetos) + manuais → o cap padrão de 200 cortava a página
        // e clientes sumiam da tela /investimento-comercial.
        // Idem para o dashboard /gestao-projetos (modo gestao): a tela carrega TODOS os
        // projetos pra filtrar/ordenar no client; o cap de 200 cortava os últimos (ex.:
        // projetos "[SUPORTE]..." que ordenam por nome depois do Z e caíam fora da página).
        // Modo minimal (dropdowns id/nome/código) também usa cap alto: são leves e o SELETOR de
        // projeto filtra client-side — com >200 projetos, os de nome no fim (ex.: "TESTE 3") caíam
        // fora dos 200 e o filtro dizia "Nenhum resultado".
        $maxPerPage = ($request->boolean('only_investimento_comercial') || $request->boolean('gestao') || $request->boolean('minimal')) ? 2000 : 200;
        $perPage = min($request->get('pageSize', $request->get('per_page', 15)), $maxPerPage);
        $minimal = $request->boolean('minimal');
        $search = $request->get('filter') ?? $request->get('search');
        $status = $request->get('status');
        $codeExact = $request->get('code');

        // Validação exata de código (usado pelo frontend para checar unicidade antes de salvar)
        if ($codeExact) {
            $excludeId = $request->get('exclude_id');
            $query2 = Project::where('code', $codeExact);
            if ($excludeId) $query2->where('id', '!=', $excludeId);
            return response()->json(['total' => $query2->exists() ? 1 : 0, 'data' => []]);
        }

        // Modo minimal: retorna apenas id, name, code (para dropdowns). Inclui
        // parent_project_id p/ os seletores montarem a árvore (filho com seta ↳).
        if ($minimal) {
            $q = Project::select('id', 'name', 'code', 'status', 'parent_project_id');
            if ($search) $q->where(fn($x) => $x->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
            if ($status === 'active') $q->active();
            elseif ($status === 'open') $q->open();
            elseif ($status) $q->where('status', $status);
            if ($request->get('customer_id')) $q->where('customer_id', $request->get('customer_id'));
            // Filtra por consultor ALOCADO (project_consultants) — usado p/ só oferecer,
            // na realocação de apontamento, projetos em que o consultor está alocado.
            if ($request->get('consultant_user_id')) {
                $cuid = (int) $request->get('consultant_user_id');
                $q->whereHas('consultants', fn ($c) => $c->where('users.id', $cuid));
            }
            $items = $q->orderBy('name')->limit($perPage)->get();
            return response()->json(['hasNext' => false, 'items' => $items]);
        }
        $customerId = $request->get('customer_id');
        $approverId = $request->get('approver_id');
        $executiveId = $request->get('executive_id');
        $consultantOnly = $request->get('consultant_only');
        $contractTypeName = $request->get('contract_type_name');
        $contractTypeCode = $request->get('contract_type_code');
        $contractTypeId = $request->get('contract_type_id');
        $serviceTypeName = $request->get('service_type_name');
        $parentProjectsOnly = $request->get('parent_projects_only') === 'true';
        // Opt-in EXCLUSIVO do Relatório de Apontamentos: coordenador de sustentação também
        // pode enxergar/selecionar o filho On Demand cujo pai é sustentação. Sem este param,
        // a regra global de visibilidade (coord de sustentação só vê sustentação) fica intacta.
        $includeSustOnDemandChildren = $request->boolean('include_sust_ondemand_children');

        // Modo gestão: query leve para o dashboard /gestao-projetos
        // Omite relações pesadas (hourContributions, serviceType, parentProject)
        // e pula cálculos financeiros detalhados
        $gestaoMode = $request->boolean('gestao');

        // Eager loading otimizado: carrega relacionamentos e soma de minutos apontados
        $withRelations = ['customer', 'contractType', 'serviceType'];
        if (!$gestaoMode) {
            $withRelations[] = 'parentProject';
        }
        $withRelations[] = 'hourContributions';
        // Em gestao simples (sem parentProjectsOnly), carrega equipe para indicadores
        // with_team=false pula apenas consultants (pesados); coordinators sempre são carregados
        $withTeam = $request->get('with_team', 'true') !== 'false';
        if ($gestaoMode && !$parentProjectsOnly) {
            $withRelations[] = 'coordinators';
            $withRelations[] = 'executivoConta';
            if ($withTeam) {
                $withRelations[] = 'consultants';
                // Grupos vinculados (necessário pra pré-selecionar no modal de Equipe).
                $withRelations[] = 'consultantGroups';
            }
        }
        // childProjects: sempre carregado em gestaoMode (para calcular closedChildrenHours)
        // e no modo completo; em multicontratual também carrega coordinators
        $withRelations[] = 'childProjects.contractType';
        if (!$gestaoMode || $parentProjectsOnly) {
            // Em modo pai/filho carrega coordenadores dos pais e dos filhos para marcar node_state
            if ($parentProjectsOnly) {
                $withRelations[] = 'coordinators';
                $withRelations[] = 'childProjects.coordinators';
            }
        }

        $query = Project::with($withRelations);

        // Filtrar apenas projetos onde o usuário é consultor (exceto para Administrators)
        if ($consultantOnly === 'true') {
            $currentUser = $request->user();
            $requestedUserId = $request->get('user_id');

            // Determinar qual usuário usar para o filtro
            $targetUserId = $currentUser->id;
            $targetUser = $currentUser;

            // Se admin OU coordenador forneceu user_id, usar esse usuário
            // (frontend permite a ambos "agir em nome de outro" no modal de apontamento).
            if ($requestedUserId && ($currentUser->isAdmin() || $currentUser->isCoordenador())) {
                $targetUserId = $requestedUserId;
                $targetUser = \App\Models\User::find($targetUserId);
            }

            // Apenas aplicar filtro se o usuário alvo NÃO for Administrator
            if ($targetUser && !$targetUser->isAdmin()) {
                $isTargetConsultor = method_exists($targetUser, 'isConsultor') && $targetUser->isConsultor();
                // Modo "Meus Projetos" (activity_allocated): projetos onde o consultor participa do
                // CRONOGRAMA — MESMO critério de acesso do ProjectStageController (allocations.user_id,
                // com ou sem delivery, OU responsável por atividade). Antes exigia delivery_id NOT NULL,
                // então quem estava na EQUIPE da etapa (alocação delivery=null) sumia do "Meus Projetos".
                // CONSULTOR: força o critério RESPONSÁVEL-only (branch elseif abaixo), mesmo com
                // activity_allocated=true — trocar o responsável de uma atividade some o projeto de
                // "Meus Projetos" (alocação-resquício em stage_allocations NÃO conta).
                if ($request->boolean('activity_allocated') && !$isTargetConsultor) {
                    // "Meus Projetos" = EXATAMENTE o critério de visibilidade do CRONOGRAMA do consultor
                    // (ProjectStageController::index, ADR 0004): alocado numa etapa OU responsável por
                    // atividade. Consistência total: se o projeto aparece na lista, o cronograma tem
                    // conteúdo pra ele (nunca abre vazio). NÃO inclui "consultor do projeto" puro nem
                    // coordenador sem alocação — esses veriam o cronograma vazio.
                    $query->where(function ($q) use ($targetUserId) {
                        $q->whereHas('stages.allocations', fn ($a) => $a->where('user_id', $targetUserId))
                          ->orWhereHas('stages.deliveries', fn ($d) => $d->where('responsible_user_id', $targetUserId));
                    });
                } elseif ($isTargetConsultor) {
                    // CONSULTOR (Meus Projetos): só vê projetos onde é RESPONSÁVEL de alguma atividade
                    // (delivery). Ao ser TROCADO (responsável alterado p/ outro), o projeto some. Estar só
                    // no time (project_consultants) ou ter alocação-resquício (stage_allocations) NÃO basta.
                    $query->whereHas('stages.deliveries', function ($d) use ($targetUserId) {
                        $d->where('responsible_user_id', $targetUserId);
                    });
                } else {
                $query->where(function ($q) use ($targetUserId) {
                    $q->whereHas('consultants', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    })->orWhereHas('approvers', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    })->orWhereHas('consultantGroups.consultants', function ($subQ) use ($targetUserId) {
                        $subQ->where('users.id', $targetUserId);
                    })->orWhereHas('coordinators', function ($subQ) use ($targetUserId) {
                        $subQ->where('user_id', $targetUserId);
                    })->orWhereHas('stages.deliveries', function ($subQ) use ($targetUserId) {
                        // Responsável por atividade do cronograma (mesmo sem estar em project_consultants).
                        $subQ->where('responsible_user_id', $targetUserId);
                    })->orWhereHas('stages.allocations', function ($subQ) use ($targetUserId) {
                        // Alocado numa atividade do cronograma.
                        $subQ->where('user_id', $targetUserId);
                    });
                });
                }
            }
            // Se o usuário alvo for Administrator, não aplica filtro (vê todos os projetos)
        }

        // Escopo por role: Coordenador só vê projetos onde é coordinator
        // (aplica apenas quando não está no modo consultant_only, que tem escopo próprio)
        // EXCEÇÃO: na rotina de Contratos de Investimento (only_investimento_comercial),
        // TODOS os coordenadores (projeto e sustentação) enxergam TODOS os projetos.
        if ($consultantOnly !== 'true' && !$request->boolean('only_investimento_comercial')) {
            $currentUser = $request->user();
            if ($currentUser && $currentUser->isCoordenador()) {
                $isSustentacao = $currentUser->coordinator_type === 'sustentacao';
                if ($parentProjectsOnly) {
                    $query->where(function ($q) use ($currentUser, $isSustentacao) {
                        $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $currentUser->id))
                          ->orWhereHas('childProjects.coordinators', fn($sq) => $sq->where('users.id', $currentUser->id));
                        if ($isSustentacao) {
                            $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'))
                              ->orWhere(fn($sq) => $sq->where('is_investimento_comercial', true)->where('categoria_interna', 'Suporte'));
                        }
                    });
                } else {
                    $query->where(function ($q) use ($currentUser, $isSustentacao, $includeSustOnDemandChildren) {
                        $q->whereHas('coordinators', fn($sq) => $sq->where('users.id', $currentUser->id));
                        if ($isSustentacao) {
                            $q->orWhereHas('serviceType', fn($sq) => $sq->where('code', 'sustentacao'))
                              ->orWhere(fn($sq) => $sq->where('is_investimento_comercial', true)->where('categoria_interna', 'Suporte'));
                            // SÓ no Relatório de Apontamentos (param include_sust_ondemand_children):
                            // permite o coord de sustentação selecionar o filho On Demand cujo pai é
                            // sustentação. Não altera a regra global em nenhum outro fluxo.
                            if ($includeSustOnDemandChildren) {
                                $q->orWhere(fn($sq) => $sq
                                    ->whereHas('contractType', fn($ct) => $ct->where('code', 'on_demand'))
                                    ->whereHas('parentProject.serviceType', fn($st) => $st->where('code', 'sustentacao')));
                            }
                        }
                    });
                }
            }
        }

        // Busca por name, code ou description
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Filtro por status — hierárquico (WITH RECURSIVE) para status específicos
        $nodeStateMap = collect(); // id => node_state ('ACTIVE' | 'DISABLED')

        if ($status) {
            if ($status === 'open') {
                $query->open(); // Scope: exclui apenas cancelled e finished (permite paused)
            } elseif ($status === 'active') {
                $query->active(); // Scope: exclui cancelled, finished e paused
            } else {
                // CTE recursiva: sobe a árvore a partir dos nós que batem,
                // depois expande para mostrar todos os filhos de cada ancestral.
                $cte = "
                    WITH RECURSIVE path_nodes AS (
                        SELECT id, parent_project_id, status
                        FROM projects
                        WHERE status = ?
                          AND deleted_at IS NULL

                        UNION

                        SELECT p.id, p.parent_project_id, p.status
                        FROM projects p
                        INNER JOIN path_nodes pn ON p.id = pn.parent_project_id
                        WHERE p.deleted_at IS NULL
                    ),
                    path_deduped AS (
                        SELECT DISTINCT id, parent_project_id, status
                        FROM path_nodes
                    ),
                    all_visible AS (
                        SELECT id, parent_project_id, status
                        FROM path_deduped

                        UNION

                        SELECT p.id, p.parent_project_id, p.status
                        FROM projects p
                        INNER JOIN path_deduped pd ON p.parent_project_id = pd.id
                        WHERE p.deleted_at IS NULL
                    )
                    SELECT
                        av.id,
                        CASE WHEN av.status = ? THEN 'ACTIVE' ELSE 'DISABLED' END AS node_state
                    FROM all_visible av
                ";

                $rows = DB::select($cte, [$status, $status]);

                $nodeStateMap = collect($rows)->keyBy('id');
                $visibleIds   = $nodeStateMap->keys()->toArray();

                // Restringe a query principal aos IDs encontrados pela CTE
                $query->whereIn('projects.id', $visibleIds);
            }
        }

        // Filtro por cliente
        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        // Filtro por aprovador
        if ($approverId) {
            $query->whereHas('approvers', function ($q) use ($approverId) {
                $q->where('users.id', $approverId);
            });
        }

        // Filtro por executivo responsável do cliente
        if ($executiveId) {
            $query->whereHas('customer', function ($q) use ($executiveId) {
                $q->where(\App\Models\Customer::activeExecutiveColumn(), $executiveId);
            });
        }

        // Filtro por tipo de contrato (por ID, code ou nome)
        if ($contractTypeId) {
            $query->where('contract_type_id', $contractTypeId);
        } elseif ($contractTypeCode) {
            $query->whereHas('contractType', function ($q) use ($contractTypeCode) {
                $q->where('code', $contractTypeCode);
            });
        } elseif ($contractTypeName) {
            $query->whereHas('contractType', function ($q) use ($contractTypeName) {
                $q->whereRaw('LOWER(name) = ?', [strtolower($contractTypeName)]);
            });
        }

        // Filtro por nome do tipo de serviço
        if ($serviceTypeName) {
            $query->whereHas('serviceType', function ($q) use ($serviceTypeName) {
                $q->where('name', $serviceTypeName);
            });
        }

        // Filtro para apenas projetos raiz (pai ou independentes)
        if ($request->get('parent_projects_only') === 'true') {
            $query->whereNull('parent_project_id');
        }

        // Filtro para apenas projetos raiz que POSSUEM subprojetos (modo multi-contratual)
        if ($request->get('with_children_only') === 'true') {
            $query->whereNull('parent_project_id')->whereHas('childProjects');
        }

        // Filtro para apenas projetos de nível raiz (sem pai), independente de ter filhos
        if ($request->get('top_level_only') === 'true') {
            $query->whereNull('parent_project_id');
        }

        // Excluir projeto específico (útil na edição)
        if ($request->has('exclude_id')) {
            $query->where('id', '!=', $request->get('exclude_id'));
        }

        // Projetos de Investimento Comercial ficam ocultos na listagem padrão;
        // só aparecem quando explicitamente solicitados (ex: dropdowns de apontamento).
        if ($request->boolean('only_investimento_comercial')) {
            $query->where('is_investimento_comercial', true);
            // Investimento Interno é POR-CLIENTE: os projetos IC/IS/IP são únicos por
            // cliente e o company_id neles é apenas o carimbo da empresa ativa na
            // criação (arbitrário). Portanto NÃO filtramos pelo company_id do projeto —
            // removemos o CompanyScope e filtramos pelo CLIENTE conforme a empresa ativa.
            $query->withoutGlobalScope(\App\Models\Scopes\CompanyScope::class);
            if (config('multiempresa.scoping_enabled')) {
                $activeId = app(\App\Services\CompanyContext::class)->id();
                if ($activeId && \App\Models\Company::where('id', $activeId)->where('slug', 'bizify')->exists()) {
                    // Bizify: a casa BIZIFY + clientes marcados is_bizify_customer.
                    $query->whereHas('customer', function ($c) {
                        $c->where('is_bizify_customer', true)
                          ->orWhereRaw('UPPER(name) = ?', ['BIZIFY']);
                    });
                    // Na Bizify todo cliente segue o PADRÃO dos 3 projetos canônicos
                    // (IC/IS/IP criados em createInvestimentoProjects). Extras específicos
                    // da casa (ex.: ERPSERV com Cloud/Day Off/Visita) só aparecem na tela
                    // da própria empresa.
                    $query->whereIn('name', ['Investimento Comercial', 'Investimento Suporte', 'Investimento Projetos']);
                }
            }
        } elseif (!$request->boolean('include_investimento_comercial')) {
            $query->where('is_investimento_comercial', false);
        } else {
            // include_investimento_comercial=true: para consultor (não admin/coord),
            // restringe IC apenas aos projetos onde ele está alocado. Projetos
            // não-IC continuam visíveis normalmente.
            $currentUser = $request->user();
            if ($currentUser && !$currentUser->isAdmin() && !$currentUser->isCoordenador() && !$currentUser->isAdministrativo()) {
                $userId = $currentUser->id;
                $query->where(function ($q) use ($userId) {
                    $q->where('is_investimento_comercial', false)
                      ->orWhere(function ($qq) use ($userId) {
                          $qq->where('is_investimento_comercial', true)
                             ->whereHas('consultants', fn($sq) => $sq->where('user_id', $userId));
                      });
                });
            }
        }

        // Mapeamento de campos virtuais/computados para colunas reais ou joins
        $virtualFieldMap = [
            'contract_type_display' => 'contract_types.name',
            'customer.name'         => 'customers.name',
        ];

        // Ordenação PO-UI
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));
            foreach ($orderFields as $field) {
                $desc = str_starts_with($field, '-');
                $col  = $desc ? substr($field, 1) : $field;
                $direction = $desc ? 'desc' : 'asc';

                if (isset($virtualFieldMap[$col])) {
                    $mapped = $virtualFieldMap[$col];
                    [$joinTable] = explode('.', $mapped);
                    if ($joinTable === 'contract_types') {
                        $query->leftJoin('contract_types', 'contract_types.id', '=', 'projects.contract_type_id');
                    } elseif ($joinTable === 'customers') {
                        $query->leftJoin('customers', 'customers.id', '=', 'projects.customer_id');
                    }
                    $query->orderBy($mapped, $direction);
                } else {
                    $query->orderBy('projects.' . $col, $direction);
                }
            }
        } else {
            $query->orderBy('projects.name'); // Ordenação padrão
        }

        // Paginação PO-UI
        $page = (int) $request->get('page', 1);

        try {
        $currentUserForTransform = $request->user();
        // Coluna Consumo: um ou mais meses (year_months=2026-05,2026-06). Aceita o legado
        // year_month (1 mês). Closure não captura $request → computa aqui e passa via use.
        $gestaoMonthsRaw = $request->query('year_months') ?? $request->query('year_month');
        $gestaoMonths = $gestaoMonthsRaw
            ? array_values(array_filter(array_map('trim', explode(',', (string) $gestaoMonthsRaw))))
            : [];
        $result = $this->cachedList($request, 'projects', function () use ($query, $perPage, $page, $nodeStateMap, $gestaoMode, $parentProjectsOnly, $currentUserForTransform, $gestaoMonths) {
        $projects = $query->paginate($perPage, ['*'], 'page', $page);

        // Carregar soma de timesheets em batch: apenas para os projetos desta página
        // Evita JOIN na query principal (que agregaria TODA a tabela timesheets)
        $parentIds = $projects->getCollection()->pluck('id')->toArray();

        // childProjects é sempre eager-loaded em gestaoMode agora (para calcular closedChildrenHours)
        $allChildProjectIds = $projects->getCollection()
            ->flatMap(function ($project) {
                return $project->relationLoaded('childProjects') && $project->childProjects
                    ? $project->childProjects->pluck('id')
                    : collect();
            })
            ->unique()
            ->values();

        $allIdsToSum = array_unique(array_merge($parentIds, $allChildProjectIds->toArray()));

        $timesheetsMap = [];
        if (!empty($allIdsToSum)) {
            // DB::table bypassa SoftDeletes do Eloquent — precisa whereNull('deleted_at')
            // explícito. Consumo = tudo apontado MENOS rejeitado/ajuste/conflito (inclui ação
            // interna, pendente, aprovado, liberado). Regra do user 2026-05-22.
            $rows = DB::table('timesheets')
                ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) as total_logged_minutes')
                ->whereIn('project_id', $allIdsToSum)
                ->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'pending'])
                ->groupBy('project_id')
                ->pluck('total_logged_minutes', 'project_id');
            $timesheetsMap = $rows->toArray();
        }

        // Consumo (horas apontadas) nos meses escolhidos — alimenta a coluna "Consumo Mensal".
        // Soma de TODOS os meses selecionados. Mesma whitelist (approved/pending).
        // Vazio quando nenhum mês selecionado.
        $monthlyMap = [];
        if ($gestaoMode && !empty($gestaoMonths) && !empty($allIdsToSum)) {
            $monthlyMap = DB::table('timesheets')
                ->selectRaw('project_id, COALESCE(SUM(effort_minutes), 0) as m')
                ->whereIn('project_id', $allIdsToSum)
                ->whereNull('deleted_at')
                ->whereIn('status', ['approved', 'pending'])
                ->whereIn(DB::raw("to_char(timesheets.date, 'YYYY-MM')"), $gestaoMonths)
                ->groupBy('project_id')
                ->pluck('m', 'project_id')
                ->toArray();
        }

        // Consumo de COORDENAÇÃO por projeto — só apontamentos cujo autor é coordenador
        // do projeto (join project_coordinators). Uma query só (sem N+1). Mesmo whitelist
        // de consumo (approved/pending) usado acima.
        $coordinationMap = [];
        if ($gestaoMode && !empty($allIdsToSum)) {
            $coordRows = DB::table('timesheets as t')
                ->join('project_coordinators as pc', function ($j) {
                    $j->on('pc.project_id', '=', 't.project_id')
                      ->on('pc.user_id', '=', 't.user_id');
                })
                ->selectRaw('t.project_id, COALESCE(SUM(t.effort_minutes), 0) as coord_minutes')
                ->whereIn('t.project_id', $allIdsToSum)
                ->whereNull('t.deleted_at')
                ->whereIn('t.status', ['approved', 'pending'])
                ->groupBy('t.project_id')
                ->pluck('coord_minutes', 't.project_id');
            $coordinationMap = $coordRows->toArray();
        }

        // Atribuir total_logged_minutes aos projetos principais
        $projects->getCollection()->each(function ($project) use ($timesheetsMap) {
            $project->total_logged_minutes = $timesheetsMap[$project->id] ?? 0;
        });

        if ($allChildProjectIds->isNotEmpty()) {
            // Atribuir total_logged_minutes e consumed_hours aos projetos filhos
            // consumed_hours usa a mesma lógica do pai para que os valores somem visualmente
            $projects->getCollection()->each(function ($project) use ($timesheetsMap, $gestaoMode, $monthlyMap) {
                if ($project->relationLoaded('childProjects') && $project->childProjects) {
                    $project->childProjects->each(function ($childProject) use ($timesheetsMap, $gestaoMode, $monthlyMap) {
                        $childProject->total_logged_minutes = $timesheetsMap[$childProject->id] ?? 0;
                        $childProject->consumo_mensal = round(($monthlyMap[$childProject->id] ?? 0) / 60, 1);
                        $childLogged = $childProject->total_logged_minutes / 60;
                        $initialConsumed = (float)($childProject->initial_hours_consumed ?? 0);

                        if ($childProject->relationLoaded('contractType') && $childProject->contractType) {
                            $ctName = strtolower(trim($childProject->contractType->name));
                            if ($ctName === 'fechado' && !$gestaoMode) {
                                // Visão do cliente: Fechado compromete todo o sold (saldo 0).
                                // Na visão gerencial (gestaoMode) o consumo do filho vem dos
                                // apontamentos reais — saldo = sold - apontado.
                                $childProject->consumed_hours = (float)($childProject->sold_hours ?? 0);
                            } else {
                                $childProject->consumed_hours = round($childLogged + $initialConsumed, 2);
                            }
                        } else {
                            $childProject->consumed_hours = round($childLogged + $initialConsumed, 2);
                        }

                        // Visão gerencial: saldo do filho = vendido − consumido real (apontamentos).
                        // Sem essa atribuição o saldo do filho fica null e a UI mostra "—".
                        if ($gestaoMode) {
                            $childSold = (float)($childProject->sold_hours ?? 0);
                            $childProject->general_hours_balance = round($childSold - (float)$childProject->consumed_hours, 2);
                        }
                    });
                }
            });
        }

        // Adicionar atributos computed aos itens
        $projectIds = $projects->getCollection()->pluck('id')->toArray();
        $openPeriodIds = \App\Models\ProjectOpenPeriod::whereIn('project_id', $projectIds)
            ->whereNull('closed_at')
            ->pluck('project_id')
            ->flip()
            ->toArray();

        $projects->getCollection()->transform(function ($project) use ($nodeStateMap, $gestaoMode, $parentProjectsOnly, $currentUserForTransform, $openPeriodIds, $coordinationMap, $monthlyMap) {
            $project->has_open_period = isset($openPeriodIds[$project->id]);
            $project->status_display = $project->status_display;
            $project->contract_type_display = $project->contract_type_display;

            if ($gestaoMode) {
                // Modo leve: usar apenas campos já presentes na query, sem relações extras
                $consumed = ($project->total_logged_minutes ?? 0) / 60;
                $initialConsumed = (float)($project->initial_hours_consumed ?? 0);

                // Consumo MENSAL do contrato = horas apontadas no mês no projeto + filhos diretos.
                $monthlyOwn = ($monthlyMap[$project->id] ?? 0) / 60;
                $monthlyChildren = 0.0;
                if ($project->relationLoaded('childProjects') && $project->childProjects) {
                    foreach ($project->childProjects as $mChild) {
                        $monthlyChildren += ($monthlyMap[$mChild->id] ?? 0) / 60;
                    }
                }
                $project->consumo_mensal = round($monthlyOwn + $monthlyChildren, 1);

                // Consumo dos filhos comprometido no banco do pai, conforme o tipo do filho:
                //  - Fechado / BH Fixo: comprometem sold_hours + aportes do filho (independe do consumo efetivo)
                //  - On Demand: consome pelas horas EFETIVAMENTE lançadas no filho (sob demanda, sem saldo próprio)
                // ANTES: On Demand era ignorado aqui e o ramo BH Mensal nem agregava filhos → filho On Demand
                // não descontava do banco do pai. Detalhe por filho exposto em $childrenBreakdown p/ a UI.
                $childrenConsumed = 0.0;
                $childrenBreakdown = [];
                if ($project->relationLoaded('childProjects')) {
                    foreach ($project->childProjects as $child) {
                        if ($child->isAusterFrozen()) continue;
                        if (!$child->relationLoaded('contractType') || !$child->contractType) continue;
                        $childCode = (string) ($child->contractType->code ?? '');
                        $childName = strtolower(trim($child->contractType->name));
                        $isClosed   = $childCode === 'closed'      || $childName === 'fechado';
                        $isBhFixo   = $childCode === 'fixed_hours' || $childName === 'banco de horas fixo';
                        $isOnDemand = $childCode === 'on_demand'   || $childName === 'on demand';
                        $childConsumedHours = 0.0;
                        if ($isClosed || $isBhFixo) {
                            $childConsumedHours = (float) $child->getTotalAvailableHours();
                        } elseif ($isOnDemand) {
                            $childLoggedH = ($child->total_logged_minutes ?? 0) / 60;
                            $childConsumedHours = round($childLoggedH + (float)($child->initial_hours_consumed ?? 0), 2);
                        } else {
                            continue;
                        }
                        $childrenConsumed += $childConsumedHours;
                        $childrenBreakdown[] = [
                            'id'             => $child->id,
                            'code'           => $child->code,
                            'name'           => $child->name,
                            'contract_type'  => $child->contractType->name,
                            'consumed_hours' => $childConsumedHours,
                        ];
                    }
                }
                $project->children_consumption_breakdown = $childrenBreakdown;
                // Decomposição p/ a UI "ver a conta": consumo próprio do pai + consumo dos filhos = total
                $project->own_consumed_hours      = round($consumed + $initialConsumed, 2);
                $project->children_consumed_hours = round($childrenConsumed, 2);

                if ($project->isBankHoursMonthly()) {
                    // accumulated_sold_hours: usa valor do DB ou calcula meses × sold_hours
                    $dbAccum = $project->getRawOriginal('accumulated_sold_hours') ?? $project->accumulated_sold_hours;
                    if ($dbAccum !== null && $dbAccum > 0) {
                        $accumulatedHours = (int)$dbAccum;
                    } else {
                        $startDate = $project->start_date ? \Carbon\Carbon::parse($project->start_date) : null;
                        // Se encerramento_date definida, congela o acúmulo naquele mês
                        $refDate = $project->encerramento_date
                            ? \Carbon\Carbon::parse($project->encerramento_date)
                            : \Carbon\Carbon::now();
                        $months = $startDate ? max(1, (int)$startDate->diffInMonths($refDate) + 1) : 1;
                        $accumulatedHours = $months * (int)($project->sold_hours ?? 0);
                    }
                    $project->accumulated_sold_hours = $accumulatedHours;
                    // total_available_hours usa a relação eager-loaded para incluir aportes novos
                    $totalAvailable = $project->getTotalAvailableHours();
                    $newContributions = $totalAvailable - ($project->sold_hours ?? 0);
                    // Quebra das HS Vendidas p/ "ver a conta": base (acumulada) + aporte = total
                    $project->vendidas_projeto_hours = round($accumulatedHours, 2);
                    $project->vendidas_aporte_hours  = round($newContributions, 2);
                    // saldo usa o acumulado real; HS consumidas iniciais + novas + consumo dos filhos
                    $project->consumed_hours = round($consumed + $initialConsumed + $childrenConsumed, 2);
                    $project->general_hours_balance = round($accumulatedHours + $newContributions - $consumed - $initialConsumed - $childrenConsumed, 2);
                } else {
                    $totalAvailable = $project->getTotalAvailableHours();
                    $project->vendidas_projeto_hours = round((float)($project->sold_hours ?? 0), 2);
                    $project->vendidas_aporte_hours  = round($totalAvailable - (float)($project->sold_hours ?? 0), 2);
                    $project->consumed_hours = round($consumed + $initialConsumed + $childrenConsumed, 2);
                    $project->general_hours_balance = round($totalAvailable - $consumed - $initialConsumed - $childrenConsumed, 2);
                }
                $project->balance_percentage = $totalAvailable > 0 ? round(($project->consumed_hours / $totalAvailable) * 100, 2) : 0;
                $project->total_available_hours = round($totalAvailable, 2);
                $project->total_contributions_hours = $project->hourContributions->sum('contributed_hours');
                $project->total_project_value = null;
                $project->weighted_hourly_rate = null;
                // Banco de coordenação (lente do coordenador). Saldo/%/risco com fallback
                // pro vendido operacional são calculados no front (alinhado à "Vendidas" exibida).
                $project->coordination_consumed_hours = round((float) ($coordinationMap[$project->id] ?? 0) / 60, 2);
            } else {
                // Calcular saldo de horas de forma otimizada (sem queries adicionais)
                $project->general_hours_balance = $this->calculateGeneralHoursBalance($project);

                // Adicionar valores calculados de aportes de horas
                // Usa a relação já eager-loaded (hourContributions sem parênteses = coleção em memória)
                $project->total_available_hours = $project->getTotalAvailableHours();
                $project->total_project_value = $project->calculateTotalProjectValue();
                $project->weighted_hourly_rate = $project->getWeightedAverageHourlyRate();
                $project->total_contributions_hours = $project->hourContributions->sum('contributed_hours');
            }

            // node_state: 'ACTIVE' | 'DISABLED' | null (sem filtro de status ativo)
            $project->node_state = $nodeStateMap->has($project->id)
                ? $nodeStateMap->get($project->id)->node_state
                : null;

            // Em modo pai/filho: marca node_state dos filhos e coordinator_is_direct do pai
            if ($parentProjectsOnly && $currentUserForTransform) {
                $userId  = $currentUserForTransform->id;
                $isAdmin = $currentUserForTransform->isAdmin();

                // Verifica se o coordenador está DIRETAMENTE no pai
                $directOnParent = $isAdmin || (
                    $project->relationLoaded('coordinators') &&
                    $project->coordinators->contains('id', $userId)
                );
                $project->coordinator_is_direct = $directOnParent;

                // Marca node_state de cada filho
                if ($project->relationLoaded('childProjects')) {
                    $project->childProjects->each(function ($child) use ($userId, $isAdmin) {
                        if ($isAdmin) {
                            $child->node_state = 'ACTIVE';
                        } elseif ($child->relationLoaded('coordinators')) {
                            $child->node_state = $child->coordinators->contains('id', $userId)
                                ? 'ACTIVE' : 'DISABLED';
                        }
                    });
                }
            }

            return $project;
        });

        // ── Horas NÃO FATURADAS (informativo) dos projetos On Demand pai ──
        // Soma das horas de meses ENCERRADOS (anteriores ao mês corrente) ainda não
        // marcados como faturados (on_demand_invoiced_months). Só p/ pai On Demand.
        $odParents = $projects->getCollection()->filter(function ($p) {
            return optional($p->contractType)->code === 'on_demand' && empty($p->parent_project_id);
        });
        if ($odParents->isNotEmpty()) {
            $parentIds   = $odParents->pluck('id')->all();
            $childToParent = \App\Models\Project::whereIn('parent_project_id', $parentIds)
                ->pluck('parent_project_id', 'id')->all(); // [childId => parentId]
            $allIds      = array_merge($parentIds, array_keys($childToParent));
            $monthStart  = \Carbon\Carbon::now()->startOfMonth()->toDateString();

            $rows = \App\Models\Timesheet::whereIn('project_id', $allIds)
                ->whereNotIn('status', [\App\Models\Timesheet::STATUS_ADJUSTMENT_REQUESTED, \App\Models\Timesheet::STATUS_REJECTED, \App\Models\Timesheet::STATUS_LATE])
                ->whereNull('deleted_at')
                ->whereDate('date', '<', $monthStart) // só meses encerrados
                ->selectRaw("project_id, to_char(date, 'YYYY-MM') as ym, SUM(effort_minutes) as mins")
                ->groupBy('project_id', 'ym')
                ->get();

            $byParent = [];
            foreach ($rows as $r) {
                $pid = $childToParent[$r->project_id] ?? $r->project_id; // filho->pai
                if (!in_array($pid, $parentIds, true)) continue;
                $byParent[$pid][$r->ym] = ($byParent[$pid][$r->ym] ?? 0) + (int) $r->mins;
            }

            $invoiced = \App\Models\OnDemandInvoicedMonth::whereIn('project_id', $parentIds)
                ->get(['project_id', 'year_month'])
                ->groupBy('project_id')
                ->map(fn ($g) => $g->pluck('year_month')->flip()->all())
                ->all();

            foreach ($odParents as $p) {
                $inv = $invoiced[$p->id] ?? [];
                $unbilledMin = 0;
                $unbilledMonths = [];
                foreach (($byParent[$p->id] ?? []) as $ym => $mins) {
                    if (isset($inv[$ym])) continue;
                    $unbilledMin += $mins;
                    $unbilledMonths[] = $ym;
                }
                sort($unbilledMonths);
                $p->unbilled_hours  = round($unbilledMin / 60, 2);
                $p->unbilled_months = $unbilledMonths;
            }
        }

        // Resposta PO-UI
        return [
            'hasNext' => $projects->hasMorePages(),
            'items'   => $projects->items(),
        ];
        }); // fim cachedList
        return response()->json($result);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ProjectController@index error', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json(['error' => 'Erro ao listar projetos', 'details' => $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/projects",
     *     tags={"Projects"},
     *     summary="Criar projeto",
     *     description="Cria um novo projeto no sistema",
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "code", "customer_id", "service_type_id", "contract_type"},
     *             @OA\Property(property="name", type="string", example="Website Institucional", description="Nome do projeto"),
     *             @OA\Property(property="code", type="string", example="WEB-001", description="Código único do projeto"),
     *             @OA\Property(property="description", type="string", example="Desenvolvimento do website institucional"),
     *             @OA\Property(property="customer_id", type="integer", example=1, description="ID do cliente"),
     *             @OA\Property(property="service_type_id", type="integer", example=1, description="ID do tipo de serviço"),
                  *             @OA\Property(property="contract_type_id", type="integer", example=1, description="ID do tipo de contrato"),
     *             @OA\Property(property="project_value", type="number", example=50000.00, description="Valor do projeto"),
     *             @OA\Property(property="hourly_rate", type="number", example=150.00, description="Valor da hora"),
     *             @OA\Property(property="sold_hours", type="integer", example=200, description="Horas vendidas"),
     *             @OA\Property(property="hour_contribution", type="integer", example=20, description="Aporte de horas"),
     *             @OA\Property(property="exceeded_hour_contribution", type="integer", example=10, description="Aporte de horas excedidas"),
     *             @OA\Property(property="additional_hourly_rate", type="number", example=180.00, description="Valor de horas adicionais"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2024-01-15", description="Data de início"),
     *             @OA\Property(property="save_erpserv", type="number", example=0.00, description="Valor Save ERPSERV"),
     *             @OA\Property(property="max_expense_per_consultant", type="number", example=500.00, description="Valor máximo de despesa por consultor"),
     *             @OA\Property(property="expense_responsible_party", type="string", example="consultancy", description="Responsável pelas despesas (consultancy/client)"),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer"), example={1,2}, description="IDs dos consultores"),
     *             @OA\Property(property="approver_ids", type="array", @OA\Items(type="integer"), example={3}, description="IDs dos aprovadores")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Projeto criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="customer", type="object"),
     *             @OA\Property(property="service_type", type="object"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="approvers", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|min:2',
            'code' => 'nullable|string|max:50|unique:projects,code',
            'description' => 'nullable|string|max:2000',
            'customer_id' => 'required|exists:customers,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'service_type_id' => 'required|exists:service_types,id',
                        'contract_type_id' => 'required|exists:contract_types,id',
            'project_value' => 'nullable|numeric|min:0|max:999999999.99',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'sold_hours' => 'nullable|numeric|min:0|max:999999',
            'hour_contribution' => 'nullable|numeric|min:0|max:999999',
            'exceeded_hour_contribution' => 'nullable|numeric|min:0|max:999999',
            'initial_hours_balance' => 'nullable|numeric|min:-999999|max:999999',
            'initial_hours_consumed' => 'nullable|numeric|min:0|max:999999',
            'initial_cost' => 'nullable|numeric|min:0|max:999999999.99',
            'consultant_hours' => 'nullable|integer|min:0|max:999999',
            'coordinator_hours' => 'nullable|integer|min:0|max:999999',
            'coordination_hours' => 'nullable|numeric|min:0|max:999999',
            'additional_hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'start_date' => 'nullable|date',
            'expected_end_date' => 'nullable|date',
            'delivery_percentage' => 'nullable|numeric|min:0|max:100',
            'encerramento_date' => 'nullable|date',
            'save_erpserv' => 'nullable|numeric|min:0|max:999999999.99',
            'max_expense_per_consultant' => 'nullable|numeric|min:0|max:999999999.99',
            'unlimited_expense' => 'nullable|boolean',
            'expense_responsible_party' => ['nullable', Rule::in(['consultancy', 'client'])],
            'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365',
            'allow_negative_balance' => 'nullable|boolean',
            'client_follows_timesheets' => 'nullable|boolean',
            'extrato_visivel_cliente' => 'nullable|boolean',
            'status' => ['nullable', Rule::in(array_keys(Project::getStatuses()))],
            'consultant_ids' => 'nullable|array',
            'consultant_ids.*' => 'exists:users,id',
            'coordinator_ids' => 'nullable|array|max:1',
            'coordinator_ids.*' => 'exists:users,id',
            'consultant_group_ids' => 'nullable|array',
            'consultant_group_ids.*' => 'exists:consultant_groups,id',
            // Contract-origin fields
            'observacoes_contrato'  => 'nullable|string',
            'condicao_pagamento'    => 'nullable|string',
            'cobra_despesa_cliente' => 'nullable|boolean',
            'tipo_faturamento'      => 'nullable|string',
            'tipo_alocacao'         => 'nullable|in:remoto,presencial,ambos',
            'vendedor_id'           => 'nullable|exists:users,id',
            'architect_id'          => 'nullable|exists:users,id',
            'executivo_conta_id'    => 'nullable|exists:users,id',
            'movidesk_integration_enabled' => 'nullable|boolean',
            'confirm_movidesk_swap'        => 'nullable|boolean',
        ], [
            'name.required' => 'O nome é obrigatório',
            'name.max' => 'O nome não pode ter mais de 255 caracteres',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres',
            'code.unique' => 'Este código já está sendo usado por outro projeto',
            'customer_id.required' => 'O cliente é obrigatório',
            'customer_id.exists' => 'Cliente não encontrado',
            'parent_project_id.exists' => 'Projeto pai não encontrado',
            'service_type_id.required' => 'O tipo de serviço é obrigatório',
            'service_type_id.exists' => 'Tipo de serviço não encontrado',
                        'contract_type_id.required' => 'O tipo de contrato é obrigatório',
            'contract_type_id.exists' => 'Tipo de contrato inválido',
            'timesheet_retroactive_limit_days.integer' => 'O prazo deve ser um número inteiro',
            'timesheet_retroactive_limit_days.min' => 'O prazo não pode ser negativo',
            'timesheet_retroactive_limit_days.max' => 'O prazo não pode ser maior que 365 dias',
            'status.in' => 'Status inválido',
        ]);

        // BH Mensal não tem horas de coordenação — força null e pula a validação/guard.
        $ctForBhMensal = isset($validated['contract_type_id'])
            ? ContractType::find($validated['contract_type_id'])
            : null;
        $isBhMensal = str_contains(strtolower((string) ($ctForBhMensal->name ?? '')), 'mensal');

        if ($isBhMensal) {
            $validated['coordination_hours'] = null;
        }

        // Horas de coordenação não podem exceder as horas vendidas (contratadas).
        if (!$isBhMensal
            && isset($validated['coordination_hours'], $validated['sold_hours'])
            && $validated['coordination_hours'] !== null && $validated['sold_hours'] !== null
            && (float) $validated['coordination_hours'] > (float) $validated['sold_hours']) {
            return response()->json([
                'code' => 'INVALID_COORDINATION_HOURS',
                'type' => 'error',
                'message' => 'Horas inválidas',
                'detailMessage' => "As horas de coordenação não podem ser maiores que as horas vendidas ({$validated['sold_hours']}h).",
            ], 422);
        }

        // Para tipos que NÃO são BH Mensal, horas de coordenação continuam obrigatórias.
        if (!$isBhMensal && (!array_key_exists('coordination_hours', $validated) || $validated['coordination_hours'] === null)) {
            return response()->json(['message' => 'Horas de Coordenação obrigatórias.'], 422);
        }

        // Validar que o projeto pai não é um subprojeto (evitar múltiplos níveis)
        if (isset($validated['parent_project_id'])) {
            $parentProject = Project::find($validated['parent_project_id']);
            if ($parentProject && $parentProject->isSubProject()) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'O projeto pai não pode ser um subprojeto. Selecione um projeto principal.'
                ], 422);
            }

            // Validar horas vendidas + aportes do subprojeto.
            // On Demand não controla saldo — não faz sentido validar limite de horas.
            $parentProject?->loadMissing('contractType');
            $parentIsOnDemand = $parentProject && $parentProject->contractType
                && strtolower(trim($parentProject->contractType->name)) === 'on demand';

            if (!$parentIsOnDemand) {
                $subProjectSoldHours = $validated['sold_hours'] ?? 0;
                $subProjectHourContribution = $validated['hour_contribution'] ?? 0;
                $subProjectTotalHours = $subProjectSoldHours + $subProjectHourContribution;

                if ($subProjectTotalHours > 0) {
                    $availableHours = $this->calculateAvailableHours($parentProject);

                    if ($subProjectTotalHours > $availableHours) {
                        return response()->json([
                            'code' => 'INVALID_SOLD_HOURS',
                            'type' => 'error',
                            'message' => 'Horas inválidas',
                            'detailMessage' => "O subprojeto não pode ter mais horas (vendidas + aportes: {$subProjectTotalHours}h) do que as horas disponíveis no projeto pai ({$availableHours}h)."
                        ], 422);
                    }
                }
            }
        }

        // Separar relacionamentos
        $consultantIds      = $validated['consultant_ids'] ?? [];
        $coordinatorIds     = $validated['coordinator_ids'] ?? $validated['approver_ids'] ?? [];
        $consultantGroupIds = $validated['consultant_group_ids'] ?? [];
        unset($validated['consultant_ids'], $validated['coordinator_ids'], $validated['approver_ids'], $validated['consultant_group_ids']);

        // Pilar 1: projeto operacional não aceita alocação direta (consultants)
        // — aloca via /stages/{id}/allocations. ADR 0007. Contratos (Investimento,
        // cloud, bizify, sustentação) alocam equipe direto no projeto.
        $isInvestimento = !empty($validated['is_investimento_comercial']);
        if (!empty($consultantIds) && !empty($validated['service_type_id']) && !$isInvestimento) {
            $st = \App\Models\ServiceType::find($validated['service_type_id']);
            $name = strtolower((string) ($st?->name ?? ''));
            $isOperational = $name === '' || (
                !str_contains($name, 'sustenta')
                && !str_contains($name, 'cloud')
                && !str_contains($name, 'bizify')
                && !str_contains($name, 'investimento')
            );
            if ($isOperational) {
                return response()->json([
                    'message' => 'Projeto operacional aloca via etapas — use /stages/{id}/allocations.',
                    'detail'  => 'A alocação direta de consultores no projeto é permitida apenas em projetos de sustentação. Em projetos operacionais, equipe é derivada das alocações de cada etapa.',
                ], 422);
            }
        }

        if (!Schema::hasColumn('projects', 'allow_negative_balance')) {
            unset($validated['allow_negative_balance']);
        }
        if (!Schema::hasColumn('projects', 'client_follows_timesheets')) {
            unset($validated['client_follows_timesheets']);
        }
        if (!Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
            unset($validated['extrato_visivel_cliente']);
        }

        // Gerar ou validar código do projeto
        $customer = Customer::findOrFail($validated['customer_id']);
        $parent   = isset($validated['parent_project_id']) ? Project::find($validated['parent_project_id']) : null;

        $codeService = new ProjectCodeService();
        $codeData    = $codeService->resolveForStore($validated['code'] ?? null, $customer, $parent);

        $validated = array_merge($validated, $codeData);

        // Movidesk integration flag: garante no máximo 1 projeto por cliente
        // com a integração ativa. Se conflito sem confirmação, devolve 409.
        $wantsMovidesk = !empty($validated['movidesk_integration_enabled']);
        $confirmSwap   = !empty($validated['confirm_movidesk_swap']);
        unset($validated['confirm_movidesk_swap']);
        if ($wantsMovidesk) {
            $existing = Project::where('customer_id', $validated['customer_id'])
                ->where('movidesk_integration_enabled', true)
                ->first();
            if ($existing && !$confirmSwap) {
                return response()->json([
                    'code'    => 'MOVIDESK_INTEGRATION_CONFLICT',
                    'type'    => 'conflict',
                    'message' => "Cliente já tem integração Movidesk ativa em outro projeto",
                    'current_project' => ['id' => $existing->id, 'name' => $existing->name, 'code' => $existing->code],
                ], 409);
            }
        }

        $project = \DB::transaction(function () use ($validated, $wantsMovidesk) {
            // Desliga o flag dos outros projetos do cliente ANTES de criar o novo com
            // o flag ativo. A ordem importa: com a unique index parcial (máx 1 flag por
            // cliente), criar com flag=true enquanto outro ainda está true violaria o
            // índice. Desligando primeiro, nunca há 2 ativos simultâneos.
            if ($wantsMovidesk) {
                Project::where('customer_id', $validated['customer_id'])
                    ->where('movidesk_integration_enabled', true)
                    ->update(['movidesk_integration_enabled' => false]);
            }
            return Project::create($validated);
        });

        // Auto-ativação da integração Movidesk para projetos de SUSTENTAÇÃO:
        // se o usuário NÃO setou a flag explicitamente E o cliente ainda não tem
        // nenhum projeto flagado, ativa neste recém-criado (respeita "máx 1 por cliente").
        if (!$request->has('movidesk_integration_enabled')) {
            $project->loadMissing('serviceType');
            $svcCode = $project->serviceType?->code;
            $svcName = strtolower(trim((string) $project->serviceType?->name));
            $isSustentacao = $svcCode === 'sustentacao' || str_contains($svcName, 'sustenta');
            if ($isSustentacao) {
                $hasFlagged = Project::where('customer_id', $project->customer_id)
                    ->where('id', '!=', $project->id)
                    ->where('movidesk_integration_enabled', true)
                    ->exists();
                if (!$hasFlagged) {
                    $project->update(['movidesk_integration_enabled' => true]);
                }
            }
        }

        // Vincular consultores
        if (!empty($consultantIds)) {
            $project->consultants()->attach($consultantIds);
        }

        // Vincular coordenadores
        if (!empty($coordinatorIds)) {
            $project->coordinators()->attach($coordinatorIds);
        }

        // Vincular grupos de consultores
        if (!empty($consultantGroupIds)) {
            $project->consultantGroups()->sync($consultantGroupIds);
        }

        // Registrar histórico inicial de sold_hours para Banco de Horas Mensal
        $project->loadMissing('contractType');
        if ($project->isBankHoursMonthly() && $project->sold_hours) {
            $effectiveFrom = $project->start_date
                ? Carbon::parse($project->start_date)->startOfMonth()->toDateString()
                : Carbon::now()->startOfMonth()->toDateString();

            \App\Models\ProjectSoldHoursHistory::create([
                'project_id'   => $project->id,
                'sold_hours'   => $project->sold_hours,
                'effective_from' => $effectiveFrom,
                'changed_by'   => auth()->id(),
            ]);
        }

        // Recarregar com relacionamentos
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups', 'parentProject', 'soldHoursHistory.changer']);

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;
        $this->invalidateListCache('projects');

        return response()->json($project, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Exibir projeto específico",
     *     description="Retorna os dados de um projeto específico",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do projeto",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="contract_type", type="string"),
     *             @OA\Property(property="project_value", type="number"),
     *             @OA\Property(property="customer", type="object"),
     *             @OA\Property(property="service_type", type="object"),
     *             @OA\Property(property="consultants", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="approvers", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function show(Project $project): JsonResponse
    {
        // Cliente: visão em DIAS, sem horas/valores. Curto-circuita a montagem interna.
        $reqUser = request()->user();
        if ($reqUser && method_exists($reqUser, 'isCliente') && $reqUser->isCliente()) {
            return app(\App\Http\Controllers\ClientProjectController::class)->summary($project, $reqUser);
        }
        // Coordenador tem ACESSO FULL ao detalhe de qualquer projeto (sem gate aqui).

        // Carregar relacionamentos essenciais
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups.consultants', 'parentProject', 'childProjects', 'hourContributions']);

        // Detalhes do contrato (Vendedor, Arquiteto, Executivo de Conta) — usados
        // pelo ProjectViewModal pra exibir os campos herdados na visão geral.
        try {
            $project->load(['architect:id,name', 'executivoConta:id,name', 'vendedor:id,name']);
        } catch (\Throwable $e) {
            // Se alguma relação ainda não existir, ignora silenciosamente.
        }

        try {
            $project->load(['soldHoursHistory.changer']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao carregar soldHoursHistory', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->setRelation('soldHoursHistory', collect());
        }

        // Carregar coordinators com fallback (tabela pode estar em migração)
        try {
            $project->load(['coordinators']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao carregar coordinators', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->setRelation('coordinators', collect());
            $project->setRelation('approvers', collect());
        }

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;

        // Saldo + consumido pela regra da GESTÃO DE PROJETOS (fonte da verdade) — conta os
        // subprojetos e popula consumed_hours (antes o detalhe mostrava 0). Mantém o detalhe
        // batendo com a lista/kanban/gestão. NÃO usa initial_hours_balance.
        try {
            $b = $project->managementBreakdown();
            $project->consumed_hours = $b['consumed'];
            $project->general_hours_balance = $b['balance'];
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@show: falha ao calcular saldo/consumo', ['error' => $e->getMessage(), 'project_id' => $project->id]); } catch (\Throwable $_) {}
            $project->general_hours_balance = null;
        }

        // Adicionar valores calculados de aportes de horas
        $project->total_available_hours = $project->getTotalAvailableHours();
        $project->total_project_value = $project->calculateTotalProjectValue();
        $project->weighted_hourly_rate = $project->getWeightedAverageHourlyRate();
        $project->total_contributions_hours = $project->hourContributions()->sum('contributed_hours') ?? 0;
        // Banco de coordenação (coordination_hours já vem como coluna). Consumo = horas
        // apontadas pelos coordenadores; saldo/%/risco c/ fallback são calculados no front.
        $project->coordination_consumed_hours = $project->getCoordinationConsumedHours();

        // Quebra das HS Vendidas (Projeto + Aporte) — espelha lógica do index()
        // gestaoMode pra que a Visão Geral do projeto não dependa do cache da lista.
        // Em BH Mensal usa accumulated_sold_hours já corrigido (cap pelo encerramento).
        if ($project->isBankHoursMonthly()) {
            $dbAccum = $project->getRawOriginal('accumulated_sold_hours') ?? $project->accumulated_sold_hours;
            if ($dbAccum !== null && $dbAccum > 0) {
                $accumulatedHours = (int) $dbAccum;
            } else {
                // Fallback: recalcula respeitando encerramento_date
                $accumulatedHours = (int) ($project->calculateAccumulatedSoldHours() ?? ($project->sold_hours ?? 0));
            }
            $newContribs = (float) ($project->total_available_hours - ($project->sold_hours ?? 0));
            $project->vendidas_projeto_hours = round($accumulatedHours, 2);
            $project->vendidas_aporte_hours  = round($newContribs, 2);
        } else {
            $project->vendidas_projeto_hours = round((float) ($project->sold_hours ?? 0), 2);
            $project->vendidas_aporte_hours  = round((float) ($project->total_available_hours - ($project->sold_hours ?? 0)), 2);
        }
        // Banco de coordenação (coordination_hours já vem como coluna). Consumo = horas
        // apontadas pelos coordenadores; saldo/%/risco c/ fallback são calculados no front.
        $project->coordination_consumed_hours = $project->getCoordinationConsumedHours();

        // Adicionar total de minutos apontados (excluindo rejeitados)
        $project->total_logged_minutes = DB::table('timesheets')
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'pending'])
            ->sum('effort_minutes') ?? 0;

        $this->invalidateListCache('projects');

        // Subprojeto faturado que gerou aporte automático no pai → flag p/ legenda verde no filho.
        // Vínculo pela referência ao CÓDIGO do filho na descrição do aporte do pai.
        $project->generated_aporte = null;
        if ($project->parent_project_id && $project->code) {
            $ap = \App\Models\HourContribution::where('project_id', $project->parent_project_id)
                ->where('description', 'ilike', '%ref. subprojeto faturado%(' . $project->code . '%')
                ->orderByDesc('id')
                ->first(['id', 'project_id', 'kanban_status']);
            if ($ap) {
                $project->generated_aporte = ['id' => $ap->id, 'parent_id' => $ap->project_id, 'kanban_status' => $ap->kanban_status];
            }
        }

        // Subprojeto faturado: garante que a proposta exista NO FILHO e NO APORTE gerado
        // (espelhamento idempotente, self-healing/backfill). O anexo pode ter chegado via
        // aporte, contrato ou projeto — pega de onde estiver e replica nos dois.
        try {
            $this->syncFaturadoSubprojectProposta($project);
        } catch (\Throwable $e) {
            \Log::warning('syncFaturadoSubprojectProposta falhou', ['project_id' => $project->id, 'err' => $e->getMessage()]);
        }

        // Acesso ao Diário do Projeto (chat) — o FE esconde a aba quando false, evitando o
        // "Erro ao carregar mensagens". Regra única em ProjectDiaryAccess, a mesma que o
        // ProjectMessageController usa pra barrar a API (senão a aba aparece e dá 403).
        $project->diary_access = \App\Services\ProjectDiaryAccess::allows(request()->user(), $project);

        return response()->json($project);
    }

    /**
     * Espelha a "proposta/aprovação" entre o subprojeto faturado e o aporte que ele gerou
     * no projeto pai, mantendo os dois com o mesmo anexo. Idempotente (registerExisting
     * deduplica por checksum) e best-effort. Vínculo pelo CÓDIGO do filho na descrição do
     * aporte ("Aporte ref. subprojeto faturado (CÓDIGO ...)").
     */
    private function syncFaturadoSubprojectProposta(Project $project): void
    {
        if (!$project->parent_project_id || empty($project->code)) {
            return; // só subprojeto com código
        }
        $aporte = \App\Models\HourContribution::where('project_id', $project->parent_project_id)
            ->where('description', 'ilike', '%ref. subprojeto faturado%(' . $project->code . '%')
            ->orderByDesc('id')
            ->first();
        if (!$aporte) {
            return; // não é subprojeto faturado (sem aporte gerado)
        }

        // Procura a proposta de origem: no aporte → no contrato vinculado → no próprio projeto.
        $att = \App\Models\Attachment::query()->where('entity_type', 'HOUR_CONTRIBUTION')->where('entity_id', $aporte->id)
            ->whereNull('deleted_at')->orderByDesc('id')->first();
        if (!$att && $project->contract_id) {
            $att = \App\Models\Attachment::query()->where('entity_type', 'CONTRACT')->where('entity_id', $project->contract_id)
                ->whereNull('deleted_at')->orderByDesc('id')->first();
        }
        if (!$att) {
            $att = \App\Models\Attachment::query()->where('entity_type', 'PROJECT')->where('entity_id', $project->id)
                ->whereNull('deleted_at')->orderByDesc('id')->first();
        }
        if (!$att) {
            return; // nenhuma proposta em lugar nenhum ainda
        }

        $svc   = app(\App\Attachments\AttachmentService::class);
        $actor = \App\Models\User::find($att->uploaded_by) ?? \App\Models\User::find($aporte->contributed_by);
        if (!$actor) {
            return;
        }
        $payload = [
            'storage_path'  => $att->storage_path,
            'original_name' => $att->original_name,
            'mime_type'     => $att->mime_type,
            'category'      => 'proposal',
            'metadata'      => ['mirrored' => true, 'from_attachment_id' => $att->id],
        ];
        // Garante no FILHO (PROJECT).
        $svc->registerExisting($actor, array_merge($payload, ['entity_type' => 'PROJECT', 'entity_id' => $project->id]));
        // Garante no APORTE (HOUR_CONTRIBUTION).
        $svc->registerExisting($actor, array_merge($payload, ['entity_type' => 'HOUR_CONTRIBUTION', 'entity_id' => $aporte->id]));
    }

    /**
     * @OA\Put(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Atualizar projeto",
     *     description="Atualiza os dados de um projeto específico",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Website Institucional v2"),
     *             @OA\Property(property="description", type="string", example="Nova versão do website"),
     *             @OA\Property(property="status", type="string", example="started"),
     *             @OA\Property(property="consultant_ids", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="approver_ids", type="array", @OA\Items(type="integer"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Projeto atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="code", type="string"),
     *             @OA\Property(property="status", type="string")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados de validação inválidos"),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        // Verificar se projeto pode ser editado (admin sempre pode)
        if (!$project->canBeEdited() && !auth()->user()->isAdmin()) {
            return response()->json([
                'code' => 'PROJECT_FINISHED',
                'type' => 'error',
                'message' => 'Projeto finalizado não pode ser editado',
                'detailMessage' => 'Este projeto já foi finalizado e não pode mais ser modificado'
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|min:2',
            'code' => 'sometimes|string|max:50|unique:projects,code,' . $project->id,
            'description' => 'nullable|string|max:2000',
            'customer_id' => 'sometimes|exists:customers,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'service_type_id' => 'sometimes|exists:service_types,id',
            'contract_type_id' => 'sometimes|exists:contract_types,id',
            // Permite o status ATUAL do projeto mesmo que não esteja em getStatuses() — há
            // projetos com status legado do Cronograma (ex.: 'liberado_para_testes') que a
            // FE reenvia ao salvar; sem isso o update falha com validation.in (422).
            'status' => ['sometimes', Rule::in(array_merge(array_keys(Project::getStatuses()), [$project->status]))],
            'project_value' => 'nullable|numeric|min:0|max:999999999.99',
            'hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'sold_hours' => 'nullable|numeric|min:0|max:999999',
            'hour_contribution' => 'nullable|numeric|min:0|max:999999',
            'exceeded_hour_contribution' => 'nullable|numeric|min:0|max:999999',
            'initial_hours_balance' => 'nullable|numeric|min:-999999|max:999999',
            'initial_hours_consumed' => 'nullable|numeric|min:0|max:999999',
            'initial_cost' => 'nullable|numeric|min:0|max:999999999.99',
            'consultant_hours' => 'nullable|integer|min:0|max:999999',
            'coordinator_hours' => 'nullable|integer|min:0|max:999999',
            'coordination_hours' => 'nullable|numeric|min:0|max:999999',
            'additional_hourly_rate' => 'nullable|numeric|min:0|max:999999.99',
            'start_date' => 'nullable|date',
            'expected_end_date' => 'nullable|date',
            'delivery_percentage' => 'nullable|numeric|min:0|max:100',
            'encerramento_date' => 'nullable|date',
            'save_erpserv' => 'nullable|numeric|min:0|max:999999999.99',
            'max_expense_per_consultant' => 'nullable|numeric|min:0|max:999999999.99',
            'unlimited_expense' => 'nullable|boolean',
            'expense_responsible_party' => ['nullable', Rule::in(['consultancy', 'client'])],
            'timesheet_retroactive_limit_days' => 'nullable|integer|min:0|max:365',
            'allow_negative_balance' => 'nullable|boolean',
            'allow_weekend_work' => 'nullable|boolean',
            'allow_holiday_work' => 'nullable|boolean',
            'client_follows_timesheets' => 'nullable|boolean',
            'extrato_visivel_cliente' => 'nullable|boolean',
            'sold_hours_effective_from' => 'nullable|date',
            'hourly_rate_effective_from' => 'nullable|date',
            'consultant_ids' => 'nullable|array',
            'consultant_ids.*' => 'exists:users,id',
            // Projetos reais por consultor (só faz sentido em projeto de investimento):
            // mapa { user_id => [real_project_id, ...] }.
            'real_projects_by_consultant' => 'nullable|array',
            'real_projects_by_consultant.*' => 'array',
            'real_projects_by_consultant.*.*' => 'integer|exists:projects,id',
            'coordinator_ids' => 'nullable|array|max:1',
            'coordinator_ids.*' => 'exists:users,id',
            'consultant_group_ids' => 'nullable|array',
            'consultant_group_ids.*' => 'exists:consultant_groups,id',
            // Contract-origin fields
            'observacoes_contrato'  => 'nullable|string',
            'condicao_pagamento'    => 'nullable|string',
            'cobra_despesa_cliente' => 'nullable|boolean',
            'tipo_faturamento'      => 'nullable|string',
            'tipo_alocacao'         => 'nullable|in:remoto,presencial,ambos',
            'vendedor_id'           => 'nullable|exists:users,id',
            'architect_id'          => 'nullable|exists:users,id',
            'executivo_conta_id'    => 'nullable|exists:users,id',
            'kanban_coordinator_override_id' => 'nullable|exists:users,id',
            'categoria_interna' => 'nullable|in:Sustentação,Projeto,Suporte,Comercial,Leads',
            'movidesk_integration_enabled' => 'nullable|boolean',
            'confirm_movidesk_swap'        => 'nullable|boolean',
            'migrate_movidesk_timesheets'  => 'nullable|boolean',
        ], [
            'name.max' => 'O nome não pode ter mais de 255 caracteres',
            'name.min' => 'O nome deve ter pelo menos 2 caracteres',
            'code.unique' => 'Este código já está sendo usado por outro projeto',
            'customer_id.exists' => 'Cliente não encontrado',
            'parent_project_id.exists' => 'Projeto pai não encontrado',
            'service_type_id.exists' => 'Tipo de serviço não encontrado',
            'timesheet_retroactive_limit_days.integer' => 'O prazo deve ser um número inteiro',
            'timesheet_retroactive_limit_days.min' => 'O prazo não pode ser negativo',
            'timesheet_retroactive_limit_days.max' => 'O prazo não pode ser maior que 365 dias',
        ]);

        // BH Mensal não tem horas de coordenação — força null e pula a validação/guard.
        // Detecta pelo tipo de contrato (novo, se enviado; senão o atual do projeto).
        $ctForBhMensal = array_key_exists('contract_type_id', $validated)
            ? ContractType::find($validated['contract_type_id'])
            : (function () use ($project) { $project->loadMissing('contractType'); return $project->contractType; })();
        $isBhMensal = str_contains(strtolower((string) ($ctForBhMensal->name ?? '')), 'mensal');

        if ($isBhMensal) {
            $validated['coordination_hours'] = null;
        }

        // Horas de coordenação não podem exceder as horas vendidas (contratadas).
        $coordH = array_key_exists('coordination_hours', $validated) ? $validated['coordination_hours'] : null;
        $soldHForCoord = array_key_exists('sold_hours', $validated) ? $validated['sold_hours'] : $project->sold_hours;
        if (!$isBhMensal && $coordH !== null && $soldHForCoord !== null && (float) $coordH > (float) $soldHForCoord) {
            return response()->json([
                'code' => 'INVALID_COORDINATION_HOURS',
                'type' => 'error',
                'message' => 'Horas inválidas',
                'detailMessage' => "As horas de coordenação não podem ser maiores que as horas vendidas ({$soldHForCoord}h).",
            ], 422);
        }

        // Tratar atualização de código manual
        if (isset($validated['code']) && $validated['code'] !== $project->code) {
            // Novo código enviado → marcar como manual
            $validated['is_manual_code'] = true;
        } elseif (!$project->is_manual_code) {
            // Código automático não pode ser alterado pelo cliente sem enviar código novo
            unset($validated['code']);
        }

        // Validações de parent_project_id
        if (isset($validated['parent_project_id'])) {
            // Não pode ser pai de si mesmo
            if ($validated['parent_project_id'] === $project->id) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'Um projeto não pode ser pai de si mesmo.'
                ], 422);
            }

            // O projeto pai não pode ser um subprojeto (evitar múltiplos níveis)
            $parentProject = Project::find($validated['parent_project_id']);
            if ($parentProject && $parentProject->isSubProject()) {
                return response()->json([
                    'code' => 'INVALID_PARENT_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto pai inválido',
                    'detailMessage' => 'O projeto pai não pode ser um subprojeto. Selecione um projeto principal.'
                ], 422);
            }

            // Se o projeto tem filhos, não pode se tornar um subprojeto
            if ($project->hasChildProjects()) {
                return response()->json([
                    'code' => 'PROJECT_HAS_CHILDREN',
                    'type' => 'error',
                    'message' => 'Operação não permitida',
                    'detailMessage' => 'Este projeto possui subprojetos e não pode se tornar um subprojeto.'
                ], 422);
            }

            // Validar horas vendidas + aporte de horas do subprojeto
            $newSoldHours = $validated['sold_hours'] ?? $project->sold_hours;
            $newHourContribution = $validated['hour_contribution'] ?? $project->hour_contribution;
            $newSubProjectTotalHours = $newSoldHours + $newHourContribution;

            if ($newSubProjectTotalHours > 0) {
                // Usar calculateAvailableHours que já retorna o saldo disponível excluindo o projeto filho atual
                // Segue a mesma lógica de $excludeProjectId: adiciona de volta o que foi subtraído do projeto filho
                $availableHours = $this->calculateAvailableHours($parentProject, $project->id);

                if ($newSubProjectTotalHours > $availableHours) {
                    return response()->json([
                        'code' => 'INVALID_SOLD_HOURS',
                        'type' => 'error',
                        'message' => 'Horas inválidas',
                        'detailMessage' => "O subprojeto não pode ter mais horas (vendidas + aporte: {$newSubProjectTotalHours}h) do que as horas disponíveis no projeto pai ({$availableHours}h)."
                    ], 422);
                }
            }
        }

        // Se o projeto é pai e está mudando sold_hours ou hour_contribution, validar que não fica menor que soma dos filhos
        if (!isset($validated['parent_project_id']) && (isset($validated['sold_hours']) || isset($validated['hour_contribution']))) {
            // Garantir que o tipo de contrato do projeto e dos filhos esteja carregado
            $project->loadMissing('contractType', 'childProjects.contractType');

            // === Horas efetivas do projeto pai ===
            $parentBaseSoldHours = $validated['sold_hours'] ?? $project->sold_hours ?? 0;
            $parentHourContribution = $validated['hour_contribution'] ?? $project->hour_contribution ?? 0;

            $parentEffectiveSoldHours = $parentBaseSoldHours;

            if ($project->isBankHoursMonthly()) {
                // Para Banco de Horas Mensal, multiplicar pela quantidade de meses ativos
                $startDateValue = $validated['start_date'] ?? $project->start_date;

                if ($startDateValue) {
                    $startDate = Carbon::parse($startDateValue);

                    if (!$startDate->isFuture()) {
                        $endDate = Carbon::now();
                        $startMonth = $startDate->copy()->startOfMonth();
                        $endMonth = $endDate->copy()->startOfMonth();
                        $monthsDiff = $startMonth->diffInMonths($endMonth);
                        // Incluir o mês corrente
                        $totalMonths = $monthsDiff + 1;

                        $parentEffectiveSoldHours = $parentBaseSoldHours * $totalMonths;
                    } else {
                        // Data de início no futuro: considerar 0 horas acumuladas
                        $parentEffectiveSoldHours = 0;
                    }
                } else {
                    // Sem data de início: considerar apenas 1 mês ativo
                    $parentEffectiveSoldHours = $parentBaseSoldHours;
                }
            }

            $parentTotalHours = $parentEffectiveSoldHours + $parentHourContribution;

            // === Soma das horas efetivas dos filhos ===
            $childrenTotalHours = 0;
            $childProjects = $project->childProjects()->get();

            foreach ($childProjects as $childProject) {
                if ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal, usar accumulated_sold_hours se já calculado; fallback para sold_hours
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                } else {
                    $childSoldHours = $childProject->sold_hours ?? 0;
                }

                $childHourContribution = $childProject->hour_contribution ?? 0;
                $childrenTotalHours += ($childSoldHours + $childHourContribution);
            }

            if ($parentTotalHours < $childrenTotalHours) {
                return response()->json([
                    'code' => 'INVALID_SOLD_HOURS',
                    'type' => 'error',
                    'message' => 'Horas inválidas',
                    'detailMessage' => "O projeto pai não pode ter menos horas (vendidas + aporte: {$parentTotalHours}h) do que a soma das horas (vendidas + aporte) dos subprojetos ({$childrenTotalHours}h)."
                ], 422);
            }
        }

        // Separar relacionamentos e campos que não pertencem ao model
        $consultantIds      = $validated['consultant_ids'] ?? null;
        // false = campo não enviado (não mexe); array (mesmo vazio) = sincronizar.
        $realProjectsByConsultant = array_key_exists('real_projects_by_consultant', $validated)
            ? ($validated['real_projects_by_consultant'] ?? [])
            : false;
        $coordinatorIds     = $validated['coordinator_ids'] ?? $validated['approver_ids'] ?? null;
        $consultantGroupIds = array_key_exists('consultant_group_ids', $validated) ? $validated['consultant_group_ids'] : false;

        // Pilar 1: bloqueia alocação direta em projeto operacional (ADR 0007).
        // Linhas existentes não são deletadas — apenas writes novos são rejeitados.
        if (!empty($consultantIds)) {
            $project->loadMissing('serviceType');
            if ($project->isOperational()) {
                return response()->json([
                    'message' => 'Projeto operacional aloca via etapas — use /stages/{id}/allocations.',
                    'detail'  => 'A alocação direta de consultores no projeto é permitida apenas em projetos de sustentação. Em projetos operacionais, equipe é derivada das alocações de cada etapa.',
                ], 422);
            }
        }
        $soldHoursEffectiveFrom = isset($validated['sold_hours_effective_from'])
            ? Carbon::parse($validated['sold_hours_effective_from'])->startOfMonth()->toDateString()
            : Carbon::now()->startOfMonth()->toDateString();
        $hourlyRateEffectiveFrom = isset($validated['hourly_rate_effective_from'])
            ? Carbon::parse($validated['hourly_rate_effective_from'])->startOfMonth()->toDateString()
            : null;
        $previousHourlyRate = $project->hourly_rate;
        unset($validated['consultant_ids'], $validated['real_projects_by_consultant'], $validated['coordinator_ids'], $validated['approver_ids'], $validated['consultant_group_ids'], $validated['sold_hours_effective_from'], $validated['hourly_rate_effective_from']);

        // Detectar mudança de sold_hours para registrar histórico (Banco de Horas Mensal)
        $previousSoldHours = (float) ($project->sold_hours ?? 0);
        $newSoldHours      = isset($validated['sold_hours']) ? (float) $validated['sold_hours'] : $previousSoldHours;

        // Log de alteração do percentual de coordenação (auditoria)
        $previousPercentage = (float) ($project->coordinator_hours ?? 0);
        $newPercentage      = isset($validated['coordinator_hours']) ? (float) $validated['coordinator_hours'] : $previousPercentage;

        // Tratar campos nullable explicitamente - se foram enviados como null ou string vazia, garantir que sejam null
        // Isso permite limpar campos que antes tinham valores
        // Verifica se o campo foi enviado na requisição (mesmo que seja null ou vazio)
        if ($request->has('max_expense_per_consultant')) {
            $maxExpenseValue = $request->input('max_expense_per_consultant');
            // Se foi enviado como null, string vazia, ou 0, definir como null
            if ($maxExpenseValue === null || $maxExpenseValue === '' || $maxExpenseValue === '0' || $maxExpenseValue === 0) {
                $validated['max_expense_per_consultant'] = null;
            }
        }

        // Remover campos que ainda não existem no banco (migrações pendentes)
        if (!Schema::hasColumn('projects', 'allow_negative_balance')) {
            unset($validated['allow_negative_balance']);
        }
        if (!Schema::hasColumn('projects', 'client_follows_timesheets')) {
            unset($validated['client_follows_timesheets']);
        }
        if (!Schema::hasColumn('projects', 'extrato_visivel_cliente')) {
            unset($validated['extrato_visivel_cliente']);
        }

        // Override de coordenador para projetos de sustentação:
        // - Só admin pode setar/limpar
        // - Só pra projetos cujo service_type seja sustentação
        // - Sincroniza com Contract Kanban (migra card pra coluna do coord ou
        //   devolve pra fila de sustentação correta).
        $overrideKey = 'kanban_coordinator_override_id';
        $overrideInValidated = array_key_exists($overrideKey, $validated);
        if ($overrideInValidated) {
            if (!auth()->user()->isAdmin()) {
                unset($validated[$overrideKey]);
                $overrideInValidated = false;
            } else {
                $project->loadMissing('serviceType');
                $svcCode = $project->serviceType?->code;
                $svcName = strtolower(trim((string) $project->serviceType?->name));
                $isSustentacao = $svcCode === 'sustentacao' || str_contains($svcName, 'sustenta');
                if (!$isSustentacao && !empty($validated[$overrideKey])) {
                    return response()->json([
                        'code' => 'OVERRIDE_NOT_ALLOWED',
                        'message' => 'Override de coordenador só é permitido em projetos de sustentação.',
                    ], 422);
                }
            }
        }

        // Movidesk integration flag (mesma regra do store): no máximo 1 por cliente.
        // Conflito sem confirm_movidesk_swap → 409.
        $confirmSwap = !empty($validated['confirm_movidesk_swap']);
        unset($validated['confirm_movidesk_swap']);
        $migrateMovideskTimesheets = !empty($validated['migrate_movidesk_timesheets']);
        unset($validated['migrate_movidesk_timesheets']);
        if (array_key_exists('movidesk_integration_enabled', $validated)
            && $validated['movidesk_integration_enabled'] === true
            && (bool) $project->movidesk_integration_enabled !== true) {
            $existing = Project::where('customer_id', $validated['customer_id'] ?? $project->customer_id)
                ->where('id', '!=', $project->id)
                ->where('movidesk_integration_enabled', true)
                ->first();
            if ($existing && !$confirmSwap) {
                return response()->json([
                    'code'    => 'MOVIDESK_INTEGRATION_CONFLICT',
                    'type'    => 'conflict',
                    'message' => "Cliente já tem integração Movidesk ativa em outro projeto",
                    'current_project' => ['id' => $existing->id, 'name' => $existing->name, 'code' => $existing->code],
                ], 409);
            }
        }

        // Captura os projetos do cliente que HOJE estão com a flag ativa (os "antigos"),
        // antes da transação desativá-los — necessário para migrar os apontamentos depois.
        $oldFlaggedIds = [];
        if (!empty($validated['movidesk_integration_enabled'])) {
            $oldFlaggedIds = Project::where('customer_id', $project->customer_id)
                ->where('id', '!=', $project->id)
                ->where('movidesk_integration_enabled', true)
                ->pluck('id')
                ->all();
        }

        \DB::transaction(function () use ($project, $validated) {
            // Desliga o flag dos OUTROS projetos do cliente ANTES de ligar neste, pra
            // nunca haver 2 ativos ao mesmo tempo (a unique index parcial barraria).
            if (!empty($validated['movidesk_integration_enabled'])) {
                Project::where('customer_id', $project->customer_id)
                    ->where('id', '!=', $project->id)
                    ->where('movidesk_integration_enabled', true)
                    ->update(['movidesk_integration_enabled' => false]);
            }
            $project->update($validated);
        });

        // Migração opcional dos apontamentos de origem Movidesk dos projetos antigos
        // para o novo projeto flagado. "Origem Movidesk" = tem movidesk_appointment_id
        // (o origin pode ser 'webhook'/'movidesk_fallback'/etc; o id é o sinal confiável).
        // Eloquent respeita SoftDeletes automaticamente.
        if ($migrateMovideskTimesheets && !empty($oldFlaggedIds)) {
            \App\Models\Timesheet::whereIn('project_id', $oldFlaggedIds)
                ->whereNotNull('movidesk_appointment_id')
                ->update(['project_id' => $project->id]);
        }

        // Idempotente: garante consistência do contract no Kanban sempre que admin envia
        // o campo num projeto de sustentação, mesmo que o valor não tenha mudado.
        if ($overrideInValidated && auth()->user()->isAdmin()) {
            $project->loadMissing('serviceType');
            $svcCodeS = $project->serviceType?->code;
            $svcNameS = strtolower(trim((string) $project->serviceType?->name));
            if ($svcCodeS === 'sustentacao' || str_contains($svcNameS, 'sustenta')) {
                $this->syncContractKanbanForOverride($project);
            }
        }

        // Garantir que accumulated_sold_hours está atualizado para Banco de Horas Mensal
        if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
            $project->load('contractType');
        }

        if ($project->isBankHoursMonthly()) {
            try {
                $project->updateAccumulatedSoldHours(null, true);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao atualizar accumulated_sold_hours', ['error' => $e->getMessage()]);
            }
        }

        // Gravar log se o percentual de coordenação mudou
        if ($previousPercentage !== $newPercentage) {
            try {
                $previousBalance = $project->getGeneralHoursBalance();
                $project->refresh();
                $newBalance = $project->getGeneralHoursBalance();

                \App\Models\ProjectCoordinatorPercentageLog::create([
                    'project_id'          => $project->id,
                    'changed_by'          => auth()->id(),
                    'previous_percentage' => $previousPercentage,
                    'new_percentage'      => $newPercentage,
                    'previous_balance'    => $previousBalance,
                    'new_balance'         => $newBalance,
                ]);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao gravar log de percentual', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Atualizar consultores se fornecido
        if ($consultantIds !== null) {
            $project->consultants()->sync($consultantIds);
        }

        // Projetos reais por consultor (alocação em projeto de investimento).
        // Só grava se o campo foi enviado E a tabela existe (migração aplicada).
        if ($realProjectsByConsultant !== false && Schema::hasTable('project_consultant_real_projects')) {
            try {
                $this->syncConsultantRealProjects($project, $realProjectsByConsultant);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao sincronizar projetos reais por consultor', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Atualizar coordenadores se fornecido
        if ($coordinatorIds !== null) {
            try {
                $project->coordinators()->sync($coordinatorIds);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao sincronizar coordinators', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Atualizar grupos de consultores se fornecido
        if ($consultantGroupIds !== false) {
            try {
                $project->consultantGroups()->sync($consultantGroupIds ?? []);
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao sincronizar consultant_groups', ['error' => $e->getMessage(), 'project_id' => $project->id]);
            }
        }

        // Registrar histórico de sold_hours se mudou (Banco de Horas Mensal)
        $project->loadMissing('contractType');
        if ($previousSoldHours !== $newSoldHours && $project->isBankHoursMonthly()) {
            try {
                // Bootstrapar histórico inicial se ainda não existe nenhum registro
                if ($project->soldHoursHistory()->count() === 0 && $project->start_date) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $previousSoldHours,
                        'effective_from' => Carbon::parse($project->start_date)->startOfMonth()->toDateString(),
                        'changed_by'     => null,
                    ]);
                }

                // Não criar duplicata se já existe registro para a data efetiva informada
                $exists = $project->soldHoursHistory()
                    ->where('effective_from', $soldHoursEffectiveFrom)
                    ->exists();

                if (!$exists) {
                    \App\Models\ProjectSoldHoursHistory::create([
                        'project_id'     => $project->id,
                        'sold_hours'     => $newSoldHours,
                        'effective_from' => $soldHoursEffectiveFrom,
                        'changed_by'     => auth()->id(),
                    ]);
                } else {
                    // Atualizar o registro já existente para essa data
                    $project->soldHoursHistory()
                        ->where('effective_from', $soldHoursEffectiveFrom)
                        ->update(['sold_hours' => $newSoldHours, 'changed_by' => auth()->id()]);
                }
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao registrar histórico de sold_hours', ['error' => $e->getMessage()]);
            }
        }

        // Recalcular accumulated_sold_hours DEPOIS de gravar o histórico de sold_hours:
        // o Observer já recalculou durante $project->update() (acima), mas as vigências
        // novas só nascem no bloco anterior — sem isto o acumulado fica stale e diverge
        // do extrato (ex.: 3840 em vez de 3850 ao mudar 320→330 com vigência futura).
        if ($previousSoldHours !== $newSoldHours && $project->isBankHoursMonthly()) {
            try {
                $project->updateAccumulatedSoldHours(null, true);
            } catch (\Throwable $e) {
                \Log::warning('ProjectController@update: falha ao recalcular accumulated_sold_hours pós-histórico', ['error' => $e->getMessage()]);
            }
        }

        // Dedup mesmo-dia do change log de hourly_rate: o Observer cria uma nova linha a cada
        // alteração. Se hourly_rate mudou, colapsamos as linhas de HOJE deste projeto numa só —
        // mantendo a MAIS ANTIGA (que tem o old_value do início do dia), atualizando seu new_value
        // para o valor atual e aplicando effective_from quando enviado; as demais de hoje são removidas.
        if ($project->wasChanged('hourly_rate')) {
            try {
                $todayLogs = ProjectChangeLog::where('project_id', $project->id)
                    ->where('field_name', 'hourly_rate')
                    ->whereDate('created_at', now()->toDateString())
                    ->orderBy('id')
                    ->get();

                if ($todayLogs->isNotEmpty()) {
                    $survivor = $todayLogs->first(); // mais antiga = old_value do início do dia
                    $survivor->new_value = (string) $project->hourly_rate;
                    // effective_from não-destrutivo: só sobrescreve se uma nova data foi enviada
                    if ($hourlyRateEffectiveFrom !== null) {
                        $survivor->effective_from = $hourlyRateEffectiveFrom;
                    }
                    $survivor->save();

                    // Remover as demais linhas de hoje (mantém só a sobrevivente)
                    $duplicateIds = $todayLogs->slice(1)->pluck('id');
                    if ($duplicateIds->isNotEmpty()) {
                        ProjectChangeLog::whereIn('id', $duplicateIds)->delete();
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('ProjectController@update: falha ao consolidar change log de hourly_rate do dia', ['error' => $e->getMessage()]);
            }
        }

        // Recarregar com relacionamentos
        $project->load(['customer', 'serviceType', 'contractType', 'consultants', 'coordinators', 'consultantGroups.consultants']);
        try {
            $project->load(['soldHoursHistory.changer']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@update: falha ao carregar soldHoursHistory', ['error' => $e->getMessage()]); } catch (\Throwable $_) {}
            $project->setRelation('soldHoursHistory', collect());
        }
        try {
            $project->load(['coordinators']);
        } catch (\Throwable $e) {
            try { \Log::warning('ProjectController@update: falha ao carregar coordinators', ['error' => $e->getMessage()]); } catch (\Throwable $_) {}
            $project->setRelation('coordinators', collect());
        }

        // Adicionar atributos computed
        $project->status_display = $project->status_display;
        $project->contract_type_display = $project->contract_type_display;

        return response()->json($project);
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/projects/{id}",
     *     tags={"Projects"},
     *     summary="Deletar projeto",
     *     description="Remove um projeto do sistema (soft delete)",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(response=204, description="Projeto deletado com sucesso"),
     *     @OA\Response(
     *         response=422,
     *         description="Projeto possui apontamentos vinculados e não pode ser deletado",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="code", type="string", example="PROJECT_HAS_TIMESHEETS"),
     *             @OA\Property(property="type", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Não é possível excluir o projeto pois existem apontamentos vinculados."),
     *             @OA\Property(property="detailMessage", type="string", example="Exclua ou remova os apontamentos vinculados antes de tentar excluir o projeto.")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão")
     * )
     */
    public function destroy(Project $project): JsonResponse
    {
        // Verificar se existem timesheets (apontamentos) vinculados ao projeto
        if ($project->timesheets()->exists()) {
            return response()->json([
                'code' => 'PROJECT_HAS_TIMESHEETS',
                'type' => 'error',
                'message' => 'Não é possível excluir o projeto pois existem apontamentos vinculados.',
                'detailMessage' => 'Exclua ou remova os apontamentos vinculados antes de tentar excluir o projeto.'
            ], 422);
        }

        $project->delete();
        $this->invalidateListCache('projects');

        return response()->json(null, 204);
    }

    /**
     * Atualiza um registro do histórico e aplica o novo valor no projeto.
     */
    public function updateChangeHistory(Project $project, \App\Models\ProjectChangeLog $log, Request $request): JsonResponse
    {
        if ($log->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $validated = $request->validate([
            'new_value' => 'nullable',
            'reason'    => 'nullable|string|max:1000',
        ]);

        $log->update($validated);

        // Aplicar o novo valor no projeto
        if (array_key_exists('new_value', $validated) && in_array($log->field_name, $project->getFillable())) {
            try {
                $project->update([$log->field_name => $validated['new_value']]);
            } catch (\Exception $e) {
                \Log::warning('updateChangeHistory: falha ao atualizar projeto', ['error' => $e->getMessage()]);
            }
        }

        return response()->json($log->fresh(['changedByUser'])->toFormattedArray());
    }

    /**
     * Remove um registro do histórico e reverte o campo do projeto ao valor anterior.
     */
    public function destroyChangeHistory(Project $project, \App\Models\ProjectChangeLog $log): JsonResponse
    {
        if ($log->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        // Reverter o campo do projeto ao valor anterior
        if ($log->old_value !== null && in_array($log->field_name, $project->getFillable())) {
            try {
                $project->update([$log->field_name => $log->old_value]);
            } catch (\Exception $e) {
                \Log::warning('destroyChangeHistory: falha ao reverter projeto', ['error' => $e->getMessage()]);
            }
        }

        $log->delete();

        return response()->json(null, 204);
    }

    /**
     * Lista o histórico de horas vendidas (vigências) de um projeto, ordenado por
     * mês de vigência. Espelha o histórico de valor-hora (change-history) na tela.
     */
    public function soldHoursHistoryIndex(Project $project): JsonResponse
    {
        $project->load('soldHoursHistory.changer');

        return response()->json($project->soldHoursHistory->sortBy('effective_from')->values());
    }

    /**
     * Atualiza um registro do histórico de horas vendidas.
     */
    public function updateSoldHoursHistory(Project $project, \App\Models\ProjectSoldHoursHistory $history, Request $request): JsonResponse
    {
        if ($history->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $validated = $request->validate([
            'sold_hours'     => 'required|numeric|min:0|max:999999',
            'effective_from' => 'required|date',
        ]);

        $validated['effective_from'] = Carbon::parse($validated['effective_from'])->startOfMonth()->toDateString();
        $validated['changed_by'] = auth()->id();

        $history->update($validated);

        $project->updateAccumulatedSoldHours(null, true);

        $project->load('soldHoursHistory.changer');

        return response()->json($project->soldHoursHistory->sortBy('effective_from')->values());
    }

    /**
     * Remove um registro do histórico de horas vendidas.
     */
    public function destroySoldHoursHistory(Project $project, \App\Models\ProjectSoldHoursHistory $history): JsonResponse
    {
        if ($history->project_id !== $project->id) {
            return response()->json(['message' => 'Registro não pertence ao projeto.'], 404);
        }

        $history->delete();

        $project->updateAccumulatedSoldHours(null, true);

        $project->load('soldHoursHistory.changer');

        return response()->json($project->soldHoursHistory->sortBy('effective_from')->values());
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}/cost-summary",
     *     tags={"Projects"},
     *     summary="Obter resumo de custos do projeto",
     *     description="Retorna informações detalhadas de custos e horas do projeto",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Resumo de custos do projeto",
     *         @OA\JsonContent(
     *             @OA\Property(property="project_info", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="code", type="string"),
     *                 @OA\Property(property="project_value", type="number"),
     *                 @OA\Property(property="hourly_rate", type="number"),
     *                 @OA\Property(property="sold_hours", type="integer"),
     *                 @OA\Property(property="hour_contribution", type="integer"),
     *                 @OA\Property(property="exceeded_hour_contribution", type="integer")
     *             ),
     *             @OA\Property(property="hours_summary", type="object",
     *                 @OA\Property(property="total_logged_hours", type="number"),
     *                 @OA\Property(property="approved_hours", type="number"),
     *                 @OA\Property(property="pending_hours", type="number"),
     *                 @OA\Property(property="remaining_hours", type="number"),
     *                 @OA\Property(property="hours_percentage", type="number")
     *             ),
     *             @OA\Property(property="cost_calculation", type="object",
     *                 @OA\Property(property="total_cost", type="number"),
     *                 @OA\Property(property="approved_cost", type="number"),
     *                 @OA\Property(property="pending_cost", type="number"),
     *                 @OA\Property(property="margin", type="number"),
     *                 @OA\Property(property="margin_percentage", type="number")
     *             ),
     *             @OA\Property(property="consultant_breakdown", type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="consultant_name", type="string"),
     *                     @OA\Property(property="total_hours", type="number"),
     *                     @OA\Property(property="approved_hours", type="number"),
     *                     @OA\Property(property="pending_hours", type="number"),
     *                     @OA\Property(property="cost", type="number")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Projeto não encontrado"
     *     )
     * )
     */
    public function costSummary(Project $project): JsonResponse
    {
        // Carregar dados relacionados do projeto principal
        $project->load(['timesheets.user', 'consultants', 'childProjects.timesheets.user', 'childProjects.contractType', 'hourContributions', 'contractType']);

        // Informações básicas do projeto
        $projectInfo = [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'project_value' => $project->project_value,
            'hourly_rate' => $project->hourly_rate,
            'sold_hours' => $project->sold_hours,
            'hour_contribution' => $project->hour_contribution,  // @deprecated - mantido para compatibilidade
            'exceeded_hour_contribution' => $project->exceeded_hour_contribution,
            'initial_hours_balance' => $project->initial_hours_balance,
            'initial_cost' => $project->initial_cost,
            'tipo_faturamento' => $project->tipo_faturamento,
            'has_child_projects' => $project->hasChildProjects(),
            // ✨ Novos campos calculados usando hour_contributions table
            'total_available_hours' => $project->getTotalAvailableHours(),
            'total_project_value' => $project->calculateTotalProjectValue(),
            'weighted_hourly_rate' => $project->getWeightedAverageHourlyRate(),
            'total_contributions_hours' => $project->hourContributions()->sum('contributed_hours') ?? 0,
        ];

        // Calcular horas do projeto principal
        $parentLoggedMinutes = $project->timesheets->sum('effort_minutes');
        $parentApprovedMinutes = $project->timesheets->where('status', 'approved')->sum('effort_minutes');
        $parentPendingMinutes = $project->timesheets->where('status', 'pending')->sum('effort_minutes');

        // Calcular horas dos projetos filhos
        $childLoggedMinutes = 0;
        $childApprovedMinutes = 0;
        $childPendingMinutes = 0;
        $childProjectsBreakdown = [];

        foreach ($project->childProjects as $childProject) {
            $childTotalMinutes = $childProject->timesheets->sum('effort_minutes');
            $childApproved = $childProject->timesheets->where('status', 'approved')->sum('effort_minutes');
            $childPending = $childProject->timesheets->where('status', 'pending')->sum('effort_minutes');

            $childLoggedMinutes += $childTotalMinutes;
            $childApprovedMinutes += $childApproved;
            $childPendingMinutes += $childPending;

            // Calcular saldo consumido baseado no tipo de contrato
            $childProject->loadMissing('contractType');
            $isClosedContract = $childProject->contractType &&
                                strtolower(trim($childProject->contractType->name)) === 'fechado';

            if ($isClosedContract) {
                // Para contratos fechados: saldo consumido = total de horas disponíveis (inclui aportes novos + fallback legado)
                $consumedBalance = $childProject->getTotalAvailableHours();
            } else {
                // Para outros tipos: saldo consumido = total de horas apontadas
                $consumedBalance = round($childTotalMinutes / 60, 2);
            }

            $childProjectsBreakdown[] = [
                'id' => $childProject->id,
                'name' => $childProject->name,
                'code' => $childProject->code,
                'total_hours' => round($childTotalMinutes / 60, 2),
                'approved_hours' => round($childApproved / 60, 2),
                'pending_hours' => round($childPending / 60, 2),
                'consumed_balance' => round($consumedBalance, 2),
            ];
        }

        // Totais combinados (projeto pai + filhos)
        $totalLoggedMinutes = $parentLoggedMinutes + $childLoggedMinutes;
        $approvedMinutes = $parentApprovedMinutes + $childApprovedMinutes;
        $pendingMinutes = $parentPendingMinutes + $childPendingMinutes;

        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);
        $approvedHours = round($approvedMinutes / 60, 2);
        $pendingHours = round($pendingMinutes / 60, 2);

        $soldHours = $project->sold_hours ?? 0;
        // Usar método auxiliar para obter total disponível (inclui aportes novos + fallback legado)
        $totalAvailableHours = $project->getTotalAvailableHours();

        $remainingHours = max(0, $soldHours - $totalLoggedHours);
        // Calcular percentual considerando o total disponível (horas vendidas + aportes)
        $hoursPercentage = $totalAvailableHours > 0 ? round(($totalLoggedHours / $totalAvailableHours) * 100, 2) : 0;

        // Calcular saldo real disponível usando getGeneralHoursBalance (considera lógica de contratos fechados)
        $generalBalance = $project->getGeneralHoursBalance();

        // ── Horas APONTÁVEIS (teto/saldo/% coerentes com o bloqueio de apontamento) ──
        // Para Fechado/BH Fixo o teto de apontamento NÃO são as vendidas e sim "Horas
        // Apontáveis" (coordination_hours) + aporte. Expõe os valores apontáveis para o
        // tooltip refletir exatamente o que a validação bloqueia. Demais tipos = disponível.
        $ctNameCs = strtolower(trim((string) ($project->contractType->name ?? '')));
        $ctCodeCs = (string) ($project->contractType->code ?? '');
        if ($ctNameCs === 'fechado' || $ctCodeCs === 'fixed_hours' || $ctNameCs === 'banco de horas fixo') {
            $aporteHrs         = max(0.0, round($totalAvailableHours - (float) $soldHours, 2));
            $apontaveisHours   = round((float) ($project->coordination_hours ?? 0) + $aporteHrs, 2);
            // Saldo = Horas Apontáveis − apontadas (gestão não consome o banco apontável).
            $apontaveisBalance = round(max(0.0, $project->getApontaveisBalance()), 2);
            $apontaveisPct     = $apontaveisHours > 0 ? round((($apontaveisHours - $apontaveisBalance) / $apontaveisHours) * 100, 2) : 0;
        } else {
            $apontaveisHours   = round($totalAvailableHours, 2);
            $apontaveisBalance = round(max(0.0, $generalBalance), 2);
            $apontaveisPct     = $hoursPercentage;
        }

        $hoursSummary = [
            'total_logged_hours' => $totalLoggedHours,
            'approved_hours' => $approvedHours,
            'pending_hours' => $pendingHours,
            'remaining_hours' => $remainingHours, // Mantido para compatibilidade (cálculo simples)
            'general_balance' => round($generalBalance, 2), // Saldo real disponível calculado
            'total_available_hours' => round($totalAvailableHours, 2), // Horas vendidas + aporte de horas
            'hours_percentage' => $hoursPercentage,
            // Apontáveis = teto real de apontamento (Fechado/BH Fixo usa Horas Apontáveis).
            'apontaveis_hours' => $apontaveisHours,
            'apontaveis_balance' => $apontaveisBalance,
            'apontaveis_percentage' => $apontaveisPct,
            'parent_project_hours' => round($parentLoggedMinutes / 60, 2),
            'child_projects_hours' => round($childLoggedMinutes / 60, 2),
        ];

        // Calcular custos usando o valor/hora próprio de cada consultor
        $totalCost = 0;
        $approvedCost = 0;
        $pendingCost = 0;

        // Quebra por consultor (incluindo horas de projetos filhos)
        $consultantBreakdown = [];

        // Coletar todos os timesheets (do projeto pai e dos filhos)
        $allTimesheets = collect($project->timesheets);
        foreach ($project->childProjects as $childProject) {
            // Adicionar informação do projeto filho aos timesheets
            foreach ($childProject->timesheets as $timesheet) {
                $timesheet->child_project_name = $childProject->name;
                $timesheet->child_project_code = $childProject->code;
            }
            $allTimesheets = $allTimesheets->merge($childProject->timesheets);
        }

        $timesheetsByUser = $allTimesheets->groupBy('user_id');

        // Valor/hora efetivo do consultor VIGENTE por competência (respeita a vigência do
        // cadastro: custo de meses passados não muda quando o valor/hora é alterado hoje).
        $effRateCache = [];
        $effRateFor = function ($user, string $ym) use (&$effRateCache) {
            $k = $user->id . '|' . $ym;
            if (isset($effRateCache[$k])) return $effRateCache[$k];
            $hist = \App\Models\UserHourlyRateLog::effectiveValuesAt($user->id, $user, $ym . '-01');
            $rate = (float) ($hist['hourly_rate'] ?? $user->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $user->rate_type ?? 'hourly';
            $eff  = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
            return $effRateCache[$k] = ['eff' => $eff, 'type' => $type];
        };

        foreach ($timesheetsByUser as $userId => $userTimesheets) {
            $user = $userTimesheets->first()->user;
            $userTotalMinutes = $userTimesheets->sum('effort_minutes');
            $userApprovedMinutes = $userTimesheets->where('status', 'approved')->sum('effort_minutes');
            $userPendingMinutes = $userTimesheets->where('status', 'pending')->sum('effort_minutes');

            $userTotalHours = round($userTotalMinutes / 60, 2);
            $userApprovedHours = round($userApprovedMinutes / 60, 2);
            $userPendingHours = round($userPendingMinutes / 60, 2);

            // Custo por competência: cada mês de apontamento usa o valor/hora efetivo
            // VIGENTE naquele mês (mensalista = salário ÷ 160). Somar a vida inteira do
            // projeto com o valor de hoje mudaria o custo/margem de meses passados.
            $userCost = 0.0; $userApprovedCost = 0.0; $userPendingCost = 0.0;
            $lastEffRate = 0.0; $rateType = $user->rate_type ?? 'hourly';
            foreach ($userTimesheets->groupBy(fn ($ts) => \Carbon\Carbon::parse($ts->date)->format('Y-m')) as $ym => $monthTs) {
                $meta        = $effRateFor($user, $ym);
                $lastEffRate = $meta['eff'];
                $rateType    = $meta['type'];
                $mAll = $monthTs->sum('effort_minutes');
                $mApp = $monthTs->where('status', 'approved')->sum('effort_minutes');
                $mPen = $monthTs->where('status', 'pending')->sum('effort_minutes');
                $userCost         += ($mAll / 60) * $meta['eff'];
                $userApprovedCost += ($mApp / 60) * $meta['eff'];
                $userPendingCost  += ($mPen / 60) * $meta['eff'];
            }
            $userCost         = round($userCost, 2);
            $userApprovedCost = round($userApprovedCost, 2);
            $userPendingCost  = round($userPendingCost, 2);
            // Valor/hora representativo p/ exibição = média efetiva do que foi pago no projeto
            // (fallback à taxa da última competência quando não há horas).
            $effectiveHourlyRate = $userTotalHours > 0 ? round($userCost / $userTotalHours, 4) : $lastEffRate;

            $totalCost += $userCost;
            $approvedCost += $userApprovedCost;
            $pendingCost += $userPendingCost;

            // Detalhar horas por projeto (pai e filhos)
            $projectsBreakdown = [];

            // Horas do projeto pai
            $parentUserTimesheets = $userTimesheets->filter(function ($ts) use ($project) {
                return $ts->project_id === $project->id;
            });

            if ($parentUserTimesheets->count() > 0) {
                $parentUserHours = round($parentUserTimesheets->sum('effort_minutes') / 60, 2);
                $projectsBreakdown[] = [
                    'project_name' => $project->name . ' (Principal)',
                    'project_code' => $project->code,
                    'hours' => $parentUserHours,
                    'hourly_rate' => $effectiveHourlyRate,
                ];
            }

            // Horas dos projetos filhos
            foreach ($project->childProjects as $childProject) {
                $childUserTimesheets = $userTimesheets->filter(function ($ts) use ($childProject) {
                    return $ts->project_id === $childProject->id;
                });

                if ($childUserTimesheets->count() > 0) {
                    $childUserHours = round($childUserTimesheets->sum('effort_minutes') / 60, 2);
                    $projectsBreakdown[] = [
                        'project_name' => $childProject->name . ' (Subprojeto)',
                        'project_code' => $childProject->code,
                        'hours' => $childUserHours,
                        'hourly_rate' => $effectiveHourlyRate,
                    ];
                }
            }

            $consultantBreakdown[] = [
                'consultant_name' => $user->name,
                'total_hours' => $userTotalHours,
                'approved_hours' => $userApprovedHours,
                'pending_hours' => $userPendingHours,
                'cost' => $userCost,
                'consultant_hourly_rate' => $effectiveHourlyRate,
                'consultant_rate_type' => $rateType,
                'projects_breakdown' => $projectsBreakdown,
            ];
        }

        $aportesTotal     = $project->hourContributions->sum(fn($c) => (float)$c->contributed_hours * (float)$c->hourly_rate);
        $isOnDemand       = $project->tipo_faturamento === 'on_demand'
            || ($project->contractType && stripos($project->contractType->name, 'on demand') !== false)
            || ($project->contractType && stripos($project->contractType->name, 'on_demand') !== false);
        $projectRevenue   = $isOnDemand
            ? round($totalLoggedHours * (float)($project->hourly_rate ?? 0), 2)
            : (float)($project->project_value ?? 0);
        $receitaTotal     = $projectRevenue + $aportesTotal;
        $initialCost      = (float)($project->initial_cost ?? 0);
        $custoTotal       = $initialCost + $totalCost;
        $margin           = $receitaTotal - $custoTotal;
        $marginPercentage = $receitaTotal > 0 ? round(($margin / $receitaTotal) * 100, 2) : 0;
        $coordPct         = (float)($project->coordinator_percentage ?? 0);
        $valorCoordenador = $coordPct > 0 ? round($margin * ($coordPct / 100), 2) : 0;

        $costCalculation = [
            'total_cost' => round($totalCost, 2),
            'approved_cost' => round($approvedCost, 2),
            'pending_cost' => round($pendingCost, 2),
            'is_on_demand' => $isOnDemand,
            'project_revenue' => round($projectRevenue, 2),
            'aportes_total' => round($aportesTotal, 2),
            'receita_total' => round($receitaTotal, 2),
            'custo_operacional' => round($totalCost, 2),
            'custo_total' => round($custoTotal, 2),
            'margin' => round($margin, 2),
            'margin_percentage' => $marginPercentage,
            'coordinator_percentage' => $coordPct,
            'valor_coordenador' => $valorCoordenador,
        ];

        return response()->json([
            'project_info' => $projectInfo,
            'hours_summary' => $hoursSummary,
            'cost_calculation' => $costCalculation,
            'consultant_breakdown' => $consultantBreakdown,
            'child_projects_summary' => $childProjectsBreakdown,
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/enum-values",
     *     tags={"Projects"},
     *     summary="Obter valores dos enums",
     *     description="Retorna os valores possíveis para enums de projetos",
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Valores dos enums",
     *         @OA\JsonContent(
     *             @OA\Property(property="contract_types", type="object"),
     *             @OA\Property(property="statuses", type="object"),
     *             @OA\Property(property="expense_responsible_parties", type="object")
     *         )
     *     )
     * )
     */
    public function enumValues(): JsonResponse
    {
        return response()->json([
            'contract_types' => ContractType::getActiveOptions(),
            'statuses' => Project::getStatuses(),
            'expense_responsible_parties' => Project::getExpenseResponsiblePartyOptions(),
        ]);
    }

    /**
     * Cronograma operacional do projeto (ADR 0009).
     *
     * Retorna estrutura para o cronograma em 1 query (eager load): stages
     * + deliveries com campos planejados (start, end, hours) e reais
     * (actual_start_at, completed_at, depends_on_delivery_id).
     *
     * Cronograma e Board são duas views da mesma entidade — sem dual store.
     */
    public function schedule(Project $project, Request $request): JsonResponse
    {
        $project->loadMissing(['serviceType', 'coordinators:id,name,email']);

        if (!$project->isOperational()) {
            return response()->json([
                'is_operational' => false,
                'stages'         => [],
                'project_window' => null,
            ]);
        }

        // Prazo de Entrega deriva do cronograma (fim da última atividade) — mantém
        // sincronizado automaticamente ao visualizar. Usa queries próprias do projeto
        // inteiro (não afetado pelo filtro de consultor abaixo).
        $project->recalcExpectedEndFromSchedule();

        $stages = $project->stages()
            ->with([
                'responsible:id,name,email',
                'deliveries:id,stage_id,title,responsible_user_id,hours_planned,priority,status,due_date,order_index,planned_start_at,actual_start_at,completed_at,depends_on_delivery_id,dependency_type,client_involved,client_user_id,client_email,extra_clients,approval_status,approval_requested_at,approval_decided_at,approval_decided_by,approval_note',
                'deliveries.responsible:id,name,email',
                'deliveries.client:id,name,email',
                'deliveries.approvalDecider:id,name',
            ])
            ->orderBy('order_index')
            ->get();

        // Consultor: vê SOMENTE as atividades onde está ALOCADO (por atividade =
        // stage_allocations.delivery_id, mesmo critério do "+ Apontar") OU é o RESPONSÁVEL
        // por ela (responsible_user_id) — não o board completo. Descarta etapas sem atividade
        // dele; contadores/resumo refletem só o escopo dele. Admin/coordenador (ou com
        // hours.view_all) seguem vendo tudo.
        // $request->user() (não auth()->user()) — reflete corretamente o usuário do token,
        // inclusive sob "Ver como" (impersonation emite token real do alvo).
        $viewer = $request->user();
        // Escopo ESTRITO por perfil: consultor só vê o cronograma dele, independentemente
        // de hours.view_all — a visão de gestão (totais/horas do projeto) é exclusiva de
        // admin/coordenador. Admin/coordenador seguem vendo tudo (isConsultor() = false).
        $isConsultorScoped = $viewer && method_exists($viewer, 'isConsultor') && $viewer->isConsultor();
        if ($isConsultorScoped) {
            $vid = $viewer->id;
            $deliveryIds = $stages->flatMap(fn ($st) => $st->deliveries->pluck('id'))->all();
            $allocatedSet = \App\Models\StageAllocation::where('user_id', $vid)
                ->whereNotNull('delivery_id')
                ->whereIn('delivery_id', $deliveryIds)
                ->pluck('delivery_id')
                ->flip();
            $stages->each(fn ($st) => $st->setRelation(
                'deliveries',
                $st->deliveries->filter(fn ($d) => isset($allocatedSet[$d->id]) || (int) $d->responsible_user_id === $vid)->values()
            ));
            $stages = $stages->filter(fn ($st) => $st->deliveries->isNotEmpty())->values();
        }

        // Follow Ups vinculados (denormalizados em project_id/stage_id/delivery_id):
        // contadores por atividade + agregados por etapa pra exibir no cronograma.
        $fuByDelivery = [];
        $fuByStage = [];
        $todayFu = \Carbon\Carbon::now()->startOfDay();
        foreach (\App\Models\FollowUp::where('project_id', $project->id)->where('status', '!=', \App\Models\FollowUp::STATUS_CANCELLED)
                     ->get(['id', 'delivery_id', 'stage_id', 'status', 'waiting_subtype', 'due_date']) as $r) {
            if ($r->delivery_id) $fuByDelivery[$r->delivery_id] = ($fuByDelivery[$r->delivery_id] ?? 0) + 1;
            if ($r->stage_id) {
                $fuByStage[$r->stage_id] ??= ['count' => 0, 'overdue' => 0, 'waiting_client' => 0, 'done' => 0];
                $fuByStage[$r->stage_id]['count']++;
                if ($r->status === 'completed') $fuByStage[$r->stage_id]['done']++;
                if (in_array($r->status, ['pending', 'in_progress'], true) && $r->due_date && \Carbon\Carbon::parse($r->due_date)->lt($todayFu)) {
                    $fuByStage[$r->stage_id]['overdue']++;
                }
                if ($r->status === 'waiting_third' && $r->waiting_subtype === 'client') $fuByStage[$r->stage_id]['waiting_client']++;
            }
        }

        // Estado do predecessor (FS) por atividade — usado pelo cronograma e board.
        // O frontend renderiza 🔒 Bloqueada por quando state === 'pending'.
        $statusById = [];
        $titleById  = [];
        $orderById  = [];
        $hoursById  = [];
        foreach ($stages as $st) {
            foreach ($st->deliveries as $d) {
                $statusById[$d->id] = $d->status;
                $titleById[$d->id]  = $d->title;
                $orderById[$d->id]  = $d->order_index;
                $hoursById[$d->id]  = (float) ($d->hours_planned ?? 0);
            }
        }
        // Adjacência FS: predecessor → [dependentes diretos]
        $adj = [];
        foreach ($stages as $st) {
            foreach ($st->deliveries as $d) {
                if ($d->depends_on_delivery_id && $d->dependency_type === 'FS') {
                    $adj[$d->depends_on_delivery_id][] = $d->id;
                }
            }
        }

        // Calcula títulos impactados (downstream transitivo FS, BFS depth ≤ 10)
        $impactedFor = function (int $rootId) use ($adj, $titleById): array {
            $out  = [];
            $seen = [];
            $queue = [$rootId];
            $depth = 0;
            while (!empty($queue) && $depth < 10) {
                $depth++;
                $next = [];
                foreach ($queue as $id) {
                    foreach ($adj[$id] ?? [] as $childId) {
                        if (isset($seen[$childId])) continue;
                        $seen[$childId] = true;
                        if (isset($titleById[$childId])) {
                            $out[] = $titleById[$childId];
                        }
                        $next[] = $childId;
                    }
                }
                $queue = $next;
            }
            return $out;
        };

        // Critical path leve = longest path no DAG FS por horas planejadas.
        // Topo-sort + DP, depois reconstrói o(s) caminho(s).
        $criticalSet = [];
        if (!empty($titleById)) {
            $inDeg = [];
            foreach ($titleById as $id => $_) {
                $inDeg[$id] = 0;
            }
            foreach ($adj as $_parent => $children) {
                foreach ($children as $c) {
                    if (isset($inDeg[$c])) $inDeg[$c]++;
                }
            }
            $queue = [];
            foreach ($inDeg as $id => $deg) {
                if ($deg === 0) $queue[] = $id;
            }
            // Tie-break por order_index
            usort($queue, fn ($a, $b) => ($orderById[$a] ?? 0) <=> ($orderById[$b] ?? 0));
            $topo = [];
            $deg = $inDeg;
            while (!empty($queue)) {
                $n = array_shift($queue);
                $topo[] = $n;
                $newReady = [];
                foreach ($adj[$n] ?? [] as $c) {
                    $deg[$c]--;
                    if ($deg[$c] === 0) $newReady[] = $c;
                }
                usort($newReady, fn ($a, $b) => ($orderById[$a] ?? 0) <=> ($orderById[$b] ?? 0));
                foreach ($newReady as $c) $queue[] = $c;
            }
            $dist = [];
            $pred = [];
            foreach ($topo as $n) {
                $dist[$n] = $hoursById[$n] ?? 0;
                $pred[$n] = null;
            }
            foreach ($topo as $n) {
                foreach ($adj[$n] ?? [] as $c) {
                    $candidate = $dist[$n] + ($hoursById[$c] ?? 0);
                    if ($candidate > $dist[$c]) {
                        $dist[$c] = $candidate;
                        $pred[$c] = $n;
                    }
                }
            }
            if (!empty($dist)) {
                arsort($dist);
                $end = array_key_first($dist);
                while ($end !== null) {
                    $criticalSet[$end] = true;
                    $end = $pred[$end] ?? null;
                }
            }
        }

        // Calendário de negócio pra duration_business_days (singleton via container)
        // Opts contextuais do projeto (Fase 7): permite sábado/feriado por projeto.
        $calendar = app(\App\Services\BusinessCalendarService::class);
        $calOpts = [
            'allow_weekend' => (bool) $project->allow_weekend_work,
            'allow_holiday' => (bool) $project->allow_holiday_work,
        ];

        // Fix Fase 9: actual_hours por etapa no payload /schedule (evita 2 fetches no front).
        // Soma effort_minutes/60 de timesheets approved+released agrupados por stage_id.
        $stageIds = $stages->pluck('id')->all();
        $actualByStage = [];
        if (!empty($stageIds)) {
            $actualByStage = \DB::table('timesheets')
                ->whereNull('deleted_at')
                ->whereIn('stage_id', $stageIds)
                ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_RELEASED])
                ->groupBy('stage_id')
                ->selectRaw('stage_id, COALESCE(SUM(effort_minutes),0)/60.0 as actual_hours')
                ->pluck('actual_hours', 'stage_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        }
        foreach ($stages as $st) {
            $st->setAttribute('actual_hours', (float) ($actualByStage[$st->id] ?? 0));
        }

        // Enriquecer cada delivery com os 4 campos derivados
        foreach ($stages as $st) {
            foreach ($st->deliveries as $d) {
                if ($d->depends_on_delivery_id && $d->dependency_type === 'FS') {
                    $predStatus = $statusById[$d->depends_on_delivery_id] ?? null;
                    $state = $predStatus === \App\Models\StageDelivery::STATUS_DONE ? 'done' : 'pending';
                    $d->setAttribute('predecessor', [
                        'id'    => (int) $d->depends_on_delivery_id,
                        'title' => $titleById[$d->depends_on_delivery_id] ?? '',
                    ]);
                } else {
                    $state = 'none';
                    $d->setAttribute('predecessor', null);
                }
                $d->setAttribute('predecessor_state', $state);
                $d->setAttribute('impacted_titles', $impactedFor($d->id));
                $d->setAttribute('is_critical', isset($criticalSet[$d->id]));

                if ($d->planned_start_at && $d->due_date) {
                    $d->setAttribute(
                        'duration_business_days',
                        $calendar->businessDaysBetween($d->planned_start_at, $d->due_date, $calOpts)
                    );
                } else {
                    $d->setAttribute('duration_business_days', null);
                }

                // Fase 10: is_late = atrasada (due passou e não concluída)
                $isLate = false;
                if ($d->due_date && $d->status !== \App\Models\StageDelivery::STATUS_DONE) {
                    $isLate = $d->due_date->lt(\Carbon\Carbon::now()->startOfDay());
                }
                $d->setAttribute('is_late', $isLate);
            }
        }

        // Fase 10: derived_status + risk_level + risk_reasons por etapa
        foreach ($stages as $st) {
            // derived_status derivado das deliveries (mesma regra de ProjectStageController)
            $total = $st->deliveries->count();
            $done  = $st->deliveries->where('status', \App\Models\StageDelivery::STATUS_DONE)->count();
            $review = $st->deliveries->where('status', \App\Models\StageDelivery::STATUS_REVIEW)->count();
            $waiting = $st->deliveries->where('status', \App\Models\StageDelivery::STATUS_WAITING_CLIENT)->count();

            if ($total === 0) {
                $derivedStatus = 'planejamento';
            } elseif ($waiting > 0) {
                $derivedStatus = 'bloqueada';
            } elseif ($done === $total) {
                $derivedStatus = 'concluida';
            } elseif (($review + $done) === $total) {
                $derivedStatus = 'homologacao';
            } elseif ($done > 0 || $st->deliveries->where('status', '!=', 'backlog')->count() > 0) {
                $derivedStatus = 'execucao';
            } else {
                $derivedStatus = 'planejamento';
            }
            $st->setAttribute('derived_status', $derivedStatus);
            // Fase 10: alinhamento com /stages — earned value se houver horas, senão contagem.
            // Atividades de responsabilidade do CLIENTE são medidas em dias, não horas:
            // não entram na soma de horas planejadas da etapa nem do progresso.
            $billableDeliveries = $st->deliveries->reject(fn ($d) => $d->client_involved);
            $totalHours = (float) $billableDeliveries->sum('hours_planned');
            $doneHours  = (float) $billableDeliveries->where('status', \App\Models\StageDelivery::STATUS_DONE)->sum('hours_planned');
            $progressPct = $totalHours > 0
                ? round(($doneHours / $totalHours) * 100, 2)
                : ($total > 0 ? round(($done / $total) * 100, 2) : 0.0);
            $st->setAttribute('progress_pct', $progressPct);

            // Horas da etapa: soma das atividades + valor "efetivo" (hours_planned da
            // etapa se preenchido, senão a soma das atividades). A UI mostra o efetivo
            // pra que as Horas Previstas informadas na criação da etapa apareçam.
            $stagePlanned = (float) ($st->hours_planned ?? 0);
            $st->setAttribute('deliveries_hours_planned_sum', $totalHours);
            $st->setAttribute('effective_hours_planned', $stagePlanned > 0 ? $stagePlanned : $totalHours);

            // Follow Ups: agregado da etapa + contador por atividade.
            $st->setAttribute('followups', $fuByStage[$st->id] ?? ['count' => 0, 'overdue' => 0, 'waiting_client' => 0, 'done' => 0]);
            foreach ($st->deliveries as $d) {
                $d->setAttribute('followups_count', $fuByDelivery[$d->id] ?? 0);
            }

            $lastActAt = $st->deliveries->pluck('updated_at')->filter()->max();
            $daysSince = $lastActAt
                ? (int) \Carbon\Carbon::now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($lastActAt)->startOfDay())
                : null;

            $risk = \App\Services\ProjectStageRiskService::compute([
                'derived_status'      => $derivedStatus,
                'expected_end_date'   => $st->expected_end_date?->toDateString(),
                'days_since_activity' => $daysSince,
                'planned_hours'       => (float) ($st->hours_planned ?? 0),
                'actual_hours'        => (float) ($st->actual_hours ?? 0),
                'team_overrun_count'  => 0,
            ]);
            $st->setAttribute('risk_level', $risk['level']);
            $st->setAttribute('risk_reasons', $risk['reasons']);
        }

        // Fase 10: executive summary + alerts + team_load
        $todayCarbon = \Carbon\Carbon::now()->startOfDay();
        $totalDeliveries = 0; $doneDeliveries = 0; $lateCount = 0;
        $blockedCount = 0; $reviewCount = 0; $waitingClientCount = 0; $inProgressCount = 0;
        $hoursPlannedTotal = 0; $hoursActualTotal = 0; $hoursPlannedDone = 0;
        $alerts = [];
        $userIdsInvolved = [];
        foreach ($stages as $st) {
            $hoursActualTotal += (float) ($st->actual_hours ?? 0);
            foreach ($st->deliveries as $d) {
                $totalDeliveries++;
                if ($d->status === \App\Models\StageDelivery::STATUS_DONE)         $doneDeliveries++;
                if ($d->status === \App\Models\StageDelivery::STATUS_IN_PROGRESS)  $inProgressCount++;
                if ($d->status === \App\Models\StageDelivery::STATUS_REVIEW)       $reviewCount++;
                if ($d->status === \App\Models\StageDelivery::STATUS_WAITING_CLIENT) $waitingClientCount++;
                if ($d->predecessor_state === 'pending')                            $blockedCount++;
                if ($d->is_late)                                                    $lateCount++;
                // Atividade do cliente é medida em dias — não soma horas planejadas.
                if (!$d->client_involved) {
                    $hoursPlannedTotal += (float) ($d->hours_planned ?? 0);
                    // Progresso por HORAS: horas planejadas das atividades já concluídas.
                    if ($d->status === \App\Models\StageDelivery::STATUS_DONE) {
                        $hoursPlannedDone += (float) ($d->hours_planned ?? 0);
                    }
                }
                if ($d->responsible_user_id) $userIdsInvolved[$d->responsible_user_id] = true;

                // Alertas leves por delivery
                if ($d->status === \App\Models\StageDelivery::STATUS_IN_PROGRESS
                    && $d->updated_at
                    && $todayCarbon->diffInDays(\Carbon\Carbon::parse($d->updated_at)->startOfDay()) > 5
                    && !$d->is_late) {
                    $alerts[] = [
                        'severity' => 'warning',
                        'type'     => 'stale_activity',
                        'message'  => 'Atividade parada há mais de 5 dias',
                        'delivery_id' => $d->id,
                        'title'    => $d->title,
                    ];
                }
                if ($d->is_late) {
                    $alerts[] = [
                        'severity' => 'danger',
                        'type'     => 'overdue',
                        'message'  => 'Prazo vencido — atividade ainda não concluída',
                        'delivery_id' => $d->id,
                        'title'    => $d->title,
                    ];
                }
                if ($d->status === \App\Models\StageDelivery::STATUS_WAITING_CLIENT
                    && $d->updated_at
                    && $todayCarbon->diffInDays(\Carbon\Carbon::parse($d->updated_at)->startOfDay()) > 7) {
                    $alerts[] = [
                        'severity' => 'warning',
                        'type'     => 'waiting_client_stale',
                        'message'  => 'Aguardando cliente há mais de 7 dias — sem retorno',
                        'delivery_id' => $d->id,
                        'title'    => $d->title,
                    ];
                }
                if (!$d->responsible_user_id) {
                    $alerts[] = [
                        'severity' => 'warning',
                        'type'     => 'no_responsible',
                        'message'  => 'Atividade sem responsável',
                        'delivery_id' => $d->id,
                        'title'    => $d->title,
                    ];
                }
            }
        }
        // Alertas no nível etapa
        foreach ($stages as $st) {
            if ($st->risk_level === \App\Services\ProjectStageRiskService::LEVEL_HIGH) {
                $alerts[] = [
                    'severity' => 'danger',
                    'type'     => 'stage_high_risk',
                    'message'  => 'Etapa em risco alto: ' . implode(' · ', $st->risk_reasons ?? []),
                    'stage_id' => $st->id,
                    'title'    => $st->name,
                ];
            }
        }

        // Risco geral (precedência alto > médio > baixo)
        $highStages = $stages->filter(fn ($s) => $s->risk_level === 'high')->count();
        $medStages  = $stages->filter(fn ($s) => $s->risk_level === 'medium')->count();
        $overallRisk = $highStages > 0 ? 'high' : ($medStages > 0 ? 'medium' : 'low');

        // Atraso estimado: max(days_late) entre etapas vencidas
        $maxDaysLate = 0;
        foreach ($stages as $st) {
            if ($st->expected_end_date && $st->derived_status !== 'concluida') {
                $diff = (int) $todayCarbon->diffInDays($st->expected_end_date->startOfDay(), false);
                if ($diff < 0 && abs($diff) > $maxDaysLate) $maxDaysLate = abs($diff);
            }
        }

        // Team load: envolvimento de cada pessoa NESTE projeto — reflete os apontamentos.
        // usage_pct = horas consumidas pela pessoa neste projeto (approved/released) sobre
        // o pool do cronograma. Assim quem apontou aparece (mesmo sem stage_allocation), e
        // não confunde com a carga GLOBAL da pessoa (bug anterior: usava planned/capacity).
        $teamLoad = [];
        if (!empty($userIdsInvolved)) {
            $uids  = array_keys($userIdsInvolved);
            $pool  = (float) $project->cronogramaPoolHours();

            // Consumido real por usuário neste projeto. Inclui 'pending' (apontou mas
            // ainda não aprovado) — mesma whitelist de consumo do card CONSUMIDAS, senão
            // o apontamento recém-feito não apareceria na equipe.
            $actualByUser = \DB::table('timesheets')
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_PENDING, \App\Models\Timesheet::STATUS_RELEASED])
                ->whereIn('user_id', $uids)
                ->groupBy('user_id')
                ->selectRaw('user_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS h')
                ->pluck('h', 'user_id');

            // Planejado (alocação) por usuário neste projeto — pro tooltip/saldo.
            $plannedByUser = \DB::table('stage_allocations as a')
                ->join('project_stages as ps', 'ps.id', '=', 'a.stage_id')
                ->where('ps.project_id', $project->id)
                ->whereNull('ps.deleted_at')
                ->whereIn('a.user_id', $uids)
                ->groupBy('a.user_id')
                ->selectRaw('a.user_id AS user_id, COALESCE(SUM(a.planned_hours), 0) AS h')
                ->pluck('h', 'user_id');

            $usersData = \App\Models\User::whereIn('id', $uids)->get(['id', 'name', 'email']);
            foreach ($usersData as $u) {
                $planned = (float) ($plannedByUser[$u->id] ?? 0);
                $actual  = (float) ($actualByUser[$u->id] ?? 0);
                $teamLoad[] = [
                    'user' => [
                        'id'    => $u->id,
                        'name'  => $u->name,
                        'profile_photo_url' => $u->profile_photo_url ?? null,
                    ],
                    'planned_hours'   => round($planned, 2),
                    'actual_hours'    => round($actual, 2),
                    'remaining_hours' => round($planned - $actual, 2),
                    'usage_pct'       => $pool > 0 ? round(($actual / $pool) * 100, 1) : 0.0,
                    'overloaded'      => $planned > 0 && $actual > $planned,
                ];
            }
            // Quem mais consumiu no projeto primeiro.
            usort($teamLoad, fn ($a, $b) => $b['actual_hours'] <=> $a['actual_hours']);
        }

        // Calcula a janela do cronograma (min start, max end) — usado pelo Gantt
        // e pelo card "Prazo Final" no executive header.
        $minDate = null;
        $maxDate = null;
        foreach ($stages as $st) {
            // Início da etapa → minDate; fim da etapa → maxDate.
            if ($st->stage_start_at) {
                $minDate = $minDate === null || $st->stage_start_at->lt($minDate) ? $st->stage_start_at : $minDate;
            }
            if ($st->expected_end_date) {
                $maxDate = $maxDate === null || $st->expected_end_date->gt($maxDate) ? $st->expected_end_date : $maxDate;
            }
            foreach ($st->deliveries as $del) {
                // Início da atividade → minDate.
                if ($del->planned_start_at) {
                    $minDate = $minDate === null || $del->planned_start_at->lt($minDate) ? $del->planned_start_at : $minDate;
                }
                // Prazo Final usa o FIM da atividade (due_date, ou início + horas no
                // calendário útil) — NUNCA o início cru como se fosse fim.
                $end = $del->plannedEndDate($calendar, $calOpts);
                if ($end) {
                    $maxDate = $maxDate === null || $end->gt($maxDate) ? $end : $maxDate;
                }
            }
        }

        // Diferença em dias entre prazo planejado (project.expected_end_date)
        // e prazo estimado real (maxDate das deliveries) — positivo = atraso projetado.
        $plannedEnd = $project->expected_end_date;
        $endDelta = ($plannedEnd && $maxDate)
            ? (int) $plannedEnd->startOfDay()->diffInDays($maxDate->startOfDay(), false)
            : null;

        $executiveSummary = [
            'progress_pct'         => $totalDeliveries > 0 ? round(($doneDeliveries / $totalDeliveries) * 100, 1) : 0.0,
            'total_deliveries'     => $totalDeliveries,
            'done_deliveries'      => $doneDeliveries,
            // Progresso por HORAS: horas planejadas concluídas / total planejado (só atividades faturáveis).
            'progress_hours_pct'   => $hoursPlannedTotal > 0 ? round(($hoursPlannedDone / $hoursPlannedTotal) * 100, 1) : 0.0,
            'hours_done'           => round($hoursPlannedDone, 2),
            'in_progress_count'    => $inProgressCount,
            'review_count'         => $reviewCount,
            'blocked_count'        => $blockedCount,
            'waiting_client_count' => $waitingClientCount,
            'overdue_count'        => $lateCount,
            'hours_planned'        => round($hoursPlannedTotal, 2),
            'hours_actual'         => round($hoursActualTotal, 2),
            // Horas CONSUMIDAS do projeto (apontadas + inicial) — MESMA fonte do card "Consumidas".
            // Usado no card "Progresso (horas)", que reflete consumo (não exige conclusão de atividade
            // nem vínculo do apontamento a uma etapa do cronograma).
            'hours_consumed'       => round($project->getTotalLoggedHours() + (float) ($project->initial_hours_consumed ?? 0), 2),
            'hours_balance'        => round($hoursPlannedTotal - $hoursActualTotal, 2),
            // Horas disponibilizadas à gestão (pool do cronograma): coordination_hours
            // se preenchido, senão 100% das vendidas. Saldo a alocar = disponibilizadas − planejadas.
            'hours_available'      => round($project->cronogramaPoolHours(), 2),
            'overall_risk'         => $overallRisk,
            'high_risk_stages'     => $highStages,
            'medium_risk_stages'   => $medStages,
            'estimated_delay_days' => $maxDaysLate,
            // Card "Prazo Final": data prevista do projeto baseada nas deliveries
            'estimated_end_date'   => $maxDate?->toDateString(),
            'planned_end_date'     => $plannedEnd?->toDateString(),
            'end_date_delta_days'  => $endDelta,
        ];

        // Feriados ativos dentro da janela do cronograma — frontend usa pra replicar
        // BusinessCalendarService::addBusinessHours client-side (sugestão de fim).
        // Todos os feriados ativos do cadastro (com nome) — o date picker do cronograma
        // marca/exibe e o BusinessCalendar usa as datas pro cálculo de dias úteis.
        // Dataset pequeno (~13/ano), então não filtra por janela (picker navega livre).
        $holidays = \App\Models\Holiday::active()
            ->orderBy('date')
            ->get(['date', 'name'])
            ->map(fn ($h) => ['date' => $h->date->toDateString(), 'name' => $h->name])
            ->values();

        return response()->json([
            'is_operational' => true,
            'project_window' => [
                'start' => $minDate?->toDateString(),
                'end'   => $maxDate?->toDateString(),
            ],
            'holidays' => $holidays,
            'project' => [
                'id'                  => $project->id,
                'name'                => $project->name,
                // Consultor não recebe as horas do projeto (vendidas/liberadas à gestão).
                'sold_hours'          => $isConsultorScoped ? 0.0 : (float) ($project->sold_hours ?? 0),
                'coordination_hours'  => $isConsultorScoped ? 0.0 : (float) ($project->coordination_hours ?? 0),
                'start_date'          => $project->start_date?->toDateString(),
                'expected_end_date'   => $project->expected_end_date?->toDateString(),
                'allow_weekend_work'  => (bool) $project->allow_weekend_work,
                'allow_holiday_work'  => (bool) $project->allow_holiday_work,
                'coordinators'        => $project->coordinators->map(fn ($u) => [
                    'id'    => $u->id,
                    'name'  => $u->name,
                    'email' => $u->email,
                ])->values(),
            ],
            // Executive/alertas/team_load são visão de gestão — omitidos pro consultor.
            'executive'  => $isConsultorScoped ? null : $executiveSummary,
            'alerts'     => $isConsultorScoped ? [] : $alerts,
            'team_load'  => $isConsultorScoped ? [] : $teamLoad,
            'stages' => $stages,
        ]);
    }

    /**
     * Preview de recálculo do Cronograma (Fase 10.1).
     *
     * Aceita 2 tipos de trigger:
     *  - delivery_field: simula mudança em delivery (hours_planned, planned_start_at,
     *    due_date, depends_on_delivery_id) e devolve cascade FS.
     *  - project_calendar: simula mudança nas flags allow_weekend_work / allow_holiday_work
     *    e lista deliveries cuja duration_business_days muda.
     *
     * Retorna { summary, impact, affected[], conflicts[] }. Sem persistência —
     * frontend usa pra mostrar modal de confirmação antes do PATCH real.
     */
    public function recalcPreview(\Illuminate\Http\Request $request, Project $project): \Illuminate\Http\JsonResponse
    {
        $trigger = $request->input('trigger');
        $simulate = $request->input('simulate', []);

        if (!in_array($trigger, ['delivery_field', 'stage_field', 'project_calendar'], true)) {
            return response()->json(['message' => 'trigger inválido'], 422);
        }

        $calendar = app(\App\Services\BusinessCalendarService::class);
        $currentEnd = $this->projectScheduleMaxEnd($project);

        if ($trigger === 'delivery_field') {
            $deliveryId = (int) $request->input('delivery_id');
            $delivery = \App\Models\StageDelivery::find($deliveryId);
            if (!$delivery) {
                return response()->json(['message' => 'delivery não encontrado'], 404);
            }
            if ($delivery->stage->project_id !== $project->id) {
                return response()->json(['message' => 'delivery não pertence ao projeto'], 422);
            }

            // Snapshot ANTES do cascade — recalcDependents muta $delivery em memória
            // quando simulate é passado. Sem snapshot, change_description fica "X → X".
            $origHours  = $delivery->hours_planned;
            $origStart  = $delivery->planned_start_at?->toDateString();
            $origDue    = $delivery->due_date?->toDateString();
            $origPredId = $delivery->depends_on_delivery_id;
            $origTitle  = $delivery->title;

            // Cascade FS via método existente (com simulate)
            $cascadeRequest = new \Illuminate\Http\Request();
            $cascadeRequest->merge(['apply' => false, 'simulate' => $simulate]);
            $cascadeResp = app(\App\Http\Controllers\StageDeliveryController::class)
                ->recalcDependents($cascadeRequest, $delivery);
            $chain = $cascadeResp->getData(true)['chain'] ?? [];

            // Descrição amigável da mudança (usa snapshot ANTES do cascade)
            $changes = [];
            if (array_key_exists('hours_planned', $simulate))    $changes[] = "Horas: {$origHours}h → {$simulate['hours_planned']}h";
            if (array_key_exists('planned_start_at', $simulate)) $changes[] = "Início: " . ($origStart ?? '—') . " → " . ($simulate['planned_start_at'] ?? '—');
            if (array_key_exists('due_date', $simulate))         $changes[] = "Fim: "    . ($origDue   ?? '—') . " → " . ($simulate['due_date']         ?? '—');
            if (array_key_exists('depends_on_delivery_id', $simulate)) {
                $oldPredTitle = $origPredId
                    ? (\App\Models\StageDelivery::find($origPredId)?->title ?? '?')
                    : 'sem predecessor';
                $newPredId = $simulate['depends_on_delivery_id'];
                $newPredTitle = $newPredId
                    ? (\App\Models\StageDelivery::find($newPredId)?->title ?? '?')
                    : 'sem predecessor';
                $changes[] = "Predecessor: {$oldPredTitle} → {$newPredTitle}";
            }

            // Mapa das atividades que se moveram (cadeia + a editada) → novo fim.
            $movedEnds = [];
            foreach ($chain as $c) {
                if (!empty($c['id'])) $movedEnds[(int) $c['id']] = $c['suggested_end'] ?? null;
            }
            $movedEnds[$delivery->id] = $simulate['due_date'] ?? $origDue;

            // Novo prazo do projeto = MAX de todas as deliveries (com o fim movido onde
            // aplicável) + as etapas. Funciona empurrando E puxando o prazo.
            $newMaxEnd = null;
            $allDeliv = \App\Models\StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
                ->get(['id', 'due_date']);
            foreach ($allDeliv as $ad) {
                $e = array_key_exists($ad->id, $movedEnds) ? $movedEnds[$ad->id] : $ad->due_date?->toDateString();
                if ($e && (!$newMaxEnd || $e > $newMaxEnd)) $newMaxEnd = $e;
            }
            $maxStage = $project->stages()->max('expected_end_date');
            $maxStageStr = $maxStage instanceof \Carbon\Carbon ? $maxStage->toDateString() : (is_string($maxStage) ? substr($maxStage, 0, 10) : null);
            if ($maxStageStr && (!$newMaxEnd || $maxStageStr > $newMaxEnd)) $newMaxEnd = $maxStageStr;

            $daysDiff = $this->datesDiff($currentEnd, $newMaxEnd);

            // Resumo executivo estruturado (campo primário alterado).
            [$fieldLabel, $oldVal, $newVal] = $this->primaryChangedField($simulate, [
                'hours' => $origHours, 'start' => $origStart, 'due' => $origDue, 'pred_id' => $origPredId,
            ]);

            // Impacto por etapa: a etapa editada (com datas simuladas) + as etapas da cadeia.
            $suggestions = $chain;
            $suggestions[] = [
                'id' => $delivery->id,
                'suggested_start' => $simulate['planned_start_at'] ?? $origStart,
                'suggested_end'   => $simulate['due_date'] ?? $origDue,
            ];
            $affectedStages = $this->buildAffectedStages($suggestions);

            return response()->json([
                'summary' => [
                    'change_description' => implode(' · ', $changes) ?: 'Sem mudanças detectadas',
                    'trigger_label' => "Alteração em '{$origTitle}'",
                    'item_title'    => $origTitle,
                    'item_kind'     => 'atividade',
                    'field_label'   => $fieldLabel,
                    'old_value'     => $oldVal,
                    'new_value'     => $newVal,
                ],
                'impact' => [
                    'affected_deliveries_count' => count($chain),
                    'affected_stages_count'     => count($affectedStages),
                    'project_end_current'       => $currentEnd,
                    'project_end_new'           => $newMaxEnd,
                    'days_diff'                 => $daysDiff,
                    'has_conflicts'             => false,
                ],
                'affected'        => $chain,
                'affected_stages' => $affectedStages,
                'conflicts'       => [],
            ]);
        }

        // trigger=stage_field — etapa não cascateia (sem FS entre etapas); só pode
        // mover o prazo do projeto se a etapa for a mais distante.
        if ($trigger === 'stage_field') {
            $stage = \App\Models\ProjectStage::find((int) $request->input('stage_id'));
            if (!$stage || $stage->project_id !== $project->id) {
                return response()->json(['message' => 'etapa inválida'], 422);
            }
            $origStageStart = $stage->stage_start_at?->toDateString();
            $origStageEnd   = $stage->expected_end_date?->toDateString();
            $origStageHours = $stage->hours_planned;

            $newStageEnd = array_key_exists('expected_end_date', $simulate) ? $simulate['expected_end_date'] : $origStageEnd;
            $newStageStart = array_key_exists('stage_start_at', $simulate) ? $simulate['stage_start_at'] : $origStageStart;

            // Novo prazo do projeto: maior entre as OUTRAS etapas, todas as deliveries e o novo fim da etapa.
            $otherStagesMax = $project->stages()->where('id', '!=', $stage->id)->max('expected_end_date');
            $delivMax = \App\Models\StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))->max('due_date');
            $newMaxEnd = null;
            foreach ([$otherStagesMax, $delivMax, $newStageEnd] as $d) {
                $ds = $d instanceof \Carbon\Carbon ? $d->toDateString() : (is_string($d) ? substr($d, 0, 10) : null);
                if ($ds && (!$newMaxEnd || $ds > $newMaxEnd)) $newMaxEnd = $ds;
            }
            $daysDiff = $this->datesDiff($currentEnd, $newMaxEnd);

            [$fieldLabel, $oldVal, $newVal] = $this->primaryChangedStageField($simulate, [
                'hours' => $origStageHours, 'start' => $origStageStart, 'end' => $origStageEnd,
            ]);

            $affectedStages = [[
                'stage_id'      => $stage->id,
                'name'          => $stage->name,
                'current_start' => $origStageStart,
                'current_end'   => $origStageEnd,
                'new_start'     => $newStageStart,
                'new_end'       => $newStageEnd,
                'days_diff'     => $this->datesDiff($origStageEnd, $newStageEnd),
            ]];

            return response()->json([
                'summary' => [
                    'change_description' => trim(($fieldLabel ?? 'Etapa') . ": " . ($oldVal ?? '—') . " → " . ($newVal ?? '—')),
                    'trigger_label' => "Alteração na etapa '{$stage->name}'",
                    'item_title'    => $stage->name,
                    'item_kind'     => 'etapa',
                    'field_label'   => $fieldLabel,
                    'old_value'     => $oldVal,
                    'new_value'     => $newVal,
                ],
                'impact' => [
                    'affected_deliveries_count' => 0,
                    'affected_stages_count'     => 1,
                    'project_end_current'       => $currentEnd,
                    'project_end_new'           => $newMaxEnd,
                    'days_diff'                 => $daysDiff,
                    'has_conflicts'             => false,
                ],
                'affected'        => [],
                'affected_stages' => $affectedStages,
                'conflicts'       => [],
            ]);
        }

        // trigger=project_calendar
        $allowWeekend = $simulate['allow_weekend_work'] ?? (bool) $project->allow_weekend_work;
        $allowHoliday = $simulate['allow_holiday_work'] ?? (bool) $project->allow_holiday_work;
        $newOpts = ['allow_weekend' => (bool) $allowWeekend, 'allow_holiday' => (bool) $allowHoliday];
        $oldOpts = [
            'allow_weekend' => (bool) $project->allow_weekend_work,
            'allow_holiday' => (bool) $project->allow_holiday_work,
        ];

        $deliveries = \App\Models\StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
            ->whereNotNull('planned_start_at')
            ->whereNotNull('due_date')
            ->get();

        $affected = [];
        $stageIds = [];
        foreach ($deliveries as $d) {
            $durOld = $calendar->businessDaysBetween($d->planned_start_at, $d->due_date, $oldOpts);
            $durNew = $calendar->businessDaysBetween($d->planned_start_at, $d->due_date, $newOpts);
            if ($durOld !== $durNew) {
                $affected[] = [
                    'id'              => $d->id,
                    'title'           => $d->title,
                    'current_start'   => $d->planned_start_at?->toDateString(),
                    'current_end'     => $d->due_date?->toDateString(),
                    'suggested_start' => $d->planned_start_at?->toDateString(),  // calendar não move datas
                    'suggested_end'   => $d->due_date?->toDateString(),
                    'duration_old'    => $durOld,
                    'duration_new'    => $durNew,
                ];
                $stageIds[$d->stage_id] = true;
            }
        }

        $changes = [];
        if (array_key_exists('allow_weekend_work', $simulate)) {
            $changes[] = "Sábado/domingo: " . ((bool) $project->allow_weekend_work ? 'SIM' : 'NÃO') . " → " . ($simulate['allow_weekend_work'] ? 'SIM' : 'NÃO');
        }
        if (array_key_exists('allow_holiday_work', $simulate)) {
            $changes[] = "Feriados: " . ((bool) $project->allow_holiday_work ? 'SIM' : 'NÃO') . " → " . ($simulate['allow_holiday_work'] ? 'SIM' : 'NÃO');
        }

        return response()->json([
            'summary' => [
                'change_description' => implode(' · ', $changes) ?: 'Sem mudanças detectadas',
                'trigger_label' => 'Alteração no calendário operacional',
            ],
            'impact' => [
                'affected_deliveries_count' => count($affected),
                'affected_stages_count'     => count($stageIds),
                'project_end_current'       => $currentEnd,
                'project_end_new'           => $currentEnd,  // datas não movem, só durações
                'days_diff'                 => 0,
                'has_conflicts'             => false,
            ],
            'affected'        => $affected,
            'affected_stages' => [],
            'conflicts'       => [],
        ]);
    }

    /** Campo primário alterado numa atividade → [label, old, new] p/ resumo executivo. */
    private function primaryChangedField(array $simulate, array $orig): array
    {
        if (array_key_exists('hours_planned', $simulate)) {
            return ['Horas planejadas', $orig['hours'] . 'h', $simulate['hours_planned'] . 'h'];
        }
        if (array_key_exists('due_date', $simulate)) {
            return ['Data de entrega', $orig['due'] ?? '—', $simulate['due_date'] ?? '—'];
        }
        if (array_key_exists('planned_start_at', $simulate)) {
            return ['Data de início', $orig['start'] ?? '—', $simulate['planned_start_at'] ?? '—'];
        }
        if (array_key_exists('depends_on_delivery_id', $simulate)) {
            $oldT = $orig['pred_id'] ? (\App\Models\StageDelivery::find($orig['pred_id'])?->title ?? '?') : 'sem predecessor';
            $newT = $simulate['depends_on_delivery_id'] ? (\App\Models\StageDelivery::find($simulate['depends_on_delivery_id'])?->title ?? '?') : 'sem predecessor';
            return ['Predecessora', $oldT, $newT];
        }
        return [null, null, null];
    }

    /** Campo primário alterado numa etapa → [label, old, new]. */
    private function primaryChangedStageField(array $simulate, array $orig): array
    {
        if (array_key_exists('hours_planned', $simulate)) {
            return ['Horas da etapa', $orig['hours'] . 'h', $simulate['hours_planned'] . 'h'];
        }
        if (array_key_exists('expected_end_date', $simulate)) {
            return ['Data de fim', $orig['end'] ?? '—', $simulate['expected_end_date'] ?? '—'];
        }
        if (array_key_exists('stage_start_at', $simulate)) {
            return ['Data de início', $orig['start'] ?? '—', $simulate['stage_start_at'] ?? '—'];
        }
        return [null, null, null];
    }

    /**
     * Impacto por etapa: dadas as sugestões {id, suggested_start/end} das atividades
     * que se moveram, agrupa por etapa e calcula o prazo atual (min/max das deliveries
     * de cada etapa hoje) vs o novo (aplicando as sugestões). Fase 10.1+.
     */
    private function buildAffectedStages(array $suggestions): array
    {
        if (empty($suggestions)) return [];
        $suggById = [];
        foreach ($suggestions as $s) {
            if (!empty($s['id'])) $suggById[(int) $s['id']] = $s;
        }
        $stageIds = \App\Models\StageDelivery::whereIn('id', array_keys($suggById))
            ->pluck('stage_id')->unique()->values()->all();

        $out = [];
        foreach ($stageIds as $sid) {
            $stage = \App\Models\ProjectStage::find($sid);
            if (!$stage) continue;
            $deliveries = \App\Models\StageDelivery::where('stage_id', $sid)
                ->where('client_involved', false)
                ->whereNotNull('planned_start_at')->whereNotNull('due_date')
                ->get(['id', 'planned_start_at', 'due_date']);

            $curStart = $curEnd = $newStart = $newEnd = null;
            foreach ($deliveries as $d) {
                $cs = $d->planned_start_at->toDateString();
                $ce = $d->due_date->toDateString();
                $ns = isset($suggById[$d->id]) ? ($suggById[$d->id]['suggested_start'] ?? $cs) : $cs;
                $ne = isset($suggById[$d->id]) ? ($suggById[$d->id]['suggested_end'] ?? $ce) : $ce;
                if ($curStart === null || $cs < $curStart) $curStart = $cs;
                if ($curEnd === null   || $ce > $curEnd)   $curEnd = $ce;
                if ($newStart === null || $ns < $newStart) $newStart = $ns;
                if ($newEnd === null   || $ne > $newEnd)   $newEnd = $ne;
            }
            $out[] = [
                'stage_id'      => $sid,
                'name'          => $stage->name,
                'current_start' => $curStart,
                'current_end'   => $curEnd,
                'new_start'     => $newStart,
                'new_end'       => $newEnd,
                'days_diff'     => $this->datesDiff($curEnd, $newEnd),
            ];
        }
        return $out;
    }

    /** Maior due_date / expected_end_date do projeto operacional. Fase 10.1. */
    private function projectScheduleMaxEnd(Project $project): ?string
    {
        $maxStage = $project->stages()->max('expected_end_date');
        $maxDeliv = \App\Models\StageDelivery::whereHas('stage', fn ($q) => $q->where('project_id', $project->id))
            ->max('due_date');
        $max = null;
        foreach ([$maxStage, $maxDeliv] as $d) {
            if ($d && (!$max || $d > $max)) $max = $d;
        }
        return $max instanceof \Carbon\Carbon ? $max->toDateString() : (is_string($max) ? substr($max, 0, 10) : null);
    }

    /** Diferença em dias (positivo = atraso, negativo = adiantamento). Fase 10.1. */
    private function datesDiff(?string $current, ?string $new): int
    {
        if (!$current || !$new) return 0;
        return \Carbon\Carbon::parse($current)->diffInDays(\Carbon\Carbon::parse($new), false);
    }

    /**
     * Equipe consolidada do projeto operacional (Pilar 1).
     *
     * Agrega `stage_allocations` por usuário, somando planejado/consumido entre
     * todas as etapas ativas do projeto. Em projetos de sustentação, retorna
     * lista vazia — equipe é direta via `project_consultants` lá.
     *
     * View derivada — nada persiste (ADR 0007).
     */
    public function consolidatedTeam(Project $project): JsonResponse
    {
        $project->loadMissing('serviceType');

        if (!$project->isOperational()) {
            return response()->json(['items' => [], 'is_operational' => false]);
        }

        // Soma de horas consumidas por (user_id, stage_id) — só approved+released
        $tsSum = \DB::table('timesheets')
            ->whereNull('deleted_at')
            ->whereIn('status', [\App\Models\Timesheet::STATUS_APPROVED, \App\Models\Timesheet::STATUS_RELEASED])
            ->groupBy('user_id', 'stage_id')
            ->selectRaw('user_id, stage_id, COALESCE(SUM(effort_minutes), 0) / 60.0 AS actual_hours');

        $rows = \DB::table('stage_allocations as a')
            ->join('project_stages as ps', 'ps.id', '=', 'a.stage_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoinSub($tsSum, 'ts', function ($j) {
                $j->on('ts.user_id', '=', 'a.user_id')
                  ->on('ts.stage_id', '=', 'a.stage_id');
            })
            ->where('ps.project_id', $project->id)
            ->whereNull('ps.deleted_at')
            ->where('ps.status', '!=', 'done')
            ->selectRaw('
                u.id AS user_id,
                u.name AS user_name,
                u.email AS user_email,
                ps.id AS stage_id,
                ps.name AS stage_name,
                a.planned_hours,
                COALESCE(ts.actual_hours, 0) AS actual_hours
            ')
            ->orderBy('u.name')
            ->orderBy('ps.order_index')
            ->get();

        // Agrupa por usuário
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user' => [
                        'id'    => $uid,
                        'name'  => $r->user_name,
                        'email' => $r->user_email,
                    ],
                    'total_planned'   => 0.0,
                    'total_actual'    => 0.0,
                    'total_remaining' => 0.0,
                    'stages'          => [],
                ];
            }
            $planned = (float) $r->planned_hours;
            $actual  = (float) $r->actual_hours;
            $byUser[$uid]['stages'][] = [
                'stage_id'   => (int) $r->stage_id,
                'stage_name' => (string) $r->stage_name,
                'planned'    => $planned,
                'actual'     => round($actual, 2),
            ];
            $byUser[$uid]['total_planned'] += $planned;
            $byUser[$uid]['total_actual']  += $actual;
        }

        $items = array_values(array_map(function ($u) {
            $u['total_planned']   = round($u['total_planned'], 2);
            $u['total_actual']    = round($u['total_actual'], 2);
            $u['total_remaining'] = round($u['total_planned'] - $u['total_actual'], 2);
            return $u;
        }, $byUser));

        // Ordena por total_planned desc (consultor com mais carga primeiro)
        usort($items, fn ($a, $b) => $b['total_planned'] <=> $a['total_planned']);

        return response()->json([
            'items'          => $items,
            'is_operational' => true,
            'totals'         => [
                'consultant_count' => count($items),
                'total_planned'    => array_sum(array_column($items, 'total_planned')),
                'total_actual'     => array_sum(array_column($items, 'total_actual')),
            ],
        ]);
    }

    public function nextCode(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id'       => 'required_without:parent_project_id|exists:customers,id',
            'parent_project_id' => 'nullable|exists:projects,id',
        ]);

        // Modo subprojeto: próximo sub_seq disponível pro projeto pai informado
        if ($request->filled('parent_project_id')) {
            $parent = Project::withTrashed()->findOrFail($request->parent_project_id);
            $parentCode = $parent->code;

            $childCodes = Project::withTrashed()
                ->where('parent_project_id', $parent->id)
                ->pluck('code')
                ->toArray();

            $maxSub = 0;
            foreach ($childCodes as $cc) {
                if ($cc && preg_match('/-(\d{2})$/', $cc, $m)) {
                    $maxSub = max($maxSub, (int) $m[1]);
                }
            }

            $next = $maxSub + 1;
            do {
                $padded = str_pad($next, 2, '0', STR_PAD_LEFT);
                $code   = $parentCode . '-' . $padded;
                $next++;
            } while (Project::withTrashed()->where('code', $code)->exists());

            return response()->json([
                'sub_seq'     => str_pad($next - 1, 2, '0', STR_PAD_LEFT),
                'parent_code' => $parentCode,
                'code'        => $code,
            ]);
        }

        $customer = Customer::findOrFail($request->customer_id);

        if (!$customer->code_prefix) {
            return response()->json(['code' => null, 'error' => 'Cliente sem prefixo de código'], 422);
        }

        $seq = \App\Models\ProjectSequence::where('customer_id', $customer->id)->first();
        $nextSeq = ($seq?->last_sequence ?? 0) + 1;
        $prefix  = strtoupper($customer->code_prefix);
        $year    = now()->format('y');

        // Avança até encontrar código disponível
        do {
            $padded = str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
            $code   = $prefix . $padded . '-' . $year;
            $nextSeq++;
        } while (Project::withTrashed()->where('code', $code)->exists());

        return response()->json([
            'code'   => $code,
            'prefix' => $prefix,
            'year'   => $year,
            'seq'    => str_pad($nextSeq - 1, 3, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Gera novo código de projeto pra um cliente, atualizando a sequência.
     * Uso interno (ex: ao desvincular projeto filho).
     */
    private function generateNextProjectCode(int $customerId): string
    {
        $customer = Customer::findOrFail($customerId);
        if (!$customer->code_prefix) {
            throw new \RuntimeException('Cliente sem prefixo de código');
        }

        $seq = \App\Models\ProjectSequence::where('customer_id', $customer->id)->first();
        $nextSeq = ($seq?->last_sequence ?? 0) + 1;
        $prefix  = strtoupper($customer->code_prefix);
        $year    = now()->format('y');

        do {
            $padded = str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
            $code   = $prefix . $padded . '-' . $year;
            $nextSeq++;
        } while (Project::withTrashed()->where('code', $code)->exists());

        \App\Models\ProjectSequence::updateOrCreate(
            ['customer_id' => $customer->id],
            ['last_sequence' => $nextSeq - 1]
        );

        return $code;
    }

    /**
     * Desvincula projeto filho do pai e o torna independente.
     * - Pai recupera as horas vendidas do filho (sold_hours)
     * - Filho fica com sold_hours = horas consumidas (apontamentos não-rejeitados)
     * - Filho ganha novo código (próximo da sequência do cliente)
     * - parent_project_id = null
     */
    public function detachFromParent(Request $request, int $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'Apenas administradores podem desvincular projeto'], 403);
        }

        $child = Project::with('contractType')->findOrFail($project);
        if (!$child->parent_project_id) {
            return response()->json(['error' => 'Projeto não está vinculado a um pai'], 422);
        }

        // Código novo: aceitar do request (opcional) ou gerar automaticamente
        $providedCode = trim((string) $request->input('code', ''));
        if ($providedCode !== '') {
            $exists = Project::withTrashed()
                ->where('code', $providedCode)
                ->where('id', '!=', $child->id)
                ->exists();
            if ($exists) {
                return response()->json([
                    'error' => "Código '{$providedCode}' já está em uso por outro projeto",
                ], 422);
            }
        }

        $parent = Project::findOrFail($child->parent_project_id);

        try {
            DB::transaction(function () use ($child, $providedCode) {
                // sold_hours do pai e do filho NÃO são alterados — desvínculo é apenas
                // estrutural. O consumo do filho deixa de contar no consumed_hours do pai
                // (cálculo dinâmico em index gestao mode).
                $child->parent_project_id = null;
                $child->code = $providedCode !== ''
                    ? $providedCode
                    : $this->generateNextProjectCode($child->customer_id);
                $child->save();
            });
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Falha ao desvincular projeto: ' . $e->getMessage(),
            ], 500);
        }

        // Invalida o cache da listagem — senão a lista (cachedList) continua mostrando
        // o vínculo antigo e o FE oferece "Desvincular do pai" num projeto já solto (422).
        $this->invalidateListCache('projects');

        return response()->json([
            'message' => 'Projeto desvinculado com sucesso',
            'parent' => [
                'id'         => $parent->id,
                'name'       => $parent->name,
                'sold_hours' => (float) $parent->sold_hours,
            ],
            'child' => [
                'id'         => $child->id,
                'name'       => $child->name,
                'code'       => $child->code,
                'sold_hours' => (float) $child->sold_hours,
            ],
        ]);
    }

    /**
     * Vincula um projeto independente como filho de outro (operação inversa
     * de detach). Pai entrega horas pro filho conforme tipo de contrato:
     *  - Fechado: pai entrega o sold_hours total do filho
     *  - Demais: pai entrega só o consumido pelo filho
     */
    public function attachToParent(Request $request, int $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user || !$user->isAdmin()) {
            return response()->json(['error' => 'Apenas administradores podem vincular projeto'], 403);
        }

        $request->validate([
            'parent_id' => 'required|integer|exists:projects,id',
        ]);

        $child = Project::with('contractType')->findOrFail($project);
        if ($child->parent_project_id) {
            return response()->json(['error' => 'Projeto já está vinculado a um pai'], 422);
        }

        $parent = Project::findOrFail($request->parent_id);

        if ($parent->id === $child->id) {
            return response()->json(['error' => 'Projeto não pode ser pai de si mesmo'], 422);
        }
        if ($parent->customer_id !== $child->customer_id) {
            return response()->json(['error' => 'Pai e filho devem pertencer ao mesmo cliente'], 422);
        }
        if ($parent->parent_project_id) {
            return response()->json(['error' => 'Pai escolhido já é filho de outro projeto'], 422);
        }

        // Subprojeto pode ser On Demand quando o pai também é On Demand
        // Subprojeto On Demand pode ser filho de qualquer tipo de pai
        // (separa apontamentos visualmente; consumo soma no saldo do pai).
        // Banco de Horas Mensal continua bloqueado (mensalidade fica no pai).
        $childCode  = (string) ($child->contractType?->code ?? '');
        $childName  = strtolower(trim((string) ($child->contractType?->name ?? '')));
        $isMonthly  = $childCode === 'monthly_hours' || $childName === 'banco de horas mensal';
        if ($isMonthly) {
            return response()->json([
                'error' => 'Projetos do tipo Banco de Horas Mensal não podem ser filhos de outro projeto',
            ], 422);
        }

        try {
            // Herda o código do pai (XXX000-YY-ZZ) — mesma regra do store ao criar subprojeto.
            $codeData = (new \App\Services\ProjectCodeService())->generateChildCode($parent);
            DB::transaction(function () use ($parent, $child, $codeData) {
                // sold_hours do pai NÃO é alterado por vínculo — o consumo do filho
                // passa a contar dinamicamente no consumed_hours do pai (ver index gestao).
                $child->parent_project_id = $parent->id;
                $child->code           = $codeData['code'];
                $child->proj_sequence  = $codeData['proj_sequence'];
                $child->proj_year      = $codeData['proj_year'];
                $child->child_sequence = $codeData['child_sequence'];
                $child->is_manual_code = $codeData['is_manual_code'];
                $child->save();
            });
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Falha ao vincular projeto: ' . $e->getMessage(),
            ], 500);
        }

        // Invalida o cache da listagem — senão a lista (cachedList) continua sem o vínculo novo.
        $this->invalidateListCache('projects');

        return response()->json([
            'message' => 'Projeto vinculado com sucesso',
            'parent' => [
                'id'         => $parent->id,
                'name'       => $parent->name,
                'sold_hours' => (float) $parent->sold_hours,
            ],
            'child' => [
                'id'                => $child->id,
                'name'              => $child->name,
                'code'              => $child->code,
                'parent_project_id' => $child->parent_project_id,
                'sold_hours'        => (float) $child->sold_hours,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{id}/available-hours",
     *     tags={"Projects"},
     *     summary="Obter horas disponíveis de um projeto pai",
     *     description="Retorna quantas horas ainda estão disponíveis em um projeto pai para alocar em subprojetos",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto pai"
     *     ),
     *     @OA\Parameter(
     *         name="exclude_id",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer"),
     *         description="ID do projeto a excluir do cálculo (útil na edição)"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Horas disponíveis",
     *         @OA\JsonContent(
     *             @OA\Property(property="parent_sold_hours", type="integer", example=100),
     *             @OA\Property(property="children_total_hours", type="integer", example=60),
     *             @OA\Property(property="available_hours", type="integer", example=40)
     *         )
     *     ),
     *     @OA\Response(response=404, description="Projeto não encontrado")
     * )
     */
    public function availableHours(Request $request, Project $project): JsonResponse
    {
        $excludeProjectId = $request->get('exclude_id');

        // Obter o saldo geral do projeto (já inclui todos os filhos com a lógica correta)
        $generalBalance = $project->getGeneralHoursBalance();

        // Calcular saldo excluindo o projeto filho específico (se fornecido)
        $availableBalance = $generalBalance;

        if ($excludeProjectId) {
            $excludedProject = $project->childProjects()->find($excludeProjectId);

            if ($excludedProject) {
                // Carregar contractType se necessário
                $excludedProject->loadMissing('contractType');

                // Verificar se o projeto excluído tem contract_type com name = "Fechado"
                $isClosedContract = $excludedProject->contractType &&
                                    strtolower(trim($excludedProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Para contratos fechados: foi subtraído (horas vendidas + aporte de horas)
                    $excludedSoldHours = $excludedProject->sold_hours ?? 0;
                    $excludedHourContribution = $excludedProject->hour_contribution ?? 0;
                    $availableBalance += ($excludedSoldHours + $excludedHourContribution);
                } else {
                    // Para outros tipos: foi subtraído pelas horas apontadas
                    $excludedLoggedHours = $excludedProject->getTotalLoggedHours(false);
                    $availableBalance += $excludedLoggedHours;
                }
            }
        }

        // Calcular informações adicionais para o retorno
        $parentSoldHours = $project->sold_hours ?? 0;
        $parentTotalLoggedHours = $project->getTotalLoggedHours(false);
        
        // Usar método auxiliar para obter total disponível (inclui aportes novos + fallback legado)
        $totalAvailable = $project->getTotalAvailableHours();

        return response()->json([
            'parent_sold_hours' => $parentSoldHours,
            'parent_hour_contribution' => $project->hour_contribution ?? 0,  // @deprecated - mantido para compatibilidade
            'parent_total_available' => $totalAvailable,
            'parent_total_logged_hours' => round($parentTotalLoggedHours, 2),
            'general_balance' => round($generalBalance, 2),
            'available_balance' => max(0, round($availableBalance, 2)),
            // ✨ Novos campos calculados usando hour_contributions table
            'parent_total_contributions_hours' => $project->hourContributions()->sum('contributed_hours') ?? 0,
            'parent_weighted_hourly_rate' => $project->getWeightedAverageHourlyRate(),
        ]);
    }

    /**
     * Calcula as horas disponíveis em um projeto pai para subprojetos
     *
     * Utiliza o método getGeneralHoursBalance do modelo Project para calcular o saldo,
     * que já considera a lógica especial para contratos fechados.
     *
     * @param Project $parentProject Projeto pai
     * @param int|null $excludeProjectId ID do projeto a excluir do cálculo (útil na edição)
     * @return int Horas disponíveis
     */
    private function calculateAvailableHours(Project $parentProject, ?int $excludeProjectId = null): int
    {
        // Disponível do pai p/ comprometer em subprojeto = saldo geral do pai
        // calculado IGNORANDO por completo o filho em edição. Antes calculava o
        // saldo cheio e tentava "somar de volta" só as horas APONTADAS do filho —
        // mas getGeneralHoursBalance subtrai o filho por apontado + initial_consumed
        // + coordenação, então o estorno divergia (não devolvia o initial_consumed) e
        // o disponível vinha menor que o real (ex.: filho com consumo histórico
        // travava a edição com "58h disponíveis"). Excluindo o filho na origem o
        // cálculo não tem como divergir.
        $balance = $parentProject->getGeneralHoursBalance(false, null, $excludeProjectId);

        // Retornar como int (arredondado) e garantir que não seja negativo
        return max(0, (int) round($balance));
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project}/change-history",
     *     tags={"Projects"},
     *     summary="Histórico de alterações do projeto",
     *     description="Lista o histórico de alterações de dados sensíveis do projeto (valores, horas e políticas de despesas)",
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer", example=1),
     *         description="ID do projeto"
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, example=1),
     *         description="Página (padrão: 1)"
     *     ),
     *     @OA\Parameter(
     *         name="pageSize",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, example=20),
     *         description="Registros por página (padrão: 20)"
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="-created_at"),
     *         description="Ordenação (ex: -created_at para mais recentes primeiro)"
     *     ),
     *     @OA\Parameter(
     *         name="field_name",
     *         in="query",
     *         required=false,
     *         @OA\Schema(type="string", example="project_value"),
     *         description="Filtrar por campo alterado (ex: project_value, hourly_rate, sold_hours, etc.)"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Histórico de alterações",
     *         @OA\JsonContent(
     *             @OA\Property(property="hasNext", type="boolean", example=false),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="project_id", type="integer"),
     *                     @OA\Property(property="changed_by", type="integer"),
     *                     @OA\Property(property="field_name", type="string"),
     *                     @OA\Property(property="field_label", type="string"),
     *                     @OA\Property(property="old_value", type="string"),
     *                     @OA\Property(property="new_value", type="string"),
     *                     @OA\Property(property="old_value_formatted", type="string"),
     *                     @OA\Property(property="new_value_formatted", type="string"),
     *                     @OA\Property(property="reason", type="string", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(
     *                         property="changed_by_user",
     *                         type="object",
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="name", type="string"),
     *                         @OA\Property(property="email", type="string")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Projeto não encontrado")
     * )
     */
    public function contractRequest(Project $project): JsonResponse
    {
        if (!$project->contract_request_id) {
            return response()->json(null);
        }

        // A aba "Comentários" do projeto é o histórico do canal do cliente (visibility='client').
        // O Diário interno da requisição (visibility='internal') NÃO entra aqui.
        $req = \App\Models\ContractRequest::with([
            'customer:id,name',
            'createdBy:id,name',
            'messages' => fn ($q) => $q->where('visibility', 'client')->orderBy('created_at'),
            'messages.author:id,name',
            'messages.attachments',
        ])->find($project->contract_request_id);

        return response()->json($req);
    }

    public function changeHistory(Request $request, Project $project): JsonResponse
    {
        // Preparar query base
        $query = ProjectChangeLog::query()
            ->where('project_id', $project->id)
            ->with('changedByUser:id,name,email');

        // Filtro por campo alterado
        if ($request->has('field_name') && $request->get('field_name') !== 'all' && $request->get('field_name') !== null) {
            $query->where('field_name', $request->get('field_name'));
        }

        // Ordenação
        $orderField = 'created_at';
        $orderDirection = 'desc';

        if ($request->has('order')) {
            $orderParam = $request->get('order');
            if (str_starts_with($orderParam, '-')) {
                $orderField = substr($orderParam, 1);
                $orderDirection = 'desc';
            } else {
                $orderField = $orderParam;
                $orderDirection = 'asc';
            }
        }

        $query->orderBy($orderField, $orderDirection);

        // Paginação
        $pageSize = min((int)$request->get('pageSize', 20), 100);
        $page = (int)$request->get('page', 1);

        $logs = $query->paginate($pageSize, ['*'], 'page', $page);

        // Formatar os registros
        $items = $logs->map(function ($log) {
            return $log->toFormattedArray();
        });

        return response()->json([
            'hasNext' => $logs->hasMorePages(),
            'items' => $items
        ]);
    }

    /**
     * Calcula o saldo geral de horas do projeto de forma otimizada
     * usando dados já carregados (evita N+1 queries)
     *
     * Replica a lógica de getGeneralHoursBalance mas usando:
     * - total_logged_minutes (carregado via withSum, já excluindo rejeitados)
     * - childProjects já carregados com suas somas
     * - accumulated_sold_hours para Banco de Horas Mensal
     *
     * @param Project $project Projeto com dados já carregados
     * @return float Saldo geral em horas
     */
    private function calculateGeneralHoursBalance(Project $project): float
    {
        // Carregar contractType se necessário
        if (!$project->relationLoaded('contractType') && $project->contract_type_id) {
            $project->load('contractType');
        }

        // Para Banco de Horas Mensal, usar accumulated_sold_hours; caso contrário, usar sold_hours
        if ($project->isBankHoursMonthly()) {
            $soldHours = $project->accumulated_sold_hours ?? $project->sold_hours ?? 0;
        } else {
            $soldHours = $project->sold_hours ?? 0;
        }
        
        // Usar método auxiliar para obter aportes (novos + fallback legado)
        $totalAvailableHours = $project->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($project->sold_hours ?? 0);

        // Converter minutos apontados para horas (dados já carregados via withSum, excluindo rejeitados)
        $totalLoggedMinutes = $project->total_logged_minutes ?? 0;
        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);

        // Calcular saldo base do projeto atual
        // IMPORTANTE: Para Banco de Horas Mensal, soldHours já é accumulated_sold_hours
        $initialConsumed = (float) ($project->initial_hours_consumed ?? 0);
        $balance = ($soldHours + $contributionHours) - $totalLoggedHours - $initialConsumed;

        // Sempre incluir projetos filhos no cálculo (se existirem)
        if ($project->relationLoaded('childProjects') && $project->childProjects->isNotEmpty()) {
            foreach ($project->childProjects as $childProject) {
                if ($childProject->isAusterFrozen()) continue;

                // Verificar se o projeto filho é "Fechado" ou "Banco de Horas Fixo"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';
                $childCode = (string) ($childProject->contractType->code ?? '');
                $childName = $childProject->contractType ? strtolower(trim($childProject->contractType->name)) : '';
                $isBhFixoChild = $childCode === 'fixed_hours' || $childName === 'banco de horas fixo';

                if ($isClosedContract || $isBhFixoChild) {
                    // Fechado E Banco de Horas Fixo (subprojeto): comprometem sold_hours + aportes
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal: usar accumulated_sold_hours no cálculo
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                    
                    // Calcular aportes usando método auxiliar
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);
                    
                    // Calcular horas apontadas do filho (já excluindo rejeitados via withSum)
                    $childLoggedMinutes = $childProject->total_logged_minutes ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    
                    // Subtrair o saldo do filho: (accumulated_sold_hours + aportes) - horas apontadas - horas consumidas iniciais
                    $childInitialConsumed = (float) ($childProject->initial_hours_consumed ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours) - $childLoggedHours - $childInitialConsumed;
                    $balance -= $childBalance;
                } else {
                    // Para outros tipos: subtrair normalmente pelas horas apontadas (já excluindo rejeitados)
                    $childLoggedMinutes = $childProject->total_logged_minutes ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    $balance -= $childLoggedHours;
                }
            }
        }

        return round($balance, 2);
    }

    /**
     * Frontend pré-check: dado um customer_id (e opcionalmente exclude_id),
     * devolve o projeto que está atualmente com movidesk_integration_enabled=true
     * (ou null). Permite mostrar modal "X já está ativo, trocar pra este?".
     */
    public function movideskIntegrationConflict(Request $request): JsonResponse
    {
        $customerId = $request->query('customer_id');
        $excludeId  = $request->query('exclude_id');
        if (!$customerId) {
            return response()->json(['current_project' => null]);
        }
        $q = Project::where('customer_id', $customerId)
            ->where('movidesk_integration_enabled', true);
        if ($excludeId) $q->where('id', '!=', $excludeId);
        $existing = $q->first(['id', 'name', 'code']);
        return response()->json(['current_project' => $existing]);
    }

    public function updateStatus(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(Project::getStatuses()))],
        ]);

        $project->status = $validated['status'];
        $project->save();

        return response()->json([
            'success' => true,
            'message' => 'Status atualizado com sucesso',
            'status' => $project->status,
            'status_display' => $project->status_display,
        ]);
    }

    /** Dados do Saving (finalização antecipada) pra exibir na tela. {early:false} se não houver. */
    public function saving(Request $request, Project $project): JsonResponse
    {
        $data = app(\App\Services\ProjectEarlyFinishNotifier::class)->earlyFinishData($project);
        if (!$data) return response()->json(['early' => false]);

        return response()->json([
            'early'        => true,
            'days_early'   => $data['days_early'],
            'hours_saved'  => $data['hours_saved'],
            'prazo'        => $data['prazo'],
            'encerramento' => $data['encerramento'],
            'notified_at'  => $project->saving_notified_at,
        ]);
    }

    /** Reenvia (manual) o e-mail de Saving de finalização antecipada. */
    public function sendSaving(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $result = app(\App\Services\ProjectEarlyFinishNotifier::class)->send($project, true);
        if (empty($result['sent'])) {
            return response()->json(['message' => $result['reason'] ?? 'Não foi possível enviar.'], 422);
        }

        return response()->json([
            'message'     => 'Saving enviado para: ' . implode(', ', $result['to']),
            'days_early'  => $result['days_early'],
            'hours_saved' => $result['hours_saved'],
        ]);
    }

    /** Edição inline (tabela Demandas e Projetos): datas de prazo + percentual de entrega. Cliente não edita. */
    public function updateDelivery(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        if ($user->type === 'cliente') {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $validated = $request->validate([
            'start_date'          => 'nullable|date',
            'expected_end_date'   => 'nullable|date',
            'delivery_percentage' => 'nullable|numeric|min:0|max:100',
        ]);

        $project->fill($validated);
        $project->save();

        return response()->json([
            'success'             => true,
            'start_date'          => $project->start_date?->toDateString(),
            'expected_end_date'   => $project->expected_end_date?->toDateString(),
            'delivery_percentage' => $project->delivery_percentage,
        ]);
    }

    public function icAnalytics(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        $from = $request->get('start_date');
        $to   = $request->get('end_date');

        $base = DB::table('timesheets')
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->when($this->activeCompanyId(), fn ($q, $cid) => $q->where('projects.company_id', $cid))
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->join('users', 'users.id', '=', 'timesheets.user_id')
            ->where('projects.is_investimento_comercial', true)
            ->whereNull('timesheets.deleted_at')
            ->whereNotIn('timesheets.status', ['rejected', 'adjustment_requested', 'conflicted']);

        if ($from) $base->where('timesheets.date', '>=', $from);
        if ($to)   $base->where('timesheets.date', '<=', $to);

        // Custo respeitando a VIGÊNCIA: cada apontamento é custeado pelo valor/hora efetivo
        // vigente na SUA competência (mensalista = salário ÷ 160). Como a vigência (effective_from
        // + regra de mês-seguinte) não é expressável num CASE de SQL, agregamos no grão
        // consultor×cliente×mês e calculamos o custo em PHP via UserHourlyRateLog::effectiveValuesAt.
        $grain = (clone $base)
            ->selectRaw("users.id as user_id, users.name as user_name,
                         customers.id as customer_id, customers.name as customer_name,
                         TO_CHAR(timesheets.date, 'YYYY-MM') as month,
                         SUM(timesheets.effort_minutes) as total_minutes")
            ->groupBy('users.id', 'users.name', 'customers.id', 'customers.name')
            ->groupByRaw("TO_CHAR(timesheets.date, 'YYYY-MM')")
            ->get();

        $userCache = \App\Models\User::whereIn('id', $grain->pluck('user_id')->unique()->all())
            ->get(['id', 'hourly_rate', 'rate_type', 'consultant_type'])
            ->keyBy('id');
        $effCache = [];
        $effRate = function ($uid, $ym) use (&$effCache, $userCache) {
            $k = $uid . '|' . $ym;
            if (isset($effCache[$k])) return $effCache[$k];
            $u    = $userCache->get($uid);
            $hist = \App\Models\UserHourlyRateLog::effectiveValuesAt((int) $uid, $u, $ym . '-01');
            $rate = (float) ($hist['hourly_rate'] ?? $u?->hourly_rate ?? 0);
            $type = $hist['rate_type'] ?? $u?->rate_type ?? 'hourly';
            return $effCache[$k] = ($type === 'monthly' && $rate > 0) ? round($rate / 160, 4) : $rate;
        };

        $custAcc = []; $consAcc = []; $monthAcc = []; $detailAcc = [];
        foreach ($grain as $r) {
            $minutes = (float) $r->total_minutes;
            $cost    = ($minutes / 60) * $effRate($r->user_id, $r->month);
            $cid = $r->customer_id; $uid = $r->user_id; $mo = $r->month;

            if (!isset($custAcc[$cid])) $custAcc[$cid] = ['customer_id' => $cid, 'customer_name' => $r->customer_name, 'minutes' => 0.0, 'cost' => 0.0];
            $custAcc[$cid]['minutes'] += $minutes; $custAcc[$cid]['cost'] += $cost;

            if (!isset($consAcc[$uid])) $consAcc[$uid] = ['user_id' => $uid, 'user_name' => $r->user_name, 'minutes' => 0.0, 'cost' => 0.0, 'customers' => []];
            $consAcc[$uid]['minutes'] += $minutes; $consAcc[$uid]['cost'] += $cost; $consAcc[$uid]['customers'][$cid] = true;

            if (!isset($monthAcc[$mo])) $monthAcc[$mo] = ['month' => $mo, 'minutes' => 0.0, 'cost' => 0.0];
            $monthAcc[$mo]['minutes'] += $minutes; $monthAcc[$mo]['cost'] += $cost;

            $dk = $uid . ':' . $cid;
            if (!isset($detailAcc[$dk])) $detailAcc[$dk] = ['user_id' => $uid, 'user_name' => $r->user_name, 'customer_id' => $cid, 'customer_name' => $r->customer_name, 'minutes' => 0.0, 'cost' => 0.0];
            $detailAcc[$dk]['minutes'] += $minutes; $detailAcc[$dk]['cost'] += $cost;
        }

        $byCustomer = collect($custAcc)->sortByDesc('minutes')->values()
            ->map(fn ($c) => [
                'customer_id'   => $c['customer_id'],
                'customer_name' => $c['customer_name'],
                'total_hours'   => round($c['minutes'] / 60, 2),
                'total_cost'    => round($c['cost'], 2),
            ]);

        $byConsultant = collect($consAcc)->sortByDesc('minutes')->values()->take(20)
            ->map(fn ($u) => [
                'user_id'       => $u['user_id'],
                'user_name'     => $u['user_name'],
                'total_hours'   => round($u['minutes'] / 60, 2),
                'total_cost'    => round($u['cost'], 2),
                'num_customers' => count($u['customers']),
            ])->values();

        $monthly = collect($monthAcc)->sortBy('month')->values()
            ->map(fn ($m) => [
                'month'       => $m['month'],
                'total_hours' => round($m['minutes'] / 60, 2),
                'total_cost'  => round($m['cost'], 2),
            ]);

        $detail = collect($detailAcc)->sortBy('user_name')->values()
            ->map(fn ($d) => [
                'user_id'       => $d['user_id'],
                'user_name'     => $d['user_name'],
                'customer_id'   => $d['customer_id'],
                'customer_name' => $d['customer_name'],
                'total_hours'   => round($d['minutes'] / 60, 2),
                'total_cost'    => round($d['cost'], 2),
            ]);

        return response()->json([
            'by_customer'  => $byCustomer,
            'by_consultant' => $byConsultant,
            'monthly'      => $monthly,
            'detail'       => $detail,
        ]);
    }

    public function icSummary(Request $request): JsonResponse
    {
        $from = $request->get('start_date');
        $to   = $request->get('end_date');

        $query = \App\Models\Timesheet::query()
            ->join('projects', 'projects.id', '=', 'timesheets.project_id')
            ->join('customers', 'customers.id', '=', 'projects.customer_id')
            ->where('projects.is_investimento_comercial', true)
            ->whereNull('timesheets.deleted_at')
            ->whereNotIn('timesheets.status', [
                \App\Models\Timesheet::STATUS_REJECTED,
                \App\Models\Timesheet::STATUS_ADJUSTMENT_REQUESTED,
                \App\Models\Timesheet::STATUS_CONFLICTED,
            ])
            ->selectRaw('projects.id as project_id, projects.code, customers.id as customer_id, customers.name as customer_name, COALESCE(SUM(timesheets.effort_minutes), 0) as total_minutes')
            ->groupBy('projects.id', 'projects.code', 'customers.id', 'customers.name');

        if ($from) {
            $query->where('timesheets.date', '>=', $from);
        }
        if ($to) {
            $query->where('timesheets.date', '<=', $to);
        }

        $rows = $query->get()->map(fn ($r) => [
            'project_id'    => $r->project_id,
            'code'          => $r->code,
            'customer_id'   => $r->customer_id,
            'customer_name' => $r->customer_name,
            'total_hours'   => round($r->total_minutes / 60, 2),
        ]);

        return response()->json($rows);
    }

    /**
     * Cria um projeto interno manual para a ERPSERV (Investimento Interno).
     * Vários projetos por cliente (ex.: IC-248-1, IC-248-2 ...). Sem horas e sem valor.
     */
    public function storeInternalProject(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isAdministrativo()) {
            return response()->json(['message' => 'Sem permissão para criar projeto interno.'], 403);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:255|min:2',
            'categoria'   => 'required|string|in:Sustentação,Projeto,Suporte,Comercial,Leads',
            // Aprovador = coordenador do mini-projeto (quem aprova os apontamentos).
            'approver_id' => 'nullable|integer|exists:users,id',
            // Investimento pai: aninha o mini-projeto abaixo de outro investimento
            // interno (ex.: leads abaixo do "Investimento Leads").
            'parent_project_id' => 'nullable|integer|exists:projects,id',
        ]);

        // Multi-empresa: o projeto de investimento nasce sob o cliente da empresa ATIVA
        // (Bizify → cliente "BIZIFY"; senão → "ERPSERV") e carrega o company_id dela.
        $internalName = 'ERPSERV';
        $internalCompanyId = null;
        if (config('multiempresa.scoping_enabled')) {
            $activeId = app(\App\Services\CompanyContext::class)->id();
            if ($activeId) {
                $internalCompanyId = $activeId;
                if (\App\Models\Company::where('id', $activeId)->where('slug', 'bizify')->exists()) {
                    $internalName = 'BIZIFY';
                }
            }
        }
        $customer = \App\Models\Customer::whereRaw('UPPER(name) = ?', [$internalName])->first();
        if (!$customer) {
            return response()->json(['message' => "Cliente \"{$internalName}\" não encontrado."], 422);
        }

        $serviceTypeId  = \App\Models\ServiceType::where('code', 'projeto')->value('id');
        $contractTypeId = \App\Models\ContractType::where('code', 'on_demand')->value('id');
        if (!$serviceTypeId || !$contractTypeId) {
            return response()->json(['message' => 'Tipos de serviço/contrato padrão não configurados.'], 500);
        }

        // Próximo sufixo sequencial: IC-{prefix}-N (fallback IC-{customer_id}-N)
        $codeKey = $customer->code_prefix ?: (string) $customer->id;
        $prefix = "IC-{$codeKey}-";
        $maxSeq = Project::withTrashed()
            ->where('code', 'like', $prefix . '%')
            ->get()
            ->map(fn ($p) => (int) preg_replace('/^.*-/', '', $p->code))
            ->max() ?? 0;
        $code = $prefix . ($maxSeq + 1);

        $project = Project::create([
            'name'                      => $data['name'],
            'code'                      => $code,
            'customer_id'               => $customer->id,
            'service_type_id'           => $serviceTypeId,
            'contract_type_id'          => $contractTypeId,
            'status'                    => Project::STATUS_STARTED,
            'is_investimento_comercial' => true,
            'is_manual_code'            => true,
            'categoria_interna'         => $data['categoria'],
            'parent_project_id'         => $data['parent_project_id'] ?? null,
            'company_id'                => $internalCompanyId,
        ]);

        // Aprovador: vincula como coordenador do projeto (é quem aprova os
        // apontamentos do mini-projeto — ver Timesheet::canBeApprovedBy).
        if (!empty($data['approver_id'])) {
            $project->coordinators()->attach($data['approver_id']);
        }

        $this->invalidateListCache('projects');

        return response()->json([
            'message' => 'Projeto interno criado com sucesso.',
            'project' => $project->load('coordinators:id,name'),
        ], 201);
    }

    public function hoursPerConsultant(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Sem permissão'], 403);
        }

        // Escopo de projetos acessíveis
        $projectQuery = Project::query();
        if ($user->isCoordenador()) {
            $projectQuery->whereHas('coordinators', fn($q) => $q->where('users.id', $user->id));
        }
        $projectIds = $projectQuery->pluck('id');

        $rows = DB::table('timesheets')
            ->join('users', 'users.id', '=', 'timesheets.user_id')
            ->whereIn('timesheets.project_id', $projectIds)
            ->whereNull('timesheets.deleted_at')
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->select(
                'users.id',
                'users.name',
                DB::raw('ROUND(SUM(timesheets.effort_minutes) / 60.0, 1) as total_hours')
            )
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_hours')
            ->limit(30)
            ->get();

        return response()->json($rows);
    }

    // ─── Project Attachments ───────────────────────────────────────────────────

    public function listAttachments(Project $project): \Illuminate\Http\JsonResponse
    {
        // FASE 11.7 (PR 7b) — Junta anexos do PROJECT + anexos do CONTRACT
        // vinculado (substitui a "shadow row" ProjectAttachment que apontava
        // ContractAttachment.id). Source distingue origem.
        $contractAtts = collect();
        if ($project->contract_id) {
            $contractAtts = \App\Models\Attachment::query()
                ->forEntity('CONTRACT', $project->contract_id)
                ->whereNull('deleted_at')
                ->get();
        }
        $projectAtts = $project->attachments;

        $merged = $projectAtts->map(fn ($a) => [
            'id'            => $a->id,
            'type'          => $a->type,
            'original_name' => $a->original_name,
            'mime_type'     => $a->mime_type,
            'size'          => $a->size_bytes,
            'source'        => 'project',
            'created_at'    => $a->created_at,
        ])->concat($contractAtts->map(fn ($a) => [
            'id'            => $a->id,
            'type'          => $a->type,
            'original_name' => $a->original_name,
            'mime_type'     => $a->mime_type,
            'size'          => $a->size_bytes,
            'source'        => 'contract',
            'created_at'    => $a->created_at,
        ]))->values();

        return response()->json($merged);
    }

    public function uploadAttachment(Request $request, Project $project): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:20480',
            'type' => 'required|in:proposta,contrato,logo,outro',
        ]);

        $file = $request->file('file');
        $path = $file->store("projects/{$project->id}/attachments");

        // FASE 11.7 (PR 7b) — persistência 100% na camada Attachment.
        $attachment = app(\App\Attachments\AttachmentService::class)->registerExisting(auth()->user(), [
            'entity_type'   => 'PROJECT',
            'entity_id'     => $project->id,
            'category'      => self::mapAttachmentTypeToCategory($request->input('type')),
            'storage_path'  => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType() ?: 'application/octet-stream',
            'metadata'      => ['legacy_type' => $request->input('type')],
        ]);

        return response()->json($attachment, 201);
    }

    public function downloadAttachment(Project $project, \App\Models\Attachment $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // FASE 11.7 (PR 7b) — aceita anexos PROJECT direto OU CONTRACT do contrato vinculado.
        $isProjectOwn  = $attachment->entity_type === 'PROJECT'  && (int) $attachment->entity_id === (int) $project->id;
        $isLinkedCont  = $attachment->entity_type === 'CONTRACT' && $project->contract_id !== null
                         && (int) $attachment->entity_id === (int) $project->contract_id;
        abort_unless($isProjectOwn || $isLinkedCont, 404);
        abort_unless(Storage::exists($attachment->storage_path), 404, 'Arquivo não encontrado.');
        return Storage::download($attachment->storage_path, $attachment->original_name);
    }

    public function deleteAttachment(Project $project, \App\Models\Attachment $attachment): \Illuminate\Http\JsonResponse
    {
        // Só o próprio anexo do projeto pode ser dropado por aqui — anexo do
        // contrato vinculado é gerenciado em /contracts/{id}/attachments.
        abort_if(
            $attachment->entity_type !== 'PROJECT' || (int) $attachment->entity_id !== (int) $project->id,
            404
        );
        $attachment->delete(); // SoftDeletes
        return response()->json(null, 204);
    }

    public function toggleConsultantManualTimesheet(Request $request, Project $project, int $userId): JsonResponse
    {
        $currentUser = Auth::user();
        if (!$currentUser->isAdmin() && !$currentUser->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $linked = $project->consultants()->where('users.id', $userId)->exists();
        if (!$linked) {
            return response()->json(['message' => 'Consultor não vinculado ao projeto'], 422);
        }

        $data = $request->validate(['allow' => 'required|boolean']);

        $project->consultants()->updateExistingPivot($userId, [
            'allow_manual_timesheet' => $data['allow'],
        ]);

        return response()->json(['allow_manual_timesheet' => $data['allow']]);
    }

    // ─── Projetos reais por consultor (alocação em investimento) ──────────────

    /**
     * Sincroniza os projetos reais escolhidos por consultor neste projeto de
     * investimento. $map = [ user_id => [real_project_id, ...] ].
     * Só grava para consultores efetivamente alocados; só reais abertos do MESMO
     * cliente e que NÃO sejam de investimento.
     */
    private function syncConsultantRealProjects(Project $project, array $map): void
    {
        $consultantIds = $project->consultants()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        $validRealIds = Project::where('customer_id', $project->customer_id)
            ->where('id', '!=', $project->id)
            ->where(function ($q) {
                $q->where('is_investimento_comercial', false)->orWhereNull('is_investimento_comercial');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $rows = [];
        $now  = now();
        foreach ($map as $userId => $realIds) {
            $userId = (int) $userId;
            if (!in_array($userId, $consultantIds, true)) {
                continue;
            }
            foreach (array_unique(array_map('intval', (array) $realIds)) as $realId) {
                if (!in_array($realId, $validRealIds, true)) {
                    continue;
                }
                $rows[] = [
                    'project_id'      => $project->id,
                    'user_id'         => $userId,
                    'real_project_id' => $realId,
                    'company_id'      => $project->company_id,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
        }

        \DB::transaction(function () use ($project, $rows) {
            \DB::table('project_consultant_real_projects')
                ->where('project_id', $project->id)
                ->delete();
            if (!empty($rows)) {
                \DB::table('project_consultant_real_projects')->insert($rows);
            }
        });
    }

    /**
     * Para o modal de Alocação: candidatos a projeto real (todos os projetos
     * abertos do cliente, não-investimento) + o mapa atual de escolhas por consultor.
     * GET /projects/{project}/real-project-assignments
     */
    public function realProjectAssignments(Request $request, Project $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isCoordenador() && !$user->isAdministrativo()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $realProjects = Project::with('serviceType')
            ->where('customer_id', $project->customer_id)
            ->where('id', '!=', $project->id)
            ->where(function ($q) {
                $q->where('is_investimento_comercial', false)->orWhereNull('is_investimento_comercial');
            })
            ->open()
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id'                        => $p->id,
                'name'                      => $p->name,
                'service_type_code'         => $p->serviceType?->code,
                'is_investimento_comercial' => (bool) $p->is_investimento_comercial,
                'categoria_interna'         => $p->categoria_interna,
            ]);

        $assignments = [];
        if (Schema::hasTable('project_consultant_real_projects')) {
            $rows = \DB::table('project_consultant_real_projects')
                ->where('project_id', $project->id)
                ->get(['user_id', 'real_project_id']);
            foreach ($rows as $r) {
                $assignments[(string) $r->user_id][] = (int) $r->real_project_id;
            }
        }

        return response()->json([
            'real_projects' => $realProjects,
            'assignments'   => (object) $assignments,
        ]);
    }

    /**
     * Para o modal de Apontamento: os projetos reais escolhidos para ESTE
     * consultor neste projeto de investimento. Se não houver configuração,
     * cai no fallback: todos os reais abertos do cliente (não bloqueia o apontamento).
     * GET /projects/{project}/real-project-options?user_id=X
     */
    public function realProjectOptions(Request $request, Project $project): JsonResponse
    {
        $currentUser = Auth::user();
        $targetUserId = (int) ($request->get('user_id') ?: $currentUser->id);
        if ($targetUserId !== (int) $currentUser->id
            && !$currentUser->isAdmin() && !$currentUser->isCoordenador()) {
            $targetUserId = (int) $currentUser->id;
        }

        $realIds = Schema::hasTable('project_consultant_real_projects')
            ? \DB::table('project_consultant_real_projects')
                ->where('project_id', $project->id)
                ->where('user_id', $targetUserId)
                ->pluck('real_project_id')
                ->all()
            : [];

        $query = Project::with('serviceType')->where('id', '!=', $project->id)->open();

        if (!empty($realIds)) {
            $query->whereIn('id', $realIds);
        } else {
            $query->where('customer_id', $project->customer_id)
                ->where(function ($q) {
                    $q->where('is_investimento_comercial', false)->orWhereNull('is_investimento_comercial');
                });
        }

        $items = $query->orderBy('name')->get()->map(fn ($p) => [
            'id'                        => $p->id,
            'name'                      => $p->name,
            'service_type_code'         => $p->serviceType?->code,
            'is_investimento_comercial' => (bool) $p->is_investimento_comercial,
            'categoria_interna'         => $p->categoria_interna,
        ]);

        return response()->json(['items' => $items]);
    }

    /**
     * Alocação de um projeto de INVESTIMENTO (consultores + projetos reais por consultor).
     * Endpoint dedicado e escopado por `projects.assign_consultants` — assim o
     * COORDENADOR (que não tem projects.update) também aloca nesta rotina, sem ganhar
     * poder de editar os demais campos do projeto.
     * PATCH /projects/{project}/investment-allocation
     */
    public function updateInvestmentAllocation(Request $request, Project $project): JsonResponse
    {
        if (!$project->is_investimento_comercial) {
            return response()->json(['message' => 'Alocação de investimento só se aplica a projetos de investimento.'], 422);
        }

        $data = $request->validate([
            'consultant_ids'                  => 'nullable|array',
            'consultant_ids.*'                => 'exists:users,id',
            'real_projects_by_consultant'     => 'nullable|array',
            'real_projects_by_consultant.*'   => 'array',
            'real_projects_by_consultant.*.*' => 'integer|exists:projects,id',
        ]);

        \DB::transaction(function () use ($project, $data) {
            if (array_key_exists('consultant_ids', $data)) {
                $project->consultants()->sync($data['consultant_ids'] ?? []);
            }
            if (array_key_exists('real_projects_by_consultant', $data)
                && Schema::hasTable('project_consultant_real_projects')) {
                $this->syncConsultantRealProjects($project, $data['real_projects_by_consultant'] ?? []);
            }
        });

        return response()->json(['message' => 'Alocação atualizada.']);
    }

    // ─── Períodos abertos por projeto ────────────────────────────────────────

    public function openPeriod(Request $request, Project $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $data = $request->validate(['year_month' => 'required|string|regex:/^\d{4}-\d{2}$/']);

        $mesAtual = \Carbon\Carbon::now()->startOfMonth();
        $mesSolicitado = \Carbon\Carbon::createFromFormat('Y-m', $data['year_month'])->startOfMonth();
        if ($mesSolicitado->greaterThanOrEqualTo($mesAtual)) {
            return response()->json(['message' => 'Só é possível abrir meses anteriores ao mês atual.'], 422);
        }

        $period = \App\Models\ProjectOpenPeriod::updateOrCreate(
            ['project_id' => $project->id, 'year_month' => $data['year_month']],
            ['opened_by' => $user->id, 'closed_by' => null, 'closed_at' => null]
        );

        return response()->json(['data' => $period], 201);
    }

    public function closePeriods(Request $request, Project $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $mesAtual = \Carbon\Carbon::now()->startOfMonth()->format('Y-m');

        $count = \App\Models\ProjectOpenPeriod::where('project_id', $project->id)
            ->whereNull('closed_at')
            ->where('year_month', '<', $mesAtual)
            ->update(['closed_at' => now(), 'closed_by' => $user->id]);

        return response()->json(['message' => "{$count} período(s) fechado(s).", 'count' => $count]);
    }

    public function listOpenPeriods(Project $project): JsonResponse
    {
        $periods = \App\Models\ProjectOpenPeriod::where('project_id', $project->id)
            ->whereNull('closed_at')
            ->orderBy('year_month')
            ->get(['id', 'year_month', 'opened_by', 'created_at']);

        return response()->json(['data' => $periods]);
    }

    /**
     * Lista TODOS os períodos de projeto abertos (closed_at = null) de todos os projetos.
     * Usado na visão de Configurações para o admin ver e fechar em lote.
     */
    public function allOpenPeriods(): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $periods = \App\Models\ProjectOpenPeriod::whereNull('closed_at')
            ->with(['project:id,code,name,customer_id', 'project.customer:id,name', 'openedBy:id,name'])
            ->orderBy('year_month')
            ->orderBy('project_id')
            ->get(['id', 'project_id', 'year_month', 'opened_by', 'created_at']);

        $data = $periods->map(fn ($p) => [
            'id'           => $p->id,
            'project_id'   => $p->project_id,
            'project_code' => $p->project?->code,
            'project_name' => $p->project?->name,
            'cliente'      => $p->project?->customer?->name,
            'year_month'   => $p->year_month,
            'opened_by'    => $p->openedBy?->name,
            'created_at'   => $p->created_at,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Fecha em lote TODOS os períodos de projeto abertos de competências anteriores à
     * vigente (o mês atual nunca é fechado). Mesma regra do closePeriods, sem projeto fixo.
     */
    public function closeAllOpenPeriods(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $mesAtual = \Carbon\Carbon::now()->startOfMonth()->format('Y-m');

        $count = \App\Models\ProjectOpenPeriod::whereNull('closed_at')
            ->where('year_month', '<', $mesAtual)
            ->update(['closed_at' => now(), 'closed_by' => $user->id]);

        return response()->json(['message' => "{$count} período(s) fechado(s).", 'count' => $count]);
    }

    /**
     * Fecha UM período de projeto específico (uma linha da visão de períodos abertos).
     * O mês atual nunca é fechado.
     */
    public function closeOnePeriod(Request $request, \App\Models\ProjectOpenPeriod $period): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isAdministrativo() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Acesso negado'], 403);
        }

        $mesAtual = \Carbon\Carbon::now()->startOfMonth()->format('Y-m');
        if ($period->year_month >= $mesAtual) {
            return response()->json(['message' => 'O mês atual não pode ser fechado.'], 422);
        }

        if ($period->closed_at === null) {
            $period->update(['closed_at' => now(), 'closed_by' => $user->id]);
        }

        return response()->json(['message' => 'Período fechado.', 'id' => $period->id]);
    }

    /**
     * Sincroniza o card do contract no Kanban quando o `kanban_coordinator_override_id`
     * do projeto é setado ou limpo.
     *
     * - Setando o override → contract muda pra coluna do coord (alocado + kanban_coordinator_id)
     *   e zera sustentacao_column. Card sai das colunas sust_* do Kanban.
     * - Limpando o override → recalcula sustentacao_column pelo tipo de contrato e devolve
     *   o card pra fila correta. Zera kanban_coordinator_id e kanban_status.
     */
    private function syncContractKanbanForOverride(Project $project): void
    {
        $project->refresh();

        // Busca contract pelos dois lados da relação (alguns vêm com contract.project_id setado,
        // outros vêm com project.contract_id apontando pro contract).
        $contract = null;
        if ($project->contract_id) {
            $contract = \App\Models\Contract::find($project->contract_id);
        }
        if (!$contract) {
            $contract = \App\Models\Contract::where('project_id', $project->id)->first();
        }
        if (!$contract) return;

        $fromColumn = $contract->kanban_status ?: ($contract->sustentacao_column ?: null);
        $overrideId = $project->kanban_coordinator_override_id;

        if ($overrideId) {
            $expected = [
                'kanban_status'         => \App\Models\Contract::KANBAN_ALOCADO,
                'kanban_coordinator_id' => (int) $overrideId,
                'sustentacao_column'    => null,
            ];
            $needsUpdate = $contract->kanban_status !== $expected['kanban_status']
                || (int) $contract->kanban_coordinator_id !== $expected['kanban_coordinator_id']
                || $contract->sustentacao_column !== null;
            if ($needsUpdate) {
                $contract->update($expected);
                \App\Models\ContractKanbanLog::create([
                    'contract_id'    => $contract->id,
                    'from_column'    => $fromColumn,
                    'to_column'      => 'coordinator:' . $overrideId,
                    'moved_by_id'    => auth()->id(),
                    'coordinator_id' => $overrideId,
                ]);
            }
            return;
        }

        // Sem override → devolve pra fila de sustentação correta (recalcula pelo tipo de contrato).
        $project->loadMissing('contractType');
        $contractName = strtolower(trim((string) ($project->contractType?->name ?? '')));
        $sustColumn = null;
        if (str_contains($contractName, 'banco de horas fixo') || str_contains($contractName, 'banco horas fixo')) {
            $sustColumn = 'sust_bh_fixo';
        } elseif (str_contains($contractName, 'banco de horas mensal') || str_contains($contractName, 'banco horas mensal')) {
            $sustColumn = 'sust_bh_mensal';
        } elseif (str_contains($contractName, 'on demand')) {
            $sustColumn = 'sust_on_demand';
        } elseif (str_contains($contractName, 'cloud')) {
            $sustColumn = 'sust_cloud';
        }

        $expectedStatus = $contract->kanban_status === \App\Models\Contract::KANBAN_ALOCADO ? null : $contract->kanban_status;
        $needsUpdate = $contract->kanban_status !== $expectedStatus
            || $contract->kanban_coordinator_id !== null
            || $contract->sustentacao_column !== $sustColumn;
        if ($needsUpdate) {
            $contract->update([
                'kanban_status'         => $expectedStatus,
                'kanban_coordinator_id' => null,
                'sustentacao_column'    => $sustColumn,
            ]);
            \App\Models\ContractKanbanLog::create([
                'contract_id'    => $contract->id,
                'from_column'    => $fromColumn,
                'to_column'      => $sustColumn ?? 'sustentacao_default',
                'moved_by_id'    => auth()->id(),
                'coordinator_id' => null,
            ]);
        }
    }

    /**
     * Extrato mensal do banco de horas de um projeto Banco de Horas Mensal:
     * incremento, acumulado, consumo (mensal) e saldo.
     *
     * Consumo de meses anteriores ao corte (self::MONTHLY_CONSUMPTION_CUTOFF) é
     * manual/editável e persistido em project_monthly_consumptions; de 2026-05
     * em diante vem dos apontamentos (timesheets approved/pending).
     */
    public function monthlyStatement(Project $project): JsonResponse
    {
        $cutoff = self::MONTHLY_CONSUMPTION_CUTOFF;
        $hoursPerMonth = (int) $project->sold_hours;

        // Tipo do extrato pelo tipo de contrato:
        //  • monthly  (BH Mensal): vendidas acumulam mês a mês.
        //  • fixed    (BH Fixo / Fechado): vendidas = total fixo constante.
        //  • on_demand: sem vendidas/saldo — só consumo.
        $project->loadMissing(['contractType', 'hourContributions', 'soldHoursHistory']);
        $ctName = strtolower((string) ($project->contractType->name ?? ''));
        $isOnDemand = str_contains($ctName, 'on demand') || $project->tipo_faturamento === 'on_demand';
        $isMonthly  = !$isOnDemand && str_contains($ctName, 'mensal');
        $isFixed    = !$isOnDemand && !$isMonthly && (str_contains($ctName, 'fixo') || $ctName === 'fechado');
        $statementType = $isOnDemand ? 'on_demand' : ($isMonthly ? 'monthly' : ($isFixed ? 'fixed' : 'none'));

        $empty = [
            'statement_type' => $statementType,
            'rows' => [],
            'total_vendidas_hours' => null,
            'total_consumption_hours' => 0,
            'balance_hours' => null,
            'cutoff' => $cutoff,
        ];

        // Mensalidade (Cloud/SaaS) não tem extrato de horas.
        if ($statementType === 'none') {
            return response()->json($empty);
        }
        // Banco Mensal e Fixo/Fechado precisam de horas vendidas > 0; On Demand não.
        if (($isMonthly || $isFixed) && $hoursPerMonth <= 0) {
            return response()->json($empty);
        }

        // Mês inicial: start_date; se vazio, cai pro 1º apontamento; senão created_at.
        $startStr = $project->start_date
            ? Carbon::parse($project->start_date)->format('Y-m-d')
            : (\App\Models\Timesheet::where('project_id', $project->id)
                    ->whereIn('status', ['approved', 'pending'])->min('date')
                ?? optional($project->created_at)->format('Y-m-d'));
        if (!$startStr) {
            return response()->json($empty);
        }
        $startDate = Carbon::parse($startStr)->startOfMonth();

        // Nº de meses: do início até o encerramento (ou mês atual se ativo). Para BH
        // Mensal cada mês incrementa o valor VIGENTE naquele mês (soldHoursForCompetencia),
        // então o total não depende mais do accumulated_sold_hours (que podia ficar stale e
        // não respeitava a vigência — meses anteriores apareciam com a quantidade nova).
        $endDate = $project->encerramento_date
            ? Carbon::parse($project->encerramento_date)->startOfMonth()
            : Carbon::now()->startOfMonth();
        if ($endDate->lt($startDate)) {
            $endDate = $startDate->copy();
        }
        $months = $startDate->diffInMonths($endDate) + 1;

        if ($months < 1) {
            return response()->json($empty);
        }

        // Filhos: os que têm horas vendidas (>0) são BLOCOS que saem do banco do
        // pai — fazem "carve-out" no extrato do pai no mês de início do filho (e seus
        // apontamentos ficam no extrato do próprio filho, não somam de novo aqui).
        // Filhos sem horas vendidas (sold=0) são extensões que compartilham o banco —
        // seus apontamentos continuam somando no consumo do pai.
        $children       = Project::where('parent_project_id', $project->id)->get(['id', 'sold_hours', 'start_date']);
        $blockChildren  = $children->where('sold_hours', '>', 0);
        $sharedChildIds = $children->where('sold_hours', '<=', 0)->pluck('id')->all();
        $bankIds        = array_merge([$project->id], $sharedChildIds);

        // Carve-out dos filhos-bloco por mês de início (clampado à janela do extrato
        // pra nunca perder o bloco se o filho começar antes/depois da janela).
        $firstYm = $startDate->format('Y-m');
        $lastYm  = $startDate->copy()->addMonths($months - 1)->format('Y-m');
        $childBlockByYm = [];
        foreach ($blockChildren as $child) {
            $cs = $child->start_date ? Carbon::parse($child->start_date)->format('Y-m') : $firstYm;
            if ($cs < $firstYm) { $cs = $firstYm; }
            if ($cs > $lastYm)  { $cs = $lastYm; }
            $childBlockByYm[$cs] = ($childBlockByYm[$cs] ?? 0) + (float) $child->sold_hours;
        }

        // Consumo real (apontamentos) por mês, status approved/pending.
        $realMap = \App\Models\Timesheet::query()
            ->whereIn('project_id', $bankIds)
            ->whereIn('status', ['approved', 'pending'])
            ->select(
                DB::raw("to_char(date, 'YYYY-MM') as ym"),
                DB::raw('SUM(effort_minutes) as mins')
            )
            ->groupBy('ym')
            ->pluck('mins', 'ym')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Consumo manual persistido (apenas do projeto pai).
        $manualMap = ProjectMonthlyConsumption::where('project_id', $project->id)
            ->pluck('consumed_minutes', 'year_month')
            ->map(fn ($v) => (int) $v)
            ->all();

        // Aportes (hour_contributions) por mês — entram nas "vendidas" no mês aportado.
        $aporteByYm = [];
        foreach ($project->hourContributions as $c) {
            if (!$c->contributed_at) { continue; }
            $aym = Carbon::parse($c->contributed_at)->format('Y-m');
            $aporteByYm[$aym] = ($aporteByYm[$aym] ?? 0) + (float) $c->contributed_hours;
        }
        $totalAportes = array_sum($aporteByYm);
        $lastIndex = $months - 1;
        $prevAccumAporte = 0.0;

        $rows = [];
        $accumulatedHours = 0.0;
        $accumulatedConsumptionHours = 0.0;
        $balanceHours = 0.0;

        $cursor = $startDate->copy();
        for ($i = 0; $i < $months; $i++) {
            $ym = $cursor->format('Y-m');

            // Vendidas exibidas: Mensal acumula (incremento/mês); Fixo/Fechado é o
            // total fixo constante; On Demand não tem vendidas.
            // Aportes acumulados ATÉ este mês (o último mês absorve aportes futuros p/ fechar o total).
            $accumAporte = $i === $lastIndex
                ? $totalAportes
                : array_sum(array_filter($aporteByYm, fn ($k) => $k <= $ym, ARRAY_FILTER_USE_KEY));
            // Aporte desta linha (incremento do acumulado) — exibido como "+Xh aporte" na tela.
            $monthAporte = round($accumAporte - $prevAccumAporte, 2);
            $prevAccumAporte = $accumAporte;

            $monthlyHours = 0.0;
            if ($isMonthly) {
                // Incremento do mês = horas vendidas VIGENTES nessa competência (não o
                // sold_hours atual) — preserva os meses anteriores ao alterar a quantidade.
                $monthlyHours = $project->soldHoursForCompetencia($ym);
                $accumulatedHours += $monthlyHours;
                $vendidasHours = $accumulatedHours + $accumAporte;
            } elseif ($isFixed) {
                $vendidasHours = (float) $hoursPerMonth + $accumAporte;
            } else {
                $vendidasHours = null; // on_demand
            }

            $editable = $ym < $cutoff;
            $consumptionMinutes = $editable
                ? ($manualMap[$ym] ?? 0)
                : ($realMap[$ym] ?? 0);
            // consumption_hours = apontamentos/manual do próprio pai (editável).
            // child_block_hours = blocos de filhos que iniciam neste mês (carve-out).
            // O saldo considera os dois; o input editável mexe só no manual.
            $consumptionHours = round($consumptionMinutes / 60, 2);
            $childBlock = round($childBlockByYm[$ym] ?? 0, 2);
            $accumulatedConsumptionHours += $consumptionHours + $childBlock;

            $balanceHours = $vendidasHours === null
                ? null
                : round($vendidasHours - $accumulatedConsumptionHours, 2);

            $rows[] = [
                'year_month' => $ym,
                'vendidas_hours' => $vendidasHours,
                'monthly_hours' => $isMonthly ? round($monthlyHours, 2) : null,
                'aporte_hours' => $monthAporte,
                'consumption_hours' => $consumptionHours,
                'child_block_hours' => $childBlock,
                'accumulated_consumption_hours' => round($accumulatedConsumptionHours, 2),
                'balance_hours' => $balanceHours,
                'editable' => $editable,
            ];

            $cursor->addMonth();
        }

        $totalVendidas = $isMonthly ? ($accumulatedHours + $totalAportes) : ($isFixed ? ((float) $hoursPerMonth + $totalAportes) : null);

        return response()->json([
            'statement_type' => $statementType,
            'rows' => $rows,
            'total_vendidas_hours' => $totalVendidas,
            'total_consumption_hours' => round($accumulatedConsumptionHours, 2),
            'balance_hours' => $isOnDemand ? null : $balanceHours,
            'cutoff' => $cutoff,
        ]);
    }

    /**
     * Atualiza (upsert) o consumo manual de um mês anterior ao corte.
     * Apenas admin/coordenador. Meses >= corte não são editáveis (vêm do sistema).
     */
    public function updateMonthlyConsumption(Request $request, Project $project): JsonResponse
    {
        $user = Auth::user();
        if (!$user->isAdmin() && !$user->isCoordenador()) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $validated = $request->validate([
            'year_month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'hours' => ['required', 'numeric', 'min:0', 'max:999999'],
        ]);

        $ym = $validated['year_month'];

        if ($ym >= self::MONTHLY_CONSUMPTION_CUTOFF) {
            return response()->json([
                'message' => 'Consumo de 05/2026 em diante vem do sistema e não é editável.',
            ], 422);
        }

        $minutes = (int) round($validated['hours'] * 60);

        ProjectMonthlyConsumption::updateOrCreate(
            ['project_id' => $project->id, 'year_month' => $ym],
            ['consumed_minutes' => $minutes]
        );

        return response()->json([
            'success' => true,
            'year_month' => $ym,
            'consumption_hours' => round($minutes / 60, 2),
        ]);
    }
}
