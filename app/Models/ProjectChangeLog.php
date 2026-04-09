<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectChangeLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'project_change_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
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

    /**
     * Get the project that owns the change log.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the user who made the change.
     */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Cria um registro de histórico formatado para exibição.
     *
     * @return array
     */
    public function toFormattedArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'changed_by' => $this->changed_by,
            'field_name' => $this->field_name,
            'field_label' => $this->getFieldLabel(),
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'old_value_formatted' => $this->formatValue($this->old_value),
            'new_value_formatted' => $this->formatValue($this->new_value),
            'reason' => $this->reason,
            'effective_from' => $this->effective_from?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'changed_by_user' => [
                'id' => $this->changedByUser->id,
                'name' => $this->changedByUser->name,
                'email' => $this->changedByUser->email,
            ],
        ];
    }

    /**
     * Retorna o label legível do campo alterado.
     *
     * @return string
     */
    private function getFieldLabel(): string
    {
        $labels = [
            'project_value' => 'Valor do Projeto',
            'hourly_rate' => 'Valor/Hora',
            'sold_hours' => 'Horas Vendidas',
            'hour_contribution' => 'Aporte de Horas',
            'exceeded_hour_contribution' => 'Aporte de Horas Excedidas',
            'consultant_hours' => 'Horas por Consultor',
            'coordinator_hours' => 'Percentual Horas Coordenador',
            'additional_hourly_rate' => 'Valor/Hora Adicional',
            'max_expense_per_consultant' => 'Despesa Máxima por Consultor',
            'unlimited_expense' => 'Despesa Ilimitada',
            'expense_responsible_party' => 'Responsável pelas Despesas',
        ];

        return $labels[$this->field_name] ?? $this->field_name;
    }

    /**
     * Formata o valor para exibição.
     *
     * @param mixed $value
     * @return string|null
     */
    private function formatValue($value): ?string
    {
        if ($value === null) {
            return '-';
        }

        // Campos monetários
        if (in_array($this->field_name, [
            'project_value',
            'hourly_rate',
            'hour_contribution',
            'exceeded_hour_contribution',
            'additional_hourly_rate',
            'max_expense_per_consultant',
        ])) {
            return 'R$ ' . number_format((float)$value, 2, ',', '.');
        }

        // Campos de horas
        if (in_array($this->field_name, [
            'sold_hours',
            'consultant_hours',
            'coordinator_hours',
        ])) {
            return $value . ' horas';
        }

        // Campo booleano
        if ($this->field_name === 'unlimited_expense') {
            return $value ? 'Sim' : 'Não';
        }

        // Campo de responsável pelas despesas
        if ($this->field_name === 'expense_responsible_party') {
            return $value === 'consultancy' ? 'Consultoria' : 'Cliente';
        }

        return (string)$value;
    }
}
