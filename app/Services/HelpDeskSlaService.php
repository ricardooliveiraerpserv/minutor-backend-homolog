<?php

namespace App\Services;

use App\Models\HelpDeskSlaPolicy;
use App\Models\HelpDeskStatus;
use App\Models\HelpDeskTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Help Desk — motor de SLA.
 *
 * Pausa de SLA (R2): quando o chamado está num status com `sla_paused = true`
 * (ex.: Aguardando Cliente), o relógio PARA. A fonte da verdade é a TIMELINE de
 * mudanças de status (`helpdesk_ticket_events` event_type=status_changed): somamos
 * o tempo em que o chamado permaneceu em status pausado e empurramos o prazo na
 * mesma medida. O prazo armazenado (`*_due_at`) é o BRUTO (criação + meta); o prazo
 * EFETIVO = bruto + minutos pausados até o instante de referência. Nada de aproximação.
 *
 * Performance: `summary()`/`computeBreaches()` aceitam a coleção de eventos já
 * carregada — a lista de chamados carrega os eventos de todos os tickets em UMA query
 * e repassa, evitando N+1.
 */
class HelpDeskSlaService
{
    /** Cache por request das chaves de status pausados. */
    private ?array $pausedKeys = null;

    /** Cache do calendário (windows/holidays/tz) por ticket. */
    private array $calCache = [];

    public function __construct(private SlaBusinessClock $clock)
    {
    }

    private function pausedKeys(): array
    {
        if ($this->pausedKeys === null) {
            $this->pausedKeys = HelpDeskStatus::where('sla_paused', true)->pluck('key')->all();
        }
        return $this->pausedKeys;
    }

    /** Cache das chaves pausantes POR ticket (dependem da regra/prioridade). */
    private array $pausingKeysCache = [];

    /** Cache: a política tem ALGUMA pausa por regra configurada? (por policy id). */
    private array $policyConfiguredCache = [];

    private function policyHasRulePauses(int $policyId): bool
    {
        if (!isset($this->policyConfiguredCache[$policyId])) {
            $this->policyConfiguredCache[$policyId] = \App\Models\HelpDeskSlaTargetPause::query()
                ->whereHas('target', fn ($q) => $q->where('sla_policy_id', $policyId))->exists();
        }
        return $this->policyConfiguredCache[$policyId];
    }

    /**
     * Status que pausam o SLA DESTE ticket (Fase 2):
     *  - Se a POLÍTICA tem pausas por regra configuradas → usa a lista PRÓPRIA do target
     *    da prioridade (vazio = a regra NÃO pausa em nada).
     *  - Se a política não tem nenhuma pausa por regra → cai no `sla_paused` GLOBAL (retrocompat).
     */
    private function pausingKeysFor(HelpDeskTicket $t): array
    {
        $key = $t->id ?? spl_object_id($t);
        if (!isset($this->pausingKeysCache[$key])) {
            $policy = $t->sla_policy_id
                ? ($t->relationLoaded('slaPolicy') ? $t->slaPolicy : HelpDeskSlaPolicy::find($t->sla_policy_id))
                : $this->resolvePolicy($t);

            if ($policy && $this->policyHasRulePauses($policy->id)) {
                $target = $policy->targetFor((string) $t->priority);
                $this->pausingKeysCache[$key] = $target ? $target->pauseKeys() : [];
            } else {
                $this->pausingKeysCache[$key] = $this->pausedKeys(); // global (legado)
            }
        }
        return $this->pausingKeysCache[$key];
    }

    /** O ticket está pausado AGORA (status atual na lista pausante da sua regra)? */
    private function isPausedNow(HelpDeskTicket $t): bool
    {
        return $t->status && in_array($t->status->key, $this->pausingKeysFor($t), true);
    }

    /**
     * Calendário de horas úteis do ticket (da política aplicável): [windows, holidays, tz].
     * Política sem business_hours → windows vazio → o relógio soma horas CORRIDAS (retrocompat).
     */
    private function calendarFor(HelpDeskTicket $t): array
    {
        $key = $t->id ?? spl_object_id($t);
        if (!isset($this->calCache[$key])) {
            $policy = $t->sla_policy_id
                ? ($t->relationLoaded('slaPolicy') ? $t->slaPolicy : HelpDeskSlaPolicy::find($t->sla_policy_id))
                : $this->resolvePolicy($t);
            $this->calCache[$key] = [
                'windows'  => $policy?->windowsByWeekday() ?? [],
                'holidays' => $policy?->holidayDates() ?? [],
                'tz'       => $policy?->slaTimezone() ?? 'America/Sao_Paulo',
            ];
        }
        return $this->calCache[$key];
    }

