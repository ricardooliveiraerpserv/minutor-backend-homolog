<?php

namespace App\Console\Commands;

use App\Models\HelpDeskTicket;
use App\Models\HelpDeskTicketEvent;
use App\Models\Playbook;
use App\Models\Timesheet;
use App\Models\UsageEvent;
use App\Models\WorkSession;
use App\Models\WorkSessionEvent;
use App\Services\WorkSessionSummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Product Review 2 (Fase de Consolidação do Help Desk) — relatório READ-ONLY.
 *
 * Mede exatamente os números definidos em docs/helpdesk-criterios-sucesso-homologacao.md
 * a partir da telemetria coletada (usage_events + work_session_events + tickets + timesheets)
 * e responde às TRÊS perguntas: (1) o que foi usado, (2) o que aumentou produtividade,
 * (3) o que simplificar. Classifica cada funcionalidade: Consolidada / Ajustar / Remover.
 *
 * NÃO é dashboard e NÃO grava nada — apenas lê e imprime. Rodar ao fim das 2 semanas.
 */
class HelpDeskProductReview extends Command
{
    protected $signature = 'helpdesk:product-review {--from=} {--to=}';
    protected $description = 'Relatório de adoção/produtividade/qualidade do Help Desk (Fase de Consolidação)';

    /** Piso de "baixíssima utilização" (abaixo = Remover). Ver Critérios de Sucesso. */
    private const FLOOR = 20.0;
    private const BLOCKS = ['cliente', 'contrato', 'projetos', 'help_desk', 'comercial', 'operacao'];

    public function handle(WorkSessionSummaryService $summaries): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from'))->startOfDay() : now()->subDays(14)->startOfDay();
        $to   = $this->option('to') ? Carbon::parse($this->option('to'))->endOfDay() : now();
        $mid  = $from->copy()->addSeconds((int) ($from->diffInSeconds($to) / 2));
        $win  = [$from, $to];

