<?php

namespace App\SourceCode;

use App\Models\SourceSemanticBlobCache;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bloco 4.2.1-A — reuso semântico PERSISTENTE por blob. Reusa a ANÁLISE, nunca o documento.
 *
 * Chave de compatibilidade: blob_sha + facts_version + schema_version + prompt_version + model.
 * facts_version representa a versão do contrato determinístico/de fatos — se o parser evoluir,
 * bumpar facts_version invalida semânticas antigas (não congela interpretação velha p/ sempre).
 *
 * Auditoria: registra HIT/MISS + origem (first_source_doc_id). Feature flag p/ rollback.
 * NÃO é fonte da verdade — a tabela é derivada/reconstruível.
 */
class SemanticBlobReuse
{
    public function enabled(): bool
    {
        return (bool) config('services.source_doc_ai.blob_reuse_enabled', true);
    }

    private function model(): string
    {
        return (string) config('services.source_doc_ai.model', config('services.anthropic.model', 'claude-sonnet-5'));
    }

    /** Partes da chave de contrato (sem o blob). */
    public function contractKey(): array
    {
        return [
            'facts_version'   => (int) config('services.source_doc_ai.facts_version', 1),
            'schema_version'  => SourceDocSemanticAnalyzer::SCHEMA_VERSION,
            'prompt_version'  => (int) config('services.source_doc_ai.prompt_version', 2),
            'model'           => $this->model(),
        ];
    }

    /**
     * Busca semântica reutilizável para (blob + contrato atual). Retorna o semantic_json (marcado
     * com bloco 'reuse') ou null. Em HIT, incrementa hits e audita.
     */
    public function get(?string $blob, ?int $sourceDocId = null, string $contextFingerprint = ''): ?array
    {
        if (! $this->enabled() || ! $blob) {
            return null;
        }
        $k = $this->contractKey();
        $row = SourceSemanticBlobCache::query()
            ->where('blob_sha', $blob)
            ->where('facts_version', $k['facts_version'])
            ->where('schema_version', $k['schema_version'])
            ->where('prompt_version', $k['prompt_version'])
            ->where('model', $k['model'])
            // Fase 3 — contexto EXTERNO faz parte da chave: blob A + contexto B NÃO reutiliza blob A + contexto C.
            // '' (self-contained neutro) casa com as linhas antigas → reuso sem contexto permanece intacto.
            ->where('context_fingerprint', $contextFingerprint)
            ->first();

        if (! $row) {
            $this->audit('MISS', $blob, $sourceDocId, null);
            return null;
        }

        SourceSemanticBlobCache::whereKey($row->id)->update(['hits' => DB::raw('hits + 1')]);
        $this->audit('HIT', $blob, $sourceDocId, $row->first_source_doc_id);

        $sem = is_array($row->semantic_json) ? $row->semantic_json : [];
        // marca proveniência do reuso NO próprio semantic_json (auditável na ficha/DOCX).
        $sem['strategy'] = 'reuse_blob_persistent';
        $sem['reuse'] = [
            'hit'                    => true,
            'origin_source_doc_id'   => $row->first_source_doc_id,
            'facts_version'          => $k['facts_version'],
            'schema_version'         => $k['schema_version'],
            'prompt_version'         => $k['prompt_version'],
            'model'                  => $k['model'],
        ];
        // zera custo/tokens desta ocorrência (a IA não foi chamada aqui).
        $sem['usage'] = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'actual_cost_usd' => 0.0, 'reused' => true]
            + (array) ($sem['usage'] ?? []);
        return $sem;
    }

    /**
     * Persiste a semântica recém-analisada para reuso futuro. Só guarda resultados APROVEITÁVEIS
     * (completed/partial com conteúdo). NÃO guarda skip por custo/estrutura (repetir é barato/vazio).
     */
    public function put(?string $blob, array $semantic, ?int $sourceDocId = null, string $contextFingerprint = ''): void
    {
        if (! $this->enabled() || ! $blob) {
            return;
        }
        $status = (string) ($semantic['status'] ?? '');
        if (! in_array($status, ['completed', 'partial'], true)) {
            return; // pending/failed/skipped_* não valem reuso
        }
        // não re-armazena um resultado que já veio de reuso.
        if (! empty($semantic['reuse']['hit'])) {
            return;
        }
        $k = $this->contractKey();
        SourceSemanticBlobCache::updateOrCreate(
            [
                'blob_sha'            => $blob,
                'facts_version'       => $k['facts_version'],
                'schema_version'      => $k['schema_version'],
                'prompt_version'      => $k['prompt_version'],
                'model'               => $k['model'],
                'context_fingerprint' => $contextFingerprint, // Fase 3 — isola semânticas com contexto externo distinto
            ],
            [
                'semantic_json'       => $semantic,
                'first_source_doc_id' => $sourceDocId,
            ]
        );
    }

    private function audit(string $event, string $blob, ?int $sourceDocId, ?int $originDocId): void
    {
        // baixo volume; sem dados sensíveis (só sha curto + ids).
        Log::channel(config('logging.default'))->info('source_doc_ai.blob_reuse', [
            'event'          => $event,
            'blob'           => substr($blob, 0, 12),
            'source_doc_id'  => $sourceDocId,
            'origin_doc_id'  => $originDocId,
        ]);
    }
}
