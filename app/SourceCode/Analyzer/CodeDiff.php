<?php

namespace App\SourceCode\Analyzer;

/**
 * Diff de CÓDIGO (o "trecho alterado da última modificação"): produz um diff unificado
 * (linhas de contexto + `-` removidas + `+` adicionadas) entre o conteúdo ANTERIOR e o NOVO.
 *
 * - Poda prefixo/sufixo comum (reduz o LCS).
 * - LCS com TETO de células (memória); acima do teto, degrada para um resumo de contagem.
 * - Arquivo novo (sem anterior) → marca is_new e lista o conteúdo como adicionado.
 * Sem dependência externa (não há sebastian/diff no runtime).
 */
class CodeDiff
{
    /** Teto de células do LCS (la*lb) após poda — acima disso não monta hunk linha-a-linha. */
    private const CELL_CAP = 1_200_000;

    /**
     * @return array{diff:string, added:int, removed:int, is_new:bool, truncated:bool}
     */
    public function unified(?string $old, string $new, int $context = 3): array
    {
        $isNew    = $old === null || trim((string) $old) === '';
        $oldLines = $isNew ? [] : $this->lines((string) $old);
        $newLines = $this->lines($new);

        if ($isNew) {
            $body = implode("\n", array_map(fn ($l) => '+' . $l, $newLines));
            return ['diff' => $body, 'added' => count($newLines), 'removed' => 0, 'is_new' => true, 'truncated' => false];
        }
        if ($oldLines === $newLines) {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'is_new' => false, 'truncated' => false];
        }

        // Poda prefixo/sufixo comum.
        $pre = 0;
        $endO = count($oldLines) - 1;
        $endN = count($newLines) - 1;
        while ($pre <= $endO && $pre <= $endN && $oldLines[$pre] === $newLines[$pre]) {
            $pre++;
        }
        while ($endO >= $pre && $endN >= $pre && $oldLines[$endO] === $newLines[$endN]) {
            $endO--;
            $endN--;
        }
        // Mantém até `context` linhas comuns na borda — servem de contexto no hunk.
        $pre  = max(0, $pre - $context);
        $endO = min(count($oldLines) - 1, $endO + $context);
        $endN = min(count($newLines) - 1, $endN + $context);
        $oldMid = array_values(array_slice($oldLines, $pre, $endO - $pre + 1));
        $newMid = array_values(array_slice($newLines, $pre, $endN - $pre + 1));
        $la = count($oldMid);
        $lb = count($newMid);

        if ($la * $lb > self::CELL_CAP) {
            return [
                'diff'      => "# Alteração extensa — {$la} linha(s) removida(s)/alterada(s), {$lb} adicionada(s). Trecho omitido por tamanho.",
                'added'     => $lb,
                'removed'   => $la,
                'is_new'    => false,
                'truncated' => true,
            ];
        }

        // Script de edição (=, -, +) via LCS sobre o MIOX podado, com os índices reais no arquivo.
        $script  = $this->lcsScript($oldMid, $newMid, $pre);
        $added   = 0;
        $removed = 0;
        foreach ($script as $op) {
            if ($op['t'] === '+') {
                $added++;
            } elseif ($op['t'] === '-') {
                $removed++;
            }
        }

        $diff = $this->formatHunks($script, $context);

        return ['diff' => $diff, 'added' => $added, 'removed' => $removed, 'is_new' => false, 'truncated' => false];
    }

    /** @return string[] linhas sem o \n final; \r podado. */
    private function lines(string $s): array
    {
        $s = str_replace("\r\n", "\n", $s);
        $s = str_replace("\r", "\n", $s);
        if ($s === '') {
            return [];
        }
        return explode("\n", $s);
    }

    /**
     * LCS clássico (DP) + backtrack → script [['t'=>'='|'-'|'+', 'line'=>str, 'n'=>indexNovo]].
     * $offset = nº de linhas podadas no prefixo (p/ numeração aproximada no arquivo novo).
     */
    private function lcsScript(array $a, array $b, int $offset): array
    {
        $la = count($a);
        $lb = count($b);
        // Tabela DP (la+1) x (lb+1).
        $dp = [];
        for ($i = 0; $i <= $la; $i++) {
            $dp[$i] = array_fill(0, $lb + 1, 0);
        }
        for ($i = $la - 1; $i >= 0; $i--) {
            for ($j = $lb - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $script = [];
        $i = 0;
        $j = 0;
        $nIdx = $offset; // linha (0-based) no arquivo novo
        while ($i < $la && $j < $lb) {
            if ($a[$i] === $b[$j]) {
                $script[] = ['t' => '=', 'line' => $b[$j], 'n' => $nIdx + 1];
                $i++;
                $j++;
                $nIdx++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $script[] = ['t' => '-', 'line' => $a[$i], 'n' => $nIdx + 1];
                $i++;
            } else {
                $script[] = ['t' => '+', 'line' => $b[$j], 'n' => $nIdx + 1];
                $j++;
                $nIdx++;
            }
        }
        while ($i < $la) {
            $script[] = ['t' => '-', 'line' => $a[$i], 'n' => $nIdx + 1];
            $i++;
        }
        while ($j < $lb) {
            $script[] = ['t' => '+', 'line' => $b[$j], 'n' => $nIdx + 1];
            $j++;
            $nIdx++;
        }
        return $script;
    }

    /** Agrupa o script em hunks (regiões de mudança + `context` linhas iguais ao redor). */
    private function formatHunks(array $script, int $context): string
    {
        // marca quais posições são mudança e quais linhas iguais ficam (dentro do raio de contexto).
        $n = count($script);
        $keep = array_fill(0, $n, false);
        for ($k = 0; $k < $n; $k++) {
            if ($script[$k]['t'] !== '=') {
                for ($d = -$context; $d <= $context; $d++) {
                    $p = $k + $d;
                    if ($p >= 0 && $p < $n) {
                        $keep[$p] = true;
                    }
                }
            }
        }
        $out = [];
        $prevKept = false;
        $lastHeaderN = null;
        for ($k = 0; $k < $n; $k++) {
            if (! $keep[$k]) {
                $prevKept = false;
                continue;
            }
            if (! $prevKept) {
                // início de um hunk
                $out[] = '@@ linha ' . ($script[$k]['n'] ?? '?') . ' @@';
            }
            $pfx = $script[$k]['t'] === '=' ? ' ' : $script[$k]['t'];
            $out[] = $pfx . $script[$k]['line'];
            $prevKept = true;
        }
        return implode("\n", $out);
    }
}
