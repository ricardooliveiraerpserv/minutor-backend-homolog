<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItem extends Model
{
    public const TIPOS = ['setup', 'desenvolvimento', 'setup_dev'];

    public const TIPO_LABEL = [
        'setup'           => 'Setup',
        'desenvolvimento' => 'Desenvolvimento',
        'setup_dev'       => 'Setup + Desenvolvimento',
    ];

    protected $fillable = [
        'contract_id', 'tipo', 'descricao',
        'valor_projeto', 'valor_hora', 'horas_contratadas', 'hora_adicional',
        'tipo_faturamento', 'condicao_pagamento',
        'pct_horas_coordenador', 'horas_coordenacao', 'horas_consultor',
        'letter', 'project_id', 'child_contract_id',
    ];

    protected $casts = [
        'valor_projeto'      => 'decimal:2',
        'valor_hora'         => 'decimal:2',
        'hora_adicional'     => 'decimal:2',
        'horas_coordenacao'  => 'decimal:2',
        'horas_consultor'    => 'decimal:2',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Card de contrato (Fechado) que este item gerou no Kanban de Contratos. */
    public function childContract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'child_contract_id');
    }

    public function tipoLabel(): string
    {
        return self::TIPO_LABEL[$this->tipo] ?? $this->tipo;
    }
}
