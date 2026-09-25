<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Certificado (A1) de um ambiente. Metadados/validade em CLARO (alertas de
 * vencimento na Fase 2). A SENHA do PFX é segredo (env_secrets); o ARQUIVO .pfx
 * é cifrado no client e sobe como anexo .enc (entity_type ENV_CERT_PFX).
 */
class EnvCertificate extends Model
{
    use SoftDeletes;

    protected $table = 'env_certificates';

    protected $fillable = [
        'environment_id', 'name', 'type', 'issuer', 'subject', 'thumbprint',
        'valid_from', 'valid_to', 'responsible_user_id', 'pfx_pass_secret_id',
        'critical', 'notes', 'created_by',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to'   => 'date',
        'critical'   => 'boolean',
    ];

    public function environment(): BelongsTo { return $this->belongsTo(EnvEnvironment::class, 'environment_id'); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
