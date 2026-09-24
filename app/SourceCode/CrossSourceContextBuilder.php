<?php

namespace App\SourceCode;

use App\Models\SourceDoc;

/**
 * Fase 3 — MATERIALIZA o contexto cross-source BOUNDED que será alimentado ao prompt semântico, e
 * calcula o context_fingerprint (P0 do cache). NÃO chama IA. NÃO deixa o modelo navegar: recebe apenas
 * os context_sources que o resolver (Fase 1) selecionou deterministicamente (resolved-only, relevantes,
 * bounded: depth 1, máx N, budget de tokens, repo-first). ambiguous/unresolved NÃO entram.
 *
 * FACTS-FIRST: para cada dependência resolved envia primeiro os FACTS mínimos do alvo (assinatura,
 * tabelas, campos, operações) extraídos do determinístico do alvo — nunca conhecimento geral. O snippet
 * é complementar e NÃO é requisito para evidence C; aqui fica facts-first (snippet só quando os facts
 * forem insuficientes E couber no budget — registrado em snippet_skipped_reason).
 *
 * fingerprint = hash determinístico e ORDENADO de (symbol, target_blob, snippet_included) por fonte.
 * Self-contained (nenhuma fonte) → '' (neutro): preserva 100% o comportamento/cache atual.
 */
class CrossSourceContextBuilder
{
    public function __construct(private SourceContextResolver $resolver) {}

    public function enabled(): bool
    {
        return (bool) config('services.source_doc_ai.cross_source.inject_enabled', false);
    }

    /**
     * @return array{enabled:bool,fingerprint:string,sources:list<array>,telemetry:array,resolver:array}
     */
    public function build(SourceDoc $doc): array
    {
        $neutral = ['enabled' => false, 'fingerprint' => '', 'sources' => [], 'telemetry' => [], 'resolver' => []];
        if (! $this->enabled()) {
            return $neutral; // OFF → fingerprint neutro, sem contexto: pipeline idêntico ao de hoje.
        }

        $res = $this->resolver->resolve($doc);
        $sources = [];
        foreach ((array) ($res['context_sources'] ?? []) as $cs) {
            $facts = $this->materializeFacts((int) $cs['target_doc_id'], (string) $cs['symbol']);
            if ($facts === null) {
                continue; // alvo sem versão/fatos → não fabrica; a edge/telemetria já registra a resolução
            }
            // facts-first: neste gate o snippet não é materializado (facts bastam p/ evidence C).
            $snippetIncluded = false;
            $snippetSkipped = ($cs['snippet_included'] ?? false)
                ? 'facts_first_sufficient'          // caberia, mas facts já sustentam a evidência
                : ($cs['snippet_skipped_reason'] ?? 'facts_first_sufficient');
            $sources[] = [
                'source_doc_id'          => (int) $cs['target_doc_id'],
                'path'                   => $facts['path'],
                'blob_sha'               => (string) $cs['target_blob'],
                'symbol'                 => (string) $cs['symbol'],
                'relation'               => 'calls_user',
                'facts'                  => $facts['facts'],
                'facts_strategy'         => $facts['strategy'], // function_scoped | related_functions | file_bounded_fallback
                'facts_included'         => true,
                'snippet_included'       => $snippetIncluded,
                'snippet_skipped_reason' => $snippetSkipped,
                'relevance_score'        => $cs['relevance_score'] ?? null,
                'estimated_context_tokens' => (int) ($cs['estimated_context_tokens'] ?? 0),
            ];
        }

        return [
            'enabled'     => true,
            'fingerprint' => $this->fingerprint($sources),
            'sources'     => $sources,
            'telemetry'   => (array) ($res['telemetry'] ?? []) + [
                'ambiguous_excluded'  => count((array) ($res['ambiguous'] ?? [])),
                'unresolved_excluded' => count((array) ($res['unresolved'] ?? [])),
                'materialized'        => count($sources),
            ],
            'resolver'    => [
                'ambiguous'  => $res['ambiguous'] ?? [],
                'unresolved' => $res['unresolved'] ?? [],
                'discarded'  => $res['discarded'] ?? [],
            ],
        ];
    }

