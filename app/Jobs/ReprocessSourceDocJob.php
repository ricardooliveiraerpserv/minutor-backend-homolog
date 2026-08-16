<?php

namespace App\Jobs;

use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Central de Fontes — C3. Executa o reprocessamento invocando o motor v2.1 (congelado).
 * Respeita a imutabilidade: reprocess_in_place em versões mutáveis; blob mudou → nova versão.
 * Atualiza o estado de execução (source_doc_action_log) e grava custo real + duração.
 */
class ReprocessSourceDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(
        public int $docId,
        public string $layer,     // deterministic | semantic | both
        public bool $force,
        public int $logId,
    ) {
    }

    public function handle(SourceDocPipeline $pipeline, GithubAppAuth $auth): void
    {
        $log = SourceDocActionLog::find($this->logId);
        $doc = SourceDoc::with('currentVersion')->find($this->docId);
        if (! $log || ! $doc || ! $doc->currentVersion) {
            $log?->update(['status' => 'failed', 'reason' => 'fonte/versão ausente']);
            return;
        }

        $log->update(['status' => 'running']);
        $t0 = microtime(true);
        $runSemantic = ($this->layer === 'semantic' || $this->layer === 'both');

        try {
            $ver = $doc->currentVersion;
            $status = $ver->analysis_status;

            if (in_array($status, ['partial', 'failed', 'analyzing'], true)) {
                // versão mutável: re-executa nela mesma
                $newVer = $pipeline->reprocessVersion($ver, $runSemantic);
            } else {
                // completed: só chega aqui se o blob mudou (nova versão) — o controller barra o resto.
                $head = $auth->getBranchHeadSha($doc->owner, $doc->repository, $doc->branch);
                $fetched = $auth->getFileWithSha($doc->owner, $doc->repository, $doc->branch, $doc->path);
                if (! $head || ! $fetched) {
                    throw new \RuntimeException('não foi possível resolver HEAD/arquivo atual');
                }
                $newVer = $pipeline->processFile([
                    'customer_id' => $doc->customer_id, 'source_repo_id' => $doc->source_repo_id,
                    'owner' => $doc->owner, 'repository' => $doc->repository, 'branch' => $doc->branch, 'path' => $doc->path,
                    'tipo' => $doc->tipo, 'new_code' => $fetched['content'],
                    'old_code' => $ver->source_commit_sha ? $auth->getFileContent($doc->owner, $doc->repository, $ver->source_commit_sha, $doc->path) : null,
                    'source_commit_sha' => $head, 'source_blob_sha' => $fetched['blob_sha'] ?? null,
                    'parent_source_commit_sha' => $ver->source_commit_sha,
                ], $runSemantic);
            }

            $cost = $this->extractCost($newVer);
            $params = array_merge($log->params ?? [], SourceDocActionLog::sanitize([
                'new_version_id' => $newVer->id,
                'analysis_status' => $newVer->analysis_status,
            ]));
            $log->update([
                'status' => 'ok', 'version_id' => $newVer->id, 'cost_usd' => $cost,
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000), 'params' => $params,
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'reason' => mb_substr($this->sanitizeError($e->getMessage()), 0, 200),
                'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
            ]);
        }
    }

    /** Custo real da IA a partir do bloco de usage do semantic_json (se houver). */
    private function extractCost($ver): ?float
    {
        $sem = is_array($ver->semantic_json) ? $ver->semantic_json : [];
        $usage = $sem['usage'] ?? $sem['_usage'] ?? [];
        foreach (['actual_cost_usd', 'cost_usd', 'actual_usd'] as $k) {
            if (isset($usage[$k])) {
                return (float) $usage[$k];
            }
        }
        return null;
    }

    /** Nunca deixa vazar caminho/segredo no reason. */
    private function sanitizeError(string $msg): string
    {
        $msg = preg_replace('#(/[^\s]+)+#', '[path]', $msg) ?? $msg;
        return preg_replace('#(gh[ps]_[A-Za-z0-9]+|Bearer\s+\S+)#', '[redacted]', $msg) ?? $msg;
    }

    public function failed(\Throwable $e): void
    {
        SourceDocActionLog::where('id', $this->logId)->update(['status' => 'failed', 'reason' => 'job falhou']);
    }
}
