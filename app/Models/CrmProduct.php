<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CRM — catálogo de Produtos e Serviços (com mapeamento p/ conversão em contrato). */
class CrmProduct extends Model
{
    use \App\Models\Concerns\BelongsToCompany;
    protected $fillable = [
        'name', 'categoria', 'tipo_precificacao', 'valor', 'descricao_tecnica', 'ativo',
        'contract_type_id', 'service_type_id', 'tipo_faturamento', 'categoria_contrato',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'ativo' => 'boolean',
    ];

    public const CATEGORIAS = [
        'Licenciamento', 'Implantação', 'Sustentação', 'Banco de Horas',
        'Pacote de Horas', 'Projeto Fechado', 'Treinamento', 'Customização',
    ];

    public const PRECIFICACOES = ['hora', 'projeto', 'mensal', 'licenca'];

    public function contractType(): BelongsTo
    {
        return $this->belongsTo(ContractType::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
