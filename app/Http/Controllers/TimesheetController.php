<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use App\Models\Project;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Http\Traits\ResponseHelpers;
use App\Exports\TimesheetsExport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @OA\Tag(
 *     name="Timesheets",
 *     description="API endpoints para gerenciamento de apontamentos de horas"
 * )
 */

/**
 * @OA\Schema(
 *     schema="Timesheet",
 *     type="object",
 *     title="Timesheet",
 *     description="Modelo de apontamento de horas",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="customer_id", type="integer", example=1),
 *     @OA\Property(property="project_id", type="integer", example=1),
 *     @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
 *     @OA\Property(property="start_time", type="string", format="time", example="09:00"),
 *     @OA\Property(property="end_time", type="string", format="time", example="17:00"),
 *     @OA\Property(property="effort_minutes", type="integer", example=480),
     *     @OA\Property(property="effort_hours", type="string", example="8:00"),
     *     @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *     @OA\Property(property="ticket", type="string", example="TICKET-123"),
     *     @OA\Property(property="origin", type="string", nullable=true, example="webhook", description="Origem do apontamento: 'webhook' para automação do Movidesk, null para criação manual"),
     *     @OA\Property(property="status", type="string", enum={"pending", "approved", "rejected"}, example="pending"),
 *     @OA\Property(property="status_display", type="string", example="Pendente"),
 *     @OA\Property(property="rejection_reason", type="string", nullable=true, example="Horário incompatível"),
 *     @OA\Property(property="reviewed_by", type="integer", nullable=true, example=2),
 *     @OA\Property(property="reviewed_at", type="string", format="datetime", nullable=true, example="2024-01-16T10:30:00Z"),
 *     @OA\Property(property="created_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(property="updated_at", type="string", format="datetime", example="2024-01-15T09:00:00Z"),
 *     @OA\Property(
 *         property="user",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="João Silva"),
 *         @OA\Property(property="email", type="string", example="joao@empresa.com")
 *     ),
 *     @OA\Property(
 *         property="customer",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Cliente ABC")
 *     ),
 *     @OA\Property(
 *         property="project",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Projeto XYZ"),
 *         @OA\Property(property="code", type="string", example="PROJ-001")
 *     )
 * )
 */