    /**
     * Política aplicável: contrato → cliente → categoria → default global.
     */
    public function resolvePolicy(HelpDeskTicket $t): ?HelpDeskSlaPolicy
    {
        $active = fn () => HelpDeskSlaPolicy::query()->where('active', true);

        if ($t->contract_id) {
            $p = $active()->where('contract_id', $t->contract_id)->first();
            if ($p) return $p;
        }
        if ($t->customer_id) {
            // Vínculo N:N (principal): política que tem este cliente na lista.
            $p = $active()->whereHas('customers', fn ($q) => $q->where('customers.id', $t->customer_id))->first();
            if ($p) return $p;
            // Legado: customer_id único na própria política.
            $p = $active()->whereNull('contract_id')->where('customer_id', $t->customer_id)->first();
            if ($p) return $p;
        }
        $catPolicyId = $t->category_id ? optional($t->category)->sla_policy_id : null;
        if ($catPolicyId) {
            $p = $active()->whereKey($catPolicyId)->first(); // a política escolhida na categoria, independentemente do escopo
            if ($p) return $p;
        }
        return HelpDeskSlaPolicy::defaultPolicy();
    }

    /**
     * O canal de abertura do ticket dispara o SLA de 1ª resposta desta regra?
     * Lista vazia/null = todos os canais (sem filtro).
     */
    private function channelTriggersFirstResponse(\App\Models\HelpDeskSlaTarget $target, HelpDeskTicket $t): bool
    {
        $chs = $target->first_response_channels;
        if (empty($chs)) return true;
        return in_array((string) $t->channel, $chs, true);
    }

    /**
     * Aplica SLA ao ticket: política + prazos BRUTOS por prioridade. Respeita override
     * (ticket.sla_policy_id já setado). Base = created_at (ou agora, se ainda não persistido).
     */
    public function apply(HelpDeskTicket $t, bool $persist = true): HelpDeskTicket
    {
        $policy = $t->sla_policy_id ? $t->slaPolicy()->first() : $this->resolvePolicy($t);
        $t->sla_policy_id = $policy?->id;

        $base   = $t->created_at ? Carbon::parse($t->created_at) : now();
        $target = $policy?->targetFor($t->priority);
        if ($target && $target->enabled === false) {
            $target = null; // regra desabilitada não aplica SLA
        }

        // Prazos BRUTOS em HORAS ÚTEIS (calendário da política + feriados). Sem calendário → corridas.
        $win = $policy?->windowsByWeekday() ?? [];
        $hol = $policy?->holidayDates() ?? [];
        $tz  = $policy?->slaTimezone() ?? 'America/Sao_Paulo';

        // 1ª resposta só conta se o canal de abertura do ticket dispara o SLA desta regra.
        $frApplies = $target && $target->first_response_minutes !== null && $this->channelTriggersFirstResponse($target, $t);

        // Grava em UTC: o relógio devolve Carbon no fuso da política (SP); sem normalizar,
        // o Laravel formataria a hora-local como se fosse UTC (deslocamento de -3h na leitura).
        $t->first_response_due_at = $frApplies
            ? $this->clock->addBusinessMinutes($base, (int) $target->first_response_minutes, $win, $hol, $tz)->setTimezone('UTC') : null;
        $t->resolution_due_at = ($target && $target->resolution_minutes !== null)
            ? $this->clock->addBusinessMinutes($base, (int) $target->resolution_minutes, $win, $hol, $tz)->setTimezone('UTC') : null;

        $this->computeBreaches($t);
        if ($persist) $t->save();
        return $t;
    }

    /** Marca a primeira resposta (idempotente — só na primeira vez). */
    public function registerFirstResponse(HelpDeskTicket $t, ?Carbon $at = null): void
    {
        if ($t->first_responded_at) return;
        $t->first_responded_at = $at ?: now();
        $this->computeBreaches($t);
        $t->save();
    }

