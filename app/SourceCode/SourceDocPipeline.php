<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SecretSanitizer;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use Illuminate\Support\Facades\Log;

/**
 * Fase 3 — pipeline de documentação de UM fonte (após o commit do código). Orquestra as camadas
 * (sanitização → determinística → diff → semântica), persiste no documento VIVO (source_docs) +
 * versão IMUTÁVEL (source_doc_versions) e registra a máquina de estados
 * (pending→extracting→analyzing→completed/partial/failed). Best-effort: NUNCA quebra a GMUD.
 * Suporta reprocessamento (mesma source_commit_sha atualiza a versão até concluir).
 */
class SourceDocPipeline
{
    public const DOC_SCHEMA_VERSION = 1;

    public function __construct(
        private GithubAppAuth $auth,
        private SecretSanitizer $sanitizer,
        private AdvplAnalyzer $analyzer,
        private SourceDiff $differ,
        private SourceDocSemanticAnalyzer $semantic,
    ) {
    }

    /**
     * @param array $ctx customer_id, source_repo_id, owner, repository, branch, path, tipo,
     *                   new_code, old_code, source_commit_sha, parent_source_commit_sha,
     *                   gmud_id, ticket_number, responsible_user_id, responsavel
     */
    public function processFile(array $ctx, bool $runSemantic = true): SourceDocVersion
    {
        $doc = SourceDoc::firstOrNew([
            'owner' => $ctx['owner'], 'repository' => $ctx['repository'], 'branch' => $ctx['branch'], 'path' => $ctx['path'],
        ]);
        $doc->fill([
            'customer_id'   => $ctx['customer_id'] ?? $doc->customer_id,
            'source_repo_id' => $ctx['source_repo_id'] ?? $doc->source_repo_id,
            'filename'      => basename($ctx['path']),
            'tipo'          => $ctx['tipo'] ?? $doc->tipo,
            'size_bytes'    => strlen((string) ($ctx['new_code'] ?? '')),
            'schema_version' => self::DOC_SCHEMA_VERSION,
        ]);
        $doc->analysis_status = 'extracting';
        $doc->save();

        // Versão por (fonte × commit do código). Reprocessamento reusa a mesma versão.
        $ver = $doc->versions()->firstOrNew(['source_commit_sha' => $ctx['source_commit_sha']]);
        $ver->fill([
            'parent_source_commit_sha' => $ctx['parent_source_commit_sha'] ?? null,
            'gmud_id'             => $ctx['gmud_id'] ?? $ver->gmud_id,
            'ticket_number'       => $ctx['ticket_number'] ?? $ver->ticket_number,
            'responsible_user_id' => $ctx['responsible_user_id'] ?? $ver->responsible_user_id,
            'responsavel'         => $ctx['responsavel'] ?? $ver->responsavel,
            'schema_version'      => self::DOC_SCHEMA_VERSION,
            'analysis_status'     => 'extracting',
        ]);
        $ver->save();

        try {
            // ── Camada 1 (determinística) — nunca falha, nunca alucina.
            $sec = $this->sanitizer->scan((string) $ctx['new_code']);
            $det = $this->analyzer->analyze((string) $ctx['new_code'], ['path' => $ctx['path'], 'filename' => basename($ctx['path'])]);
            $det['security_findings'] = $sec['findings'];
            $doc->lang = $det['language'] ?? $doc->lang;

            $oldDet = ($ctx['old_code'] ?? null) !== null ? $this->analyzer->analyze((string) $ctx['old_code'], ['path' => $ctx['path']]) : null;
            $diff = $this->differ->compare($oldDet, $det, $ctx['old_code'] ?? null, (string) $ctx['new_code']);

            $ver->deterministic_json = $det;
            $ver->diff_stats = $diff['diff_stats'] ?? null;
            $ver->analysis_status = 'analyzing';
            $ver->save();

            // ── Camada 2 (semântica) — best-effort; sem IA/falha ⇒ 'partial' (determinística vale).
            $sem = null;
            $status = 'analyzing';
            if ($runSemantic) {
                $sem = $this->semantic->analyze($det, $sec['masked'], $diff);
                $ver->semantic_json = $sem;
                $ver->diff_summary = $sem['resumo_alteracao'] ?? $ver->diff_summary;
                $status = ($sem['status'] ?? '') === 'completed' ? 'completed' : 'partial';
            }

            $ver->documentation_json = $this->consolidate($doc, $ctx, $det, $sem, $diff, $sec['findings'], $status);
            $ver->analysis_status = $status;
            $ver->save();

            // ── Ponteiro do documento vivo.
            $doc->fill([
                'current_version_id' => $ver->id,
                'current_source_sha' => $ctx['source_commit_sha'],
                'documentation_json' => $ver->documentation_json,
                'analysis_status'    => $status,
                'analysis_error'     => null,
                'last_ticket_id'     => $ctx['gmud_id'] ?? $doc->last_ticket_id,
                'last_responsavel'   => $ctx['responsavel'] ?? $doc->last_responsavel,
            ]);
            $doc->save();
        } catch (\Throwable $e) {
            $ver->analysis_status = 'failed';
            $ver->save();
            $doc->analysis_status = 'failed';
            $doc->analysis_error = mb_substr($e->getMessage(), 0, 500);
            $doc->current_version_id = $ver->id;
            $doc->save();
            Log::warning('source_doc.pipeline_failed', [
                'doc' => $doc->id, 'version' => $ver->id, 'path' => $ctx['path'], 'commit' => $ctx['source_commit_sha'],
                'stage' => 'pipeline', 'error' => $e->getMessage(),
            ]);
        }
        return $ver;
    }

