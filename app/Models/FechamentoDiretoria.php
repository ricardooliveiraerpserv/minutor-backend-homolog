<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FechamentoDiretoria extends Model
{
    protected $table = 'fechamento_diretoria';

    public const STATUS_ABERTO     = 'aberto';
    public const STATUS_FINALIZADO = 'finalizado';

    protected $fillable = [
        'user_id',
        'year_month',
        'cooperativa',
        'taxa_inss',
        'valor_coop_erpserv',
        'valor_coop_bizify',
        'taxa_coop_erpserv',
        'taxa_coop_bizify',
        'status',
        'finalized_at',
        'finalized_by',
    ];

    protected $casts = [
        'taxa_inss'          => 'decimal:2',
        'valor_coop_erpserv' => 'decimal:2',
        'valor_coop_bizify'  => 'decimal:2',
        'taxa_coop_erpserv'  => 'decimal:2',
        'taxa_coop_bizify'   => 'decimal:2',
        'finalized_at'       => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinalizado(): bool
    {
        return $this->status === self::STATUS_FINALIZADO;
    }
}