class TimesheetController extends Controller
{
    use ResponseHelpers;

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets",
     *     summary="Listar apontamentos de horas",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="Filtrar por projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="Filtrar por cliente",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data de início do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data de fim do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="ticket",
     *         in="query",
     *         description="Filtrar por número do ticket",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="service_type_id",
     *         in="query",
     *         description="Filtrar por tipo de serviço",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de timesheets",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Timesheet"))
     *         )
     *     )
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Paginação PO-UI
        $perPage = min($request->get('pageSize', 15), 100);
        $page = (int) $request->get('page', 1);

        $query = Timesheet::with(['user', 'customer', 'project', 'reviewedBy'])
            ->select('timesheets.*', 'movidesk_tickets.titulo as ticket_subject')
            ->leftJoin('movidesk_tickets', 'movidesk_tickets.ticket_id', '=', 'timesheets.ticket');

        // Se não é admin nem tem permissão para ver todos, só pode ver os próprios
        if (!$user->hasRole('Administrator') && !$user->can('hours.view_all')) {
            $query->forUser($user->id);
        }

        // Filtros PO-UI
        if ($request->filled('project_id')) {
            $query->forProject($request->project_id);
        }

        if ($request->filled('customer_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('customer_id', $request->customer_id);
            });
        }

        if ($request->filled('user_id') && ($user->hasRole('Administrator') || $user->can('hours.view_all'))) {
            $query->forUser($request->user_id);
        }

        if ($request->filled('status')) {
            $query->withStatus($request->status);
        }

        if ($request->filled('ticket')) {
            $query->where('ticket', 'like', "%{$request->ticket}%");
        }

        if ($request->filled('service_type_id')) {
            $query->whereHas('project', function ($q) use ($request) {
                $q->where('service_type_id', $request->service_type_id);
            });
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->inPeriod($request->start_date, $request->end_date);
        }

        // Busca geral
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('observation', 'like', "%{$search}%")
                  ->orWhere('ticket', 'like', "%{$search}%")
                  ->orWhereHas('project', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Ordenação PO-UI (com suporte a relacionamentos)
        if ($request->has('order')) {
            $orderFields = explode(',', $request->get('order'));

            // Flags de join para evitar joins duplicados
            $needsUserJoin = false;
            $needsProjectJoin = false;
            $needsCustomerJoin = false;

            $relationOrders = [];
            $scalarOrders = [];

            foreach ($orderFields as $field) {
                $direction = 'asc';

                if (str_starts_with($field, '-')) {
                    $direction = 'desc';
                    $field = substr($field, 1);
                }

                // Mapear campos calculados/virtuais para colunas reais
                if ($field === 'effort_hours') {
                    $field = 'effort_minutes';
                }

                // Ordenação por relacionamentos, ex: user.name, project.name, customer.name
                if (str_contains($field, '.')) {
                    [$relation, $column] = explode('.', $field, 2);

                    switch ($relation) {
                        case 'user':
                            $needsUserJoin = true;
                            $relationOrders[] = [
                                'table' => 'users',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        case 'project':
                            $needsProjectJoin = true;
                            $relationOrders[] = [
                                'table' => 'projects',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        case 'customer':
                            $needsCustomerJoin = true;
                            $relationOrders[] = [
                                'table' => 'customers',
                                'column' => $column,
                                'direction' => $direction,
                            ];
                            break;

                        default:
                            // Relacionamento não suportado: ignorar silenciosamente
                            break;
                    }
                } else {
                    // Campos da própria tabela timesheets
                    $scalarOrders[] = [
                        'column' => $field,
                        'direction' => $direction,
                    ];
                }
            }

            // Aplicar joins necessários para ordenação por relacionamentos
            if ($needsUserJoin) {
                $query->leftJoin('users', 'users.id', '=', 'timesheets.user_id');
            }

            if ($needsProjectJoin) {
                $query->leftJoin('projects', 'projects.id', '=', 'timesheets.project_id');
            }

            if ($needsCustomerJoin) {
                $query->leftJoin('customers', 'customers.id', '=', 'timesheets.customer_id');
            }

            // Ordenação por campos da própria tabela
            foreach ($scalarOrders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }

            // Ordenação por campos de relacionamentos
            foreach ($relationOrders as $order) {
                $query->orderBy($order['table'] . '.' . $order['column'], $order['direction']);
            }
        } else {
            $query->orderBy('date', 'desc')->orderBy('start_time', 'desc');
        }

        // Calcular total de horas (sem considerar paginação)
        $totalEffortMinutes = (clone $query)->sum('effort_minutes');
        $totalEffortMinutes = (int) $totalEffortMinutes;

        $totalHours = intdiv($totalEffortMinutes, 60);
        $totalMinutes = $totalEffortMinutes % 60;
        $totalEffortHours = sprintf('%d:%02d', $totalHours, $totalMinutes);

        // Paginação PO-UI (após calcular o total geral)
        $timesheets = $query->paginate($perPage, ['*'], 'page', $page);

        // Resposta PO-UI
        return response()->json([
            'hasNext' => $timesheets->hasMorePages(),
            'items' => $timesheets->items(),
            'totalEffortMinutes' => $totalEffortMinutes,
            'totalEffortHours' => $totalEffortHours,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets",
     *     summary="Criar novo apontamento de horas",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"project_id", "date", "start_time", "end_time"},
     *             @OA\Property(property="project_id", type="integer", example=1),
     *             @OA\Property(property="user_id", type="integer", example=2, description="ID do usuário (apenas para administradores)"),
     *             @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="start_time", type="string", format="time", example="09:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00"),
     *             @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *             @OA\Property(property="ticket", type="string", example="TICKET-123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Timesheet criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento criado com sucesso!")
     *         )
     *     )
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Definir regras de validação base
        $rules = [
            'project_id' => 'required|exists:projects,id',
            'date' => 'required|date|before_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'total_hours' => 'nullable|string|regex:/^\d+:[0-5][0-9]$/',
            'observation' => 'nullable|string|max:5000',
            'ticket' => 'nullable',
        ];

        // Se é administrador, pode especificar user_id
        if ($user->hasRole('Administrator') || $user->can('admin.full_access')) {
            $rules['user_id'] = 'nullable|exists:users,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'code' => 'VALIDATION_FAILED',
                'type' => 'error',
                'message' => 'Dados de validação inválidos',
                'detailMessage' => 'Um ou mais campos contêm valores inválidos',
                'details' => $validator->errors()->all()
            ], 422);
        }

        // Determinar o usuário do apontamento
        $timesheetUserId = Auth::id(); // Padrão: usuário logado

        // Se é admin e especificou user_id, usar o usuário especificado
        if (($user->hasRole('Administrator') || $user->can('admin.full_access')) && $request->filled('user_id')) {
            $timesheetUserId = $request->user_id;

            // Verificar se o usuário especificado existe e está ativo
            $targetUser = User::find($timesheetUserId);
            if (!$targetUser || !$targetUser->enabled) {
                return response()->json([
                    'code' => 'INVALID_USER',
                    'type' => 'error',
                    'message' => 'Usuário inválido',
                    'detailMessage' => 'O usuário especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Verificar se o projeto existe e está ativo
        $project = Project::find($request->project_id);
        if (!$project || !$project->isActive()) {
            return response()->json([
                'code' => 'INACTIVE_PROJECT',
                'type' => 'error',
                'message' => 'Projeto inativo ou não encontrado',
                'detailMessage' => 'Não é possível apontar horas em projetos inativos ou inexistentes'
            ], 422);
        }

        // Verificar se o projeto permite apontamentos manuais (exceto para administradores)
        if (!$user->hasRole('Administrator') && !$user->can('admin.full_access')) {
            if (!$project->allow_manual_timesheets) {
                return response()->json([
                    'code' => 'MANUAL_TIMESHEETS_NOT_ALLOWED',
                    'type' => 'error',
                    'message' => 'Apontamentos manuais não permitidos',
                    'detailMessage' => 'Este projeto não permite criação de apontamentos pelo Minutor. Apenas o webhook do Movidesk pode criar apontamentos para este projeto.'
                ], 422);
            }
        }

        // Verificar prazo limite para lançamento retroativo de horas
        $serviceDate = \Carbon\Carbon::parse($request->date);
        if (!$project->isWithinTimesheetDeadline($serviceDate)) {
            $limitDays = $project->getTimesheetRetroactiveLimitDays();
            $deadlineDate = $project->getTimesheetDeadline($serviceDate);

            $source = $project->timesheet_retroactive_limit_days !== null
                ? 'configurado para este projeto'
                : 'configuração global do sistema';

            return response()->json([
                'code' => 'TIMESHEET_DEADLINE_EXPIRED',
                'type' => 'error',
                'message' => 'Prazo expirado para lançamento de horas',
                'detailMessage' => sprintf(
                    'O prazo limite para lançamento de horas deste serviço expirou. ' .
                    'Serviço realizado em %s, prazo limite era %s (limite de %d %s %s).',
                    $serviceDate->format('d/m/Y'),
                    $deadlineDate->format('d/m/Y'),
                    $limitDays,
                    $limitDays === 1 ? 'dia' : 'dias',
                    $source
                ),
                'details' => [
                    'service_date' => $serviceDate->format('Y-m-d'),
                    'deadline_date' => $deadlineDate->format('Y-m-d'),
                    'limit_days' => $limitDays,
                    'source' => $source
                ]
            ], 422);
        }

        // Verificar se já existe um apontamento duplicado para o mesmo usuário, data, projeto e horário
        $existingTimesheet = Timesheet::where('user_id', $timesheetUserId)
            ->where('project_id', $request->project_id)
            ->where('date', $request->date)
            ->where('start_time', $request->start_time)
            ->where('end_time', $request->end_time)
            ->where('status', '!=', Timesheet::STATUS_REJECTED) // Ignorar apontamentos rejeitados
            ->first();

        if ($existingTimesheet) {
            $userName = $timesheetUserId === Auth::id() ? 'você' : User::find($timesheetUserId)->name;
            return response()->json([
                'code' => 'DUPLICATE_TIMESHEET',
                'type' => 'error',
                'message' => 'Apontamento duplicado',
                'detailMessage' => 'Já existe um apontamento para ' . $userName . ' neste projeto na mesma data e horário.',
                'details' => [
                    'Já existe um apontamento para o projeto "' . $project->name . '" no dia ' .
                    \Carbon\Carbon::parse($request->date)->format('d/m/Y') .
                    ' das ' . $request->start_time . ' às ' . $request->end_time
                ]
            ], 422);
        }

        // Verificar se há sobreposição de horários no mesmo dia e projeto
        $overlappingTimesheet = Timesheet::where('user_id', $timesheetUserId)
            ->where('project_id', $request->project_id)
            ->where('date', $request->date)
            ->where('status', '!=', Timesheet::STATUS_REJECTED) // Ignorar apontamentos rejeitados
            ->where(function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    // Verificar se o novo horário se sobrepõe a um horário existente
                    $q->where('start_time', '<', $request->end_time)
                      ->where('end_time', '>', $request->start_time);
                });
            })
            ->first();

        if ($overlappingTimesheet) {
            $userName = $timesheetUserId === Auth::id() ? 'você' : User::find($timesheetUserId)->name;
            return response()->json([
                'code' => 'OVERLAPPING_TIMESHEET',
                'type' => 'error',
                'message' => 'Sobreposição de horários',
                'detailMessage' => 'Existe sobreposição de horários com outro apontamento de ' . $userName . ' no mesmo dia e projeto.',
                'details' => [
                    'Há sobreposição com o apontamento das ' . $overlappingTimesheet->start_time .
                    ' às ' . $overlappingTimesheet->end_time . ' no projeto "' . $project->name . '"'
                ]
            ], 422);
        }

        DB::beginTransaction();
        try {
            $validatedData = $validator->validated();

            // Processar total_hours se fornecido
            $effortMinutes = null;
            if (!empty($validatedData['total_hours'])) {
                // Converter total_hours para effort_minutes antes de criar o timesheet
                if (preg_match('/^(\d+):([0-5][0-9])$/', $validatedData['total_hours'], $matches)) {
                    $hours = intval($matches[1]);
                    $minutes = intval($matches[2]);
                    $effortMinutes = ($hours * 60) + $minutes;
                    $validatedData['effort_minutes'] = $effortMinutes;
                }
                // Remover total_hours dos dados validados pois não é um campo do modelo
                unset($validatedData['total_hours']);
            } else {
                // Se não forneceu total_hours, calcular a partir de start_time e end_time
                // O modelo Timesheet calcula automaticamente no boot, mas precisamos calcular aqui para validação
                if (isset($validatedData['start_time']) && isset($validatedData['end_time'])) {
                    $startTime = \Carbon\Carbon::parse($validatedData['start_time']);
                    $endTime = \Carbon\Carbon::parse($validatedData['end_time']);

                    // Se o horário final for menor que o inicial, assumir que passou da meia-noite
                    if ($endTime->lt($startTime)) {
                        $endTime->addDay();
                    }

                    $effortMinutes = $startTime->diffInMinutes($endTime);
                }
            }

            // Calcular horas decimais para validação
            $hoursToAdd = $effortMinutes ? round($effortMinutes / 60, 2) : 0;

            // Verificar se o projeto é do tipo de contrato On Demand
            $project->loadMissing('contractType');
            $isOnDemandContract = $project->contractType && $project->contractType->code === 'on_demand';

            Log::info('Criando apontamento - Antes de validar saldo', [
                'project_id' => $project->id,
                'user_id' => $timesheetUserId,
                'effort_minutes' => $effortMinutes,
                'hours_to_add' => $hoursToAdd,
                'date' => $validatedData['date'] ?? null,
                'start_time' => $validatedData['start_time'] ?? null,
                'end_time' => $validatedData['end_time'] ?? null,
                'contract_type_code' => $project->contractType->code ?? null,
                'is_on_demand_contract' => $isOnDemandContract,
            ]);

            // Para projetos On Demand, não bloquear criação por saldo de horas
            if (!$isOnDemandContract) {
                // Validar saldo de horas antes de salvar
                $balanceValidation = $this->validateHoursBalance($project, $timesheetUserId, $hoursToAdd, null);
                if ($balanceValidation) {
                    Log::warning('Criação de apontamento bloqueada - Saldo insuficiente', [
                        'project_id' => $project->id,
                        'user_id' => $timesheetUserId,
                        'hours_to_add' => $hoursToAdd,
                    ]);
                    DB::rollBack();
                    return $balanceValidation;
                }

                Log::info('Validação de saldo aprovada - Prosseguindo com criação do apontamento', [
                    'project_id' => $project->id,
                    'user_id' => $timesheetUserId,
                    'hours_to_add' => $hoursToAdd,
                ]);
            } else {
                Log::info('Projeto On Demand - Pulando validação de saldo de horas para criação de apontamento', [
                    'project_id' => $project->id,
                    'user_id' => $timesheetUserId,
                    'hours_to_add' => $hoursToAdd,
                ]);
            }

            $timesheet = new Timesheet($validatedData);
            $timesheet->user_id = $timesheetUserId;
            $timesheet->customer_id = $project->customer_id;
            $timesheet->status = Timesheet::STATUS_PENDING;
            $timesheet->origin = 'web'; // Origem: criação manual via webapp

            $timesheet->save();

            DB::commit();

            $timesheet->load(['user', 'customer', 'project']);

            Log::info('Apontamento criado com sucesso', [
                'timesheet_id' => $timesheet->id,
                'project_id' => $timesheet->project_id,
                'user_id' => $timesheet->user_id,
                'effort_minutes' => $timesheet->effort_minutes,
                'effort_hours' => round($timesheet->effort_minutes / 60, 2),
                'date' => $timesheet->date,
                'status' => $timesheet->status,
            ]);

            $message = $timesheetUserId === Auth::id()
                ? 'Apontamento criado com sucesso!'
                : 'Apontamento criado com sucesso para ' . $timesheet->user->name . '!';

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => $message
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar apontamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Visualizar apontamento específico",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do timesheet",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet")
     *         )
     *     )
     * )
     */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::with(['user', 'customer', 'project.approvers', 'reviewedBy', 'reversals.reversedBy', 'reversals.originalApprover'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        // Verificar se pode visualizar este timesheet
        if (!$user->hasRole('Administrator') && !$user->can('hours.view_all') && $timesheet->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $timesheet
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Atualizar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="integer", example=1, description="ID do usuário (apenas para administradores)"),
     *             @OA\Property(property="date", type="string", format="date", example="2024-01-15"),
     *             @OA\Property(property="start_time", type="string", format="time", example="09:00"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00"),
     *             @OA\Property(property="observation", type="string", example="Desenvolvimento de funcionalidade X"),
     *             @OA\Property(property="ticket", type="string", example="TICKET-123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet atualizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento atualizado com sucesso!")
     *         )
     *     )
     * )
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        // Verificar permissões
        if (!$user->hasRole('Administrator') && !$user->can('hours.update_all') && $timesheet->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        // Só pode editar se estiver pendente ou rejeitado
        if (!$timesheet->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível editar apontamentos já aprovados'
            ], 422);
        }

        $validationRules = [
            'user_id' => 'nullable|exists:users,id',
            'date' => 'sometimes|date|before_or_equal:today',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'total_hours' => 'nullable|string|regex:/^\d+:[0-5][0-9]$/',
            'observation' => 'nullable|string|max:5000',
            'ticket' => 'nullable|string|max:100',
            'customer_id' => 'sometimes|exists:customers,id',
            'project_id' => 'sometimes|exists:projects,id',
        ];

        $validator = Validator::make($request->all(), $validationRules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        // Processar user_id se fornecido (apenas para administradores)
        $validatedData = $validator->validated();
        if (isset($validatedData['user_id'])) {
            // Verificar se o usuário atual tem permissão para alterar o usuário do apontamento
            if (!($user->hasRole('Administrator') || $user->can('admin.full_access'))) {
                return response()->json([
                    'code' => 'PERMISSION_DENIED',
                    'type' => 'error',
                    'message' => 'Acesso negado',
                    'detailMessage' => 'Apenas administradores podem alterar o usuário do apontamento'
                ], 403);
            }

            // Verificar se o usuário especificado existe e está ativo
            $targetUser = User::find($validatedData['user_id']);
            if (!$targetUser || !$targetUser->enabled) {
                return response()->json([
                    'code' => 'INVALID_USER',
                    'type' => 'error',
                    'message' => 'Usuário inválido',
                    'detailMessage' => 'O usuário especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Validar customer_id e project_id quando fornecidos
        if (isset($validatedData['project_id'])) {
            $project = Project::find($validatedData['project_id']);
            if (!$project || !$project->isActive()) {
                return response()->json([
                    'code' => 'INACTIVE_PROJECT',
                    'type' => 'error',
                    'message' => 'Projeto inativo ou não encontrado',
                    'detailMessage' => 'Não é possível apontar horas em projetos inativos ou inexistentes'
                ], 422);
            }

            // Se customer_id também foi fornecido, validar que o projeto pertence ao cliente
            if (isset($validatedData['customer_id'])) {
                if ($project->customer_id != $validatedData['customer_id']) {
                    return response()->json([
                        'code' => 'PROJECT_CUSTOMER_MISMATCH',
                        'type' => 'error',
                        'message' => 'Projeto não pertence ao cliente',
                        'detailMessage' => 'O projeto selecionado não pertence ao cliente informado'
                    ], 422);
                }
            } else {
                // Se não forneceu customer_id mas forneceu project_id, usar o customer_id do projeto
                $validatedData['customer_id'] = $project->customer_id;
            }
        } elseif (isset($validatedData['customer_id'])) {
            // Se forneceu customer_id mas não project_id, validar que o cliente existe
            $customer = Customer::find($validatedData['customer_id']);
            if (!$customer || !$customer->active) {
                return response()->json([
                    'code' => 'INACTIVE_CUSTOMER',
                    'type' => 'error',
                    'message' => 'Cliente inativo ou não encontrado',
                    'detailMessage' => 'O cliente especificado não existe ou está inativo'
                ], 422);
            }
        }

        // Determinar qual projeto usar para validação de prazo
        $projectForValidation = null;
        if (isset($validatedData['project_id'])) {
            $projectForValidation = Project::find($validatedData['project_id']);
        } else {
            $projectForValidation = $timesheet->project;
        }

        // Verificar se o projeto permite apontamentos manuais (exceto para administradores)
        if ($projectForValidation && !$user->hasRole('Administrator') && !$user->can('admin.full_access')) {
            // Se mudou de projeto, validar o novo projeto
            if (isset($validatedData['project_id']) && $validatedData['project_id'] != $timesheet->project_id) {
                if (!$projectForValidation->allow_manual_timesheets) {
                    return response()->json([
                        'code' => 'MANUAL_TIMESHEETS_NOT_ALLOWED',
                        'type' => 'error',
                        'message' => 'Apontamentos manuais não permitidos',
                        'detailMessage' => 'Este projeto não permite criação de apontamentos pelo frontend. Apenas o webhook do Movidesk pode criar apontamentos para este projeto.'
                    ], 422);
                }
            } elseif (!$timesheet->project->allow_manual_timesheets) {
                // Se não mudou de projeto e o projeto não permite apontamentos manuais
                // Permitir edição apenas se o apontamento foi criado pelo webhook (origin = 'webhook')
                if ($timesheet->origin !== 'webhook') {
                    return response()->json([
                        'code' => 'MANUAL_TIMESHEETS_NOT_ALLOWED',
                        'type' => 'error',
                        'message' => 'Apontamentos manuais não permitidos',
                        'detailMessage' => 'Este projeto não permite edição de apontamentos criados pelo frontend. Apenas apontamentos criados pelo webhook do Movidesk podem ser editados.'
                    ], 422);
                }
            }
        }

        // Verificar prazo limite se a data foi alterada
        if (isset($validatedData['date']) && $projectForValidation) {
            $serviceDate = \Carbon\Carbon::parse($validatedData['date']);

            if (!$projectForValidation->isWithinTimesheetDeadline($serviceDate)) {
                $limitDays = $projectForValidation->getTimesheetRetroactiveLimitDays();
                $deadlineDate = $projectForValidation->getTimesheetDeadline($serviceDate);

                $source = $projectForValidation->timesheet_retroactive_limit_days !== null
                    ? 'configurado para este projeto'
                    : 'configuração global do sistema';

                return response()->json([
                    'code' => 'TIMESHEET_DEADLINE_EXPIRED',
                    'type' => 'error',
                    'message' => 'Prazo expirado para lançamento de horas',
                    'detailMessage' => sprintf(
                        'O prazo limite para lançamento de horas deste serviço expirou. ' .
                        'Serviço realizado em %s, prazo limite era %s (limite de %d %s %s).',
                        $serviceDate->format('d/m/Y'),
                        $deadlineDate->format('d/m/Y'),
                        $limitDays,
                        $limitDays === 1 ? 'dia' : 'dias',
                        $source
                    ),
                    'details' => [
                        'service_date' => $serviceDate->format('Y-m-d'),
                        'deadline_date' => $deadlineDate->format('Y-m-d'),
                        'limit_days' => $limitDays,
                        'source' => $source
                    ]
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            // Determinar qual projeto usar para validação
            $projectForValidation = $projectForValidation ?? $timesheet->project;

            // Determinar qual usuário usar para validação
            $userIdForValidation = isset($validatedData['user_id']) ? $validatedData['user_id'] : $timesheet->user_id;

            // Processar total_hours se fornecido
            $newEffortMinutes = null;
            if (!empty($validatedData['total_hours'])) {
                // Converter total_hours para effort_minutes antes de atualizar
                if (preg_match('/^(\d+):([0-5][0-9])$/', $validatedData['total_hours'], $matches)) {
                    $hours = intval($matches[1]);
                    $minutes = intval($matches[2]);
                    $newEffortMinutes = ($hours * 60) + $minutes;
                    $validatedData['effort_minutes'] = $newEffortMinutes;
                }
                // Remover total_hours dos dados validados pois não é um campo do modelo
                unset($validatedData['total_hours']);
            } elseif (isset($validatedData['start_time']) && isset($validatedData['end_time'])) {
                // Se não forneceu total_hours mas forneceu start_time e end_time, calcular
                $startTime = \Carbon\Carbon::parse($validatedData['start_time']);
                $endTime = \Carbon\Carbon::parse($validatedData['end_time']);

                // Se o horário final for menor que o inicial, assumir que passou da meia-noite
                if ($endTime->lt($startTime)) {
                    $endTime->addDay();
                }

                $newEffortMinutes = $startTime->diffInMinutes($endTime);
            } else {
                // Se não forneceu nem total_hours nem start_time/end_time, usar o valor atual
                $newEffortMinutes = $timesheet->effort_minutes;
            }

            // Calcular a diferença de horas (novas horas - horas atuais)
            $currentHours = round(($timesheet->effort_minutes ?? 0) / 60, 2);
            $newHours = round(($newEffortMinutes ?? 0) / 60, 2);
            $hoursDifference = $newHours - $currentHours;

            Log::info('Editando apontamento - Calculando diferença de horas', [
                'timesheet_id' => $timesheet->id,
                'current_project_id' => $timesheet->project_id,
                'current_user_id' => $timesheet->user_id,
                'current_hours' => $currentHours,
                'new_hours' => $newHours,
                'hours_difference' => $hoursDifference,
                'new_project_id' => $validatedData['project_id'] ?? null,
                'new_user_id' => $validatedData['user_id'] ?? null,
            ]);

            // Verificar se mudou de projeto ou usuário
            $projectChanged = isset($validatedData['project_id']) && $validatedData['project_id'] != $timesheet->project_id;
            $userChanged = isset($validatedData['user_id']) && $validatedData['user_id'] != $timesheet->user_id;

            Log::info('Editando apontamento - Verificando mudanças', [
                'timesheet_id' => $timesheet->id,
                'project_changed' => $projectChanged,
                'user_changed' => $userChanged,
                'old_project_id' => $timesheet->project_id,
                'new_project_id' => $validatedData['project_id'] ?? $timesheet->project_id,
                'old_user_id' => $timesheet->user_id,
                'new_user_id' => $userIdForValidation,
            ]);

            if ($projectChanged || $userChanged) {
                // Se mudou de projeto ou usuário, validar o novo projeto/usuário com as novas horas
                $targetProject = $projectChanged ? Project::find($validatedData['project_id']) : $projectForValidation;

                Log::info('Editando apontamento - Mudança detectada, validando novo projeto/usuário', [
                    'timesheet_id' => $timesheet->id,
                    'target_project_id' => $targetProject?->id,
                    'target_user_id' => $userIdForValidation,
                    'new_hours' => $newHours,
                    'project_changed' => $projectChanged,
                    'user_changed' => $userChanged,
                ]);

                if ($targetProject) {
                    // Validar saldo no projeto alvo com as novas horas
                    // Não excluir o apontamento atual pois ele será movido/atribuído a outro projeto/usuário
                    $balanceValidation = $this->validateHoursBalance(
                        $targetProject,
                        $userIdForValidation,
                        $newHours,
                        null
                    );

                    if ($balanceValidation) {
                        Log::warning('Edição de apontamento bloqueada - Saldo insuficiente no novo projeto/usuário', [
                            'timesheet_id' => $timesheet->id,
                            'target_project_id' => $targetProject->id,
                            'target_user_id' => $userIdForValidation,
                            'new_hours' => $newHours,
                        ]);
                        DB::rollBack();
                        return $balanceValidation;
                    }
                }
            } else {
                // Se não mudou de projeto nem usuário, validar apenas a diferença de horas (excluindo o apontamento atual)
                if ($projectForValidation && $hoursDifference > 0) {
                    Log::info('Editando apontamento - Mesmo projeto/usuário, validando diferença de horas', [
                        'timesheet_id' => $timesheet->id,
                        'project_id' => $projectForValidation->id,
                        'user_id' => $userIdForValidation,
                        'hours_difference' => $hoursDifference,
                        'exclude_timesheet_id' => $timesheet->id,
                    ]);

                    $balanceValidation = $this->validateHoursBalance(
                        $projectForValidation,
                        $userIdForValidation,
                        $hoursDifference,
                        $timesheet->id // Excluir o apontamento atual do cálculo
                    );

                    if ($balanceValidation) {
                        Log::warning('Edição de apontamento bloqueada - Saldo insuficiente para aumentar horas', [
                            'timesheet_id' => $timesheet->id,
                            'project_id' => $projectForValidation->id,
                            'user_id' => $userIdForValidation,
                            'hours_difference' => $hoursDifference,
                        ]);
                        DB::rollBack();
                        return $balanceValidation;
                    }
                } else {
                    Log::info('Editando apontamento - Não precisa validar saldo (redução de horas ou sem mudança)', [
                        'timesheet_id' => $timesheet->id,
                        'hours_difference' => $hoursDifference,
                        'project_id' => $projectForValidation?->id,
                    ]);
                }
            }

            // Resetar status para pendente se houve alterações após rejeição
            if ($timesheet->status === Timesheet::STATUS_REJECTED) {
                $validatedData['status'] = Timesheet::STATUS_PENDING;
                $validatedData['rejection_reason'] = null;
                $validatedData['reviewed_by'] = null;
                $validatedData['reviewed_at'] = null;
            }

            $timesheet->fill($validatedData);
            $timesheet->save();

            DB::commit();

            $timesheet->load(['user', 'customer', 'project']);

            Log::info('Apontamento atualizado com sucesso', [
                'timesheet_id' => $timesheet->id,
                'project_id' => $timesheet->project_id,
                'user_id' => $timesheet->user_id,
                'old_effort_minutes' => $timesheet->getOriginal('effort_minutes'),
                'new_effort_minutes' => $timesheet->effort_minutes,
                'old_effort_hours' => round(($timesheet->getOriginal('effort_minutes') ?? 0) / 60, 2),
                'new_effort_hours' => round($timesheet->effort_minutes / 60, 2),
                'hours_difference' => $hoursDifference,
                'date' => $timesheet->date,
                'status' => $timesheet->status,
                'project_changed' => $projectChanged ?? false,
                'user_changed' => $userChanged ?? false,
            ]);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento atualizado com sucesso!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar apontamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/timesheets/{id}",
     *     summary="Excluir apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet excluído com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Apontamento excluído com sucesso!")
     *         )
     *     )
     * )
     */
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        // Verificar permissões
        if (!$user->hasRole('Administrator') && !$user->can('hours.delete_all') && $timesheet->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado'
            ], 403);
        }

        // Só pode excluir se estiver pendente
        if (!$timesheet->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir apontamentos já aprovados ou rejeitados'
            ], 422);
        }

        $timesheet->delete();

        return response()->json([
            'success' => true,
            'message' => 'Apontamento excluído com sucesso!'
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/approve",
     *     summary="Aprovar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet aprovado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento aprovado com sucesso!")
     *         )
     *     )
     * )
     */
    public function approve(int $id): JsonResponse
    {
        $user = Auth::user();

        $timesheet = Timesheet::with(['project.approvers'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        if (!$timesheet->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para aprovar este apontamento'
            ], 403);
        }

        if ($timesheet->approve($user)) {
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento aprovado com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao aprovar apontamento'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reject",
     *     summary="Rejeitar apontamento",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Horário não compatível com o projeto")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Timesheet rejeitado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Apontamento rejeitado com sucesso!")
     *         )
     *     )
     * )
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Motivo da rejeição é obrigatório',
                'errors' => $validator->errors()
            ], 422);
        }

        $timesheet = Timesheet::with(['project.approvers'])->find($id);

        if (!$timesheet) {
            return response()->json([
                'success' => false,
                'message' => 'Apontamento não encontrado'
            ], 404);
        }

        if (!$timesheet->canBeApprovedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para rejeitar este apontamento'
            ], 403);
        }

        if ($timesheet->reject($user, $request->reason)) {
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Apontamento rejeitado com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao rejeitar apontamento'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reverse-approval",
     *     summary="Estornar aprovação do apontamento",
     *     description="Estorna a aprovação de um apontamento, retornando-o ao status pendente",
     *     operationId="reverseApprovalTimesheet",
     *     tags={"Timesheets"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Aprovação realizada por engano")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Aprovação estornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Aprovação estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseApproval(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return $this->notFoundResponse('Apontamento não encontrado');
        }

        if (!$timesheet->canBeReversedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para estornar esta aprovação ou o período permitido expirou'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($timesheet->reverseApproval($user, $request->reason)) {
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Aprovação estornada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao estornar aprovação'
        ], 500);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/timesheets/{id}/reverse-rejection",
     *     summary="Estornar rejeição do apontamento",
     *     description="Estorna a rejeição de um apontamento, retornando-o ao status pendente",
     *     operationId="reverseRejectionTimesheet",
     *     tags={"Timesheets"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="ID do timesheet",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"reason"},
     *             @OA\Property(property="reason", type="string", example="Rejeição realizada por engano")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rejeição estornada com sucesso",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", ref="#/components/schemas/Timesheet"),
     *             @OA\Property(property="message", type="string", example="Rejeição estornada com sucesso!")
     *         )
     *     )
     * )
     */
    public function reverseRejection(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $timesheet = Timesheet::find($id);

        if (!$timesheet) {
            return $this->notFoundResponse('Apontamento não encontrado');
        }

        if (!$timesheet->canBeRejectionReversedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para estornar esta rejeição ou o período permitido expirou'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->all());
        }

        if ($timesheet->reverseRejection($user, $request->reason)) {
            $timesheet->load(['user', 'customer', 'project', 'reviewedBy']);

            return response()->json([
                'success' => true,
                'data' => $timesheet,
                'message' => 'Rejeição estornada com sucesso!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Erro ao estornar rejeição'
        ], 500);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/timesheets/export",
     *     summary="Exportar apontamentos para Excel",
     *     tags={"Timesheets"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="Filtrar por projeto",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="customer_id",
     *         in="query",
     *         description="Filtrar por cliente",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filtrar por usuário",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filtrar por status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "approved", "rejected"})
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Data de início do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="Data de fim do período",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="ticket",
     *         in="query",
     *         description="Filtrar por número do ticket",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Busca geral",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="order",
     *         in="query",
     *         description="Ordenação (ex: date,-start_time)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Arquivo Excel com apontamentos exportados",
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
     *         )
     *     )
     * )
     */
    public function export(Request $request)
    {
        $user = Auth::user();

        // Gerar nome do arquivo com timestamp
        $filename = 'apontamentos_' . date('Y-m-d_H-i-s') . '.xlsx';

        return Excel::download(new TimesheetsExport($request, $user), $filename);
    }

    /**
     * Validar saldo de horas disponível para o usuário no projeto
     *
     * @param Project $project Projeto a validar
     * @param int $userId ID do usuário que está criando/editando o apontamento
     * @param float $hoursToAdd Horas que serão adicionadas (em horas decimais)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return JsonResponse|null Retorna erro se não houver saldo suficiente, null se OK
     */
    private function validateHoursBalance(Project $project, int $userId, float $hoursToAdd, ?int $excludeTimesheetId = null): ?JsonResponse
    {
        Log::info('Iniciando validação de saldo de horas', [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'user_id' => $userId,
            'hours_to_add' => $hoursToAdd,
            'exclude_timesheet_id' => $excludeTimesheetId,
            'sold_hours' => $project->sold_hours,
            'hour_contribution' => $project->hour_contribution,
            'consultant_hours' => $project->consultant_hours,
            'coordinator_hours' => $project->coordinator_hours,
        ]);

        // Carregar relacionamentos necessários
        $project->load(['consultants', 'approvers']);

        // Verificar se é consultor
        $isConsultant = $project->isUserConsultant($userId);

        // Verificar se é coordenador
        $isCoordinator = $project->isUserCoordinator($userId);

        Log::info('Identificação de tipo de usuário', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'consultant_ids' => $project->consultants->pluck('id')->toArray(),
            'approver_ids' => $project->approvers->pluck('id')->toArray(),
        ]);

        // Se não é nem consultor nem coordenador, não precisa validar saldo específico
        // Mas ainda valida o saldo geral
        if (!$isConsultant && !$isCoordinator) {
            $generalBalance = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
            $totalLoggedHours = $project->getTotalLoggedHours(false, $excludeTimesheetId);

            Log::info('Validação de saldo geral (usuário não é consultor nem coordenador)', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'sold_hours' => $project->sold_hours ?? 0,
                'hour_contribution' => $project->hour_contribution ?? 0,
                'total_logged_hours' => $totalLoggedHours,
                'general_balance' => $generalBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($generalBalance < $hoursToAdd) {
                Log::warning('Saldo de horas insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'general_balance' => $generalBalance,
                    'balance_type' => 'general',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas insuficiente',
                    'detailMessage' => 'O projeto não possui saldo suficiente de horas.',
                    'details' => [
                        'available_balance' => $generalBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'general'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo geral aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'general_balance' => $generalBalance,
                'hours_to_add' => $hoursToAdd,
            ]);

            return null; // OK
        }

        // Validar saldo de consultor se for consultor
        if ($isConsultant) {
            $consultantBalance = $project->getConsultantHoursBalance(false, $excludeTimesheetId);
            $consultantLoggedHours = $project->getConsultantLoggedHours(false, $excludeTimesheetId);

            Log::info('Validação de saldo de consultor', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'consultant_hours' => $project->consultant_hours ?? 0,
                'consultant_logged_hours' => $consultantLoggedHours,
                'consultant_balance' => $consultantBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($consultantBalance < $hoursToAdd) {
                Log::warning('Saldo de horas de consultor insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'consultant_balance' => $consultantBalance,
                    'consultant_hours' => $project->consultant_hours ?? 0,
                    'consultant_logged_hours' => $consultantLoggedHours,
                    'balance_type' => 'consultant',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_CONSULTANT_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas de consultor insuficiente',
                    'detailMessage' => sprintf(
                        'Você não possui saldo suficiente de horas como consultor neste projeto. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                        $consultantBalance,
                        $hoursToAdd
                    ),
                    'details' => [
                        'available_balance' => $consultantBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'consultant'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo de consultor aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'consultant_balance' => $consultantBalance,
                'hours_to_add' => $hoursToAdd,
            ]);
        }

        // Validar saldo de coordenador se for coordenador
        if ($isCoordinator) {
            $coordinatorBalance = $project->getCoordinatorHoursBalance(false, $excludeTimesheetId);
            $coordinatorLoggedHours = $project->getCoordinatorLoggedHours(false, $excludeTimesheetId);
            $consultantHours = $project->consultant_hours ?? 0;
            $coordinatorHoursPercent = $project->coordinator_hours ?? 0;
            $coordinatorAvailableHours = ($consultantHours * $coordinatorHoursPercent) / 100;

            Log::info('Validação de saldo de coordenador', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'coordinator_hours_percent' => $coordinatorHoursPercent,
                'consultant_hours' => $consultantHours,
                'coordinator_available_hours' => $coordinatorAvailableHours,
                'coordinator_logged_hours' => $coordinatorLoggedHours,
                'coordinator_balance' => $coordinatorBalance,
                'hours_to_add' => $hoursToAdd,
                'exclude_timesheet_id' => $excludeTimesheetId,
            ]);

            if ($coordinatorBalance < $hoursToAdd) {
                Log::warning('Saldo de horas de coordenador insuficiente - Validação falhou', [
                    'project_id' => $project->id,
                    'user_id' => $userId,
                    'hours_to_add' => $hoursToAdd,
                    'coordinator_balance' => $coordinatorBalance,
                    'coordinator_available_hours' => $coordinatorAvailableHours,
                    'coordinator_logged_hours' => $coordinatorLoggedHours,
                    'balance_type' => 'coordinator',
                ]);
                return response()->json([
                    'code' => 'INSUFFICIENT_COORDINATOR_HOURS_BALANCE',
                    'type' => 'error',
                    'message' => 'Saldo de horas de coordenador insuficiente',
                    'detailMessage' => sprintf(
                        'Você não possui saldo suficiente de horas como coordenador neste projeto. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                        $coordinatorBalance,
                        $hoursToAdd
                    ),
                    'details' => [
                        'available_balance' => $coordinatorBalance,
                        'requested_hours' => $hoursToAdd,
                        'balance_type' => 'coordinator'
                    ]
                ], 422);
            }

            Log::info('Validação de saldo de coordenador aprovada', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'coordinator_balance' => $coordinatorBalance,
                'hours_to_add' => $hoursToAdd,
            ]);
        }

        // Também validar saldo geral
        $generalBalance = $project->getGeneralHoursBalance(false, $excludeTimesheetId);
        $totalLoggedHours = $project->getTotalLoggedHours(false, $excludeTimesheetId);

        Log::info('Validação final de saldo geral (após validações específicas)', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'sold_hours' => $project->sold_hours ?? 0,
            'hour_contribution' => $project->hour_contribution ?? 0,
            'total_logged_hours' => $totalLoggedHours,
            'general_balance' => $generalBalance,
            'hours_to_add' => $hoursToAdd,
            'exclude_timesheet_id' => $excludeTimesheetId,
        ]);

        if ($generalBalance < $hoursToAdd) {
            Log::warning('Saldo geral de horas insuficiente - Validação final falhou', [
                'project_id' => $project->id,
                'user_id' => $userId,
                'hours_to_add' => $hoursToAdd,
                'general_balance' => $generalBalance,
                'total_logged_hours' => $totalLoggedHours,
                'balance_type' => 'general',
            ]);
            return response()->json([
                'code' => 'INSUFFICIENT_HOURS_BALANCE',
                'type' => 'error',
                'message' => 'Saldo de horas insuficiente',
                'detailMessage' => sprintf(
                    'O projeto não possui saldo suficiente de horas. Saldo disponível: %.2f h, horas solicitadas: %.2f h.',
                    $generalBalance,
                    $hoursToAdd
                ),
                'details' => [
                    'available_balance' => $generalBalance,
                    'requested_hours' => $hoursToAdd,
                    'balance_type' => 'general'
                ]
            ], 422);
        }

        Log::info('Validação de saldo de horas concluída com sucesso', [
            'project_id' => $project->id,
            'user_id' => $userId,
            'is_consultant' => $isConsultant,
            'is_coordinator' => $isCoordinator,
            'general_balance' => $generalBalance,
            'hours_to_add' => $hoursToAdd,
            'validation_result' => 'approved',
        ]);

        return null; // OK
    }
}