        $usage = UsageEvent::whereBetween('created_at', $win)->get();
        $uf = fn (string $f, string $a) => $usage->where('feature', $f)->where('action', $a);
        $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1) : null;
        $ts = fn ($v) => Carbon::parse($v);

        $this->line('');
        $this->info('═══ PRODUCT REVIEW 2 — Help Desk ═══');
        $this->line("Janela: {$from->toDateString()} → {$to->toDateString()}  (corte 1ª×2ª metade: {$mid->toDateTimeString()})");

        // ── Bases ───────────────────────────────────────────────────────────
        $resolved = $uf('ticket', 'resolved');
        $resN = $resolved->count();
        $resolvedIds = $resolved->pluck('entity_id')->filter()->unique();
        $finalizedSE = WorkSessionEvent::where('type', 'ticket_finalized')->whereBetween('created_at', $win)->get();

        if ($resN === 0) {
            $this->warn("\nSem resoluções na janela — nada a medir ainda. Rode após o uso interno acumular dados.");
            return self::SUCCESS;
        }

        // ── 1) ADOÇÃO ───────────────────────────────────────────────────────
        $finalizeResolved = $uf('finalize', 'used')->filter(fn ($e) => data_get($e->metadata, 'resolved') === true)->count();
        $pbTickets   = $uf('playbook', 'executed')->map(fn ($e) => data_get($e->metadata, 'ticket_id'))->filter()->unique()->intersect($resolvedIds)->count();
        $c360Tickets = $uf('customer_360', 'viewed')->where('entity_type', 'helpdesk_ticket')->pluck('entity_id')->filter()->unique()->intersect($resolvedIds)->count();
        $modoTickets = $finalizedSE->pluck('entity_id')->filter()->unique()->intersect($resolvedIds)->count();

        $metas = [
            ['Finalizar Atendimento', $pct($finalizeResolved, $resN), 80],
            ['Playbooks',             $pct($pbTickets, $resolvedIds->count()), 60],
            ['Customer 360',          $pct($c360Tickets, $resolvedIds->count()), 70],
            ['Modo Atendimento',      $pct($modoTickets, $resN), 50],
        ];
        $this->line("\n── 1. ADOÇÃO ".str_repeat('─', 40));
        $rows = [];
        foreach ($metas as [$nome, $m, $meta]) {
            [$icon, $veredito] = $this->classify($m, $meta);
            $rows[] = [$nome, $m === null ? '—' : "{$m}%", "≥{$meta}%", "$icon $veredito"];
        }
        $this->table(['Funcionalidade', 'Medido', 'Meta', 'Veredito'], $rows);

        // ── 2) PRODUTIVIDADE (1ª × 2ª metade) ───────────────────────────────
        $gapAvg = function ($events) use ($ts) {
            $secs = [];
            foreach ($events->groupBy('work_session_id') as $g) {
                $s = $g->sortBy(fn ($e) => $ts($e->created_at))->values();
                for ($i = 1; $i < $s->count(); $i++) $secs[] = abs($ts($s[$i]->created_at)->diffInSeconds($ts($s[$i - 1]->created_at)));
            }
            return count($secs) ? round(array_sum($secs) / count($secs) / 60, 1) : null;
        };
        $tma = fn ($a, $b) => round((float) (Timesheet::whereNotNull('helpdesk_ticket_id')->whereNull('deleted_at')->whereBetween('created_at', [$a, $b])->avg('effort_minutes') ?? 0), 1);
        $feFirst = $finalizedSE->filter(fn ($e) => $ts($e->created_at)->lt($mid));
        $feSecond = $finalizedSE->filter(fn ($e) => $ts($e->created_at)->gte($mid));

        $this->line("\n── 2. PRODUTIVIDADE (1ª metade → 2ª metade) ".str_repeat('─', 12));
        $this->table(['Indicador', '1ª metade', '2ª metade', 'Sinal'], [
            $this->trend('Tempo médio entre chamados (min)', $gapAvg($feFirst), $gapAvg($feSecond), 'down'),
            $this->trend('Tempo médio de atendimento (min)', $tma($from, $mid), $tma($mid, $to), 'down'),
        ]);
        $sessions = WorkSession::whereBetween('started_at', $win)->get();
        $sessN = $sessions->count();
        $resolvedPerSession = $sessN ? round($finalizedSE->count() / $sessN, 2) : null;
        $horasPerSession = $sessN ? round($sessions->avg(fn ($s) => $summaries->summarize($s)['horas_apontadas']), 2) : null;
        $this->line("  Chamados resolvidos por sessão: " . ($resolvedPerSession ?? '—') . "   |   Horas apontadas por sessão: " . ($horasPerSession ?? '—'));

        // ── 3) QUALIDADE ────────────────────────────────────────────────────
        $semApont = function ($coll) {
            $n = $coll->count(); if (!$n) return null;
            return round($coll->filter(fn ($e) => data_get($e->metadata, 'has_apontamento') === false)->count() / $n * 100, 1);
        };
        $rFirst = $resolved->filter(fn ($e) => $ts($e->created_at)->lt($mid));
        $rSecond = $resolved->filter(fn ($e) => $ts($e->created_at)->gte($mid));
        $reab = HelpDeskTicketEvent::where('event_type', 'reopened')->whereBetween('created_at', $win)->count();
        $this->line("\n── 3. QUALIDADE ".str_repeat('─', 38));
        $this->table(['Indicador', '1ª metade', '2ª metade', 'Sinal'], [
            $this->trend('Resoluções SEM apontamento (%)', $semApont($rFirst), $semApont($rSecond), 'down'),
        ]);
        $this->line("  Reaberturas na janela: {$reab}  (" . ($pct($reab, $resN) ?? 0) . "% das resoluções)");

        // ── 4) CUSTOMER 360 ─────────────────────────────────────────────────
        $toggles = $uf('customer_360', 'block_toggled');
        $opens = $toggles->filter(fn ($e) => data_get($e->metadata, 'open') === true)->groupBy(fn ($e) => data_get($e->metadata, 'block'))->map->count();
        $closes = $toggles->filter(fn ($e) => data_get($e->metadata, 'open') === false)->groupBy(fn ($e) => data_get($e->metadata, 'block'))->map->count();
        $durMs = $uf('customer_360', 'closed')->map(fn ($e) => data_get($e->metadata, 'duration_ms'))->filter();
        $this->line("\n── 4. CUSTOMER 360 — uso por bloco ".str_repeat('─', 21));
        $b360 = [];
        foreach (self::BLOCKS as $bk) $b360[] = [$bk, (int) $opens->get($bk, 0), (int) $closes->get($bk, 0), $opens->get($bk, 0) ? '' : '⚠ nunca expandido'];
        $this->table(['Bloco', 'Aberturas', 'Fechamentos', 'Alerta'], $b360);
        $this->line('  Tempo médio de consulta: ' . ($durMs->count() ? round($durMs->avg() / 1000, 1) . 's' : '—') . "   (views: " . $uf('customer_360', 'viewed')->count() . ')');

        // ── 5) PLAYBOOKS ────────────────────────────────────────────────────
        $pbExec = $uf('playbook', 'executed');
        $execByPb = $pbExec->groupBy(fn ($e) => data_get($e->metadata, 'playbook_name'))->map->count();
        $this->line("\n── 5. PLAYBOOKS ".str_repeat('─', 39));
        $pbRows = [];
        foreach ($execByPb->sortDesc() as $nome => $qtd) {
            $tids = $pbExec->filter(fn ($e) => data_get($e->metadata, 'playbook_name') === $nome)->map(fn ($e) => data_get($e->metadata, 'ticket_id'))->filter()->unique();
            $tk = HelpDeskTicket::whereIn('id', $tids)->whereNotNull('resolved_at')->get(['created_at', 'resolved_at']);
            $spd = $tk->count() ? round($tk->avg(fn ($t) => abs($ts($t->created_at)->diffInHours($ts($t->resolved_at)))), 1) . 'h' : '—';
            $pbRows[] = [$nome, $qtd, $spd];
        }
        if ($pbRows) $this->table(['Playbook', 'Execuções', 'Resolução média'], $pbRows);
        $neverUsed = Playbook::where('scope', 'help_desk')->where('active', true)->pluck('name')->reject(fn ($n) => $execByPb->has($n))->values();
        $this->line('  Nunca usados: ' . ($neverUsed->isEmpty() ? '(nenhum)' : $neverUsed->implode(', ')));

        // ── 6) MODO ATENDIMENTO ─────────────────────────────────────────────
        $endedN = $sessions->whereNotNull('ended_at')->count();
        $skipped = WorkSessionEvent::where('type', 'ticket_skipped')->whereBetween('created_at', $win)->count();
        $this->line("\n── 6. MODO ATENDIMENTO ".str_repeat('─', 32));
        $this->line("  Sessões iniciadas: {$sessN}  |  concluídas: {$endedN}  |  chamados/sessão: " . ($resolvedPerSession ?? '—') . "  |  pulados: {$skipped}");

        // ── AS TRÊS PERGUNTAS ───────────────────────────────────────────────
        $this->line("\n".str_repeat('═', 58));
        $this->info('AS TRÊS PERGUNTAS DO PRODUCT REVIEW 2');
        $this->line("\n1) O QUE OS USUÁRIOS REALMENTE UTILIZARAM?");
        foreach ($metas as [$nome, $m, $meta]) { [$icon, $v] = $this->classify($m, $meta); $this->line("   $icon {$nome}: " . ($m ?? '—') . "% (meta ≥{$meta}%) → {$v}"); }

        $this->line("\n2) O QUE AUMENTOU PRODUTIVIDADE?");
        $this->line("   Tempo entre chamados: {$gapAvg($feFirst)}→{$gapAvg($feSecond)} min | TMA: {$tma($from,$mid)}→{$tma($mid,$to)} min");
        $this->line("   Resolvidos/sessão: " . ($resolvedPerSession ?? '—') . " | Horas/sessão: " . ($horasPerSession ?? '—'));

        $this->line("\n3) O QUE DEVEMOS SIMPLIFICAR ANTES DA PRÓXIMA FASE?");
        $ajustar = collect($metas)->filter(fn ($r) => in_array($this->classify($r[1], $r[2])[1], ['Ajustar', 'Remover']))->map(fn ($r) => $r[0].' ('.$this->classify($r[1], $r[2])[1].')');
        $this->line('   Funcionalidades a revisar/remover: ' . ($ajustar->isEmpty() ? '(nenhuma — todas consolidadas 🟢)' : $ajustar->implode(', ')));
        $blocosFrios = collect(self::BLOCKS)->reject(fn ($b) => $opens->get($b, 0))->values();
        $this->line('   Blocos do 360 nunca expandidos: ' . ($blocosFrios->isEmpty() ? '(nenhum)' : $blocosFrios->implode(', ')));
        $this->line('   Playbooks nunca usados: ' . ($neverUsed->isEmpty() ? '(nenhum)' : $neverUsed->implode(', ')));
        $this->line('   + notas de UX da Folha de Observação (qualitativo).');
        $this->line('');

        return self::SUCCESS;
    }

    /** Classifica adoção: Consolidada (≥meta) / Ajustar (≥FLOOR) / Remover (<FLOOR). */
    private function classify(?float $m, float $meta): array
    {
        if ($m === null) return ['—', 'sem dados'];
        if ($m >= $meta) return ['🟢', 'Consolidada'];
        if ($m >= self::FLOOR) return ['🟡', 'Ajustar'];
        return ['🔴', 'Remover'];
    }

    /** Linha de tendência com sinal (good = direção desejada). */
    private function trend(string $label, ?float $a, ?float $b, string $good): array
    {
        $sinal = '—';
        if ($a !== null && $b !== null) {
            if ($a === $b) $sinal = '→ estável';
            else { $melhorou = $good === 'down' ? $b < $a : $b > $a; $sinal = $melhorou ? '✅ melhora' : '⚠ piora'; }
        }
        return [$label, $a ?? '—', $b ?? '—', $sinal];
    }
}
