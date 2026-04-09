<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    // Contract type constants removidos - agora vem da tabela contract_types

    /**
     * Status constants
     */
    public const STATUS_AWAITING_START = 'awaiting_start';
    public const STATUS_STARTED = 'started';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FINISHED = 'finished';

    /**
     * Expense responsible party constants
     */
    public const EXPENSE_RESPONSIBLE_CONSULTANCY = 'consultancy';
    public const EXPENSE_RESPONSIBLE_CLIENT = 'client';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'customer_id',
        'parent_project_id',
        'project_value',
        'hourly_rate',
        'sold_hours',
        'accumulated_sold_hours',
        'hour_contribution',
        'exceeded_hour_contribution',
        'initial_hours_balance',
        'initial_cost',
        'consultant_hours',
        'coordinator_hours',
        'additional_hourly_rate',
        'start_date',
        'save_erpserv',
        'max_expense_per_consultant',
        'unlimited_expense',
        'expense_responsible_party',
        'timesheet_retroactive_limit_days',
        'allow_manual_timesheets',
        'service_type_id',
        'contract_type_id',
        'status',
        'allow_negative_balance',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'project_value' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'additional_hourly_rate' => 'decimal:2',
        'max_expense_per_consultant' => 'decimal:2',
        'unlimited_expense' => 'boolean',
        'sold_hours' => 'integer',
        'accumulated_sold_hours' => 'integer',
        'hour_contribution' => 'integer',
        'exceeded_hour_contribution' => 'integer',
        'initial_hours_balance' => 'decimal:2',
        'consultant_hours' => 'integer',
        'coordinator_hours' => 'integer',
        'timesheet_retroactive_limit_days' => 'integer',
        'allow_manual_timesheets' => 'boolean',
        'allow_negative_balance' => 'boolean',
        'save_erpserv' => 'decimal:2',
        'start_date' => 'date:Y-m-d', // Retorna apenas a data sem horário
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get all available contract types
     */
    public static function getContractTypes(): array
    {
        return ContractType::getActiveOptionsWithCode();
    }

    /**
     * Get all available statuses (from database)
     */
    public static function getStatuses(): array
    {
        return ProjectStatus::getActiveOptionsWithCode();
    }

    /**
     * Get all available expense responsible party options
     */
    public static function getExpenseResponsiblePartyOptions(): array
    {
        return [
            self::EXPENSE_RESPONSIBLE_CONSULTANCY => 'Consultoria',
            self::EXPENSE_RESPONSIBLE_CLIENT => 'Cliente',
        ];
    }

    /**
     * Get human readable contract type
     */
    public function getContractTypeDisplayAttribute(): string
    {
        return $this->contractType?->name ?? 'N/A';
    }

    /**
     * Get human readable status
     */
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->status) {
            return 'Não definido';
        }

        return self::getStatuses()[$this->status] ?? $this->status;
    }

    /**
     * Relacionamento com customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com tipo de serviço
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Relacionamento com tipo de contrato
     */
    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    /**
     * Relacionamento com consultores (many-to-many)
     */
    public function consultants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_consultants')
                    ->withTimestamps();
    }

    /**
     * Relacionamento com coordenadores (many-to-many)
     */
    public function coordinators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_coordinators')
                    ->withTimestamps();
    }

    /**
     * @deprecated Use coordinators()
     */
    public function approvers(): BelongsToMany
    {
        return $this->coordinators();
    }

    /**
     * Relacionamento com timesheets (one-to-many)
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    /**
     * Relacionamento com despesas
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Relacionamento com aportes de horas
     */
    public function hourContributions(): HasMany
    {
        return $this->hasMany(HourContribution::class)
                    ->orderBy('contributed_at', 'desc');
    }

    /**
     * Relacionamento com projeto pai (many-to-one)
     */
    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    /**
     * Relacionamento com projetos filhos (one-to-many)
     */
    public function childProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id');
    }

    /**
     * Verifica se o projeto é um subprojeto
     */
    public function isSubProject(): bool
    {
        return $this->parent_project_id !== null;
    }

    /**
     * Verifica se o projeto tem subprojetos
     */
    public function hasChildProjects(): bool
    {
        return $this->childProjects()->exists();
    }

    /**
     * Scope para filtrar por status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para filtrar por tipo de contrato
     */
    public function scopeWithContractType($query, int $contractTypeId)
    {
        return $query->where('contract_type_id', $contractTypeId);
    }

    /**
     * Scope para projetos ativos (não cancelados nem encerrados)
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_CANCELLED, self::STATUS_FINISHED]);
    }

    /**
     * Verifica se o projeto está ativo
     */
    public function isActive(): bool
    {
        return !in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_FINISHED]);
    }

    /**
     * Verifica se o projeto pode ser editado
     */
    public function canBeEdited(): bool
    {
        return $this->status !== self::STATUS_FINISHED;
    }

    /**
     * Obter o prazo limite (em dias) para lançamento retroativo de horas
     * Usa configuração do projeto, ou fallback para configuração do sistema
     */
    public function getTimesheetRetroactiveLimitDays(): int
    {
        // Se o projeto tem configuração própria, usar ela
        if ($this->timesheet_retroactive_limit_days !== null) {
            return $this->timesheet_retroactive_limit_days;
        }

        // Senão, usar configuração global do sistema
        return \App\Models\SystemSetting::get('timesheet_retroactive_limit_days', 7);
    }

    /**
     * Verificar se uma data de serviço ainda está dentro do prazo para lançamento
     */
    public function isWithinTimesheetDeadline(\Carbon\Carbon $serviceDate): bool
    {
        $limitDays = $this->getTimesheetRetroactiveLimitDays();

        // Se limite é 0, não há restrição
        if ($limitDays === 0) {
            return true;
        }

        // Calcular data limite
        $deadlineDate = $serviceDate->copy()->addDays($limitDays)->endOfDay();
        $now = \Carbon\Carbon::now();

        return $now->lessThanOrEqualTo($deadlineDate);
    }

    /**
     * Obter a data limite para lançamento de horas de um serviço
     */
    public function getTimesheetDeadline(\Carbon\Carbon $serviceDate): \Carbon\Carbon
    {
        $limitDays = $this->getTimesheetRetroactiveLimitDays();
        return $serviceDate->copy()->addDays($limitDays)->endOfDay();
    }

    /**
     * Calcular o total de horas apontadas no projeto (em minutos)
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados
     */
    public function getTotalLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        $query = $this->timesheets()->where('status', '!=', 'rejected');

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()->where('status', '!=', 'rejected');
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas no projeto (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas
     */
    public function getTotalLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getTotalLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular o total de horas apontadas por consultores (em minutos)
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados por consultores
     */
    public function getConsultantLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        // Obter IDs dos consultores do projeto
        $consultantIds = $this->consultants()->pluck('users.id')->toArray();

        if (empty($consultantIds)) {
            return 0;
        }

        $query = $this->timesheets()
            ->whereIn('user_id', $consultantIds)
            ->where('status', '!=', 'rejected');

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()
                    ->whereIn('user_id', $consultantIds)
                    ->where('status', '!=', 'rejected');
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas por consultores (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas por consultores
     */
    public function getConsultantLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getConsultantLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular o total de horas apontadas por coordenadores (em minutos)
     *
     * Coordenadores são identificados como aprovadores do projeto ou usuários com role 'Coordinator'
     * 
     * IMPORTANTE: Não inclui apontamentos com status 'rejected'
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return int Total de minutos apontados por coordenadores
     */
    public function getCoordinatorLoggedMinutes(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): int
    {
        // Obter IDs dos coordenadores do projeto
        $coordinatorIds = $this->coordinators()->pluck('users.id')->toArray();

        // Também incluir usuários com role 'Coordinator' se existir
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            $coordinatorRole = \Spatie\Permission\Models\Role::where('name', 'Coordinator')->first();
            if ($coordinatorRole) {
                $roleCoordinatorIds = $coordinatorRole->users()->pluck('users.id')->toArray();
                $coordinatorIds = array_unique(array_merge($coordinatorIds, $roleCoordinatorIds));
            }
        }

        if (empty($coordinatorIds)) {
            return 0;
        }

        $query = $this->timesheets()
            ->whereIn('user_id', $coordinatorIds)
            ->where('status', '!=', 'rejected');

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalMinutes = $query->sum('effort_minutes') ?? 0;

        if ($includeChildProjects && $this->hasChildProjects()) {
            foreach ($this->childProjects as $childProject) {
                $childQuery = $childProject->timesheets()
                    ->whereIn('user_id', $coordinatorIds)
                    ->where('status', '!=', 'rejected');
                if ($excludeTimesheetId) {
                    $childQuery->where('id', '!=', $excludeTimesheetId);
                }
                $totalMinutes += $childQuery->sum('effort_minutes') ?? 0;
            }
        }

        return $totalMinutes;
    }

    /**
     * Calcular o total de horas apontadas por coordenadores (em horas)
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Total de horas apontadas por coordenadores
     */
    public function getCoordinatorLoggedHours(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $totalMinutes = $this->getCoordinatorLoggedMinutes($includeChildProjects, $excludeTimesheetId);
        return round($totalMinutes / 60, 2);
    }

    /**
     * Calcular horas de coordenação para um total de horas apontadas.
     *
     * Regras:
     * - On Demand: sempre 0
     * - Banco de Horas aberto: horas_apontadas × percentual_coordenacao
     * - Banco de Horas fechado (FINISHED): consultant_hours × percentual_coordenacao
     *
     * @param float $loggedHours Horas apontadas (não usadas quando fechado)
     * @return float Horas de coordenação
     */
    public function calculateCoordinationHours(float $loggedHours = 0.0): float
    {
        $this->loadMissing('contractType');

        // On Demand não tem coordenação
        if ($this->isOnDemand()) {
            return 0.0;
        }

        $percent = (float) ($this->coordinator_hours ?? 0);
        if ($percent <= 0) {
            return 0.0;
        }

        // Projeto fechado: base = consultant_hours (fixo)
        if ($this->status === self::STATUS_FINISHED) {
            $base = (float) ($this->consultant_hours ?? 0);
        } else {
            // Projeto aberto: base = horas apontadas (dinâmico)
            $base = $loggedHours;
        }

        return round($base * $percent / 100, 2);
    }

    /**
     * Verificar se o projeto é do tipo On Demand
     */
    public function isOnDemand(): bool
    {
        if (!$this->contractType) {
            return false;
        }
        return strtolower(trim($this->contractType->name)) === 'on demand';
    }

    /**
     * Calcular o saldo geral de horas do projeto
     *
     * Fórmula (Banco de Horas):
     *   saldo = horas_disponíveis - (horas_apontadas + horas_coordenação) + saldo_inicial
     *
     * Fórmula (On Demand):
     *   sem saldo (retorna 0)
     *
     * IMPORTANTE: Sempre inclui projetos filhos no cálculo.
     * Para projetos com contract_type.name = "Banco de Horas Mensal", usa accumulated_sold_hours.
     * Para projetos filhos com contract_type.name = "Fechado", subtrai (horas vendidas + aporte).
     *
     * @param bool $includeChildProjects Não utilizado (sempre inclui filhos)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo geral em horas
     */
    public function getGeneralHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $this->loadMissing('contractType');

        // On Demand não controla saldo
        if ($this->isOnDemand()) {
            return 0.0;
        }

        // Para Banco de Horas Mensal, usar accumulated_sold_hours; caso contrário, usar sold_hours
        if ($this->isBankHoursMonthly()) {
            $soldHours = $this->accumulated_sold_hours ?? $this->sold_hours ?? 0;
        } else {
            $soldHours = $this->sold_hours ?? 0;
        }

        $totalAvailableHours = $this->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($this->sold_hours ?? 0);

        // Horas apontadas (excluindo rejeitados)
        $query = $this->timesheets()->where('status', '!=', 'rejected');
        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }
        $totalLoggedMinutes = $query->sum('effort_minutes') ?? 0;
        $totalLoggedHours   = round($totalLoggedMinutes / 60, 2);

        // Horas de coordenação sobre as horas apontadas deste projeto
        $coordinationHours = $this->calculateCoordinationHours($totalLoggedHours);

        $initialBalance = (float) ($this->initial_hours_balance ?? 0);
        $balance = ($soldHours + $contributionHours) - ($totalLoggedHours + $coordinationHours) + $initialBalance;

        // Sempre incluir projetos filhos no cálculo
        if ($this->hasChildProjects()) {
            // Carregar relacionamento contractType para evitar N+1 queries
            $this->loadMissing('childProjects.contractType');

            foreach ($this->childProjects as $childProject) {
                // Verificar se o projeto filho é do tipo "Fechado"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Contratos fechados: subtrai horas vendidas + aportes (valor fixo)
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isOnDemand()) {
                    // On Demand: não consome saldo do projeto pai
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Banco de Horas Mensal: usa accumulated_sold_hours
                    $childSoldHours = $childProject->accumulated_sold_hours ?? $childProject->sold_hours ?? 0;
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);

                    $childQuery = $childProject->timesheets()->where('status', '!=', 'rejected');
                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }
                    $childLoggedHours = round(($childQuery->sum('effort_minutes') ?? 0) / 60, 2);
                    $childCoordinationHours = $childProject->calculateCoordinationHours($childLoggedHours);

                    $childInitialBalance = (float) ($childProject->initial_hours_balance ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours)
                        - ($childLoggedHours + $childCoordinationHours)
                        + $childInitialBalance;
                    $balance -= $childBalance;
                } else {
                    // Outros tipos de Banco de Horas
                    $childQuery = $childProject->timesheets()->where('status', '!=', 'rejected');
                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }
                    $childLoggedHours = round(($childQuery->sum('effort_minutes') ?? 0) / 60, 2);
                    $childCoordinationHours = $childProject->calculateCoordinationHours($childLoggedHours);
                    $balance -= ($childLoggedHours + $childCoordinationHours);
                }
            }
        }

        return round($balance, 2);
    }

    /**
     * Calcular o saldo geral de horas do projeto excluindo o mês atual
     * 
     * Este método calcula o saldo considerando apenas meses fechados (até o final do mês anterior).
     * Para projetos "Banco de Horas Mensal", usa accumulated_sold_hours calculado até o mês anterior.
     * Exclui horas apontadas do mês atual.
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos (sempre true internamente)
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo geral em horas (excluindo mês atual)
     */
    public function getGeneralHoursBalanceExcludingCurrentMonth(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        // Carregar contractType se necessário
        $this->loadMissing('contractType');

        // Data de referência: último dia do mês anterior
        $endOfLastMonth = \Carbon\Carbon::now()->subMonth()->endOfMonth();

        // Para Banco de Horas Mensal, calcular accumulated_sold_hours até o mês anterior
        if ($this->isBankHoursMonthly()) {
            $soldHours = $this->calculateAccumulatedSoldHours($endOfLastMonth) ?? $this->sold_hours ?? 0;
        } else {
            $soldHours = $this->sold_hours ?? 0;
        }
        
        // Usar método auxiliar para obter aportes (novos + fallback legado)
        $totalAvailableHours = $this->getTotalAvailableHours();
        $contributionHours = $totalAvailableHours - ($this->sold_hours ?? 0);

        // Calcular horas apontadas excluindo o mês atual (até o final do mês anterior)
        $query = $this->timesheets()
            ->where('status', '!=', 'rejected')
            ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

        if ($excludeTimesheetId) {
            $query->where('id', '!=', $excludeTimesheetId);
        }

        $totalLoggedMinutes = $query->sum('effort_minutes') ?? 0;
        $totalLoggedHours = round($totalLoggedMinutes / 60, 2);

        // Calcular saldo base do projeto atual
        // IMPORTANTE: Para Banco de Horas Mensal, soldHours já é accumulated até o mês anterior
        $initialBalance = (float) ($this->initial_hours_balance ?? 0);
        $balance = ($soldHours + $contributionHours) - $totalLoggedHours + $initialBalance;

        // Sempre incluir projetos filhos no cálculo
        if ($this->hasChildProjects()) {
            // Carregar relacionamento contractType para evitar N+1 queries
            $this->loadMissing('childProjects.contractType');

            foreach ($this->childProjects as $childProject) {
                // Verificar se o projeto filho é do tipo "Fechado"
                $isClosedContract = $childProject->contractType &&
                                    strtolower(trim($childProject->contractType->name)) === 'fechado';

                if ($isClosedContract) {
                    // Para contratos fechados: subtrair (horas vendidas + aportes) do projeto filho
                    // Usar getTotalAvailableHours() que já contempla novos aportes + fallback legado
                    $childTotalHours = $childProject->getTotalAvailableHours();
                    $balance -= $childTotalHours;
                } elseif ($childProject->isBankHoursMonthly()) {
                    // Para Banco de Horas Mensal: calcular accumulated_sold_hours até o mês anterior
                    $childSoldHours = $childProject->calculateAccumulatedSoldHours($endOfLastMonth) ?? $childProject->sold_hours ?? 0;
                    
                    // Calcular aportes usando método auxiliar
                    $childTotalAvailable = $childProject->getTotalAvailableHours();
                    $childContributionHours = $childTotalAvailable - ($childProject->sold_hours ?? 0);
                    
                    // Calcular horas apontadas do filho excluindo o mês atual
                    $childQuery = $childProject->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }

                    $childLoggedMinutes = $childQuery->sum('effort_minutes') ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    
                    // Subtrair o saldo do filho: (accumulated_sold_hours até mês anterior + aportes + saldo inicial) - horas apontadas até mês anterior
                    $childInitialBalance = (float) ($childProject->initial_hours_balance ?? 0);
                    $childBalance = ($childSoldHours + $childContributionHours) - $childLoggedHours + $childInitialBalance;
                    $balance -= $childBalance;
                } else {
                    // Para outros tipos: subtrair normalmente pelas horas apontadas (excluindo mês atual e rejeitados)
                    $childQuery = $childProject->timesheets()
                        ->where('status', '!=', 'rejected')
                        ->where('date', '<=', $endOfLastMonth->format('Y-m-d'));

                    if ($excludeTimesheetId) {
                        $childQuery->where('id', '!=', $excludeTimesheetId);
                    }

                    $childLoggedMinutes = $childQuery->sum('effort_minutes') ?? 0;
                    $childLoggedHours = round($childLoggedMinutes / 60, 2);
                    $balance -= $childLoggedHours;
                }
            }
        }

        return round($balance, 2);
    }

    /**
     * Calcular o saldo de horas dos consultores
     *
     * Fórmula: Quantidade Horas Consultor - todos apontamentos de consultores vinculados
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo de horas dos consultores
     */
    public function getConsultantHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $consultantHours = $this->consultant_hours ?? 0;
        $consultantLoggedHours = $this->getConsultantLoggedHours($includeChildProjects, $excludeTimesheetId);

        $balance = $consultantHours - $consultantLoggedHours;

        return round($balance, 2);
    }

    /**
     * Calcular o saldo de horas dos coordenadores
     *
     * Base do cálculo depende do status:
     * - Projeto aberto:  base = horas_apontadas_pelos_consultores  × percentual
     * - Projeto fechado: base = consultant_hours (fixo)            × percentual
     *
     * @param bool $includeChildProjects Se deve incluir horas dos subprojetos
     * @param int|null $excludeTimesheetId ID do timesheet a excluir do cálculo (útil na edição)
     * @return float Saldo de horas dos coordenadores
     */
    public function getCoordinatorHoursBalance(bool $includeChildProjects = false, ?int $excludeTimesheetId = null): float
    {
        $coordinatorLoggedHours = $this->getCoordinatorLoggedHours($includeChildProjects, $excludeTimesheetId);

        // Base para cálculo do disponível
        if ($this->status === self::STATUS_FINISHED) {
            $base = (float) ($this->consultant_hours ?? 0);
        } else {
            $base = $this->getConsultantLoggedHours($includeChildProjects, $excludeTimesheetId);
        }

        $percent = (float) ($this->coordinator_hours ?? 0);
        $coordinatorAvailableHours = round($base * $percent / 100, 2);

        return round($coordinatorAvailableHours - $coordinatorLoggedHours, 2);
    }

    /**
     * Verificar se o projeto é do tipo "Banco de Horas Mensal"
     *
     * @return bool
     */
    public function isBankHoursMonthly(): bool
    {
        if (!$this->contractType) {
            return false;
        }

        $contractTypeName = strtolower(trim($this->contractType->name));
        return $contractTypeName === 'banco de horas mensal';
    }

    /**
     * Calcular horas acumuladas (somatória de horas vendidas mês a mês)
     * 
     * Este método calcula a somatória de horas vendidas mês a mês desde a data de início
     * até a data atual. Apenas válido para projetos do tipo "Banco de Horas Mensal".
     * 
     * IMPORTANTE: Conta apenas meses completos (inteiros), de 1 em 1.
     * Exemplo: Se começou em 15/01/2025 e hoje é 26/01/2026, conta 13 meses (jan/2025 até jan/2026).
     * 
     * Fórmula: sold_hours * número de meses completos desde o início do mês de start_date até o mês atual
     * 
     * @param \Carbon\Carbon|null $referenceDate Data de referência para o cálculo (padrão: hoje)
     * @return int|null Horas acumuladas ou null se não for Banco de Horas Mensal ou faltar dados
     */
    public function calculateAccumulatedSoldHours(?\Carbon\Carbon $referenceDate = null): ?int
    {
        // Apenas calcular para projetos do tipo "Banco de Horas Mensal"
        if (!$this->isBankHoursMonthly()) {
            return null;
        }

        // Verificar se tem sold_hours e start_date
        if (!$this->sold_hours || !$this->start_date) {
            return null;
        }

        // Usar data de referência fornecida ou data atual
        $endDate = $referenceDate ?? \Carbon\Carbon::now();
        $startDate = \Carbon\Carbon::parse($this->start_date);

        // Se a data de início for no futuro, retornar 0
        if ($startDate->isFuture()) {
            return 0;
        }

        // Normalizar para o início dos meses para contar apenas meses completos
        $startMonth = $startDate->copy()->startOfMonth();
        $endMonth = $endDate->copy()->startOfMonth();

        // Calcular diferença em meses completos
        // diffInMonths retorna a diferença entre os meses, mas precisamos incluir ambos os meses
        $monthsDiff = $startMonth->diffInMonths($endMonth);
        
        // Sempre adicionar 1 para incluir o mês atual
        // Exemplo: jan/2025 até jan/2026 = 12 meses de diferença, mas são 13 meses no total
        $totalMonths = $monthsDiff + 1;

        // Calcular horas acumuladas: sold_hours * número de meses completos
        $accumulatedHours = $this->sold_hours * $totalMonths;

        return (int) $accumulatedHours;
    }

    /**
     * Atualizar o campo accumulated_sold_hours automaticamente
     * 
     * @param \Carbon\Carbon|null $referenceDate Data de referência para o cálculo (padrão: hoje)
     * @param bool $skipObserver Se true, atualiza sem disparar observers (útil quando chamado de dentro do observer)
     * @return bool True se atualizou, false caso contrário
     */
    public function updateAccumulatedSoldHours(?\Carbon\Carbon $referenceDate = null, bool $skipObserver = false): bool
    {
        $accumulatedHours = $this->calculateAccumulatedSoldHours($referenceDate);
        
        // Se retornou null, não é Banco de Horas Mensal ou faltam dados
        if ($accumulatedHours === null) {
            // Se não é Banco de Horas Mensal, limpar o campo
            if (!$this->isBankHoursMonthly()) {
                // Usar update direto no banco para evitar loop de observers
                if ($skipObserver) {
                    \Illuminate\Support\Facades\DB::table('projects')
                        ->where('id', $this->id)
                        ->update(['accumulated_sold_hours' => null]);
                    $this->accumulated_sold_hours = null;
                    return true;
                }
                $this->accumulated_sold_hours = null;
                return $this->save();
            }
            return false;
        }

        // Atualizar o campo apenas se o valor mudou
        if ($this->accumulated_sold_hours !== $accumulatedHours) {
            // Usar update direto no banco para evitar loop de observers
            if ($skipObserver) {
                \Illuminate\Support\Facades\DB::table('projects')
                    ->where('id', $this->id)
                    ->update(['accumulated_sold_hours' => $accumulatedHours]);
                $this->accumulated_sold_hours = $accumulatedHours;
                return true;
            }
            $this->accumulated_sold_hours = $accumulatedHours;
            return $this->save();
        }

        return true;
    }

    /**
     * Verificar se um usuário é consultor do projeto
     *
     * @param int $userId ID do usuário
     * @return bool
     */
    public function isUserConsultant(int $userId): bool
    {
        return $this->consultants()->where('users.id', $userId)->exists();
    }

    /**
     * Verificar se um usuário é coordenador do projeto
     *
     * @param int $userId ID do usuário
     * @return bool
     */
    public function isUserCoordinator(int $userId): bool
    {
        // Verificar se é coordenador do projeto
        if ($this->coordinators()->where('users.id', $userId)->exists()) {
            return true;
        }

        // Verificar se tem role 'Coordinator'
        if (class_exists(\Spatie\Permission\Models\Role::class)) {
            $user = \App\Models\User::find($userId);
            if ($user && $user->hasRole('Coordinator')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obter o total de horas disponíveis no projeto
     * Considera horas vendidas + aporte legado + novos aportes
     *
     * @return int Total de horas disponíveis
     */
    public function getTotalAvailableHours(): int
    {
        // Se existem aportes novos, usar apenas eles (ignorar hour_contribution legado)
        $newContributions = $this->hourContributions()->sum('contributed_hours') ?? 0;
        
        if ($newContributions > 0) {
            return ($this->sold_hours ?? 0) + $newContributions;
        }
        
        // Fallback: usar aporte legado (para projetos antigos)
        return ($this->sold_hours ?? 0) + ($this->hour_contribution ?? 0);
    }

    /**
     * Calcular o valor total do projeto
     * Considera valor base + valor de todos os aportes
     *
     * @return float Valor total do projeto
     */
    public function calculateTotalProjectValue(): float
    {
        // Valor das horas vendidas inicialmente
        $baseSoldHoursValue = ($this->sold_hours ?? 0) * ($this->hourly_rate ?? 0);
        
        // Se existem aportes novos, usar apenas eles
        $newContributions = $this->hourContributions()->sum(\Illuminate\Support\Facades\DB::raw('contributed_hours * hourly_rate')) ?? 0;
        
        if ($newContributions > 0) {
            // Usar apenas novos aportes (ignorar hour_contribution legado)
            return round($baseSoldHoursValue + $newContributions, 2);
        }
        
        // Fallback: usar aporte legado (para projetos antigos)
        $legacyContributionValue = ($this->hour_contribution ?? 0) * ($this->hourly_rate ?? 0);
        
        return round($baseSoldHoursValue + $legacyContributionValue, 2);
    }

    /**
     * Calcular o valor médio ponderado da hora
     * Considera todas as horas e valores
     *
     * @return float Valor médio ponderado por hora
     */
    public function getWeightedAverageHourlyRate(): float
    {
        // Se existem aportes novos
        $contributionsCount = $this->hourContributions()->count();
        
        if ($contributionsCount > 0) {
            $totalHours = $this->sold_hours ?? 0;
            $totalValue = ($this->sold_hours ?? 0) * ($this->hourly_rate ?? 0);
            
            // Adicionar horas e valores dos novos aportes
            foreach ($this->hourContributions as $contribution) {
                $totalHours += $contribution->contributed_hours;
                $totalValue += $contribution->getTotalValue();
            }
            
            return $totalHours > 0 ? round($totalValue / $totalHours, 2) : 0;
        }
        
        // Fallback: usar aporte legado (para projetos antigos)
        $totalHours = ($this->sold_hours ?? 0) + ($this->hour_contribution ?? 0);
        $totalValue = $totalHours * ($this->hourly_rate ?? 0);
        
        return $totalHours > 0 ? round($totalValue / $totalHours, 2) : ($this->hourly_rate ?? 0);
    }
}
