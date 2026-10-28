<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\TimesheetTransferRule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill da ORIGEM dos apontamentos já migrados pela Transferência de Apontamentos.
 *
 * A migração sobrescreve customer_id (vira o do projeto destino) e os auto-migrados (save hook)
 * não deixavam rastro, então a empresa de origem se perdeu. Aqui atribuímos retroativamente
 * transfer_rule_id + transfer_source_customer_id APENAS onde é NÃO-AMBÍGUO: projetos destino com
 * UMA ÚNICA regra. Onde 2+ regras apontam pro mesmo destino, não há como saber de qual empresa
 * veio cada apontamento → ficam "não registrados".
 *
 * ⚠️ Heurística: marca como migrados os apontamentos no destino dentro da janela da regra. Um
 * apontamento NATIVO do destino (lançado direto) dentro da janela também será marcado — risco
 * aceito pelo usuário. Dry-run por padrão; --execute grava (update raw, sem disparar hooks).
 */
class BackfillTransferOrigin extends Command
{
    protected $signature = 'transfer:backfill-origin {--execute : Grava de fato (sem isto é só dry-run)}';

    protected $description = 'Preenche a origem (regra/empresa) dos apontamentos já migrados, onde o projeto destino tem só 1 regra.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->info($execute ? '>> MODO EXECUÇÃO (vai gravar)' : '>> DRY-RUN (nada é gravado) — use --execute para aplicar');

        // Agrupa regras por projeto destino; só processa destinos com EXATAMENTE uma regra.
        $byTarget = TimesheetTransferRule::get(['id', 'source_customer_id', 'target_project_id', 'date_field'])
            ->groupBy('target_project_id');

        $totalUpdated = 0;
        $ambiguous = [];
        foreach ($byTarget as $targetId => $rules) {
            $targetId = (int) $targetId;
            if ($rules->count() > 1) {
                $ambiguous[$targetId] = $rules->count();
                continue; // ambíguo: não dá p/ saber a origem de cada apontamento
            }
            $rule = $rules->first();
            $dateCol = $rule->date_field === 'digitacao' ? 'created_at' : 'date';

            $q = DB::table('timesheets')->whereNull('deleted_at')->whereNull('transfer_rule_id')
                ->where(function ($w) use ($targetId) {
                    $w->where('project_id', $targetId)->orWhere('real_project_id', $targetId);
                });
            if ($rule->date_from)  { $q->whereDate($dateCol, '>=', $rule->date_from->toDateString()); }
            if ($rule->date_until) { $q->whereDate($dateCol, '<=', $rule->date_until->toDateString()); }

            $count = (clone $q)->count();
            $srcName = optional(Customer::find($rule->source_customer_id))->name ?? ('#' . $rule->source_customer_id);
            $this->line(sprintf('  destino %d (regra %d, origem %s): %d apontamento(s)', $targetId, $rule->id, $srcName, $count));

            if ($execute && $count > 0) {
                $q->update([
                    'transfer_rule_id'            => $rule->id,
                    'transfer_source_customer_id' => $rule->source_customer_id,
                ]);
            }
            $totalUpdated += $count;
        }

        if ($ambiguous) {
            $this->warn('Destinos AMBÍGUOS (2+ regras) — pulados, ficam "não registrados":');
            foreach ($ambiguous as $t => $n) {
                $this->line(sprintf('  projeto %d: %d regras', $t, $n));
            }
        }

        $this->info(sprintf('%s %d apontamento(s) %s.', $execute ? 'Gravado em' : 'Seriam atualizados', $totalUpdated, $execute ? 'atualizados' : '(dry-run)'));
        return self::SUCCESS;
    }
}
