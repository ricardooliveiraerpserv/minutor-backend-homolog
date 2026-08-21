<?php

namespace App\Console\Commands;

use App\Models\Holiday;
use App\Models\ProjectOpenPeriod;
use App\Models\WeekOpenPeriod;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fecha automaticamente os MESES ABERTOS de projetos (ProjectOpenPeriod, closed_at = null)
 * cuja competência é anterior à vigente.
 *
 * Regra (roda todo dia 06h + de hora em hora p/ as reaberturas vencidas):
 *  - Meses 2+ atrás (anteriores ao mês imediatamente anterior) → fecham SEMPRE.
 *  - Mês imediatamente anterior ao vigente → só fecha APÓS o 2º dia útil do mês vigente
 *    (carência: fica aberto até o 2º dia útil; a partir do 3º dia útil é encerrado).
 *  - Reaberturas TEMPORÁRIAS (mês/semana) com auto_close_at vencido → encerram fisicamente
 *    (carimba closed_at) para sumir das listas de "reaberto"; inclui o mês vigente.
 */
class CloseStaleProjectPeriodsCommand extends Command
{
    protected $signature   = 'projects:close-stale-periods {--force : Ignora a carência e fecha também o mês imediatamente anterior}';
    protected $description  = 'Encerra os meses abertos (ProjectOpenPeriod) de competências anteriores à vigente; o mês imediatamente anterior só após o 2º dia útil.';

    public function handle(): int
    {
        $hoje       = Carbon::today();
        $currentYM  = $hoje->format('Y-m');
        $previousYM = $hoje->copy()->subMonth()->format('Y-m');

        $segundoDiaUtil = $this->calcularSegundoDiaUtil($hoje->year, $hoje->month);
        // "após o 2º dia útil" → estritamente depois da data do 2º dia útil do mês vigente.
        $aposSegundoDiaUtil = $this->option('force') || $hoje->greaterThan($segundoDiaUtil);

        // Após o 2º dia útil → fecha tudo < vigente (inclui o mês imediatamente anterior).
        // Antes (carência) → fecha só o que é < mês imediatamente anterior (2+ meses atrás).
        $cutoff = $aposSegundoDiaUtil ? $currentYM : $previousYM;

        $closed = ProjectOpenPeriod::whereNull('closed_at')
            ->where('year_month', '<', $cutoff)
            ->update(['closed_at' => now(), 'closed_by' => null]);

        // Reaberturas TEMPORÁRIAS (mês ou semana) com auto_close_at já vencido: encerram
        // fisicamente. São reaberturas com prazo (auto_close_at = 23:59 do dia da abertura);
        // o bloqueio funcional já expira nessa hora (activeMonthReopen/activeWeekReopen
        // filtram auto_close_at >= now), mas o closed_at precisa ser carimbado p/ a linha
        // sumir das listas de "meses/semanas reabertos". Inclui o mês vigente (é reabertura
        // com prazo, não fechamento definitivo do mês atual).
        $expMonths = ProjectOpenPeriod::whereNull('closed_at')
            ->whereNotNull('auto_close_at')
            ->where('auto_close_at', '<', now())
            ->update(['closed_at' => now(), 'closed_by' => null]);
        $expWeeks = WeekOpenPeriod::whereNull('closed_at')
            ->whereNotNull('auto_close_at')
            ->where('auto_close_at', '<', now())
            ->update(['closed_at' => now(), 'closed_by' => null]);

        $detalhe = $aposSegundoDiaUtil
            ? 'após o 2º dia útil — inclui o mês anterior'
            : "carência até o 2º dia útil ({$segundoDiaUtil->toDateString()}) — mês anterior {$previousYM} preservado";
        $msg = "projects:close-stale-periods — {$closed} período(s) encerrado(s) (year_month < {$cutoff}; {$detalhe})"
            . "; reaberturas vencidas encerradas: {$expMonths} mês/meses + {$expWeeks} semana(s).";

        $this->info($msg);
        \Log::info($msg);

        return self::SUCCESS;
    }

    /** 2º dia útil do mês (pula fim de semana e feriados ativos do cadastro). */
    private function calcularSegundoDiaUtil(int $ano, int $mes): Carbon
    {
        $feriados = Holiday::whereYear('date', $ano)
            ->whereMonth('date', $mes)
            ->where('active', true)
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        $dia      = Carbon::create($ano, $mes, 1);
        $contagem = 0;
        while (true) {
            if (!$dia->isWeekend() && !in_array($dia->toDateString(), $feriados)) {
                $contagem++;
                if ($contagem === 2) {
                    return $dia->copy();
                }
            }
            $dia->addDay();
        }
    }
}
