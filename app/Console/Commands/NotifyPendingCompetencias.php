<?php

namespace App\Console\Commands;

use App\Services\SkillCampaignNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cobra os consultores que ainda NÃO atualizaram as competências nas campanhas
 * ABERTAS (pop-up + e-mail via workflow competencias.campanha), respeitando a
 * recorrência definida na Central (recurrence_days) e o prazo da campanha.
 */
class NotifyPendingCompetencias extends Command
{
    protected $signature = 'competencias:notify-pendentes {--date= : Data de referência YYYY-MM-DD (p/ teste)}';
    protected $description = 'Cobra consultores pendentes nas campanhas de atualização de competências (recorrência da Central)';

    public function handle(): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->startOfDay() : now()->startOfDay();
        $r = SkillCampaignNotifier::sweep($today);
        $this->info("campanhas abertas: {$r['campaigns']} · pendentes: {$r['pending']} · e-mails: {$r['mails']}");

        return self::SUCCESS;
    }
}
