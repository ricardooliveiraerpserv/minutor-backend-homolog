<?php

namespace App\Services;

use App\Models\HelpDeskTicket;
use App\Models\Playbook;
use Illuminate\Support\Carbon;

/**
 * Customer 360 — motor de DIAGNÓSTICOS (regras simples; NÃO é IA).
 *
 * Consolida regras operacionais sobre os blocos já calculados do 360 e produz uma lista
 * ESTRUTURADA de "atenções" (severidade + mensagem + sugestão de Playbook). A interface
 * (FE) renderiza essa estrutura genericamente — amanhã as regras podem ser substituídas/
 * complementadas por IA com a MESMA saída, sem mudar a experiência do usuário.
 *
 * severity ∈ {danger, warning, info, ok}. Cada atenção pode trazer `suggested_playbook`
 * (resolvido a um playbook real {id,name} quando existir) → "próxima ação" 1-clique.
 */
class Customer360Diagnostics
{
    /** Limiar de horas críticas (saldo < 10% do contratado). */
    private const BH_BAIXO_PCT = 0.10;
    /** Volume de chamados críticos abertos que acende alerta. */
    private const CRITICOS_ALERTA = 3;
    /** Dias parado aguardando cliente para sugerir cobrança. */
    private const AGUARDANDO_DIAS = 3;

    public function analyze(array $blocos, ?HelpDeskTicket $ticket): array
    {
        $out = [];

        // ── Banco de horas ────────────────────────────────────────────────
        $bh = $blocos['contrato']['banco_horas'] ?? null;
        if ($bh && (float) $bh['contratadas'] > 0) {
            if ((float) $bh['saldo'] < 0) {
                $out[] = $this->item('danger', 'bh_negativo', 'Banco de horas negativo.');
            } elseif ((float) $bh['saldo'] / (float) $bh['contratadas'] < self::BH_BAIXO_PCT) {
                $out[] = $this->item('warning', 'bh_baixo', 'Banco de horas próximo do limite.');
            }
        }

        // ── SLA do chamado ────────────────────────────────────────────────
        $sla = $blocos['contrato']['sla'] ?? null;
        if ($sla) {
            if (!empty($sla['resolution_breached']) || !empty($sla['first_response_breached'])) {
                $out[] = $this->item('danger', 'sla_estourado', 'SLA deste chamado estourado.');
            } elseif (!empty($sla['resolution_overdue']) || !empty($sla['first_response_overdue'])) {
                $out[] = $this->item('warning', 'sla_vencido', 'SLA deste chamado vencido.');
            } elseif (($sla['resolution_minutes_left'] ?? null) !== null && $sla['resolution_minutes_left'] > 0 && $sla['resolution_minutes_left'] < 120) {
                $out[] = $this->item('warning', 'sla_proximo', 'SLA próximo do vencimento.');
            }
        }

        // ── Histórico de chamados ─────────────────────────────────────────
        $hd = $blocos['help_desk'] ?? null;
        if (($hd['vencidos'] ?? 0) > 0) {
            $out[] = $this->item('danger', 'cliente_sla_vencido', 'Cliente tem chamado(s) com SLA estourado.');
        }
        if (($hd['criticos'] ?? 0) >= self::CRITICOS_ALERTA) {
            $out[] = $this->item('warning', 'muitos_criticos', 'Cliente com volume elevado de incidentes críticos.');
        }

        // ── Projeto em risco (horas estouradas — proxy; deadline/Cronograma = futuro) ──
        foreach ($blocos['projetos']['ativos'] ?? [] as $p) {
            if (($p['evolucao_pct'] ?? 0) >= 100) {
                $out[] = $this->item('warning', 'projeto_em_risco', 'Existe projeto com horas estouradas que pode impactar este atendimento.');
                break;
            }
        }

        // ── Comercial ─────────────────────────────────────────────────────
        if (count($blocos['comercial']['oportunidades_abertas'] ?? []) > 0) {
            $out[] = $this->item('info', 'oportunidade_aberta', 'Cliente possui negociação comercial em andamento.');
        }

        // ── Chamado parado aguardando cliente → sugere "Cobrar Retorno" ───
        if ($ticket && $ticket->status && $ticket->status->sla_paused && $ticket->last_activity_at) {
            $dias = (int) Carbon::parse($ticket->last_activity_at)->diffInDays(now());
            if ($dias >= self::AGUARDANDO_DIAS) {
                $out[] = $this->item('warning', 'aguardando_cliente_parado',
                    "Chamado aguardando cliente há {$dias} dia(s).", 'Cobrar Retorno');
            }
        }

        // ── Sem riscos ────────────────────────────────────────────────────
        if (empty($out)) {
            $out[] = $this->item('ok', 'sem_riscos', 'Cliente sem riscos relevantes.');
        }

        // Ordena por severidade (danger > warning > info > ok).
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        usort($out, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $out;
    }

    private function item(string $severity, string $code, string $message, ?string $playbookName = null): array
    {
        $pb = null;
        if ($playbookName) {
            $found = Playbook::forScope('help_desk')->where('active', true)->where('name', $playbookName)->first(['id', 'name']);
            if ($found) $pb = ['id' => $found->id, 'name' => $found->name];
        }
        return ['severity' => $severity, 'code' => $code, 'message' => $message, 'suggested_playbook' => $pb];
    }
}
