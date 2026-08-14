<?php

namespace App\Models;

use App\Observers\TimesheetObserver;
use App\Notifications\TimesheetStatusNotification;
use App\Services\TimesheetN8nNotifier;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

#[ObservedBy([TimesheetObserver::class])]
class Timesheet extends Model
{
    use HasFactory, SoftDeletes, \App\Traits\WithAbilities, \App\Models\Concerns\BelongsToCompany;
    use \App\Attachments\Concerns\HasGlobalAttachments;

    // FASE 11 — chave do registry global de anexos.
    public static function attachmentEntityType(): string { return 'TIMESHEET'; }

    /**
     * Source explícito pra o TimesheetObserver registrar no log de auditoria.
     * Propriedade pública (não atributo Eloquent) — não vai pro UPDATE SQL.
     * Setar antes do save() quando origem != contexto padrão (ex: sync Movidesk).
     */
    public ?string $_logSource = null;

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CONFLICTED = 'conflicted';
    public const STATUS_ADJUSTMENT_REQUESTED = 'adjustment_requested';
    public const STATUS_INTERNAL = 'internal';
    public const STATUS_RELEASED = 'released';
    // Atraso pós-fechamento: apontamento chegou pela integração com data em competência
    // já fechada. Fica fora de fechamento/consumo/dashboards até ser aprovado na tela de Atrasos.
    public const STATUS_LATE = 'late';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'customer_id',
        'project_id',
        'real_project_id',
        'stage_id',
        'stage_delivery_id',
        'date',
        'start_time',
        'end_time',
        'effort_minutes',
        'observation',
        'ticket',
        'origin',
        'is_billable_only',
        'is_internal_action',
        'client_extra_pct',
        'consultant_extra_pct',
        'status',
        'rejection_reason',
        'reviewed_by',
        'reviewed_at',
        'manual_project_edit',
        'date_locked',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date' => 'date:Y-m-d',
        'is_billable_only'    => 'boolean',
        'is_internal_action'  => 'boolean',
        'manual_project_edit' => 'boolean',
        'date_locked'         => 'boolean',
        'client_extra_pct'    => 'decimal:2',
        'contract_client_pct' => 'decimal:2', // derivado da regra do contrato (mantido pelo serviço)
        'consultant_extra_pct'=> 'decimal:2',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'effort_minutes' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['effort_hours', 'status_display', 'attachment_url'];

