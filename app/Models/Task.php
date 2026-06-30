<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Tarefa rápida pessoal (Smart To-Do). Sempre escopada ao user_id. */
class Task extends Model
{
    protected $fillable = [
        'user_id', 'created_by', 'assigned_to', 'completed_by', 'group_item_id', 'title', 'description',
        'due_date', 'due_time', 'completed', 'completed_at',
        'type', 'priority', 'entity_type', 'entity_id', 'recurrence_type', 'recurrence_interval', 'recurrence_weekdays', 'recurrence_end_date',
    ];

    protected $casts = [
        'due_date'            => 'date:Y-m-d',
        'recurrence_end_date' => 'date:Y-m-d',
        'completed'           => 'boolean',
        'completed_at'        => 'datetime',
        'recurrence_interval' => 'integer',
        'recurrence_weekdays' => 'array',
    ];

    public const TYPES = ['pessoal', 'cliente', 'follow-up', 'interno'];
    public const PRIORITIES = ['baixa', 'media', 'alta'];
    public const ENTITY_TYPES = ['customer', 'project', 'contract'];
    public const RECURRENCES = ['none', 'daily', 'weekly', 'monthly'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function completer(): BelongsTo { return $this->belongsTo(User::class, 'completed_by'); }

    /** Próxima data de recorrência a partir de uma base. Diário/semanal com dias = próximo dia marcado. */
    public function nextDueDate(?Carbon $base = null): ?Carbon
    {
        $base ??= $this->due_date ? $this->due_date->copy() : now()->startOfDay();
        $i = max(1, (int) $this->recurrence_interval);
        $days = array_values(array_filter(array_map('intval', (array) ($this->recurrence_weekdays ?? [])), fn ($d) => $d >= 0 && $d <= 6));

        return match ($this->recurrence_type) {
            'daily'   => $days ? $this->nextWeekday($base, $days) : $base->copy()->addDays($i),
            'weekly'  => $days ? $this->nextWeekday($base, $days) : $base->copy()->addWeeks($i),
            'monthly' => $base->copy()->addMonthsNoOverflow($i),
            default   => null,
        };
    }

    /** Próxima data (após $base) cujo dia da semana está em $days (0=dom..6=sáb). */
    private function nextWeekday(Carbon $base, array $days): Carbon
    {
        $d = $base->copy()->addDay();
        for ($k = 0; $k < 14; $k++) {
            if (in_array($d->dayOfWeek, $days, true)) return $d->copy();
            $d->addDay();
        }
        return $base->copy()->addWeek();
    }
}
