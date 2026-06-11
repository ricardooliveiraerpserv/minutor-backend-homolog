<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoDiretoriaItem extends Model
{
    protected $table = 'fechamento_diretoria_itens';

    protected $fillable = [
        'year_month',
        'user_id',
        'ordem',
        'descricao',
        'tipo',
        'valor',
        'cooperativa',
        'repasse',
        'taxa_inss',
        'valor_receber',
        'observacao',
        'observacao_itens',
        'destaque',
        'created_by',
    ];

    protected $casts = [
        'ordem'            => 'integer',
        'valor'            => 'decimal:2',
        'repasse'          => 'decimal:2',
        'taxa_inss'        => 'decimal:2',
        'valor_receber'    => 'decimal:2',
        'observacao_itens' => 'array',
        'destaque'         => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
