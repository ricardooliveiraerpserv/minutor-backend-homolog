<?php

namespace App\Console\Commands;

use App\Models\ClientSourceRepo;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Corrige os duplicados vazios: para cada vínculo ainda em "cliente-<slug>" cujo repo REAL
 * "<slug>" já existe na org, aponta o vínculo pro repo real e (com --delete-dups) apaga o
 * duplicado vazio que o backfill criou por engano — "os que já existem não deveria ter
 * criado, só vinculado".
 *
 * Guardas de segurança da deleção: só apaga se o nome começa com "cliente-", o repo é
 * pequeno/vazio (auto_init) e o repo real de destino existe. Deleção é IRREVERSÍVEL.
 *
 *   php artisan source-repos:relink-existing --dry-run
 *   php artisan source-repos:relink-existing --delete-dups
 */
class SourceRepoRelinkExistingCommand extends Command
{
    protected $signature = 'source-repos:relink-existing {--dry-run : Só simula} {--delete-dups : Apaga o duplicado vazio no GitHub} {--max-size=100 : KB máx. do repo p/ poder apagar} {--sleep=800}';

    protected $description = 'Aponta vínculos "cliente-*" para o repo real existente e apaga o duplicado vazio.';

    public function handle(GithubAppAuth $auth): int
    {
        if (!$auth->isConfigured()) {
            $this->error('GitHub App não configurada.');
            return self::FAILURE;
        }
        $owner = (string) config('services.github_source.default_owner', 'erpserv-clientes');
        $rows = ClientSourceRepo::where('owner', $owner)
            ->where('active', true)
            ->where('repository', 'like', 'cliente-%')
            ->orderBy('id')
            ->get();

        $dry = (bool) $this->option('dry-run');
        $del = (bool) $this->option('delete-dups');
        $maxSize = max(0, (int) $this->option('max-size'));
        $sleepMs = max(0, (int) $this->option('sleep'));
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Owner: {$owner} · vínculos 'cliente-*': {$rows->count()}" . ($del ? ' · APAGANDO duplicados' : ''));

        $relinked = 0;
        $deleted = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $dup = $r->repository;              // cliente-<slug>
            $target = preg_replace('/^cliente-/', '', $dup);
            if ($target === '' || $target === $dup) {
                continue;
            }
            if (!$auth->repoExists($owner, $target)) {
                $skipped++;
                $this->warn("· #{$r->customer_id}: repo real '{$target}' não existe — mantido {$dup}");
                continue;
            }

            // Decide a deleção ANTES de repontar (para poder inspecionar o duplicado).
            $canDelete = false;
            $sizeInfo = '?';
            if ($del) {
                $meta = $auth->repoMeta($owner, $dup);
                $size = $meta['size'] ?? null;
                $sizeInfo = $size === null ? 'inexistente' : "{$size}KB";
                $canDelete = str_starts_with($dup, 'cliente-') && $size !== null && $size <= $maxSize;
            }

            if ($dry) {
                $act = $del ? ($canDelete ? "🗑 apaga {$dup} ({$sizeInfo})" : "⚠ NÃO apaga {$dup} ({$sizeInfo})") : '(mantém repo)';
                $this->line("↔ #{$r->customer_id}: vínculo {$dup} → {$target}  ·  {$act}");
                continue;
            }

            // 1) Deleção do duplicado (retryável: só repontamos depois de decidir).
            if ($del) {
                if ($canDelete) {
                    try {
                        $auth->deleteRepo($owner, $dup);
                        $deleted++;
                        $this->line("🗑 apagado {$owner}/{$dup} ({$sizeInfo})");
                    } catch (\Throwable $e) {
                        $this->error("✗ falha ao apagar {$dup}: " . $e->getMessage());
                        if (str_contains($e->getMessage(), 'permissão de escrita')) {
                            $this->warn('Interrompendo: falta "Administration: Read and write".');
                            break;
                        }
                    }
                } else {
                    $this->warn("⚠ NÃO apaguei {$dup} (size={$sizeInfo}) — repontei o vínculo mesmo assim");
                }
            }

            // 2) Reponta o vínculo pro repo real.
            $r->repository = $target;
            $r->save();
            $relinked++;
            $this->line("↔ #{$r->customer_id}: vínculo → {$owner}/{$target}");

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Concluído. Vínculos repontados={$relinked}  Duplicados apagados={$deleted}  Pulados={$skipped}");
        return self::SUCCESS;
    }
}
