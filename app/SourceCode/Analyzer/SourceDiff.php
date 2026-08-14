<?php

namespace App\SourceCode\Analyzer;

/**
 * Diff ESTRUTURAL entre a versão anterior (parent) e a atual de um fonte. Compara as saídas
 * determinísticas do AdvplAnalyzer + um diff de linhas. NÃO produz explicação semântica da
 * alteração — isso é a Fase 2 (IA). Só fatos: funções add/rem/alteradas, tabelas/campos
 * add/rem e diff_stats.
 */
class SourceDiff
{
    /**
     * @param array|null $old deterministic_json anterior (null = criação)
     * @param array      $new deterministic_json atual
     */
    public function compare(?array $old, array $new, ?string $oldCode, string $newCode): array
    {
        $isCreation = $old === null;
        $oldFns = $isCreation ? [] : $this->byName($old['functions'] ?? []);
        $newFns = $this->byName($new['functions'] ?? []);

        $added = array_values(array_diff(array_keys($newFns), array_keys($oldFns)));
        $removed = array_values(array_diff(array_keys($oldFns), array_keys($newFns)));
        $changed = [];
        foreach (array_intersect(array_keys($newFns), array_keys($oldFns)) as $name) {
            if ($this->signature($newFns[$name]) !== $this->signature($oldFns[$name])) {
                $changed[] = $name;
            }
        }

        $oldTables = $isCreation ? [] : array_map('strtoupper', array_column($old['tables'] ?? [], 'alias'));
        $newTables = array_map('strtoupper', array_column($new['tables'] ?? [], 'alias'));
        $tablesAdded = array_values(array_diff($newTables, $oldTables));
        $tablesRemoved = array_values(array_diff($oldTables, $newTables));

        $line = $this->lineStats($oldCode ?? '', $newCode);

        return [
            'is_creation'       => $isCreation,
            'functions_added'   => array_map(fn ($n) => $newFns[$n]['name'], $added),
            'functions_removed' => array_map(fn ($n) => $oldFns[$n]['name'], $removed),
            'functions_changed' => array_map(fn ($n) => $newFns[$n]['name'], $changed),
            'tables_added'      => $tablesAdded,
            'tables_removed'    => $tablesRemoved,
            'diff_stats'        => [
                'added_lines'       => $line['added'],
                'removed_lines'     => $line['removed'],
                'functions_added'   => count($added),
                'functions_removed' => count($removed),
                'functions_changed' => count($changed),
                'tables_added'      => count($tablesAdded),
                'tables_removed'    => count($tablesRemoved),
            ],
        ];
    }

    private function byName(array $functions): array
    {
        $out = [];
        foreach ($functions as $f) {
            $out[strtoupper($f['name'])] = $f;
        }
        return $out;
    }

    /** Assinatura estrutural p/ detectar "alterada" (params/retornos/chamadas/escrita). */
    private function signature(array $f): string
    {
        return json_encode([
            'params'  => array_map('strtolower', $f['params'] ?? []),
            'returns' => array_map('strtolower', $f['returns'] ?? []),
            'ci'      => array_map('strtolower', $f['calls_internal'] ?? []),
            'cu'      => array_map('strtolower', $f['calls_user'] ?? []),
            'writes'  => $f['writes'] ?? false,
        ]);
    }

    /** Diff de linhas via LCS → contagem de adicionadas/removidas (stats, não hunks). */
    private function lineStats(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", str_replace("\r\n", "\n", $old));
        $b = explode("\n", str_replace("\r\n", "\n", $new));
        $n = count($a);
        $m = count($b);
        if ($n === 0) {
            return ['added' => $m, 'removed' => 0];
        }
        // LCS (comprimento) — O(n*m), suficiente para fontes.
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }
        $lcs = $dp[$n][$m];
        return ['added' => $m - $lcs, 'removed' => $n - $lcs];
    }
}
