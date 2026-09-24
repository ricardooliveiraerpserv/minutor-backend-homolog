<?php

namespace App\SourceCode\Cost;

use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocCostApproval;
use App\Models\SourceDocCostLedger;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — Frente A. Enforcement de custo POR FONTE, na ORQUESTRAÇÃO (o motor congelado
 * segue com seu teto por passo). Regra: actual + reserved + estimated_next <= limite operacional.
 * Se não couber e a aprovação estiver ligada → cria solicitação e NÃO chama a IA. Reserva atômica
 * com lockForUpdate (espelha o CampaignBudgetLedger). Toda decisão é auditada em source_doc_action_log.
 */
class SourceCostGovernor
{
    public function __construct(private CostSettingsResolver $resolver)
    {
    }

    /**
     * Autoriza (ou não) o próximo passo pago da fonte. Quando allow, JÁ reservou estimatedNext.
     *
     * @param  array  $context  reason, completeness_level, gaps, recommendation, layer (p/ a fila)
     */
    public function authorizeStep(SourceDoc $doc, string $step, float $estimatedNext, ?int $userId = null, array $context = []): CostDecision
    {
        $settings = $this->resolver->for($doc);
        $operational = $settings->operationalLimit();

        return DB::transaction(function () use ($doc, $step, $estimatedNext, $userId, $context, $settings, $operational) {
            $ledger = $this->lockLedger($doc, $operational);
            // Propaga aumento de limite global/config; nunca abaixa um teto já aprovado para a fonte.
            $effectiveLimit = max((float) $ledger->authorized_limit_usd, $operational);
            if ($effectiveLimit !== (float) $ledger->authorized_limit_usd) {
                $ledger->authorized_limit_usd = $effectiveLimit;
            }

            $projected = (float) $ledger->actual_cost_usd + (float) $ledger->reserved_cost_usd + max(0, $estimatedNext);

            if ($projected <= $effectiveLimit) {
                $ledger->reserved_cost_usd = (float) $ledger->reserved_cost_usd + max(0, $estimatedNext);
                $ledger->save();
                $this->audit($doc, 'ok', $step, $estimatedNext, $settings, $userId);
                return new CostDecision('allow', max(0, $estimatedNext), (float) $ledger->actual_cost_usd, $effectiveLimit, $estimatedNext, $settings);
            }

            if (! $settings->approvalRequiredAboveLimit) {
                $ledger->save();
                $this->audit($doc, 'skipped', $step, $estimatedNext, $settings, $userId, 'cost_over_source_limit');
                return new CostDecision('deny_partial', 0.0, (float) $ledger->actual_cost_usd, $effectiveLimit, $estimatedNext, $settings);
            }

            $ledger->save();
            $approval = $this->ensureApproval($doc, $step, $estimatedNext, (float) $ledger->actual_cost_usd, $effectiveLimit, $userId, $context);
            $this->audit($doc, 'denied', $step, $estimatedNext, $settings, $userId, 'awaiting_cost_approval');
            return new CostDecision('needs_approval', 0.0, (float) $ledger->actual_cost_usd, $effectiveLimit, $estimatedNext, $settings, $approval);
        });
    }

    /** Fecha um passo: converte reserva em custo real (atômico). */
    public function settle(SourceDoc $doc, float $reserved, float $actual): void
    {
        DB::transaction(function () use ($doc, $reserved, $actual) {
            $ledger = $this->lockLedger($doc, 0.0);
            $ledger->reserved_cost_usd = max(0, (float) $ledger->reserved_cost_usd - max(0, $reserved));
            $ledger->actual_cost_usd = (float) $ledger->actual_cost_usd + max(0, $actual);
            $ledger->save();
        });
    }

    /** Libera a reserva sem gasto (falha/cancelamento/reuso a US$ 0). */
    public function release(SourceDoc $doc, float $reserved): void
    {
        DB::transaction(function () use ($doc, $reserved) {
            $ledger = $this->lockLedger($doc, 0.0);
            $ledger->reserved_cost_usd = max(0, (float) $ledger->reserved_cost_usd - max(0, $reserved));
            $ledger->save();
        });
    }

    /**
     * Aprova SÓ o próximo passo: eleva o teto da fonte APENAS o suficiente para este passo
     * (actual + reserved + estimated). Sem reserva pendurada — ao liquidar, o actual sobe e a folga
     * some, de modo que o passo seguinte volta a exigir aprovação. Retorna o teto aplicado.
     */
    public function approveStep(SourceDocCostApproval $approval, int $userId): float
    {
        return DB::transaction(function () use ($approval, $userId) {
            $doc = SourceDoc::query()->whereKey($approval->source_doc_id)->first();
            $applied = (float) $approval->authorized_limit_usd;
            if ($doc) {
                $settings = $this->resolver->for($doc);
                $ledger = $this->lockLedger($doc, $settings->operationalLimit());
                $needed = (float) $ledger->actual_cost_usd + (float) $ledger->reserved_cost_usd + max(0, (float) $approval->estimated_next_usd);
                // one-shot: só o bastante p/ o próximo passo, nunca acima do teto administrativo.
                $applied = min(max($needed, (float) $ledger->authorized_limit_usd), $settings->maxApprovedCostUsd);
                $ledger->authorized_limit_usd = $applied;
                $ledger->save();
            }
            $approval->status = 'approved_step';
            $approval->decided_by = $userId;
            $approval->decided_at = now();
            $approval->save();
            return $applied;
        });
    }

