<?php

namespace App\Console\Commands;

use App\Models\SourceDoc;
use App\SourceCode\SourceDocPipeline;
use Illuminate\Console\Command;

/**
 * Gate de robustez dos grandes — TOP-UP/RECOVERY seletivo de fontes já processados PARCIAIS.
 * Não processa fonte novo, não refaz do zero: só enriquece o semantic_json existente (blocos
 * quebrados + funções em missing técnico). Emite before × after em JSON por fonte.
 *
 * Uso: php artisan source-doc:topup 1622 1623 1625 ...
 */
class SourceDocTopUpCommand extends Command
{
    protected $signature = 'source-doc:topup {ids* : IDs de source_docs (versão corrente)}';
    protected $description = 'Top-up/recovery seletivo de fontes parciais (before × after).';

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
                $ver = $pipeline->topUpVersion($ver->fresh());
            } catch (\Throwable $e) {
                $out[] = ['id' => $id, 'error' => mb_substr($e->getMessage(), 0, 200)];
                continue;
            }
            $after = $this->snapshot($ver->semantic_json);
            $out[] = [
                'id' => $id,
                'file' => $doc->filename ?: $doc->path,
                'before' => $before,
                'after' => $after,
            ];
        }
        $this->line(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    /** Métricas comparáveis do semantic_json. */
    private function snapshot($sem): array
    {
        $sem = is_array($sem) ? $sem : [];
        $trace = (array) ($sem['funcoes_trace'] ?? []);
        $tech = 0;
        $tset = ['cost_budget', 'truncated_unrecovered', 'deepen_call_budget', 'simple_truncated'];
        foreach (($trace['missing'] ?? []) as $m) {
            if (in_array($m['reason'] ?? '', $tset, true)) {
                $tech++;
            }
        }
        $dc = (array) ($sem['documentary_completeness'] ?? []);
        $usage = (array) ($sem['usage'] ?? []);
        return [
            'status' => $sem['status'] ?? null,
            'partial_reason' => $sem['partial_reason'] ?? null,
            'strategy' => $sem['strategy'] ?? null,
            'block_status' => $sem['block_status'] ?? null,
            'completed' => count($trace['completed'] ?? []),
            'not_identified' => count($trace['not_identified'] ?? []),
            'missing_total' => count($trace['missing'] ?? []),
            'missing_tecnico' => $tech,
            'regras' => count($sem['regras_negocio'] ?? []),
            'deps' => count($sem['dependencias_criticas'] ?? []),
            'risco' => ! empty(($sem['risco_alteracao']['fatores'] ?? [])),
            'documentary_completeness' => $dc['level'] ?? null,
            'dc_missing_dims' => $dc['missing'] ?? [],
            'rejeicoes_evidencia' => (int) ($sem['validation']['rejected_count'] ?? 0),
            'cost_usd' => $usage['actual_cost_usd'] ?? null,
            'topup_cost_usd' => $usage['topup_cost_usd'] ?? null,
            'topup_calls' => $usage['topup_calls'] ?? null,
            'calls' => $usage['calls'] ?? null,
        ];
    }
}
