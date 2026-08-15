<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotografia IMUTÁVEL da documentação de um fonte em um commit do CÓDIGO (source_commit_sha).
 * `source_blob_sha` (Bloco 3) = git blob SHA do CONTEÚDO daquele fonte nessa versão — a identidade
 * técnica imutável usada para responder "a doc bate com o arquivo atual no Git?" (blob × blob,
 * nunca commit HEAD). Faz parte da identidade: depois de completed, não muda (guard abaixo).
 * Regra: uma versão CONCLUÍDA (analysis_status='completed') nunca é sobrescrita nem excluída —
 * nova alteração do fonte gera uma NOVA versão. Enquanto pending/extracting/analyzing/partial/failed
 * pode ser atualizada (reprocessamento) até concluir. documentation_commit_sha NUNCA substitui
 * source_commit_sha (código × documento são entidades diferentes).
 */
class SourceDocVersion extends Model
{
    protected $fillable = [
        'source_doc_id', 'source_commit_sha', 'source_blob_sha', 'parent_source_commit_sha', 'documentation_commit_sha',
        'gmud_id', 'ticket_number', 'responsible_user_id', 'responsavel',
        'deterministic_json', 'semantic_json', 'documentation_json', 'diff_summary', 'diff_stats',
        'analysis_status', 'schema_version',
    ];

    protected $casts = [
        'deterministic_json' => 'array',
        'semantic_json'      => 'array',
        'documentation_json' => 'array',
        'diff_stats'         => 'array',
        'schema_version'     => 'integer',
    ];

    protected static function booted(): void
    {
        // Imutabilidade: a ANÁLISE de uma versão concluída não muda. Exceção: gravar o
        // documentation_commit_sha (metadado do export ao Git, posterior à análise) é permitido.
        static::updating(function (SourceDocVersion $v) {
            if ($v->getOriginal('analysis_status') === 'completed') {
                $changed = array_diff(array_keys($v->getDirty()), ['documentation_commit_sha', 'updated_at']);
                if (!empty($changed)) {
                    throw new \RuntimeException("source_doc_version #{$v->id} está concluída e é imutável.");
                }
            }
        });
        static::deleting(function (SourceDocVersion $v) {
            throw new \RuntimeException("source_doc_version #{$v->id} não pode ser excluída (histórico imutável).");
        });
    }

    public function doc(): BelongsTo
    {
        return $this->belongsTo(SourceDoc::class, 'source_doc_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function gmud(): BelongsTo
    {
        return $this->belongsTo(HelpDeskTicket::class, 'gmud_id');
    }
}
