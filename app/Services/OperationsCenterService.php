<?php

namespace App\Services;

use App\Models\HelpDeskTeam;
use App\Models\HelpDeskTicket;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Central de Operações — NÚCLEO DE DADOS (torre de controle do Help Desk).
 *
 * Monta os BLOCOS operacionais (equipe, filas, clientes em risco, sessões) + métricas de
 * tendência cruas. NÃO decide prioridades nem redige frases — isso é do
 * {@see OperationsDiagnostics} (regras hoje, IA amanhã, MESMA saída/UI). Mesmo desenho de
 * {@see Customer360Service} + {@see Customer360Diagnostics}.
 *
 * Performance: a torre é uma visão AGREGADA (não é faturamento). "Vencido" usa as flags
 * persistidas + due_at < now respeitando a pausa NO NÍVEL DO STATUS, sem reprocessar a
 * timeline evento-a-evento (precisão fina é exclusiva da tela do chamado). Rápido e suficiente.
 */
class OperationsCenterService
{
    /** Janela das tendências (compara as últimas N horas com as N anteriores). */
    public const TREND_WINDOW_HOURS = 4;

    public function __construct(private WorkSessionSummaryService $summaries)
    {
    }

    public function build(): array
    {
        $now = now();
        $open = $this->openTickets();
        $sessions = $this->activeSessions();
        $horasHoje = $this->horasHojePorUser();

        return [
            'equipe'     => $this->equipe($open, $sessions, $horasHoje, $now),
            'filas'      => $this->filas($open, $sessions, $now),
            'clientes'   => $this->clientesRisco($open),
            'sessoes'    => $this->sessoes($sessions),
            'tendencias' => $this->trendMetrics($open, $now),
        ];
    }

    // ── Fontes ────────────────────────────────────────────────────────────────

    /** Todos os chamados abertos (1 query) — base de toda a agregação em memória. */
    private function openTickets(): Collection
    {
        return HelpDeskTicket::query()
            ->with([
                'status:id,key,label,color,is_open,is_resolved,is_terminal,sla_paused',
                'team:id,name,color',
                'assignee:id,name',
                'customer:id,name',
            ])
            ->whereHas('status', fn ($q) => $q->where('is_open', true))
            ->get();
    }

    /** Sessões de Help Desk em andamento (ended_at nulo), com eventos pré-carregados. */
    private function activeSessions(): Collection
    {
        return WorkSession::where('scope', 'help_desk')
            ->whereNull('ended_at')->with('events')->get();
    }

    /** Horas de Help Desk apontadas HOJE, por usuário (1 query). */
    private function horasHojePorUser(): Collection
    {
        return Timesheet::query()
            ->whereNotNull('helpdesk_ticket_id')->whereNull('deleted_at')
            ->whereDate('created_at', today())
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(effort_minutes) as m')
            ->pluck('m', 'user_id')
            ->map(fn ($m) => round(((float) $m) / 60, 2));
    }

    // ── Regra única de "vencido" (respeita pausa no nível do status) ───────────

    private function vencido(HelpDeskTicket $t, Carbon $now): bool
    {
        if ($t->first_response_breached || $t->resolution_breached) return true;
        if ($t->status && $t->status->sla_paused) return false;
        if ($t->resolution_due_at && !$t->resolved_at && $t->resolution_due_at->lt($now)) return true;
        if ($t->first_response_due_at && !$t->first_responded_at && $t->first_response_due_at->lt($now)) return true;
        return false;
    }

    // ── Bloco: Equipe ─────────────────────────────────────────────────────────

