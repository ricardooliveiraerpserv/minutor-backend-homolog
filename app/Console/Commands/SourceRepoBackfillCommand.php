<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\SourceCode\SourceRepoProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Cria repositório GitHub + vínculo para TODOS os clientes ativos "reais" que ainda não têm.
 * Idempotente e resumível: quem já tem vínculo ativo é ignorado. Respeita o secondary rate
 * limit do GitHub com uma pausa entre criações (--sleep, ms).
 *
 *   php artisan source-repos:backfill --dry-run     # só lista o que faria
 *   php artisan source-repos:backfill --limit=10     # provisiona os 10 primeiros
 */
class SourceRepoBackfillCommand extends Command
{
    protected $signature = 'source-repos:backfill {--dry-run : Só lista, não cria} {--limit=0 : Máximo de clientes} {--sleep=1000 : Pausa em ms entre criações}';

    protected $description = 'Provisiona repositório de código-fonte para clientes ativos sem vínculo.';

    public function handle(SourceRepoProvisioner $prov): int
    {
        if (!$prov->enabled()) {
            $this->error('Provisionamento desabilitado: GitHub App não configurada ou GITHUB_SOURCE_AUTO_PROVISION=false.');
            return self::FAILURE;
        }

        $hasCrm = Schema::hasColumn('customers', 'crm_status');
        $query = Customer::query()
            ->where('active', true)
            ->when($hasCrm, fn ($x) => $x->where(fn ($w) => $w->whereNull('crm_status')->orWhereNotIn('crm_status', ['lead', 'prospect'])))
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('client_source_repos')
                    ->whereColumn('client_source_repos.customer_id', 'customers.id')
                    ->where('client_source_repos.active', true)
                    ->whereNull('client_source_repos.deleted_at');
            })
            ->orderBy('id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $targets = $query->get(['id', 'name']);

        $dry = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $this->info(($dry ? '[DRY-RUN] ' : '') . "Owner: {$prov->owner()} · clientes a provisionar: {$targets->count()}");

        $ok = 0;
        $fail = 0;
        foreach ($targets as $c) {
            $name = $prov->repoName($c);
            if ($dry) {
                $this->line("• #{$c->id}  {$c->name}  →  {$prov->owner()}/{$name}");
                continue;
            }
            try {
                $repo = $prov->provisionFor($c);
                $ok++;
                $this->line("✓ #{$c->id} {$c->name} → " . ($repo ? $repo->fullName() : '—'));
            } catch (\Throwable $e) {
                $fail++;
                $this->error("✗ #{$c->id} {$c->name}: " . $e->getMessage());
                // 403 sem permissão de escrita = configuração; não adianta continuar martelando.
                if (str_contains($e->getMessage(), 'permissão de escrita')) {
                    $this->warn('Interrompendo: ative "Administration: Read and write" na GitHub App e aprove a atualização na instalação.');
                    break;
                }
            }
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("Concluído. Criados/vinculados={$ok}  Falhas={$fail}");
        return self::SUCCESS;
    }
}
