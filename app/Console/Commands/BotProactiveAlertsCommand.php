<?php

namespace App\Console\Commands;

use App\Models\BotProactiveDetector;
use App\Services\Bot\ProactiveAlertsService;
use Illuminate\Console\Command;

/**
 * Varre os detectores ativos em bot_proactive_detectors e cria alertas
 * no OperationalFeed. Detectores são configuráveis pelo admin via
 * /api/v1/bot/detectors/*.
 *
 * Os 5 detectores padrão (banco de horas, despesas, timesheets pendentes,
 * tickets parados, timesheets late) são semeados via migration
 * 2026_06_24_000100_seed_default_bot_proactive_detectors.php.
 */
class BotProactiveAlertsCommand extends Command
{
    protected $signature = 'bot:proactive-alerts
        {--detector= : Slug de um detector específico (default: todos os ativos)}
        {--dry : Apenas reporta sem criar OperationalFeed}';

    protected $description = 'Roda os detectores proativos do BOT (DB-driven) e cria alertas no OperationalFeed.';

    public function handle(ProactiveAlertsService $alerts): int
    {
        $dry = (bool) $this->option('dry');
        $slug = (string) $this->option('detector');

        $q = BotProactiveDetector::query()->active();
        if ($slug !== '') {
            $q->where('slug', $slug);
        }
        $detectors = $q->orderBy('id')->get();

        if ($detectors->isEmpty()) {
            $this->warn($slug !== ''
                ? "Nenhum detector ativo com slug '{$slug}'."
                : 'Nenhum detector ativo. Configure em /configuracoes/bot-minutor → Detectores.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($detectors as $d) {
            [$count, $err] = $alerts->runDetector($d, $dry);
            $tag = $dry ? '[DRY]' : '[RUN]';
            if ($err) {
                $this->error("$tag {$d->slug}: ERRO — {$err}");
            } else {
                $this->info("$tag {$d->slug}: {$count} alerta(s)");
            }
            $total += $count;
        }
        $this->info(($dry ? '[DRY] ' : '') . "Total: $total");
        return self::SUCCESS;
    }
}