    /**
     * Tabela operacional. Mostra apenas quem está ATUANDO agora (sessão ativa ou chamados
     * assumidos) — torre foca em quem precisa de ajuda, não no cadastro inteiro.
     */
    private function equipe(Collection $open, Collection $sessions, Collection $horasHoje, Carbon $now): array
    {
        $sessionByUser = $sessions->keyBy('user_id');
        $assignedByUser = $open->whereNotNull('assignee_id')->groupBy('assignee_id');

        // Usuários relevantes = quem tem sessão ativa OU chamado assumido aberto.
        $userIds = $assignedByUser->keys()->merge($sessions->pluck('user_id'))->filter()->unique();
        if ($userIds->isEmpty()) return [];

        $users = User::whereIn('id', $userIds)->orderBy('name')->get(['id', 'name', 'type'])->keyBy('id');
        $out = [];

        foreach ($userIds as $uid) {
            $u = $users->get($uid);
            if (!$u) continue;
            $mine = collect($assignedByUser->get($uid) ?? []);
            $emAtendimento = $mine->filter(fn ($t) => $t->status && !$t->status->sla_paused)->count();
            $vencidos = $mine->filter(fn ($t) => $this->vencido($t, $now))->count();
            $session = $sessionByUser->get($uid);
            $resumo = $session ? $this->summaries->summarize($session, $session->events) : null;
            $tempoMedio = ($resumo && $resumo['atendidos'] > 0)
                ? (int) round($resumo['tempo_total_segundos'] / $resumo['atendidos']) : null;
            $ultima = $mine->max('last_activity_at');

            $out[] = [
                'user_id'             => (int) $uid,
                'nome'                => $u->name,
                'tipo'                => $u->type,
                'sessao_ativa'        => $session !== null,
                'em_atendimento'      => $emAtendimento,
                'vencidos'            => $vencidos,
                'sla'                 => $vencidos > 0 ? 'vencido' : ($emAtendimento > 0 ? 'ok' : 'livre'),
                'tempo_medio_seg'     => $tempoMedio,
                'horas_hoje'          => (float) ($horasHoje->get($uid) ?? 0),
                'ultima_atividade'    => $ultima ? Carbon::parse($ultima)->toIso8601String() : null,
                'atendidos_sessao'    => $resumo['atendidos'] ?? null,
            ];
        }

        // Mais carregado primeiro (vencidos, depois em atendimento).
        usort($out, fn ($a, $b) => [$b['vencidos'], $b['em_atendimento']] <=> [$a['vencidos'], $a['em_atendimento']]);
        return $out;
    }

    // ── Bloco: Filas ──────────────────────────────────────────────────────────

    private function filas(Collection $open, Collection $sessions, Carbon $now): array
    {
        $activeUserIds = $sessions->pluck('user_id')->filter()->unique();
        $byTeam = $open->groupBy(fn ($t) => $t->team_id ?? 0);
        $teams = HelpDeskTeam::where('active', true)->orderBy('sort_order')->get(['id', 'name', 'color']);

        $cards = [];
        $emit = function ($id, $name, $color, Collection $tickets) use ($activeUserIds, $now, &$cards) {
            if ($tickets->isEmpty()) return;
            $aguardando = $tickets->filter(fn ($t) => $t->assignee_id === null)->count();
            $emAtendimento = $tickets->filter(fn ($t) => $t->assignee_id !== null && $t->status && !$t->status->sla_paused)->count();
            $aguardandoCliente = $tickets->filter(fn ($t) => $t->status && $t->status->sla_paused)->count();
            $vencidos = $tickets->filter(fn ($t) => $this->vencido($t, $now))->count();
            $consultoresAtivos = $tickets->pluck('assignee_id')->filter()->unique()
                ->intersect($activeUserIds)->count();
            // Fila "parada": minutos desde a última atividade do chamado em espera mais antigo.
            $esperando = $tickets->filter(fn ($t) => $t->assignee_id === null);
            $paradaMin = null;
            if ($esperando->isNotEmpty()) {
                $maisAntigo = $esperando->min(fn ($t) => $t->last_activity_at ?? $t->created_at);
                if ($maisAntigo) $paradaMin = (int) Carbon::parse($maisAntigo)->diffInMinutes($now);
            }

            $cards[] = [
                'team_id'            => $id ?: null,
                'nome'               => $name,
                'cor'                => $color,
                'aguardando'         => $aguardando,
                'em_atendimento'     => $emAtendimento,
                'aguardando_cliente' => $aguardandoCliente,
                'vencidos'           => $vencidos,
                'consultores_ativos' => $consultoresAtivos,
                'parada_min'         => $paradaMin,
                'total'              => $tickets->count(),
            ];
        };

        foreach ($teams as $team) {
            $emit($team->id, $team->name, $team->color, collect($byTeam->get($team->id) ?? []));
        }
        // Chamados sem fila (team_id nulo) — não podem ficar invisíveis na torre.
        $emit(null, 'Sem fila', null, collect($byTeam->get(0) ?? []));

        // Mais vencidos / mais aguardando primeiro.
        usort($cards, fn ($a, $b) => [$b['vencidos'], $b['aguardando']] <=> [$a['vencidos'], $a['aguardando']]);
        return $cards;
    }

