<?php

namespace App\Models\Financeiro;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Favorecido de contas a pagar. Consultores (via cooperativa, ex. "NOVACOP – nome") apontam
 * para o user; parceiros para partners. O título a pagar é por favorecido; a baixa pode
 * aglutinar vários títulos num único movimento bancário.
 */
class FinPayee extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'fin_payees';

    protected $fillable = [
        'company_id', 'tipo', 'user_id', 'partner_id', 'nome', 'documento', 'email',
        'pix_tipo', 'pix_chave', 'banco_codigo', 'agencia', 'conta', 'chart_account_id', 'ativo',
    ];

    protected $casts = ['ativo' => 'boolean'];

    public const TIPOS = ['consultor', 'cooperativa', 'parceiro', 'diretoria', 'fornecedor'];
    public const PIX_TIPOS = ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function partner(): BelongsTo { return $this->belongsTo(Partner::class); }
    public function chartAccount(): BelongsTo { return $this->belongsTo(FinChartAccount::class, 'chart_account_id'); }

    public function setDocumentoAttribute($v): void
    {
        $this->attributes['documento'] = $v === null ? null : (preg_replace('/\D/', '', (string) $v) ?: null);
    }
}