    /** Reprocessa uma versão partial/failed: re-busca o código no SHA e refaz (semântica inclusa). */
    public function reprocessVersion(SourceDocVersion $ver, bool $runSemantic = true): SourceDocVersion
    {
        $doc = $ver->doc;
        if (!$doc || !$ver->source_commit_sha) {
            return $ver;
        }
        $newCode = $this->auth->getFileContent($doc->owner, $doc->repository, $ver->source_commit_sha, $doc->path);
        if ($newCode === null) {
            return $ver;
        }
        $oldCode = $ver->parent_source_commit_sha
            ? $this->auth->getFileContent($doc->owner, $doc->repository, $ver->parent_source_commit_sha, $doc->path)
            : null;

        return $this->processFile([
            'customer_id' => $doc->customer_id, 'source_repo_id' => $doc->source_repo_id,
            'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch, 'path' => $doc->path,
            'tipo' => $doc->tipo, 'new_code' => $newCode, 'old_code' => $oldCode,
            'source_commit_sha' => $ver->source_commit_sha, 'parent_source_commit_sha' => $ver->parent_source_commit_sha,
            'gmud_id' => $ver->gmud_id, 'ticket_number' => $ver->ticket_number,
            'responsible_user_id' => $ver->responsible_user_id, 'responsavel' => $ver->responsavel,
        ], $runSemantic);
    }

    /** JSON consolidado renderizável (a Fase 4 compõe as 14 seções daqui). Lossless. */
    private function consolidate(SourceDoc $doc, array $ctx, array $det, ?array $sem, array $diff, array $findings, string $status): array
    {
        return [
            'schema_version' => self::DOC_SCHEMA_VERSION,
            'status'         => $status,
            'identity'       => [
                'customer_id' => $ctx['customer_id'] ?? null,
                'owner'       => $ctx['owner'], 'repository' => $ctx['repository'], 'branch' => $ctx['branch'],
                'path'        => $ctx['path'], 'filename' => basename($ctx['path']),
                'tipo'        => $ctx['tipo'] ?? null, 'lang' => $det['language'] ?? null,
            ],
            'version'        => [
                'source_commit_sha'        => $ctx['source_commit_sha'],
                'parent_source_commit_sha' => $ctx['parent_source_commit_sha'] ?? null,
                'gmud_id'                  => $ctx['gmud_id'] ?? null,
                'ticket_number'            => $ctx['ticket_number'] ?? null,
                'responsavel'              => $ctx['responsavel'] ?? null,
            ],
            'deterministic'    => $det,
            'semantic'         => $sem,
            'diff'             => $diff,
            'security_findings' => $findings,
        ];
    }
}
