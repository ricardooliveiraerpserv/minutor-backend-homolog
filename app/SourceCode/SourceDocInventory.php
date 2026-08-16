<?php

namespace App\SourceCode;

use App\Models\ClientSourceRepo;
use App\Models\SourceDoc;
use App\Models\SourceRepoCoverage;
use App\SourceCode\Exceptions\SourceIntegrationException;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C3.5. Inventário do acervo: varre os repos CADASTRADOS (client_source_repos),
 * cataloga em source_docs os fontes elegíveis e gera a camada DETERMINÍSTICA (IA ZERO). Snapshot de
 * cobertura em source_repo_coverage (dashboard + checkpoint retomável). Motor congelado (invocado);
 * GitHub read-only; nunca altera fonte nem faz commit.
 *
 * Estados (não misturar): NOVO=não catalogado (vira catalogado) · blob igual=coberto ·
 * blob difere=DOCUMENTAÇÃO DESATUALIZADA (NÃO reprocessa aqui; decisão fica p/ o reprocess da C3).
 * Índice C2 divergente = índice pendente/stale (outro assunto — reaper/index scheduler da C3).
 *
 * Rate-limit: o GithubAppAuth (frozen) não expõe o header; tratamos REATIVAMENTE — um 403/erro do
 * GitHub vira SourceIntegrationException → marca rate_limited + salva cursor + retoma no próximo ciclo.
 * Controle proativo por LOTE (inventory_batch): nº máx. de NOVOS documentados por execução.
 */
class SourceDocInventory
{
    public function __construct(
        private GithubAppAuth $auth,
        private SourceDocPipeline $pipeline,
        private SourceDocIndexer $indexer,
    ) {
    }

    private function extensions(): array
    {
        return config('services.source_doc.inventory_extensions', ['prw', 'prx', 'prg', 'tlpp', 'tlp', 'aph']);
    }