    /**
     * Aprova NOVO teto para a fonte (≤ max_approved). O próximo authorizeStep passará a caber.
     * Retorna o teto efetivamente aplicado.
     */
    public function approveLimit(SourceDocCostApproval $approval, float $newLimit, int $userId): float
    {
        return DB::transaction(function () use ($approval, $newLimit, $userId) {
            $doc = SourceDoc::query()->whereKey($approval->source_doc_id)->first();
            $applied = $newLimit;
            if ($doc) {
                $settings = $this->resolver->for($doc);
                $ledger = $this->lockLedger($doc, $settings->operationalLimit());
                // clamp: nunca abaixo do atual, nunca acima do teto administrativo aprovável.
                $applied = min(max($newLimit, (float) $ledger->authorized_limit_usd), $settings->maxApprovedCostUsd);
                $ledger->authorized_limit_usd = $applied;
                $ledger->save();
            }
            $approval->status = 'approved_limit';
            $approval->new_limit_usd = $applied;
            $approval->decided_by = $userId;
            $approval->decided_at = now();
            $approval->save();
            return $applied;
        });
    }

    public function decide(SourceDocCostApproval $approval, string $status, int $userId): void
    {
        $approval->status = $status; // closed_partial | rejected
        $approval->decided_by = $userId;
        $approval->decided_at = now();
        $approval->save();
    }

    /**
     * Ledger da fonte com lock; cria (seedando o já gasto) se ausente — RACE-SAFE.
     * Duas chamadas simultâneas na 1ª autorização não podem estourar por criar 2 linhas: o
     * insertOrIgnore (ON CONFLICT DO NOTHING) garante 1 linha sem exceção, e o re-select FOR UPDATE
     * serializa ambas na mesma linha (a 2ª espera e lê o estado já atualizado).
     */
    private function lockLedger(SourceDoc $doc, float $operationalSeed): SourceDocCostLedger
    {
        $ledger = SourceDocCostLedger::query()->where('source_doc_id', $doc->id)->lockForUpdate()->first();
        if ($ledger) {
            return $ledger;
        }
        DB::table('source_doc_cost_ledger')->insertOrIgnore([
            'source_doc_id' => $doc->id,
            'actual_cost_usd' => $this->existingSpend($doc),
            'reserved_cost_usd' => 0,
            'authorized_limit_usd' => $operationalSeed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return SourceDocCostLedger::query()->where('source_doc_id', $doc->id)->lockForUpdate()->first();
    }

    /** Custo já gasto na fonte, lido do semantic_json.usage (total dos passos anteriores). */
    private function existingSpend(SourceDoc $doc): float
    {
        $ver = $doc->relationLoaded('currentVersion') ? $doc->currentVersion : $doc->currentVersion()->first();
        $usage = is_array($ver?->semantic_json) ? ($ver->semantic_json['usage'] ?? []) : [];
        return (float) ($usage['total_cost_usd'] ?? $usage['actual_cost_usd'] ?? 0.0);
    }

    private function ensureApproval(SourceDoc $doc, string $step, float $estimatedNext, float $actual, float $limit, ?int $userId, array $context): SourceDocCostApproval
    {
        $open = SourceDocCostApproval::query()->where('source_doc_id', $doc->id)->where('status', 'pending')->first();
        if ($open) {
            return $open; // 1 aberta por fonte (índice parcial único)
        }
        return SourceDocCostApproval::query()->create([
            'source_doc_id' => $doc->id,
            'version_id' => $doc->current_version_id,
            'status' => 'pending',
            'actual_cost_usd' => round($actual, 4),
            'authorized_limit_usd' => round($limit, 4),
            'next_step' => $step,
            'estimated_next_usd' => round(max(0, $estimatedNext), 4),
            'reason' => isset($context['reason']) ? mb_substr((string) $context['reason'], 0, 200) : 'limite operacional por fonte atingido',
            'completeness_level' => $context['completeness_level'] ?? null,
            'gaps_json' => $context['gaps'] ?? null,
            'recommendation' => isset($context['recommendation']) ? mb_substr((string) $context['recommendation'], 0, 200) : null,
            'requested_by' => $userId,
            'idempotency_key' => hash('sha256', "{$doc->id}|{$step}|{$doc->current_version_id}"),
        ]);
    }

    private function audit(SourceDoc $doc, string $status, string $step, float $est, ResolvedCostSettings $settings, ?int $userId, ?string $reason = null): void
    {
        SourceDocActionLog::create([
            'source_doc_id' => $doc->id, 'version_id' => $doc->current_version_id,
            'action' => 'cost_gate', 'layer' => 'semantic', 'actor_user_id' => $userId,
            'status' => $status, 'reason' => $reason,
            'params' => SourceDocActionLog::sanitize([
                'estimated_cost_usd' => round($est, 4),
                'hard_limit_usd' => $settings->operationalLimit(),
                'reason' => $reason,
            ]),
        ]);
    }
}
