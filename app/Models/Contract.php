<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'status', 'categoria', 'service_type_id', 'contract_type_id',
        'tipo_faturamento', 'cobra_despesa_cliente', 'limite_despesa',
        'architect_id', 'tipo_alocacao', 'horas_contratadas',
        'valor_projeto', 'valor_hora', 'hora_adicional', 'pct_horas_coordenador', 'horas_consultor',
        'expectativa_inicio', 'condicao_pagamento',
        'executivo_conta_id', 'vendedor_id', 'observacoes', 'project_code_preview',
        'project_id', 'generated_at', 'generated_by_id',
        'approved_by_id', 'approved_at', 'created_by_id',
    ];

    protected $casts = [
        'cobra_despesa_cliente'  => 'boolean',
        'expectativa_inicio'     => 'date:Y-m-d',
        'generated_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'horas_contratadas'      => 'integer',
        'horas_consultor'        => 'integer',
        'valor_projeto'          => 'decimal:2',
        'valor_hora'             => 'decimal:2',
        'hora_adicional'         => 'decimal:2',
        'pct_horas_coordenador'  => 'decimal:2',
        'limite_despesa'         => 'decimal:2',
        'created_at'             => 'datetime',
        'updated_at'             => 'datetime',
        'deleted_at'             => 'datetime',
    ];

    const STATUS_RASCUNHO          = 'rascunho';
    const STATUS_APROVADO          = 'aprovado';
    const STATUS_INICIO_AUTORIZADO = 'inicio_autorizado';
    const STATUS_ATIVO             = 'ativo';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function architect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'architect_id');
    }

    public function executivoConta(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executivo_conta_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ContractContact::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ContractAttachment::class);
    }
}
