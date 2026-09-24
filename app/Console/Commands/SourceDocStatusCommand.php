<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\Models\SystemSetting;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Console\Command;

/**
 * Bloco 3 — expõe o SourceDocStatusResolver (ponto ÚNICO de verdade) no CLI. Resolve o status de
 * UMA doc (--doc) ou de TODAS de um repo (--repo, sem N+1) e grava o resultado em system_settings
 * 'diag_srcdoc_status'. Base do futuro "Validar agora" (--force ignora o cache da árvore).
 *
 *   php artisan source-doc:status --doc=6
 *   php artisan source-doc:status --repo=erpserv-clientes/promax@main --force
 */
class SourceDocStatusCommand extends Command
{
    protected $signature = 'source-doc:status {--doc=} {--repo=} {--force}';
    protected $description = 'Bloco 3: resolve o status (ATUALIZADA/DESATUALIZADA/NAO_VALIDADO) por blob SHA.';

    public function handle(SourceDocStatusResolver $resolver): int
    {
        $force = (bool) $this->option('force');

        if ($this->option('doc')) {
            $doc = SourceDoc::with(['currentVersion', 'sourceRepo'])->find((int) $this->option('doc'));
            if (!$doc) {
                $this->error('Doc não encontrada.');
                return self::FAILURE;
            }
            $res = $resolver->resolve($doc, $force);
            $out = [$this->row($doc, $res)];
        } elseif ($this->option('repo')) {
            [$ownerRepo, $branch] = array_pad(explode('@', (string) $this->option('repo'), 2), 2, 'main');
            [$owner, $repo] = explode('/', $ownerRepo, 2);
            $docs = SourceDoc::with(['currentVersion', 'sourceRepo'])
                ->where('owner', $owner)->where('repository', $repo)->where('branch', $branch)->get();
            $map = $resolver->resolveMany($docs, $force);
            $out = $docs->map(fn ($d) => $this->row($d, $map[$d->id]))->all();
        } else {
            $this->error('Informe --doc ou --repo.');
            return self::FAILURE;
        }

        SystemSetting::set('diag_srcdoc_status', json_encode($out, JSON_UNESCAPED_UNICODE), 'string', 'diag');
        $this->line(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }

    private function row(SourceDoc $doc, array $res): array
    {
        return [
            'doc_id'              => $doc->id,
            'repository'          => "{$doc->owner}/{$doc->repository}@{$doc->branch}",
            'path'                => $doc->path,
            'status'              => $res['status'],
            'reason'              => $res['reason'],
            'documented_blob_sha' => $res['documented_blob_sha'],
            'current_blob_sha'    => $res['current_blob_sha'],
            'source_commit_sha'   => $res['source_commit_sha'],
            'checked_at'          => $res['checked_at'],
        ];
    }
}
