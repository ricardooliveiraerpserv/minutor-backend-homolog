<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E-mail avulso (destinatário adicional) do alerta de consumo de horas de um contrato.
 * Não é contato: só serve como destinatário extra do alerta daquele contrato.
 */
class ContractAlertExtraEmail extends Model
{
    protected $fillable = ['contract_id', 'email', 'normalized_email'];

    protected static function booted(): void
    {
        // normalized_email é sempre derivado de email (lower + trim) — garante o dedup
        // case-insensitive do índice único, independente de quem escreveu.
        static::saving(function (ContractAlertExtraEmail $m) {
            $m->normalized_email = mb_strtolower(trim((string) $m->email));
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