    /**
     * Minutos acumulados em status PAUSADO entre a criação e $until, reconstruídos da timeline.
     * Se $events for passado (status_changed do ticket, asc), evita query — usado na lista (sem N+1).
     */
    public function pausedMinutesUntil(HelpDeskTicket $t, Carbon $until, ?Collection $events = null): int
    {
        if (!$t->created_at) return 0;
        $cal = $this->calendarFor($t);
        $total = 0;

        // (a) Pausa por STATUS (event-sourced): tempo útil em status pausante.
        $paused = $this->pausingKeysFor($t);
        if (!empty($paused)) {
            $events ??= $t->events()->where('event_type', 'status_changed')
                ->orderBy('created_at')->get(['from_value', 'to_value', 'created_at']);

            // Segmentos [início, statusKey] na ordem cronológica.
            $segments = [];
            if ($events->isEmpty()) {
                $segments[] = [Carbon::parse($t->created_at), $t->status?->key];
            } else {
                $segments[] = [Carbon::parse($t->created_at), $events->first()->from_value];
                foreach ($events as $e) {
                    $segments[] = [Carbon::parse($e->created_at), $e->to_value];
                }
            }
            $n = count($segments);
            for ($i = 0; $i < $n; $i++) {
                $start = $segments[$i][0];
                $end   = ($i + 1 < $n) ? $segments[$i + 1][0] : $until;
                if ($end->lessThanOrEqualTo($start)) continue;
                $key = $segments[$i][1];
                if ($key !== null && in_array($key, $paused, true)) {
                    $total += $this->clock->businessMinutesBetween($start, $end, $cal['windows'], $cal['holidays'], $cal['tz']);
                }
            }
        }

        // (b) Pausa por AGENDAMENTO: enquanto `sla_paused_at` estiver setado, o relógio congela
        // de sla_paused_at até min(until, agora, scheduled_until). Ao retomar, o prazo é empurrado
        // (resumeSchedule) e sla_paused_at é limpo — sem dupla contagem.
        if ($t->sla_paused_at) {
            $pauseStart = Carbon::parse($t->sla_paused_at);
            $pauseEnd = $until->copy();
            if ($pauseEnd->greaterThan(now())) $pauseEnd = now();
            if ($t->scheduled_until && $pauseEnd->greaterThan(Carbon::parse($t->scheduled_until))) {
                $pauseEnd = Carbon::parse($t->scheduled_until);
            }
            if ($pauseEnd->greaterThan($pauseStart)) {
                $total += $this->clock->businessMinutesBetween($pauseStart, $pauseEnd, $cal['windows'], $cal['holidays'], $cal['tz']);
            }
        }
        return (int) $total;
    }

    /** Pausado AGORA pelo STATUS atual (público p/ o controller decidir a pausa por agendamento). */
    public function isPausedByStatus(HelpDeskTicket $t): bool
    {
        return $this->isPausedNow($t);
    }

    /** Existe agendamento vigente (a data ainda não venceu)? — independe da pausa por status. */
    public function hasActiveSchedule(HelpDeskTicket $t): bool
    {
        return $t->scheduled_until !== null && now()->lessThan(Carbon::parse($t->scheduled_until));
    }

    /** Pausado AGORA por agendamento? (sla_paused_at setado e a janela ainda não venceu). */
    public function isSchedulePaused(HelpDeskTicket $t): bool
    {
        return $t->sla_paused_at !== null && (!$t->scheduled_until || now()->lessThan(Carbon::parse($t->scheduled_until)));
    }

    /**
     * Retoma o SLA de um chamado agendado: assa a pausa (minutos úteis de sla_paused_at até a
     * retomada) nos prazos brutos e limpa o agendamento. Retomada manual pode ocorrer antes da data.
     */
    public function resumeSchedule(HelpDeskTicket $t, bool $persist = true): void
    {
        // Só ASSA a pausa se o SLA estava parado POR AGENDAMENTO (sla_paused_at). Se a pausa era
        // por STATUS, o próprio timeline já cuida — aqui só limpamos o agendamento.
        if ($t->sla_paused_at) {
            $cal = $this->calendarFor($t);
            $pauseStart = Carbon::parse($t->sla_paused_at);
            $pauseEnd = $t->scheduled_until ? Carbon::parse($t->scheduled_until) : now();
            if ($pauseEnd->greaterThan(now())) $pauseEnd = now(); // retomada antecipada
            if ($pauseEnd->greaterThan($pauseStart)) {
                $mins = (int) $this->clock->businessMinutesBetween($pauseStart, $pauseEnd, $cal['windows'], $cal['holidays'], $cal['tz']);
                if ($mins > 0) {
                    if ($t->first_response_due_at && !$t->first_responded_at) {
                        $t->first_response_due_at = $this->clock->addBusinessMinutes(Carbon::parse($t->first_response_due_at), $mins, $cal['windows'], $cal['holidays'], $cal['tz'])->setTimezone('UTC');
                    }
                    if ($t->resolution_due_at && !$t->resolved_at) {
                        $t->resolution_due_at = $this->clock->addBusinessMinutes(Carbon::parse($t->resolution_due_at), $mins, $cal['windows'], $cal['holidays'], $cal['tz'])->setTimezone('UTC');
                    }
                }
            }
            $t->sla_paused_at = null;
            $this->computeBreaches($t);
        }
        $t->scheduled_until = null;
        $t->scheduled_all_day = false;
        if ($persist) $t->save();
    }

