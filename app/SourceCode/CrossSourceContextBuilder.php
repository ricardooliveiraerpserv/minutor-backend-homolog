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
     * Facts mínimos do ALVO a partir do determinístico dele (assinatura + tabelas/campos + operações da
     * função referenciada). NUNCA inventa: se o alvo não tiver a função nos fatos, retorna só metadados.
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
        // tabelas efetivamente tocadas pela função (ou, na ausência, as do arquivo) — bounded.
        $tables = [];
        foreach (($det['tables'] ?? []) as $t) {
            $touchedByFn = $fn && in_array((string) ($fn['name'] ?? ''), (array) ($t['functions'] ?? []), true);
            if ($fn && ! $touchedByFn) {
                continue;
            }
            $tables[] = array_filter([
                'table'        => $t['table'] ?? $t['alias'] ?? null,
                'access'       => $t['access'] ?? null,
                'read_fields'  => array_slice((array) ($t['read_fields'] ?? []), 0, 8),
                'write_fields' => array_slice((array) ($t['write_fields'] ?? []), 0, 8),
            ], fn ($v) => $v !== null && $v !== []);
            if (count($tables) >= 6) {
                break;
            }
        }

        return [
            'path'  => $doc->path,
            'facts' => array_filter([
                'function'      => $fn['name'] ?? $symbolNorm,
                'is_user_function' => true,
                'line_start'    => $fn['start_line'] ?? ($fn['line_start'] ?? null),
                'line_end'      => $fn['end_line'] ?? ($fn['line_end'] ?? null),
                'params'        => array_slice((array) ($fn['params'] ?? $fn['parameters'] ?? []), 0, 8),
                'calls_user'    => array_slice((array) ($fn['calls_user'] ?? []), 0, 8),
                'tables'        => $tables,
            ], fn ($v) => $v !== null && $v !== []),
        ];
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
