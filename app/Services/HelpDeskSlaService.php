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

    private function pausedKeys(): array
    {
        if ($this->pausedKeys === null) {
            $this->pausedKeys = HelpDeskStatus::where('sla_paused', true)->pluck('key')->all();
        }
        return $this->pausedKeys;
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
     * Aplica SLA ao ticket: política + prazos BRUTOS por prioridade. Respeita override
     * (ticket.sla_policy_id já setado). Base = created_at (ou agora, se ainda não persistido).
     */
    public function apply(HelpDeskTicket $t, bool $persist = true): HelpDeskTicket
    {
        $policy = $t->sla_policy_id ? $t->slaPolicy()->first() : $this->resolvePolicy($t);
        $t->sla_policy_id = $policy?->id;

        $base   = $t->created_at ? Carbon::parse($t->created_at) : now();
        $target = $policy?->targetFor($t->priority);

        $t->first_response_due_at = ($target && $target->first_response_minutes !== null)
            ? (clone $base)->addMinutes($target->first_response_minutes) : null;
        $t->resolution_due_at = ($target && $target->resolution_minutes !== null)
            ? (clone $base)->addMinutes($target->resolution_minutes) : null;

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
        $paused = $this->pausedKeys();
        if (empty($paused)) return 0;

        $events ??= $t->events()->where('event_type', 'status_changed')
            ->orderBy('created_at')->get(['from_value', 'to_value', 'created_at']);

        // Segmentos [início, statusKey] na ordem cronológica.
        $segments = [];
        if ($events->isEmpty()) {
            // Nunca mudou de status → vida inteira no status atual.
            $segments[] = [Carbon::parse($t->created_at), $t->status?->key];
        } else {
            $segments[] = [Carbon::parse($t->created_at), $events->first()->from_value]; // status inicial (antes da 1ª troca)
            foreach ($events as $e) {
                $segments[] = [Carbon::parse($e->created_at), $e->to_value];
            }
        }

        $total = 0;
        $n = count($segments);
        for ($i = 0; $i < $n; $i++) {
            $start = $segments[$i][0];
            $end   = ($i + 1 < $n) ? $segments[$i + 1][0] : $until;
            if ($end->lessThanOrEqualTo($start)) continue;
            $key = $segments[$i][1];
            if ($key !== null && in_array($key, $paused, true)) {
                $total += $start->diffInMinutes($end);
            }
        }
        return (int) $total;
    }

    /** Prazo EFETIVO (bruto + pausa até a referência). */
    private function effectiveDue(?Carbon $rawDue, HelpDeskTicket $t, Carbon $ref, ?Collection $events): ?Carbon
    {
        if (!$rawDue) return null;
        return (clone $rawDue)->addMinutes($this->pausedMinutesUntil($t, $ref, $events));
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
        $isPaused = $t->status && $t->status->sla_paused;
        // Enquanto pausado, o "relógio" congela: referência de cálculo é o instante de entrada
        // na pausa não é necessário pois effectiveDue já desconta a pausa até $now.
        $effFr  = $this->effectiveDue(optional($t->first_response_due_at) ? Carbon::parse($t->first_response_due_at) : null, $t, $now, $events);
        $effRes = $this->effectiveDue(optional($t->resolution_due_at) ? Carbon::parse($t->resolution_due_at) : null, $t, $now, $events);
        $minsTo = fn ($due) => $due ? (int) round($now->diffInMinutes($due, false)) : null;

        return [
            'policy_id'                   => $t->sla_policy_id,
            'paused'                      => (bool) $isPaused,
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
            'em_pausa'           => (bool) ($t->status && $t->status->sla_paused),
        ];
    }
}