    /**
     * Facts mínimos do ALVO a partir do determinístico dele. Fallback BOUNDED em 3 níveis quando o
     * determinístico não atribui tabelas à função-alvo (comum: tabelas ficam em sub-funções):
     *   P1 function_scoped     — tabelas atribuídas DIRETAMENTE à função resolvida;
     *   P2 related_functions   — tabelas das funções chamadas pela resolvida (calls_internal/calls_user do alvo);
     *   fallback file_bounded  — conjunto BOUNDED das tabelas do arquivo, priorizando SINAL (escrita > leitura),
     *                            com teto (nunca despeja tudo).
     * Prioriza sinal funcional (escritas primeiro). NUNCA inventa: só usa o que está no determinístico do alvo.
     */
    private function materializeFacts(int $targetDocId, string $symbolNorm): ?array
    {
        $doc = SourceDoc::with('currentVersion')->find($targetDocId);
        $ver = $doc?->currentVersion;
        if (! $doc || ! $ver || ! is_array($ver->deterministic_json)) {
            return null;
        }
        $det = $ver->deterministic_json;
        $fn = null;
        foreach (($det['functions'] ?? []) as $f) {
            if ($this->norm((string) ($f['name'] ?? '')) === $symbolNorm) {
                $fn = $f;
                break;
            }
        }
        $fnName = (string) ($fn['name'] ?? $symbolNorm);
        $cap = (int) config('services.source_doc_ai.cross_source.max_context_tables', 6);
        $allTables = (array) ($det['tables'] ?? []);

        // P1 — função-alvo.
        $names = [$fnName];
        $tables = $this->tablesForFns($allTables, $names, $cap);
        $strategy = 'function_scoped';

        // P2 — funções relacionadas (chamadas pela função-alvo dentro do próprio alvo).
        if (! $tables && $fn) {
            $related = array_merge((array) ($fn['calls_internal'] ?? []), (array) ($fn['calls_user'] ?? []));
            $related = array_values(array_unique(array_map('strval', $related)));
            if ($related) {
                $tables = $this->tablesForFns($allTables, $related, $cap);
                if ($tables) {
                    $strategy = 'related_functions';
                }
            }
        }

        // Fallback — arquivo, BOUNDED e priorizando escrita (sinal de negócio). Não despeja tudo.
        if (! $tables) {
            $tables = $this->tablesForFns($allTables, null, $cap); // null = todas, priorizadas + capadas
            $strategy = $tables ? 'file_bounded_fallback' : 'none';
        }

        return [
            'path'     => $doc->path,
            'strategy' => $strategy,
            'facts'    => array_filter([
                'function'      => $fnName,
                'is_user_function' => true,
                'line_start'    => $fn['start_line'] ?? ($fn['line_start'] ?? null),
                'line_end'      => $fn['end_line'] ?? ($fn['line_end'] ?? null),
                'params'        => array_slice((array) ($fn['params'] ?? $fn['parameters'] ?? []), 0, 8),
                'calls_user'    => array_slice((array) ($fn['calls_user'] ?? []), 0, 8),
                'tables'        => $tables,
                'facts_strategy' => $strategy,
            ], fn ($v) => $v !== null && $v !== []),
        ];
    }

    /**
     * Seleciona tabelas do alvo priorizando SINAL (escrita > leitura material > demais), com teto BOUNDED.
     * $fnNames = null ⇒ todas (fallback de arquivo); senão só as atribuídas a essas funções.
     */
    private function tablesForFns(array $allTables, ?array $fnNames, int $cap): array
    {
        $fnSet = $fnNames === null ? null : array_flip(array_map('strval', $fnNames));
        $picked = [];
        foreach ($allTables as $t) {
            if ($fnSet !== null) {
                $tfns = (array) ($t['functions'] ?? []);
                if (! array_intersect_key($fnSet, array_flip(array_map('strval', $tfns)))) {
                    continue;
                }
            }
            $writes = (array) ($t['write_fields'] ?? []);
            $reads  = (array) ($t['read_fields'] ?? []);
            $picked[] = [
                '_signal' => ! empty($writes) ? 2 : (! empty($reads) ? 1 : 0), // escrita > leitura > só referência
                'row'     => array_filter([
                    'table'        => $t['table'] ?? $t['alias'] ?? null,
                    'access'       => $t['access'] ?? null,
                    'read_fields'  => array_slice($reads, 0, 8),
                    'write_fields' => array_slice($writes, 0, 8),
                ], fn ($v) => $v !== null && $v !== []),
            ];
        }
        usort($picked, fn ($a, $b) => $b['_signal'] <=> $a['_signal']); // escrita primeiro
        return array_map(fn ($p) => $p['row'], array_slice($picked, 0, max(1, $cap)));
    }

    /**
     * Hash determinístico e ORDENADO do contexto EFETIVAMENTE usado. Captura "qual conteúdo externo, em
     * qual resolução (facts vs snippet)". Ordena por symbol|blob para ser estável e reproduzível.
     */
    public function fingerprint(array $sources): string
    {
        if (! $sources) {
            return ''; // neutro (self-contained) — casa com o cache existente.
        }
        $rows = array_map(fn ($s) => [
            $this->norm((string) $s['symbol']),
            (string) $s['blob_sha'],
            (bool) $s['snippet_included'],
        ], $sources);
        usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return str_starts_with($s, 'u_') ? substr($s, 2) : $s;
    }
}