    /** Prazo EFETIVO = bruto + minutos ÚTEIS pausados, empurrados pelo calendário. */
    private function effectiveDue(?Carbon $rawDue, HelpDeskTicket $t, Carbon $ref, ?Collection $events): ?Carbon
    {
        if (!$rawDue) return null;
        $paused = $this->pausedMinutesUntil($t, $ref, $events);
        if ($paused <= 0) return clone $rawDue;
        $cal = $this->calendarFor($t);
        return $this->clock->addBusinessMinutes($rawDue, $paused, $cal['windows'], $cal['holidays'], $cal['tz']);
    }

    /** (Re)calcula as flags de violação considerando a pausa de SLA. */
    public function computeBreaches(HelpDeskTicket $t, ?Collection $events = null): void
    {
        if ($t->first_response_due_at) {
            $ref = $t->first_responded_at ? Carbon::parse($t->first_responded_at) : now();
            $eff = $this->effectiveDue(Carbon::parse($t->first_response_due_at), $t, $ref, $events);
            $t->first_response_breached = $ref->greaterThan($eff);
        }
        if ($t->resolution_due_at) {
            $ref = $t->resolved_at ? Carbon::parse($t->resolved_at) : now();
            $eff = $this->effectiveDue(Carbon::parse($t->resolution_due_at), $t, $ref, $events);
            $t->resolution_breached = $ref->greaterThan($eff);
        }
    }

    /**
     * Resumo de SLA para a UI INTERNA (prazos efetivos, atraso, minutos restantes, pausa).
     */
    public function summary(HelpDeskTicket $t, ?Collection $events = null): array
    {
        $now = now();
        $isPaused = $this->isPausedNow($t) || $this->isSchedulePaused($t);
        // Enquanto pausado, o "relógio" congela: referência de cálculo é o instante de entrada
        // na pausa não é necessário pois effectiveDue já desconta a pausa até $now.
        $effFr  = $this->effectiveDue(optional($t->first_response_due_at) ? Carbon::parse($t->first_response_due_at) : null, $t, $now, $events);
        $effRes = $this->effectiveDue(optional($t->resolution_due_at) ? Carbon::parse($t->resolution_due_at) : null, $t, $now, $events);
        $minsTo = fn ($due) => $due ? (int) round($now->diffInMinutes($due, false)) : null;

        return [
            'policy_id'                   => $t->sla_policy_id,
            'paused'                      => (bool) $isPaused,
            'scheduled'                   => $this->hasActiveSchedule($t),
            'scheduled_until'             => optional($t->scheduled_until)->toIso8601String(),
            'scheduled_all_day'           => (bool) $t->scheduled_all_day,
            'first_response_due_at'       => optional($effFr)->toIso8601String(),
            'resolution_due_at'           => optional($effRes)->toIso8601String(),
            'first_responded_at'          => optional($t->first_responded_at)->toIso8601String(),
            'resolved_at'                 => optional($t->resolved_at)->toIso8601String(),
            'first_response_breached'     => (bool) $t->first_response_breached,
            'resolution_breached'         => (bool) $t->resolution_breached,
            'first_response_overdue'      => !$t->first_responded_at && $effFr && $now->greaterThan($effFr),
            'resolution_overdue'          => !$t->resolved_at && $effRes && $now->greaterThan($effRes),
            'first_response_minutes_left' => $t->first_responded_at ? null : $minsTo($effFr),
            'resolution_minutes_left'     => $t->resolved_at ? null : $minsTo($effRes),
        ];
    }

    /**
     * Resumo de SLA voltado ao CLIENTE (Portal). Sem flags internas de violação,
     * sem timing de primeira resposta interno: só o que faz sentido para o cliente.
     */
    public function clientSummary(HelpDeskTicket $t, ?Collection $events = null): array
    {
        $now  = now();
        $effRes = $this->effectiveDue(optional($t->resolution_due_at) ? Carbon::parse($t->resolution_due_at) : null, $t, $now, $events);

        return [
            'previsao_resolucao' => optional($effRes)->toIso8601String(),
            'respondido'         => (bool) $t->first_responded_at,
            'resolvido_em'       => optional($t->resolved_at)->toIso8601String(),
            'em_pausa'           => $this->isPausedNow($t),
        ];
    }
}