    /**
     * Boot method - define model events
     */
    protected static function boot()
    {
        parent::boot();

        // Calcular automaticamente o esforço antes de salvar
        static::saving(function ($timesheet) {
            $timesheet->calculateEffort();
        });

        // % de uplift do cliente DERIVADO da regra do contrato (denormaliza p/ a cobrança
        // ler sem join). Só recalcula quando muda projeto/data (ou é novo) — update de
        // status não mexe. É lado-cliente puro: consultor/parceiro NUNCA lê contract_client_pct.
        static::saving(function ($timesheet) {
            if ($timesheet->project_id && $timesheet->date &&
                (!$timesheet->exists || $timesheet->isDirty('project_id') || $timesheet->isDirty('date'))) {
                $timesheet->contract_client_pct = app(\App\Services\ContractHourMultiplierService::class)
                    ->pctForTimesheet((int) $timesheet->project_id, $timesheet->date);
            }
        });
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING              => 'Pendente',
            self::STATUS_APPROVED             => 'Aprovado',
            self::STATUS_REJECTED             => 'Rejeitado',
            self::STATUS_CONFLICTED           => 'Conflitante',
            self::STATUS_ADJUSTMENT_REQUESTED => 'Ajuste Solicitado',
            self::STATUS_INTERNAL             => 'Ação Interna',
            self::STATUS_RELEASED             => 'Liberado',
            self::STATUS_LATE                 => 'Atraso (pós-fechamento)',
        ];
    }

    /**
     * Minutos FATURÁVEIS ao cliente = minutos reais × (1 + uplift efetivo).
     * Uplift efetivo = COALESCE(contract_client_pct, client_extra_pct, 0) — a regra do
     * CONTRATO vence (decisão B); manual só como fallback. É o ÚNICO lugar do acréscimo:
     * as rotinas do lado cliente leem billableMinutes(); o CONSULTOR/PARCEIRO lê
     * effort_minutes cru e NUNCA passa por aqui (fica sempre no real).
     */
    public function billableMinutes(): float
    {
        $pct = (float) ($this->contract_client_pct ?? $this->client_extra_pct ?? 0);
        return ((float) $this->effort_minutes) * (1 + $pct / 100);
    }

    /** Horas faturáveis ao cliente (decimais), arredondadas a 2 casas. */
    public function billableHours(): float
    {
        return round($this->billableMinutes() / 60, 2);
    }

    /**
     * Calcular esforço em minutos baseado no horário de início e fim
     * ou usar valor customizado se fornecido
     */
    public function calculateEffort(): void
    {
        // Não sobrescrever effort_minutes já definido: vale tanto para lançamento
        // manual (total_hours) quanto para a integração Movidesk, que grava o tempo
        // REPORTADO e usa start/end apenas como janela (o span pode diferir do effort).
        // A apuração por intervalo do fluxo web é feita no TimesheetController, que é
        // quem decide quando o intervalo deve prevalecer sobre o total_hours enviado.
        if ($this->effort_minutes !== null && $this->effort_minutes > 0) {
            return;
        }

        $minutes = self::minutesFromInterval($this->start_time, $this->end_time);
        if ($minutes !== null) {
            $this->effort_minutes = $minutes;
        }
    }

    /**
     * Minutos entre início e fim. Fonte única usada pelo model e pelo
     * TimesheetController (store/update) para apurar o TEMPO a partir do intervalo.
     * Aceita Carbon ou string (HH:MM / HH:MM:SS). Trata virada de meia-noite.
     * Retorna null se faltar início ou fim; 0 quando início == fim (não é intervalo).
     */
    public static function minutesFromInterval($start, $end): ?int
    {
        if (empty($start) || empty($end)) {
            return null;
        }

        $startTime = Carbon::parse($start);
        $endTime   = Carbon::parse($end);

        // Se o horário final for menor que o inicial, assumir que passou da meia-noite
        if ($endTime->lt($startTime)) {
            $endTime->addDay();
        }

        return (int) round($startTime->diffInMinutes($endTime));
    }

    /**
     * Converte uma string `total_hours` em minutos (int) ou null se inválido.
     * Aceita 3 formatos:
     *   - HH:MM     ("4:30"   → 270)
     *   - Decimal   ("4.5", "4,5" → 270; "4.25" → 255; "4.75" → 285)
     *   - Inteiro   ("4"      → 240)
     *
     * Centralizado aqui pra que TimesheetController (store + update) e setTotalHours
     * usem a mesma regra. Fração com mais de 2 casas é arredondada.
     */
    public static function parseTotalHoursToMinutes(?string $totalHours): ?int
    {
        if ($totalHours === null || $totalHours === '') {
            return null;
        }
        // HH:MM (minutos 00-59)
        if (preg_match('/^(\d+):([0-5][0-9])$/', $totalHours, $m)) {
            return ((int) $m[1]) * 60 + ((int) $m[2]);
        }
        // Decimal (ponto ou vírgula) ou inteiro puro
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $totalHours, $m)) {
            $hours    = (int) $m[1];
            $fracStr  = $m[2] ?? '';
            $fraction = $fracStr === '' ? 0.0 : (float) ('0.' . $fracStr);
            return $hours * 60 + (int) round($fraction * 60);
        }
        return null;
    }

    /**
     * Converte total_hours (HH:MM ou decimal) para effort_minutes do model.
     */
    public function setTotalHours(string $totalHours): void
    {
        $minutes = self::parseTotalHoursToMinutes($totalHours);
        if ($minutes !== null) {
            $this->effort_minutes = $minutes;
        }
    }

    /**
     * Get effort in hours (computed attribute)
     */
    public function getEffortHoursAttribute(): string
    {
        // TEMPO sempre em DECIMAL (ex.: 480min → "8" ; 150min → "2,5" ; 0 → "0").
        // Vale para todos os perfis/rotinas que exibem este accessor (view, edição, dashboards).
        $h = (float) ($this->effort_minutes ?? 0) / 60;
        $s = number_format($h, 2, ',', '');           // "8,00" | "2,50" | "0,00"
        return rtrim(rtrim($s, '0'), ',') ?: '0';     // "8" | "2,5" | "0"
    }

    /**
     * Accessor para URL do anexo — 100% via nova camada (FASE 11.7).
     */
    public function getAttachmentUrlAttribute(): ?string
    {
        $newUrl = $this->attachmentUrl('attachment');
        if ($newUrl === null) return null;
        return rtrim(config('app.url'), '/') . $newUrl;
    }

    /**
     * Get human readable status
     */
    public function getStatusDisplayAttribute(): string
    {
        return self::getStatuses()[$this->status] ?? $this->status;
    }

    /**
     * Relacionamento com usuário
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Relacionamento com projeto
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // ─── Cronograma ───────────────────────────────────────────────
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(StageDelivery::class, 'stage_delivery_id');
    }

    /** stage_delivery_id sempre implica no stage_id correto (sem divergência). */
    public function setStageDeliveryIdAttribute($value): void
    {
        $this->attributes['stage_delivery_id'] = $value;
        if ($value) {
            $delivery = StageDelivery::find($value);
            if ($delivery) {
                $this->attributes['stage_id'] = $delivery->stage_id;
            }
        }
    }

    /**
     * Projeto REAL do apontamento de investimento (referência). O consumo é contabilizado
     * no project_id (investimento); o real define o coordenador que aprova.
     */
    public function realProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'real_project_id');
    }

    /**
     * Relacionamento com quem revisou
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Relacionamento com estornos de aprovação
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(TimesheetReversal::class);
    }

    /**
     * Scope para filtrar por usuário
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('timesheets.user_id', $userId);
    }

    /**
     * Scope para filtrar por projeto
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('timesheets.project_id', $projectId);
    }

    /**
     * Scope para filtrar por status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('timesheets.status', $status);
    }

    /**
     * Scope para filtrar por período
     */
    public function scopeInPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Scope para timesheets pendentes
     */
    public function scopePending($query)
    {
        return $query->where('timesheets.status', self::STATUS_PENDING);
    }

    /**
     * Scope para timesheets aprovados
     */
    public function scopeApproved($query)
    {
        return $query->where('timesheets.status', self::STATUS_APPROVED);
    }

    /**
     * Scope para timesheets rejeitados
     */
    public function scopeRejected($query)
    {
        return $query->where('timesheets.status', self::STATUS_REJECTED);
    }

    /**
     * Scope para timesheets conflitantes
     */
    public function scopeConflicted($query)
    {
        return $query->where('timesheets.status', self::STATUS_CONFLICTED);
    }

    /**
     * Verifica se o timesheet pode ser editado
     */
    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_REJECTED ||
               $this->status === self::STATUS_CONFLICTED ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED;
    }

    /**
     * Verifica se o timesheet pode ser aprovado
     */
    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_PENDING ||
               $this->status === self::STATUS_CONFLICTED ||
               $this->status === self::STATUS_ADJUSTMENT_REQUESTED;
    }

    /**
     * Verifica se o usuário pode aprovar este timesheet
     */
    public function canBeApprovedBy(User $user): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if (!$this->project) {
            return false;
        }

        // Investimento COMERCIAL: o EXECUTIVO do cliente aprova (mesmo sem ser coordenador).
        // Concede ao executivo; se não for, cai na regra de coordenador abaixo.
        if ($this->project->is_investimento_comercial && $this->project->categoria_interna === 'Comercial'
            && $this->customer_id
            && \App\Models\Customer::where('id', $this->customer_id)->where('executive_id', $user->id)->exists()) {
            return true;
        }

        // QUALQUER coordenador pode aprovar apontamentos (decisão do negócio 2026-07-07).
        // A responsabilidade/coordenador cadastrado do projeto NÃO muda — isto define só
        // quem PODE aprovar. As regras antigas (coord. de sustentação restrito a projetos
        // de sustentação; coord. de projetos restrito aos projetos onde está vinculado)
        // travavam os apontamentos de "Investimento Suporte" — coordenados por um
        // coordenador de sustentação num projeto de service_type 'projeto' — para todos
        // exceto admin: nem o próprio coordenador do projeto conseguia aprovar.
        if ($user->isCoordenador()) {
            return true;
        }

        return false;
    }

    /**
     * Aprovar timesheet
     */
    public function approve(User $approver): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_APPROVED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Rejeitar timesheet
     */
    public function reject(User $approver, string $reason): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_REJECTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;

        $saved = $this->save();

        if ($saved) {
            $timesheet = $this;
            DB::afterCommit(function () use ($timesheet, $reason) {
                app(TimesheetN8nNotifier::class)->notify($timesheet, 'REJEITADO', $reason);
                $timesheet->notifyOwnerOfStatus('REJEITADO', $reason);
            });
        }

        return $saved;
    }

    /**
     * Liberar ação interna
     */
    public function release(User $reviewer): bool
    {
        if ($this->status !== self::STATUS_INTERNAL) {
            return false;
        }

        $this->status      = self::STATUS_RELEASED;
        $this->reviewed_by = $reviewer->id;
        $this->reviewed_at = now();

        return $this->save();
    }

    /**
     * Estornar liberação de ação interna
     */
    public function reverseRelease(): bool
    {
        if ($this->status !== self::STATUS_RELEASED) {
            return false;
        }

        $this->status      = self::STATUS_INTERNAL;
        $this->reviewed_by = null;
        $this->reviewed_at = null;

        return $this->save();
    }

    /**
     * Solicitar ajuste no timesheet
     */
    public function requestAdjustment(User $approver, string $reason): bool
    {
        if (!$this->canBeApprovedBy($approver)) {
            return false;
        }

        $this->status = self::STATUS_ADJUSTMENT_REQUESTED;
        $this->reviewed_by = $approver->id;
        $this->reviewed_at = now();
        $this->rejection_reason = $reason;

        $saved = $this->save();

        if ($saved) {
            $timesheet = $this;
            DB::afterCommit(function () use ($timesheet, $reason) {
                app(TimesheetN8nNotifier::class)->notify($timesheet, 'AJUSTE', $reason);
                $timesheet->notifyOwnerOfStatus('AJUSTE', $reason);
            });
        }

        return $saved;
    }

    /**
     * Envia e-mail para o dono do apontamento avisando da mudança de status.
     * Funciona em paralelo ao webhook n8n (caso de redundância); o destino é
     * sempre o user.email do timesheet — fix da reclamação onde o n8n estava
     * caindo no admin em vez do dono.
     */
    public function notifyOwnerOfStatus(string $statusKey, ?string $reason = null): void
    {
        $owner = $this->user;
        if (!$owner || !$owner->email) {
            return;
        }
        try {
            // Workflow por tipo: rejeição / ajuste têm Central própria; CONFLITO
            // não tem workflow (dono é avisado direto, sem CC de papéis).
            $workflowKey = match ($statusKey) {
                'REJEITADO' => 'timesheet.rejected',
                'AJUSTE'    => 'timesheet.adjustment',
                default     => null,
            };
            $cc = [];
            if ($workflowKey) {
                // Dono é o destinatário; a Central pode adicionar papéis (ex.: coordenador) em cópia.
                $rcpt = app(\App\Workflows\WorkflowRecipientResolver::class)->resolve($workflowKey, [
                    'actor'   => $owner,
                    'project' => $this->project,
                ]);
                $cc = array_values(array_diff(
                    array_merge($rcpt['to'], $rcpt['cc']),
                    [strtolower(trim((string) $owner->email))],
                ));
            }
            $owner->notify((new TimesheetStatusNotification($this, $statusKey, $reason))->withCc($cc));
        } catch (\Throwable $e) {
            \Log::warning('notifyOwnerOfStatus: falha ao enviar', [
                'timesheet_id' => $this->id,
                'status'       => $statusKey,
                'error'        => $e->getMessage(),
            ]);
        }

        // Pop-up in-app (Central de Notificações) para REJEIÇÃO / AJUSTE → leva à tela JÁ filtrada.
        if (in_array($statusKey, ['REJEITADO', 'AJUSTE'], true)) {
            try {
                $this->upsertOwnerPendingPopup($owner, $statusKey === 'REJEITADO', $reason);
            } catch (\Throwable $e) {
                \Log::warning('notifyOwnerOfStatus: pop-up falhou', ['timesheet_id' => $this->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Um pop-up POR PENDÊNCIA, não por apontamento: se o dono já tem um aviso vivo daquela
     * fila (rejeitados ou ajuste), atualiza a contagem em vez de empilhar outro. O lembrete
     * recorrente (actions:remind-pending) continua reenviando no ciclo dele.
     */
    private function upsertOwnerPendingPopup(User $owner, bool $isRej, ?string $reason): void
    {
        $status = $isRej ? self::STATUS_REJECTED : self::STATUS_ADJUSTMENT_REQUESTED;
        $url    = $isRej ? '/timesheets?status=rejected' : '/timesheets?status=adjustment_requested';
        $total  = static::where('user_id', $owner->id)->where('status', $status)->count();

        $dia     = $this->date ? ' de ' . $this->date->format('d/m/Y') : '';
        $motivo  = $reason ? ' Motivo: ' . e($reason) : '';
        $message = $total > 1
            ? ($isRej
                ? "Você tem {$total} apontamentos rejeitados aguardando correção."
                : "Você tem {$total} apontamentos com ajuste solicitado.")
            : ($isRej
                ? "Seu apontamento{$dia} foi rejeitado.{$motivo}"
                : "Foi solicitado ajuste no seu apontamento{$dia}.{$motivo}");

        $attrs = [
            'title'        => $isRej ? 'Apontamento rejeitado' : 'Ajuste solicitado no apontamento',
            'message'      => $message,
            'type'         => 'action',
            'priority'     => $isRej ? 'critical' : 'high',   // rejeitado=vermelho, ajuste=amarelo
            'target_users' => [$owner->id],
            'cta_label'    => 'Ver apontamentos',
            'cta_url'      => $url,
            'send_email'   => false,
            'visible'      => true,
            'created_by'   => $owner->id,
            'expires_at'   => now()->addDays(14),
        ];

        // Só reaproveita aviso INDIVIDUAL deste dono. O lembrete recorrente (fix_ts_rejected)
        // usa a mesma cta_url com vários destinatários — sequestrá-lo cortaria os outros.
        $existing = \App\Models\AppNotification::where('type', 'action')
            ->where('cta_url', $url)
            ->where('visible', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereJsonContains('target_users', $owner->id)
            ->orderByDesc('id')
            ->get()
            ->first(fn ($n) => array_map('intval', $n->target_users ?? []) === [$owner->id]);

        // Sem re-abrir: mexer em `resent_at`/reads é papel do lembrete recorrente.
        $existing ? $existing->forceFill($attrs)->save() : \App\Models\AppNotification::create($attrs);
    }

    /**
     * Verifica se o timesheet pode ter sua aprovação estornada
     */
    public function canBeReversedBy(User $user): bool
    {
        // Apenas timesheets aprovados podem ser estornados
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        // Administradores podem estornar qualquer aprovação
        if ($user->isAdmin()) {
            return true;
        }

        // Qualquer coordenador pode estornar (simétrico a canBeApprovedBy — decisão de
        // negócio 2026-07-07: coordenador aprova → coordenador estorna, sem o limite de 24h).
        if (method_exists($user, 'isCoordenador') && $user->isCoordenador()) {
            return true;
        }

        // Quem aprovou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('timesheets.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar aprovação do timesheet
     */
    public function reverseApproval(User $reverser, string $reason): bool
    {
        if (!$this->canBeReversedBy($reverser)) {
            return false;
        }

        // Criar registro de estorno
        $this->reversals()->create([
            'reversed_by' => $reverser->id,
            'reversal_reason' => $reason,
            'original_approver_id' => $this->reviewed_by,
            'original_approval_date' => $this->reviewed_at,
        ]);

        // Reverter status para pendente
        $this->status = self::STATUS_PENDING;
        $this->reviewed_by = null;
        $this->reviewed_at = null;
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Verifica se o timesheet pode ter sua rejeição estornada
     */
    public function canBeRejectionReversedBy(User $user): bool
    {
        // Apenas timesheets rejeitados ou com ajuste solicitado podem ser estornados
        if (!in_array($this->status, [self::STATUS_REJECTED, self::STATUS_ADJUSTMENT_REQUESTED])) {
            return false;
        }

        // Administradores podem estornar qualquer rejeição
        if ($user->isAdmin()) {
            return true;
        }

        // Qualquer coordenador pode estornar (simétrico a canBeApprovedBy — decisão de
        // negócio 2026-07-07: coordenador aprova/rejeita → coordenador estorna, sem 24h).
        if (method_exists($user, 'isCoordenador') && $user->isCoordenador()) {
            return true;
        }

        // Quem rejeitou pode estornar dentro do período permitido
        if ($this->reviewed_by === $user->id) {
            $reversalPeriod = config('timesheets.reversal_period_hours', 24);
            $reversalDeadline = $this->reviewed_at->addHours($reversalPeriod);

            return now()->lt($reversalDeadline);
        }

        return false;
    }

    /**
     * Estornar rejeição do timesheet
     */
    public function reverseRejection(User $reverser, string $reason): bool
    {
        if (!$this->canBeRejectionReversedBy($reverser)) {
            return false;
        }

        // Criar registro de estorno
        $this->reversals()->create([
            'reversed_by' => $reverser->id,
            'reversal_reason' => $reason,
            'original_approver_id' => $this->reviewed_by,
            'original_approval_date' => $this->reviewed_at,
        ]);

        // Reverter status para pendente
        $this->status = self::STATUS_PENDING;
        $this->reviewed_by = null;
        $this->reviewed_at = null;
        $this->rejection_reason = null;

        return $this->save();
    }

    /**
     * Reavalia timesheets conflitados do usuário na data informada.
     *
     * Regra: um apontamento só permanece em `conflicted` enquanto houver
     * OUTRO apontamento do MESMO cliente/data sobreposto que AINDA NÃO
     * TENHA SIDO TRATADO MANUALMENTE pelo admin/coord.
     *
     * "Tratado manualmente" = qualquer status diferente de pending/conflicted
     * (approved, rejected, adjustment_requested, internal, released). Esses
     * já passaram por decisão humana — não fazem mais sentido travar o par
     * como conflicted indefinidamente.
     *
     * Se o overlap restante já foi tratado (ou sumiu), volta pra `pending`
     * pra que o ciclo de aprovação continue.
     */
    public static function resolveStaleConflicts(int $userId, string $date): void
    {
        static::where('user_id', $userId)
            ->where('date', $date)
            ->where('status', self::STATUS_CONFLICTED)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get()
            ->each(function ($ts) {
                $stillConflicts = static::where('user_id', $ts->user_id)
                    ->where('customer_id', $ts->customer_id)
                    ->where('date', $ts->date)
                    ->where('id', '!=', $ts->id)
                    // Considera "em disputa" SOMENTE outro pending ou conflicted.
                    // Approved/rejected/adjustment_requested/internal/released =
                    // decisão humana tomada, libera o par.
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFLICTED])
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->where('start_time', '<', $ts->end_time)
                    ->where('end_time', '>', $ts->start_time)
                    ->exists();
                if (!$stillConflicts) {
                    $ts->status = self::STATUS_PENDING;
                    $ts->save();
                }
            });
    }
}
