<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetLog;
use App\Models\TimesheetTransferRule;
use Illuminate\Support\Carbon;

/**
 * Motor da TRANSFERÊNCIA DE APONTAMENTOS (regras cliente→projeto por janela de data).
 * - applyToModel(): hook em memória (antes de salvar) — move apontamentos NOVOS (Movidesk/HD/web).
 * - applyRule():    aplicação RETROATIVA — move os apontamentos existentes que casam a regra.
 *
 * Decisões (travadas pelo usuário 2026-10-24): move o PROJETO COMPLETO (project_id + real_project_id)
 * e o cliente passa a ser o do projeto destino; RESPEITA edição manual (override_manual=false);
 * PULA período fechado (date_locked) — mover alteraria fechamentos encerrados.
 */
class TimesheetTransferService
{
    /** @var array<int, \Illuminate\Support\Collection<int,TimesheetTransferRule>> cache por request */
    private array $rulesByCustomer = [];

    /** Regra ATIVA que casa (cliente + data na janela). A mais recente (id desc) vence. */
    public function matchingRule(int $customerId, $serviceDate, $createdAt): ?TimesheetTransferRule
    {
        $rules = $this->rulesByCustomer[$customerId] ??=
            TimesheetTransferRule::where('source_customer_id', $customerId)->where('active', true)
                ->orderByDesc('id')->get();

        foreach ($rules as $rule) {
            $raw = $rule->date_field === 'digitacao' ? $createdAt : $serviceDate;
            if (! $raw) {
                continue;
            }
            $val = Carbon::parse($raw)->toDateString();
            if ($rule->date_from && $val < $rule->date_from->toDateString()) {
                continue;
            }
            if ($rule->date_until && $val > $rule->date_until->toDateString()) {
                continue;
            }
            return $rule;
        }
        return null;
    }

    /**
     * Aplica a regra num Timesheet EM MEMÓRIA (chamado no boot 'saving'). Move project/real/cliente.
     * Retorna true se moveu (os campos são alterados no modelo; a gravação é do caller).
     */
    public function applyToModel(Timesheet $ts): bool
    {
        if (! $ts->customer_id || ! $ts->date) {
            return false;
        }
        $rule = $this->matchingRule((int) $ts->customer_id, $ts->date, $ts->created_at ?? now());
        if (! $rule) {
            return false;
        }
        if ($ts->manual_project_edit && ! $rule->override_manual) {
            return false;
        }
        if ((int) $ts->project_id === (int) $rule->target_project_id) {
            return false; // já no destino
        }
        $target = Project::find($rule->target_project_id);
        if (! $target) {
            return false;
        }
        // Grava a ORIGEM ANTES de sobrescrever o customer_id (que vira o do destino). Usamos
        // rule->source_customer_id (= cliente de origem por definição da regra que casou).
        $ts->transfer_rule_id            = (int) $rule->id;
        $ts->transfer_source_customer_id = (int) $rule->source_customer_id;
        $ts->project_id      = (int) $rule->target_project_id;
        $ts->real_project_id = (int) $rule->target_project_id;
        if ($target->customer_id) {
            $ts->customer_id = (int) $target->customer_id;
        }
        return true;
    }

    /**
     * Aplicação RETROATIVA: move os apontamentos EXISTENTES que casam a regra.
     * $dryRun=true só conta (preview), não grava.
     * @return array{total:int,moved:int,skipped_manual:int,skipped_locked:int}
     */
    public function applyRule(TimesheetTransferRule $rule, bool $dryRun = false, ?int $actorId = null): array
    {
        $out = ['total' => 0, 'moved' => 0, 'skipped_manual' => 0, 'skipped_locked' => 0];
        $target = Project::find($rule->target_project_id);
        if (! $target) {
            return $out;
        }
        $dateCol = $rule->date_field === 'digitacao' ? 'created_at' : 'date';
        // Projetos ENCERRADOS (finished) do cliente origem: p/ estes, MOVE mesmo em período fechado
        // (date_locked) — decisão do user "migrar mesmo encerrado" (mantém a migração). Set p/ O(1).
        $finishedProjectIds = Project::where('customer_id', $rule->source_customer_id)
            ->where('status', Project::STATUS_FINISHED)->pluck('id')->flip();

        $q = Timesheet::where('customer_id', $rule->source_customer_id)
            ->where(function ($w) use ($rule) {
                $w->whereNull('project_id')->orWhere('project_id', '!=', $rule->target_project_id);
            });
        if ($rule->date_from) {
            $q->whereDate($dateCol, '>=', $rule->date_from->toDateString());
        }
        if ($rule->date_until) {
            $q->whereDate($dateCol, '<=', $rule->date_until->toDateString());
        }

        $out['moved_locked'] = 0; // movidos APESAR de período fechado (projeto encerrado)
        $q->chunkById(300, function ($chunk) use (&$out, $rule, $target, $dryRun, $actorId, $finishedProjectIds) {
            foreach ($chunk as $ts) {
                $out['total']++;
                // Projeto origem ENCERRADO → migra mesmo em período fechado; senão PULA o fechado.
                $originEncerrado = $ts->project_id && isset($finishedProjectIds[$ts->project_id]);
                if ($ts->date_locked && ! $originEncerrado) {
                    $out['skipped_locked']++;
                    continue;
                }
                if ($ts->date_locked && $originEncerrado) {
                    $out['moved_locked']++;
                }
                if ($ts->manual_project_edit && ! $rule->override_manual) {
                    $out['skipped_manual']++;
                    continue;
                }
                $out['moved']++;
                if ($dryRun) {
                    continue;
                }
                $from = $ts->project_id;
                $ts->transfer_rule_id            = (int) $rule->id;
                $ts->transfer_source_customer_id = (int) $rule->source_customer_id;
                $ts->project_id      = (int) $rule->target_project_id;
                $ts->real_project_id = (int) $rule->target_project_id;
                if ($target->customer_id) {
                    $ts->customer_id = (int) $target->customer_id;
                }
                $ts->save(); // dispara o boot (recalcula pct/company_id); o hook de regra é no-op (já no destino)
                try {
                    TimesheetLog::create([
                        'timesheet_id' => $ts->id,
                        'changed_by'   => $actorId,
                        'source'       => 'transfer_rule',
                        'action'       => 'project_transferred',
                        'changes'      => ['from_project_id' => $from, 'to_project_id' => (int) $rule->target_project_id, 'rule_id' => $rule->id],
                    ]);
                } catch (\Throwable $e) {
                    // auditoria best-effort
                }
            }
        });

        return $out;
    }
}
