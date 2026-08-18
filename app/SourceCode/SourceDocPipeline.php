<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\Analyzer\AdvplAnalyzer;
use App\SourceCode\Analyzer\SecretSanitizer;
use App\SourceCode\Analyzer\SourceDiff;
use App\SourceCode\Analyzer\SourceDocSemanticAnalyzer;
use App\SourceCode\SemanticBlobReuse;
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
        private SemanticBlobReuse $blobReuse,
        private CrossSourceContextBuilder $contextBuilder,
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
        // Já concluída (imutável) → no-op; só garante o ponteiro/estado do documento vivo.
        if ($ver->exists && $ver->analysis_status === 'completed') {
            if ((int) $doc->current_version_id !== (int) $ver->id) {
                $doc->current_version_id = $ver->id;
                $doc->current_source_sha = $ver->source_commit_sha;
                $doc->documentation_json = $ver->documentation_json;
                $doc->analysis_status = 'completed';
                $doc->save();
            } elseif ($doc->analysis_status !== 'completed') {
                $doc->analysis_status = 'completed';
                $doc->save();
            }
            return $ver;
        }
        $ver->fill([
            // Bloco 3: identidade técnica do CONTEÚDO (git blob SHA autoritativo do GitHub). Não
            // fabricar: se a origem não forneceu (versões antigas), fica null → NAO_VALIDADO.
            'source_blob_sha'    => $ctx['source_blob_sha'] ?? $ver->source_blob_sha,
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
                // Bloco 4.1: passa a semântica ANTERIOR (incremental/diff-first) + blob (reuso). Só
                // considera "anterior" se for outra versão (não a própria, ao reprocessar).
                $prevVer = $doc->currentVersion;
                $prevSem = ($prevVer && (int) $prevVer->id !== (int) $ver->id) ? $prevVer->semantic_json : null;
                $blob = $ctx['source_blob_sha'] ?? null;

                // Fase 3 — CONTEXTO CROSS-SOURCE bounded: resolve DETERMINISTICAMENTE (resolved-only) a partir
                // do det que está sendo analisado ($ver), materializa facts-first e calcula o fingerprint.
                // OFF (default) ⇒ neutro (fingerprint '', sem contexto): tudo abaixo se comporta como hoje.
                $doc->setRelation('currentVersion', $ver);
                $xsrc = $this->contextBuilder->build($doc);
                $fp = (string) ($xsrc['fingerprint'] ?? '');

                // Bloco 4.2.1-A: reuso PERSISTENTE por blob+contrato (+ fingerprint de contexto). HIT ⇒ 0
                // chamadas IA. MISS ⇒ analisa e persiste. O fingerprint impede reuso entre contextos distintos.
                $sem = $this->blobReuse->get($blob, (int) $doc->id, $fp);
                if ($sem === null) {
                    $sem = $this->semantic->analyze($det, $sec['masked'], $diff, [
                        'previous_semantic' => $prevSem,
                        'blob_sha'          => $blob,
                        'source_doc_id'     => (int) $doc->id, // Fase 2 — dependente, p/ validar evidência cross-source
                        'cross_source'      => $xsrc,          // Fase 3 — contexto materializado + fingerprint
                    ]);
                    $this->blobReuse->put($blob, $sem, (int) $doc->id, $fp);
                }
                $ver->semantic_json = $sem;
                $ver->diff_summary = $sem['resumo_alteracao'] ?? $ver->diff_summary;
                // completed / skipped_* / reuse_* ⇒ versão completa (determinístico pronto + semântica
                // intencionalmente concluída/pulada/reusada). partial/failed/pending ⇒ partial (reprocessável).
                $status = in_array($sem['status'] ?? '', ['completed', 'skipped_cost_limit', 'skipped_no_structural_change', 'reuse_blob', 'reuse_blob_persistent'], true) ? 'completed' : 'partial';
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
        // Bloco 3: re-busca conteúdo + blob SHA no commit documentado (ref=source_commit_sha).
        // Resolução determinística do blob daquele commit — não fabrica (o GitHub devolve o sha).
        $fetched = $this->auth->getFileWithSha($doc->owner, $doc->repository, $ver->source_commit_sha, $doc->path);
        if ($fetched === null) {
            return $ver;
        }
        $newCode = $fetched['content'];
        $oldCode = $ver->parent_source_commit_sha
            ? $this->auth->getFileContent($doc->owner, $doc->repository, $ver->parent_source_commit_sha, $doc->path)
            : null;

        return $this->processFile([
            'customer_id' => $doc->customer_id, 'source_repo_id' => $doc->source_repo_id,
            'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch, 'path' => $doc->path,
            'tipo' => $doc->tipo, 'new_code' => $newCode, 'old_code' => $oldCode,
            'source_commit_sha' => $ver->source_commit_sha, 'source_blob_sha' => $fetched['blob_sha'] ?? $ver->source_blob_sha,
            'parent_source_commit_sha' => $ver->parent_source_commit_sha,
            'gmud_id' => $ver->gmud_id, 'ticket_number' => $ver->ticket_number,
            'responsible_user_id' => $ver->responsible_user_id, 'responsavel' => $ver->responsavel,
        ], $runSemantic);
    }

    /**
     * TOP-UP / RECOVERY de uma versão PARCIAL: enriquece o semantic_json existente reprocessando SÓ
     * blocos quebrados + funções em missing técnico (não refaz do zero). Custo adicional entra no
     * acumulado por fonte (≤ hard_limit). Caminho ESPECÍFICO de enriquecimento — não é reprocesso genérico.
     */
    public function topUpVersion(SourceDocVersion $ver): SourceDocVersion
    {
        $doc = $ver->doc;
        if (! $doc || ! $ver->source_commit_sha || ! is_array($ver->semantic_json) || ! $this->semantic->enabled()) {
            return $ver;
        }
        $fetched = $this->auth->getFileWithSha($doc->owner, $doc->repository, $ver->source_commit_sha, $doc->path);
        if ($fetched === null) {
            return $ver;
        }
        $sec = $this->sanitizer->scan($fetched['content']);
        $det = is_array($ver->deterministic_json)
            ? $ver->deterministic_json
            : $this->analyzer->analyze($fetched['content'], ['path' => $doc->path, 'filename' => basename($doc->path)]);

        // Fase 3 — top-up re-produz blocos/finalidades que podem depender de contexto externo: resolve o
        // mesmo contexto cross-source bounded (resolved-only) e passa ao topUp (OFF ⇒ neutro, como antes).
        $doc->setRelation('currentVersion', $ver);
        $xsrc = $this->contextBuilder->build($doc);
        $sem = $this->semantic->topUp($ver->semantic_json, $det, $sec['masked'], null, ['cross_source' => $xsrc]);
        $status = in_array($sem['status'] ?? '', ['completed', 'skipped_cost_limit', 'skipped_no_structural_change', 'reuse_blob', 'reuse_blob_persistent'], true) ? 'completed' : 'partial';

        $ctx = [
            'customer_id' => $doc->customer_id, 'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch,
            'path' => $doc->path, 'tipo' => $doc->tipo, 'source_commit_sha' => $ver->source_commit_sha,
            'parent_source_commit_sha' => $ver->parent_source_commit_sha, 'gmud_id' => $ver->gmud_id,
            'ticket_number' => $ver->ticket_number, 'responsavel' => $ver->responsavel,
        ];
        $ver->semantic_json = $sem;
        $ver->diff_summary = $sem['resumo_alteracao'] ?? $ver->diff_summary;
        $ver->documentation_json = $this->consolidate($doc, $ctx, $det, $sem, ['diff_stats' => $ver->diff_stats], (array) ($det['security_findings'] ?? []), $status);
        $ver->analysis_status = $status;
        $ver->save();

        // réplicas do MESMO blob+contexto herdam o enriquecimento (cache persistente por blob+fingerprint).
        // Fingerprint vem da proveniência já registrada no semantic_json (self-contained ⇒ '' neutro).
        if ($ver->source_blob_sha) {
            $fp = (string) (($sem['cross_source']['context_fingerprint'] ?? '') ?: '');
            $this->blobReuse->put($ver->source_blob_sha, $sem, (int) $doc->id, $fp);
        }
        if ((int) $doc->current_version_id === (int) $ver->id) {
            $doc->documentation_json = $ver->documentation_json;
            $doc->analysis_status = $status;
            $doc->save();
        }
        return $ver;
    }

    /**
     * CRITICAL RULES PASS de uma versão: passo DEDICADO (≤ US$ 0,30 próprio) que decide os candidatos a
     * regra crítica não cobertos. Política: Initial + Critical Rules Pass = máx US$ 0,60/fonte; além disso o
     * doc segue partial_recoverable (aprofundamento on-demand). NÃO refaz o initial.
     */
    public function criticalRulesPassVersion(SourceDocVersion $ver): SourceDocVersion
    {
        $doc = $ver->doc;
        if (! $doc || ! $ver->source_commit_sha || ! is_array($ver->semantic_json) || ! $this->semantic->enabled()) {
            return $ver;
        }
        $fetched = $this->auth->getFileWithSha($doc->owner, $doc->repository, $ver->source_commit_sha, $doc->path);
        if ($fetched === null) {
            return $ver;
        }
        $sec = $this->sanitizer->scan($fetched['content']);
        $det = is_array($ver->deterministic_json)
            ? $ver->deterministic_json
            : $this->analyzer->analyze($fetched['content'], ['path' => $doc->path, 'filename' => basename($doc->path)]);

        $doc->setRelation('currentVersion', $ver);
        $xsrc = $this->contextBuilder->build($doc);
        $sem = $this->semantic->criticalRulesPass($ver->semantic_json, $det, $sec['masked'], ['cross_source' => $xsrc, 'source_doc_id' => (int) $doc->id]);
        // status: se recuperou regras críticas e não há mais bloco quebrado → completed; senão preserva.
        $blocks = (array) ($sem['block_status'] ?? []);
        $broken = array_filter(['entendimento', 'regras', 'deps_risco'], fn ($b) => ! in_array($blocks[$b] ?? 'ok', ['ok', 'critical_rules_recovered', 'recovered'], true));
        $status = empty($broken) ? 'completed' : 'partial';

        $ctx = [
            'customer_id' => $doc->customer_id, 'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch,
            'path' => $doc->path, 'tipo' => $doc->tipo, 'source_commit_sha' => $ver->source_commit_sha,
            'parent_source_commit_sha' => $ver->parent_source_commit_sha, 'gmud_id' => $ver->gmud_id,
            'ticket_number' => $ver->ticket_number, 'responsavel' => $ver->responsavel,
        ];
        $ver->semantic_json = $sem;
        $ver->documentation_json = $this->consolidate($doc, $ctx, $det, $sem, ['diff_stats' => $ver->diff_stats], (array) ($det['security_findings'] ?? []), $status);
        $ver->analysis_status = $status;
        $ver->save();
        if ($ver->source_blob_sha) {
            $fp = (string) (($sem['cross_source']['context_fingerprint'] ?? '') ?: '');
            $this->blobReuse->put($ver->source_blob_sha, $sem, (int) $doc->id, $fp);
        }
        if ((int) $doc->current_version_id === (int) $ver->id) {
            $doc->documentation_json = $ver->documentation_json;
            $doc->analysis_status = $status;
            $doc->save();
        }
        return $ver;
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
