<?php

namespace App\Jobs;

use App\Models\ClientSourceRepo;
use App\Models\GmudPackageFile;
use App\SourceCode\GithubAppAuth;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GMUD — Fase 1: após a PUBLICAÇÃO (commit atômico), gera/atualiza a documentação via IA
 * de UM fonte publicado. 1 job por arquivo → isola latência/custo e respeita o timeout da IA.
 * Best-effort: a publicação já concluiu; falha aqui NÃO desfaz o commit. Fila dedicada source-doc.
 * Reutiliza o motor congelado `SourceDocPipeline::processFile` (mesmo ctx do GmudSourceProcessor legado).
 */
class GmudGenerateSourceDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $packageFileId, public string $commitSha)
    {
        // Async DEDICADO: conexão 'database' + fila 'source-doc' (mesma do reprocess) — nunca inline.
        $this->onConnection('database')->onQueue('source-doc');
    }

    public function handle(SourceDocPipeline $pipeline, GithubAppAuth $auth): void
    {
        $f = GmudPackageFile::with(['package.ticket.assignee'])->find($this->packageFileId);
        if (! $f || empty($f->dest_git_path)) {
            return;
        }
        $pkg = $f->package;
        if (! $pkg) {
            return;
        }
        $repo = ClientSourceRepo::find($pkg->source_repo_id);
        if (! $repo) {
            Log::warning('gmud.generate_doc.no_repo', ['file' => $f->id, 'package' => $pkg->id]);
            return;
        }
        $branch = $repo->branch ?: 'main';

        // Lê o conteúdo publicado direto do commit (ref = SHA do publish) → autoritativo.
        $fetched = $auth->getFileWithSha($repo->owner, $repo->repository, $this->commitSha, $f->dest_git_path);
        if (! $fetched || ! isset($fetched['content'])) {
            Log::warning('gmud.generate_doc.fetch_failed', ['path' => $f->dest_git_path, 'commit' => $this->commitSha]);
            return;
        }

        // old_code = conteúdo da versão ANTERIOR documentada deste fonte (o "trecho alterado"
        // é o diff ENTRE VERSÕES da doc: versão anterior × esta publicação).
        $oldCode = null;
        $prevDoc = \App\Models\SourceDoc::with('currentVersion')
            ->where('owner', $repo->owner)->where('repository', $repo->repository)
            ->where('branch', $branch)->where('path', $f->dest_git_path)->first();
        $prevVer = $prevDoc?->currentVersion;
        if ($prevVer && $prevVer->source_commit_sha && $prevVer->source_commit_sha !== $this->commitSha) {
            $prevFetched = $auth->getFileWithSha($repo->owner, $repo->repository, $prevVer->source_commit_sha, $f->dest_git_path);
            $oldCode = $prevFetched['content'] ?? null;
        }

        $ticket = $pkg->ticket;
        try {
            $pipeline->processFile([
                'customer_id'         => $pkg->customer_id ?? $repo->customer_id,
                'source_repo_id'      => $repo->id,
                'owner'               => $repo->owner,
                'repository'          => $repo->repository,
                'branch'              => $branch,
                'path'                => $f->dest_git_path,
                'tipo'                => $repo->tipo,
                'new_code'            => $fetched['content'],
                'old_code'            => $oldCode,
                'source_commit_sha'   => $this->commitSha,
                'source_blob_sha'     => $f->published_blob_sha ?: ($fetched['blob_sha'] ?? null),
                'gmud_id'             => $ticket?->id,
                'ticket_number'       => $ticket?->ticket_number,
                'responsible_user_id' => $ticket?->assignee_id,
                'responsavel'         => optional($ticket?->assignee)->name,
            ], true);
        } catch (\Throwable $e) {
            Log::warning('gmud.generate_doc.pipeline_failed', ['path' => $f->dest_git_path, 'error' => $e->getMessage()]);
        }
    }
}