    /** Varre 1 repo. $maxNew limita NOVOS documentados nesta execução (0 = usa config). */
    public function scanRepo(ClientSourceRepo $repo, int $maxNew = 0): SourceRepoCoverage
    {
        $maxNew = $maxNew ?: (int) config('services.source_doc.inventory_batch', 150);
        $cov = SourceRepoCoverage::firstOrNew(['source_repo_id' => $repo->id]);
        $cov->fill([
            'customer_id' => $repo->customer_id, 'owner' => $repo->owner,
            'repository' => $repo->repository, 'branch' => $repo->branch,
            'scan_status' => 'running', 'scan_started_at' => now(), 'last_error' => null,
        ])->save();

        try {
            $tree = $this->auth->treeBlobShas($repo->owner, $repo->repository, $repo->branch); // 1 chamada
            $head = $this->auth->getBranchHeadSha($repo->owner, $repo->repository, $repo->branch);
            $base = $repo->normalizedBasePath();
            $exts = $this->extensions();
            ksort($tree); // ordem determinística p/ cursor

            $github = count($tree);
            $eligible = $new = $unchanged = $changed = $ignored = 0;
            $cursor = $cov->last_scan_cursor;
            $resuming = ! empty($cursor);
            $status = 'completed';

            foreach ($tree as $path => $blob) {
                if (! $this->eligible($path, $base, $exts)) {
                    $ignored++;
                    continue;
                }
                $eligible++;

                // retomada: pula tudo até (inclusive) o último path processado
                if ($resuming) {
                    if ($path === $cursor) {
                        $resuming = false;
                    }
                    continue;
                }

                $doc = SourceDoc::where([
                    'owner' => $repo->owner, 'repository' => $repo->repository,
                    'branch' => $repo->branch, 'path' => $path,
                ])->first();

                if ($doc) {
                    $documented = $doc->relationLoaded('currentVersion')
                        ? $doc->currentVersion?->source_blob_sha
                        : $doc->currentVersion()->first()?->source_blob_sha;
                    if ($documented === $blob) {
                        $unchanged++;              // coberto
                    } else {
                        $changed++;                // DOCUMENTAÇÃO DESATUALIZADA (não reprocessa aqui)
                    }
                    continue;
                }

                // NOVO (não catalogado) → documenta DETERMINÍSTICO (runSemantic=false FIXO)
                if ($maxNew > 0 && $new >= $maxNew) {
                    // last_scan_cursor já aponta o último NOVO processado → retoma daqui.
                    $status = 'partial';
                    break;
                }
                $content = $this->auth->getFileContent($repo->owner, $repo->repository, $head ?: $repo->branch, $path);
                if ($content === null) {
                    $ignored++; // arquivo ilegível (binário/removido em corrida) — não bloqueia
                    continue;
                }
                $ver = $this->pipeline->processFile([
                    'customer_id' => $repo->customer_id, 'source_repo_id' => $repo->id,
                    'owner' => $repo->owner, 'repository' => $repo->repository, 'branch' => $repo->branch,
                    'path' => $path, 'tipo' => $repo->tipo,
                    'new_code' => $content, 'old_code' => null,
                    'source_commit_sha' => $head, 'source_blob_sha' => $blob,
                    'parent_source_commit_sha' => null,
                    'gmud_id' => null, 'ticket_number' => null,
                    'responsible_user_id' => null, 'responsavel' => 'Inventário',
                ], false); // ← runSemantic=false OBRIGATÓRIO (IA ZERO no inventário)

                $indexDoc = SourceDoc::find($ver->source_doc_id);
                if ($indexDoc) {
                    $this->indexer->index($indexDoc);
                }
                $new++;
                $cov->last_scan_cursor = $path;
            }

            if ($status === 'completed') {
                $cov->last_scan_cursor = null; // varredura inteira concluída
            }
            $cov->fill([
                'scan_status' => $status, 'scan_finished_at' => now(), 'last_synced_at' => now(),
                'github_files' => $github, 'eligible_source_files' => $eligible,
                'new_files' => $new, 'unchanged_files' => $unchanged,
                'changed_files' => $changed, 'ignored_files' => $ignored,
            ]);
            $this->refreshCounts($cov, $repo);
            $cov->save();
        } catch (SourceIntegrationException $e) {
            $rl = in_array($e->errorCode ?? '', ['TIMEOUT', 'GITHUB_UNAVAILABLE', 'RATE_LIMIT'], true)
                || stripos($e->getMessage(), 'rate') !== false;
            $cov->scan_status = $rl ? 'rate_limited' : 'failed';
            $cov->last_error = mb_substr($this->sanitize($e->getMessage()), 0, 500);
            $cov->save();
        } catch (\Throwable $e) {
            $cov->scan_status = 'failed';
            $cov->last_error = mb_substr($this->sanitize($e->getMessage()), 0, 500);
            $cov->save();
        }

        return $cov->refresh();
    }

    /** Cobertura consolidada (do banco) — dashboard + critério de aceite. */
    private function refreshCounts(SourceRepoCoverage $cov, ClientSourceRepo $repo): void
    {
        $scope = fn ($q) => $q->where('source_docs.owner', $repo->owner)
            ->where('source_docs.repository', $repo->repository)
            ->where('source_docs.branch', $repo->branch);

        $cov->cataloged = $scope(SourceDoc::query())->count();

        $agg = $scope(
            SourceDoc::query()->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
        )->selectRaw('
            count(*) filter (where cv.deterministic_json is not null) as det,
            count(*) filter (where cv.semantic_json is not null) as sem
        ')->first();
        $cov->deterministic = (int) ($agg->det ?? 0);
        $cov->semantic = (int) ($agg->sem ?? 0);

        // índice C2 não-stale = indexed_version_id == current_version_id
        $cov->indexed = $scope(
            SourceDoc::query()->join('source_doc_index as si', 'si.source_doc_id', '=', 'source_docs.id')
        )->whereColumn('si.indexed_version_id', 'source_docs.current_version_id')->count();
    }

    private function eligible(string $path, string $base, array $exts): bool
    {
        $p = ltrim($path, '/');
        if ($base !== '' && ! str_starts_with($p, $base . '/') && $p !== $base) {
            return false;
        }
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $exts, true);
    }

    private function sanitize(string $msg): string
    {
        $msg = preg_replace('#(gh[ps]_[A-Za-z0-9]+|Bearer\s+\S+)#', '[redacted]', $msg) ?? $msg;
        return preg_replace('#(/[^\s]+){2,}#', '[path]', $msg) ?? $msg;
    }
}
