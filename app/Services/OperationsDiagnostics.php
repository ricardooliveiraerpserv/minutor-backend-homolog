<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Central de Operações — motor de DIAGNÓSTICOS (regras simples; NÃO é IA).
 *
 * Lê os BLOCOS já montados pelo {@see OperationsCenterService} e produz:
 *  - `atencoes`: lista PRIORIZADA (severidade + mensagem + AÇÃO 1-clique) — "onde agir primeiro";
 *  - `tendencias`: FRASES (o coordenador lê mais rápido do que interpreta gráfico).
 *
 * Saída ESTRUTURADA: amanhã as regras podem ser trocadas/complementadas por IA com a MESMA
 * forma — a interface (FE) não muda. Espelha {@see Customer360Diagnostics}.
 *
 * Toda atenção carrega uma `action` de vocabulário fixo que o FE sabe despachar:
 *  open_ticket {ticket_id} · open_tickets {query} · open_queue {team_id} ·
 *  open_customer360 {customer_id} · redistribute {assignee_id?, team_id?}.
 * Princípio: NENHUM indicador sem ação.
 */
class OperationsDiagnostics
{
    private const SOBRECARGA = 8;           // chamados em atendimento → consultor sobrecarregado
    private const FILA_PARADA_MIN = 30;     // min de espera → fila travada
    private const SESSAO_ABANDONO_MIN = 30; // min sem evento → sessão abandonada
    private const TREND_AUMENTO_PCT = 0.25; // +25% de entrada → tendência de fila
    private const TREND_TMA_PCT = 0.15;     // ±15% no tempo médio → tendência

    /** @param array $b blocos do OperationsCenterService (equipe, filas, clientes, sessoes, tendencias) */
    public function analyze(array $b): array
    {
        return [
            'atencoes'   => $this->atencoes($b),
            'tendencias' => $this->tendencias($b['tendencias'] ?? []),
        ];
    }

    // ── Atenções (priorizadas + acionáveis) ───────────────────────────────────

    private function atencoes(array $b): array
    {
        $out = [];
        $now = now();

        // 🔴 SLA estourado (global).
        $vencidos = collect($b['filas'] ?? [])->sum('vencidos');
        if ($vencidos > 0) {
            $out[] = $this->item('danger', 'sla_estourado', "SLA estourado em {$vencidos} chamado(s).",
                ['kind' => 'open_tickets', 'query' => ['breached' => 1], 'label' => 'Abrir chamados']);
        }

        // 🔴 Clientes com banco de horas negativo.
        $bhNeg = collect($b['clientes'] ?? [])->filter(fn ($c) => collect($c['motivos'])->contains(fn ($m) => $m['code'] === 'bh_negativo'))->values();
        if ($bhNeg->isNotEmpty()) {
            $n = $bhNeg->count();
            $first = $bhNeg->first();
            $action = $n === 1
                ? ['kind' => 'open_customer360', 'customer_id' => $first['customer_id'], 'label' => 'Abrir Customer 360']
                : ['kind' => 'open_tickets', 'query' => [], 'label' => 'Ver clientes'];
            $msg = $n === 1 ? "Cliente {$first['nome']} com banco de horas negativo." : "{$n} clientes com banco de horas negativo.";
            $out[] = $this->item('danger', 'cliente_bh_negativo', $msg, $action);
        }

        // 🟡 Consultores sobrecarregados.
        foreach (collect($b['equipe'] ?? [])->where('em_atendimento', '>=', self::SOBRECARGA) as $c) {
            $out[] = $this->item('warning', 'consultor_sobrecarregado',
                "{$c['nome']} com {$c['em_atendimento']} chamados em atendimento.",
                ['kind' => 'redistribute', 'assignee_id' => $c['user_id'], 'label' => 'Redistribuir']);
        }

        // 🟡 Filas travadas (espera antiga).
        foreach (collect($b['filas'] ?? []) as $f) {
            if (($f['aguardando'] ?? 0) > 0 && ($f['parada_min'] ?? 0) >= self::FILA_PARADA_MIN) {
                $out[] = $this->item('warning', 'fila_parada',
                    "Fila {$f['nome']} parada há {$f['parada_min']} min.",
                    ['kind' => 'open_queue', 'team_id' => $f['team_id'], 'label' => 'Entrar na fila']);
            }
        }

        // 🟡 Sessões de atendimento abandonadas (ativa, sem evento recente).
        foreach (collect($b['sessoes'] ?? []) as $s) {
            if (!$s['ultimo_evento']) continue;
            $min = (int) Carbon::parse($s['ultimo_evento'])->diffInMinutes($now);
            if ($min >= self::SESSAO_ABANDONO_MIN) {
                $out[] = $this->item('warning', 'sessao_abandonada',
                    "Sessão de {$s['nome']} parada há {$min} min.",
                    ['kind' => 'open_tickets', 'query' => ['assignee_id' => $s['user_id']], 'label' => 'Ver chamados']);
            }
        }

        if (empty($out)) {
            $out[] = $this->item('ok', 'operacao_saudavel', 'Operação saudável.', null);
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        usort($out, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $out;
    }

    // ── Tendências (frases) ───────────────────────────────────────────────────

    private function tendencias(array $t): array
    {
        $out = [];
        $w = $t['window_hours'] ?? OperationsCenterService::TREND_WINDOW_HOURS;

        foreach ($t['fila_entrada'] ?? [] as $f) {
            $rec = $f['recente']; $ant = $f['anterior'];
            if ($ant > 0 && $rec >= $ant * (1 + self::TREND_AUMENTO_PCT)) {
                $pct = (int) round(($rec - $ant) / $ant * 100);
                $out[] = $this->frase('warning', "Fila {$f['nome']} aumentou {$pct}% nas últimas {$w} horas.",
                    ['kind' => 'open_queue', 'team_id' => $f['team_id'], 'label' => 'Entrar na fila']);
            } elseif ($ant === 0 && $rec >= 3) {
                $out[] = $this->frase('warning', "Fila {$f['nome']} recebeu {$rec} novos chamados nas últimas {$w} horas.",
                    ['kind' => 'open_queue', 'team_id' => $f['team_id'], 'label' => 'Entrar na fila']);
            }
        }

        if (($t['top_resolver']['qtd'] ?? 0) > 0) {
            $r = $t['top_resolver'];
            $out[] = $this->frase('ok', "{$r['nome']} resolveu {$r['qtd']} chamado(s) hoje.",
                ['kind' => 'open_tickets', 'query' => ['assignee_id' => $r['user_id']], 'label' => 'Ver chamados']);
        }

        $hoje = $t['tma_hoje_min'] ?? 0; $ontem = $t['tma_ontem_min'] ?? 0;
        if ($ontem > 0 && $hoje > 0) {
            $delta = ($hoje - $ontem) / $ontem;
            if ($delta >= self::TREND_TMA_PCT) {
                $out[] = $this->frase('warning', 'Tempo médio de atendimento aumentou ' . (int) round($delta * 100) . '% em relação a ontem.', null);
            } elseif ($delta <= -self::TREND_TMA_PCT) {
                $out[] = $this->frase('ok', 'Tempo médio de atendimento caiu ' . (int) round(abs($delta) * 100) . '% em relação a ontem.', null);
            }
        }

        if (empty($out)) {
            $out[] = $this->frase('ok', 'Sem variações relevantes na operação.', null);
        }
        return $out;
    }

    private function item(string $severity, string $code, string $message, ?array $action): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message, 'action' => $action];
    }

    private function frase(string $severity, string $message, ?array $action): array
    {
        return ['severity' => $severity, 'message' => $message, 'action' => $action];
    }
}
