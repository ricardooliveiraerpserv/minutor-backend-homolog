<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * C6 — CompileContext: fatores OBSERVADOS capazes de influenciar a saída da compilação (compiler/version/
 * build/patch, flags, includes, dependencies, runtime, e outros descobertos). NÃO há fórmula/hash de
 * CompileContextIdentity ainda (C6.1 P5): primeiro descobrir os inputs reais, depois propor. Sem secret/path.
 */
class CompileContext extends Model
{
    protected $table = 'compile_contexts';
    protected $guarded = ['id'];
    protected $casts = ['factors' => 'array', 'captured_at' => 'datetime'];

    public function request()
    {
        return $this->belongsTo(CompileRequest::class, 'compile_request_id');
    }
}
