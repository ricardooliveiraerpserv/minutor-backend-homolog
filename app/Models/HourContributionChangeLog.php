<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HourContributionChangeLog extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

    protected $table = 'hour_contribution_change_logs';

    protected $fillable = [
        'hour_contribution_id',
        'project_id',
        'changed_by',
        'field_name',
        'old_value',
        'new_value',
        'reason',
        'effective_from',
    ];

    protected $casts = [
        'effective_from' => 'date:Y-m-d',
    ];

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(HourContribution::class, 'hour_contribution_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Rótulo amigável do campo alterado. */
    public function getFieldLabel(): string
    {
        return [
            'hourly_rate'       => 'Valor da Hora',
            'contributed_hours' => 'Horas',
            'contributed_at'    => 'Mês de Vigência',
            'deleted'           => 'Exclusão do aporte',
        ][$this->field_name] ?? $this->field_name;
    }

    /** Formata o valor conforme o campo (moeda / horas / data). */
    public function formatValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return match ($this->field_name) {
            'hourly_rate'       => 'R$ ' . number_format((float) $value, 2, ',', '.'),
            'contributed_hours' => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',') . 'h',
            'contributed_at'    => \Illuminate\Support\Carbon::parse($value)->format('m/Y'),
            default             => (string) $value,
        };
    }

    public function toFormattedArray(): array
    {
        return [
            'id'                   => $this->id,
            'field_name'           => $this->field_name,
            'field_label'          => $this->getFieldLabel(),
            'old_value'            => $this->old_value,
            'new_value'            => $this->new_value,
            'old_value_formatted'  => $this->formatValue($this->old_value),
            'new_value_formatted'  => $this->formatValue($this->new_value),
            'reason'               => $this->reason,
            'effective_from'       => $this->effective_from?->toDateString(),
            'created_at'           => $this->created_at?->toIso8601String(),
            'changed_by_user'      => $this->changedByUser ? [
                'id'    => $this->changedByUser->id,
                'name'  => $this->changedByUser->name,
                'email' => $this->changedByUser->email,
            ] : null,
        ];
    }
}
