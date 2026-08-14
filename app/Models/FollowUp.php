<?php

namespace App\Models;

use App\Attachments\Concerns\HasGlobalAttachments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FollowUp extends Model
{
    use HasFactory, SoftDeletes, HasGlobalAttachments;

    public const STATUS_PENDING       = 'pending';
    public const STATUS_IN_PROGRESS   = 'in_progress';
    public const STATUS_WAITING_THIRD = 'waiting_third';
    public const STATUS_COMPLETED     = 'completed';
    public const STATUS_CANCELLED     = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_THIRD,
        self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    public const WAITING_SUBTYPES = ['client', 'partner', 'supplier', 'approval'];

    public const CATEGORIES = [
        'reuniao', 'projeto', 'cliente', 'aprovacao', 'homologacao',
        'financeiro', 'comercial', 'juridico', 'suporte', 'outro',
    ];

    public const PRIORITIES = ['baixa', 'media', 'alta', 'critica'];

    // Nível de origem (vínculo onde nasceu) — do mais profundo ao mais raso.
    public const ORIGIN_ACTIVITY = 'activity';
    public const ORIGIN_STAGE    = 'stage';
    public const ORIGIN_PROJECT  = 'project';
    public const ORIGIN_CONTRACT = 'contract';
    public const ORIGIN_COMPANY  = 'company';

    protected $fillable = [
        'title', 'description', 'status', 'waiting_subtype', 'category', 'priority',
        'due_date', 'responsible_user_id', 'requester_user_id',
        'client_involved', 'client_user_id', 'client_email',
        'customer_id', 'contract_id', 'project_id', 'stage_id', 'delivery_id', 'origin_type',
        'created_by', 'completed_at', 'completed_by', 'kanban_order', 'sla_paused_at',
    ];

    protected $casts = [
        'due_date'        => 'date:Y-m-d',
        'completed_at'    => 'datetime',
        'sla_paused_at'   => 'datetime',
        'kanban_order'    => 'integer',
        'client_involved' => 'boolean',
    ];

    protected $appends = ['status_display', 'is_overdue', 'days_overdue'];

    public static function attachmentEntityType(): string
    {
        return 'FOLLOW_UP';
    }

    // ── Relations ───────────────────────────────────────────────────────────
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
    public function requester(): BelongsTo   { return $this->belongsTo(User::class, 'requester_user_id'); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function completedBy(): BelongsTo  { return $this->belongsTo(User::class, 'completed_by'); }
    public function client(): BelongsTo       { return $this->belongsTo(User::class, 'client_user_id'); }

    /** Cliente $user pode ver este Follow Up (timeline/anexos)? Só se for o envolvido. */
    public function clientCanSee(User $user): bool
    {
        return $this->client_involved
            && ($this->client_user_id === $user->id
                || ($this->client_email && strcasecmp((string) $this->client_email, (string) $user->email) === 0));
    }
    public function customer(): BelongsTo     { return $this->belongsTo(Customer::class); }
    public function contract(): BelongsTo     { return $this->belongsTo(Contract::class); }
    public function project(): BelongsTo      { return $this->belongsTo(Project::class); }
    public function stage(): BelongsTo        { return $this->belongsTo(ProjectStage::class, 'stage_id'); }
    public function delivery(): BelongsTo     { return $this->belongsTo(StageDelivery::class, 'delivery_id'); }
    public function events(): HasMany         { return $this->hasMany(FollowUpEvent::class)->orderByDesc('created_at'); }

    // ── Scopes ──────────────────────────────────────────────────────────────
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS]);
    }

    public function scopeOverdue(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString());
    }

    // ── Accessors ───────────────────────────────────────────────────────────
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING       => 'Pendente',
            self::STATUS_IN_PROGRESS   => 'Em andamento',
            self::STATUS_WAITING_THIRD => 'Aguardando terceiro',
            self::STATUS_COMPLETED     => 'Concluído',
            self::STATUS_CANCELLED     => 'Cancelado',
            default                    => $this->status,
        };
    }

    /** Atrasado: vencido e ainda aberto (waiting_third pausa o SLA → não conta). */
    public function getIsOverdueAttribute(): bool
    {
        if (!$this->due_date) return false;
        if (!in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS])) return false;
        return $this->due_date->isPast() && !$this->due_date->isToday();
    }

    public function getDaysOverdueAttribute(): ?int
    {
        if (!$this->is_overdue) return null;
        return (int) $this->due_date->startOfDay()->diffInDays(now()->startOfDay());
    }
}
