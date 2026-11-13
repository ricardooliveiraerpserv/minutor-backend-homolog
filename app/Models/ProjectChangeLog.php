<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectChangeLog extends Model
{
    use \App\Models\Concerns\BelongsToCompany;

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
     * Campos numéricos em que null/'' e 0 significam a mesma coisa (saldo/custo inicial "sem valor"
     * = zero). Fora desta lista null ≠ 0 (ex.: limite de despesa null = sem limite).
     */
    public const NULL_EQUALS_ZERO_FIELDS = [
        'initial_cost', 'initial_hours_balance', 'initial_hours_consumed',
    ];

    /**
     * true quando "antes" e "depois" são o MESMO valor, só com outra representação
     * (ex.: decimal "0.00" → "0", "150.5" → "150.50", true → "1", data com/sem hora).
     * Usado para não gravar (Observer) e não exibir (Auditoria) alterações fantasmas.
     */
    public static function isNoopChange(string $field, mixed $old, mixed $new): bool
    {
        $norm = function (mixed $v) {
            if (is_bool($v)) return $v ? '1' : '0';
            if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
            if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($v === null) return null;
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        };
        $o = $norm($old);
        $n = $norm($new);

        if (in_array($field, self::NULL_EQUALS_ZERO_FIELDS, true)) {
            if ($o === null && is_numeric($n)) $o = '0';
            if ($n === null && is_numeric($o)) $n = '0';
        }
        if ($o === $n) return true;
        if ($o === null || $n === null) return false;

        // Números: compara pelo valor (até 6 casas), não pelo texto.
        if (is_numeric($o) && is_numeric($n)) {
            return abs((float) $o - (float) $n) < 0.000001;
        }
        // Booleanos gravados como texto.
        $boolMap = ['true' => '1', 'false' => '0'];
        if (isset($boolMap[strtolower($o)]) || isset($boolMap[strtolower($n)])) {
            return ($boolMap[strtolower($o)] ?? $o) === ($boolMap[strtolower($n)] ?? $n);
        }
        // Datas: "2026-01-01" ≡ "2026-01-01 00:00:00" ≡ "2026-01-01T00:00:00.000000Z".
        $dateRe = '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';
        if (preg_match($dateRe, $o) && preg_match($dateRe, $n)) {
            $to = strtotime($o);
            $tn = strtotime($n);
            return $to !== false && $to === $tn;
        }
        // JSON equivalente (ordem/espaços diferentes).
        if (($o[0] === '{' || $o[0] === '[') && ($n[0] === '{' || $n[0] === '[')) {
            $jo = json_decode($o, true);
            $jn = json_decode($n, true);
            return $jo !== null && $jo == $jn;
        }
        return false;
    }

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