    // ── Bloco: Clientes em risco (apenas exceções) ────────────────────────────

    private function clientesRisco(Collection $open): array
    {
        $byCustomer = $open->whereNotNull('customer_id')->groupBy('customer_id');
        $ids = $byCustomer->keys()->map(fn ($k) => (int) $k)->all();
        if (empty($ids)) return [];

        // Banco de horas por cliente — mesma regra do Customer360Service::bancoHoras, em lote.
        $sold = Project::whereIn('customer_id', $ids)->whereNull('deleted_at')
            ->groupBy('customer_id')->selectRaw('customer_id, sum(sold_hours) s')->pluck('s', 'customer_id');
        $consumed = DB::table('timesheets')
            ->join('projects', 'timesheets.project_id', '=', 'projects.id')
            ->whereIn('projects.customer_id', $ids)
            ->whereNull('timesheets.deleted_at')
            ->whereIn('timesheets.status', ['approved', 'pending'])
            ->groupBy('projects.customer_id')
            ->selectRaw('projects.customer_id as cid, sum(timesheets.effort_minutes)/60.0 c')
            ->pluck('c', 'cid');

        // Projeto "atrasado" (proxy: horas estouradas) → clientes com algum projeto consumido >= vendido.
        $consumedByProject = DB::table('timesheets')
            ->whereNull('deleted_at')->whereIn('status', ['approved', 'pending'])
            ->groupBy('project_id')->selectRaw('project_id, sum(effort_minutes)/60.0 c')->pluck('c', 'project_id');
        $atrasado = [];
        foreach (Project::whereIn('customer_id', $ids)->where('sold_hours', '>', 0)->whereNull('deleted_at')->get(['id', 'customer_id', 'sold_hours']) as $p) {
            if (((float) ($consumedByProject[$p->id] ?? 0)) >= (float) $p->sold_hours) $atrasado[$p->customer_id] = true;
        }

        $out = [];
        foreach ($byCustomer as $cid => $tickets) {
            $cid = (int) $cid;
            $tickets = collect($tickets);
            $criticos = $tickets->filter(fn ($t) => in_array($t->priority, ['alta', 'urgente']))->count();
            $vencidos = $tickets->filter(fn ($t) => $t->first_response_breached || $t->resolution_breached)->count();
            $reaberturas = (int) $tickets->sum('reopen_count');
            $contratadas = (float) ($sold[$cid] ?? 0);
            $saldo = $contratadas - (float) ($consumed[$cid] ?? 0);

            $motivos = [];
            if ($contratadas > 0 && $saldo < 0)                  $motivos[] = ['code' => 'bh_negativo', 'label' => 'Banco de horas negativo', 'sev' => 'danger'];
            if ($vencidos > 0)                                   $motivos[] = ['code' => 'sla_estourado', 'label' => "SLA estourado em {$vencidos} chamado(s)", 'sev' => 'danger'];
            if ($criticos >= self::CRITICOS_CLIENTE)             $motivos[] = ['code' => 'muitos_criticos', 'label' => "{$criticos} chamados críticos abertos", 'sev' => 'warning'];
            if (!empty($atrasado[$cid]))                         $motivos[] = ['code' => 'projeto_atrasado', 'label' => 'Projeto com horas estouradas', 'sev' => 'warning'];
            if ($reaberturas >= self::REABERTURAS_CLIENTE)       $motivos[] = ['code' => 'reaberturas', 'label' => "{$reaberturas} reaberturas", 'sev' => 'warning'];

            if (empty($motivos)) continue; // só exceções

            $out[] = [
                'customer_id' => $cid,
                'nome'        => optional($tickets->first()->customer)->name ?? "Cliente #{$cid}",
                'severity'    => collect($motivos)->contains(fn ($m) => $m['sev'] === 'danger') ? 'danger' : 'warning',
                'motivos'     => $motivos,
                'abertos'     => $tickets->count(),
            ];
        }

        usort($out, fn ($a, $b) => [$b['severity'] === 'danger' ? 1 : 0, count($b['motivos'])]
            <=> [$a['severity'] === 'danger' ? 1 : 0, count($a['motivos'])]);
        return $out;
    }

