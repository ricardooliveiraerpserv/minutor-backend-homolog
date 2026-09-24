<?php

namespace App\Console\Commands;

use App\Models\ClientSourceRepo;
use App\SourceCode\GithubAppAuth;
use Illuminate\Console\Command;

/**
 * Remove o prefixo "cliente-" dos repositórios provisionados, deixando só o nome da empresa
 * (cliente-zeg-biogas → zeg-biogas). Renomeia no GitHub (Administration:RW) e atualiza o vínculo.
 *
 * Detecta COLISÃO: se já existir um repo com o nome-alvo (ex.: cliente-comlub → comlub, e "comlub"
 * já existe na org), NÃO renomeia — deixa como está e reporta, pra decisão manual (apontar o
 * vínculo pro repo real / remover o duplicado vazio).
 *
 *   php artisan source-repos:strip-prefix --dry-run
 */
class SourceRepoStripPrefixCommand extends Command
{
    protected $signature = 'source-repos:strip-prefix {--dry-run : Só simula} {--sleep=1000 : Pausa ms entre renomeações}';

    protected $description = 'Tira o prefixo "cliente-" dos repositórios, mantendo só o nome da empresa.';

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
        $sleepMs = max(0, (int) $this->option('sleep'));
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Owner: {$owner} · repos com prefixo 'cliente-': {$rows->count()}");

        $renamed = 0;
        $collide = 0;
        $fail = 0;
        foreach ($rows as $r) {
            $new = preg_replace('/^cliente-/', '', $r->repository);
            if ($new === '' || $new === $r->repository) {
                continue;
            }
            // Colisão: já existe um repo com o nome-alvo? (não renomeia por cima)
            if ($auth->repoExists($owner, $new)) {
                $collide++;
                $this->warn("⚠ COLISÃO  {$r->repository} → {$new} (já existe · customer #{$r->customer_id}) — mantido");
                continue;
            }
            if ($dry) {
                $this->line("• {$r->repository} → {$new}");
                continue;
            }
            try {
                $auth->renameRepo($owner, $r->repository, $new);
                $r->repository = $new;
                $r->save();
                $renamed++;
                $this->line("✓ {$owner}/{$new}");
            } catch (\Throwable $e) {
                $fail++;
                $this->error("✗ {$r->repository}: " . $e->getMessage());
                if (str_contains($e->getMessage(), 'permissão de escrita')) {
                    $this->warn('Interrompendo: falta "Administration: Read and write" na App.');
                    break;
                }
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Concluído. Renomeados={$renamed}  Colisões={$collide}  Falhas={$fail}");
        return self::SUCCESS;
    }
}
