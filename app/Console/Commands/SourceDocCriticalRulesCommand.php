<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Console\Command;

/**
 * CRITICAL RULES PASS — passo operacional DEDICADO e estreito. Decide os candidatos a regra crítica
 * (autorização/permissão, limite/teto, bloqueio, mudança de estado) NÃO cobertos por regra validada.
 * Teto próprio ≤ US$ 0,30 (política: Initial + Critical Rules Pass = máx US$ 0,60/fonte). Não refaz o initial.
 *
 * Uso: php artisan source-doc:critical-rules 341 1349 ...
 */
class SourceDocCriticalRulesCommand extends Command
{
    protected $signature = 'source-doc:critical-rules {ids* : IDs de source_docs (versão corrente)}';
    protected $description = 'Critical Rules Pass: decide candidatos a regra crítica (before × after).';

    public function handle(SourceDocPipeline $pipeline): int
    {
        $ids = array_map('intval', (array) $this->argument('ids'));
        $out = [];
        foreach ($ids as $id) {
            $doc = SourceDoc::find($id);
            $ver = $doc?->currentVersion;
            if (! $doc || ! $ver) {
                $out[] = ['id' => $id, 'error' => 'doc/versão não encontrada'];
                continue;
            }
            $before = $this->snapshot($ver->semantic_json);
            try {
                $ver = $pipeline->criticalRulesPassVersion($ver->fresh());
            } catch (\Throwable $e) {
                $out[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 200)];
                continue;
            }
            $out[] = ['id' => $id, 'file' => $doc->filename ?: $doc->path, 'before' => $before, 'after' => $this->snapshot($ver->semantic_json)];
        }
        $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    private function snapshot($sem): array
    {
        $sem = is_array($sem) ? $sem : [];
        $usage = (array) ($sem['usage'] ?? []);
        $crp = (array) ($sem['critical_rules_pass'] ?? []);
        return [
            'status' => $sem['status'] ?? null,
            'block_status' => $sem['block_status'] ?? null,
            'strategy' => $sem['strategy'] ?? null,
            'regras' => count($sem['regras_negocio'] ?? []),
            'evidence_c_aceitas' => count(($sem['cross_source']['evidence_accepted'] ?? [])),
            'cost_usd' => $usage['actual_cost_usd'] ?? null,
            'initial_cost_usd' => $usage['initial_cost_usd'] ?? null,
            'topup_cost_usd' => $usage['topup_cost_usd'] ?? null,
            'total_cost_usd' => $usage['total_cost_usd'] ?? null,
            'cost_model' => $usage['cost_model'] ?? null,
            'crp_triggered' => $crp['triggered'] ?? null,
            'crp_reason' => $crp['reason'] ?? null,
            'crp_uncovered' => count($crp['uncovered'] ?? []),
            'crp_confirmed' => $crp['confirmed'] ?? null,
            'crp_decisions' => array_map(fn ($d) => [($d['candidato'] ?? '?'), ($d['decision'] ?? '?')], (array) ($crp['decisions'] ?? [])),
        ];
    }
}