    private const CRITICOS_CLIENTE = 3;
    private const REABERTURAS_CLIENTE = 3;

    // ── Bloco: Sessões ativas ─────────────────────────────────────────────────

    private function sessoes(Collection $sessions): array
    {
        if ($sessions->isEmpty()) return [];
        $users = User::whereIn('id', $sessions->pluck('user_id')->unique())->get(['id', 'name'])->keyBy('id');
        $out = [];
        foreach ($sessions as $s) {
            $resumo = $this->summaries->summarize($s, $s->events);
            $ultimoEvento = $s->events->max('created_at');
            $out[] = [
                'session_id'        => $s->id,
                'user_id'           => (int) $s->user_id,
                'nome'              => optional($users->get($s->user_id))->name ?? "Usuário #{$s->user_id}",
                'label'             => $s->context['label'] ?? 'Sessão',
                'duracao_seg'       => $resumo['tempo_total_segundos'],
                'atendidos'         => $resumo['atendidos'],
                'resolvidos'        => $resumo['resolvidos'],
                'horas_apontadas'   => $resumo['horas_apontadas'],
                'playbooks'         => $resumo['playbooks'],
                'tempo_medio_seg'   => $resumo['atendidos'] > 0 ? (int) round($resumo['tempo_total_segundos'] / $resumo['atendidos']) : null,
                'ultimo_evento'     => $ultimoEvento ? Carbon::parse($ultimoEvento)->toIso8601String() : null,
            ];
        }
        usort($out, fn ($a, $b) => $b['duracao_seg'] <=> $a['duracao_seg']);
        return $out;
    }

    // ── Métricas de tendência (cruas; OperationsDiagnostics transforma em frases) ──

    private function trendMetrics(Collection $open, Carbon $now): array
    {
        $w = self::TREND_WINDOW_HOURS;
        $iniRecente = $now->copy()->subHours($w);
        $iniAnterior = $now->copy()->subHours($w * 2);

        // Entrada de chamados por fila — janela recente vs. anterior.
        $criadosRecentes = HelpDeskTicket::whereBetween('created_at', [$iniRecente, $now])
            ->groupBy('team_id')->selectRaw('team_id, count(*) c')->pluck('c', 'team_id');
        $criadosAnteriores = HelpDeskTicket::whereBetween('created_at', [$iniAnterior, $iniRecente])
            ->groupBy('team_id')->selectRaw('team_id, count(*) c')->pluck('c', 'team_id');
        $teamNames = HelpDeskTeam::pluck('name', 'id');
        $filaEntrada = [];
        foreach ($criadosRecentes as $tid => $rec) {
            if (!$tid) continue;
            $filaEntrada[] = [
                'team_id' => (int) $tid,
                'nome'    => $teamNames[$tid] ?? "Fila #{$tid}",
                'recente' => (int) $rec,
                'anterior' => (int) ($criadosAnteriores[$tid] ?? 0),
            ];
        }

        // Quem mais resolveu hoje.
        $resolvidosHoje = HelpDeskTicket::whereDate('resolved_at', today())->whereNotNull('assignee_id')
            ->groupBy('assignee_id')->selectRaw('assignee_id, count(*) c')->orderByDesc('c')->limit(1)->get();
        $topResolver = null;
        if ($resolvidosHoje->isNotEmpty()) {
            $r = $resolvidosHoje->first();
            $topResolver = ['user_id' => (int) $r->assignee_id, 'nome' => optional(User::find($r->assignee_id))->name ?? '—', 'qtd' => (int) $r->c];
        }

        // Tempo médio de atendimento (min/apontamento HD) — hoje vs. ontem.
        $avg = fn ($date) => (float) (Timesheet::whereNotNull('helpdesk_ticket_id')->whereNull('deleted_at')
            ->whereDate('created_at', $date)->avg('effort_minutes') ?? 0);
        $tmaHoje = round($avg(today()), 1);
        $tmaOntem = round($avg(today()->copy()->subDay()), 1);

        return [
            'window_hours'    => $w,
            'fila_entrada'    => $filaEntrada,
            'top_resolver'    => $topResolver,
            'tma_hoje_min'    => $tmaHoje,
            'tma_ontem_min'   => $tmaOntem,
        ];
    }
}
