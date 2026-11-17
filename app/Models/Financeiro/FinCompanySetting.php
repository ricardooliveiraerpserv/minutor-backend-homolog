<?php

namespace App\Models\Financeiro;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuração fiscal da empresa emissora (1 por company). O certificado A1 e a senha
 * ficam cifrados com a APP_KEY (cast encrypted) e NUNCA saem em JSON ($hidden).
 */
class FinCompanySetting extends Model
{
    protected $table = 'fin_company_settings';

    protected $fillable = [
        'company_id', 'razao_social', 'inscricao_municipal', 'regime_tributario', 'codigo_ibge',
        'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf',
        'aliquota_iss', 'aliquota_irrf', 'aliquota_csll', 'aliquota_pis', 'aliquota_cofins',
        'aliquota_cbs', 'aliquota_ibs', 'codigo_tributacao_nacional',
        'dps_serie', 'dps_proximo_numero', 'nfse_ambiente', 'nfse_emissor',
    ];

    protected $hidden = ['certificado_pfx', 'certificado_senha'];

    // Espelha os defaults do banco para o model recém-criado já sair completo.
    protected $attributes = ['nfse_ambiente' => 'homologacao', 'dps_proximo_numero' => 1, 'nfse_emissor' => 'simulado'];

    protected $casts = [
        'certificado_pfx' => 'encrypted',
        'certificado_senha' => 'encrypted',
        'certificado_valido_ate' => 'datetime',
        'certificado_enviado_em' => 'datetime',
        'dps_proximo_numero' => 'integer',
        'aliquota_iss' => 'decimal:4', 'aliquota_irrf' => 'decimal:4', 'aliquota_csll' => 'decimal:4',
        'aliquota_pis' => 'decimal:4', 'aliquota_cofins' => 'decimal:4',
        'aliquota_cbs' => 'decimal:4', 'aliquota_ibs' => 'decimal:4',
    ];

    protected $appends = ['tem_certificado'];

    public const AMBIENTES = ['homologacao', 'producao'];
    public const REGIMES = ['simples', 'lucro_presumido', 'lucro_real'];
    public const EMISSORES = ['simulado', 'nacional'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function enviadoPor(): BelongsTo { return $this->belongsTo(User::class, 'certificado_enviado_por'); }

    public function getTemCertificadoAttribute(): bool
    {
        return ! empty($this->attributes['certificado_pfx']);
    }

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(['company_id' => $companyId]);
    }
}
