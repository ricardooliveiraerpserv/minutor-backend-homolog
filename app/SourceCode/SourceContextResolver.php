<?php

namespace App\SourceCode;

use App\Models\SourceDoc;
use App\Models\SourceSemanticContextEdge;
use Illuminate\Support\Facades\DB;

/**
 * Cross-source Fase 1 — resolução DETERMINÍSTICA (read-only, SEM IA). Dado um fonte, descobre
 * quais User Functions externas ele chama e ONDE estão definidas, com:
 *   repo-first · dedup por blob_sha · estados resolved/ambiguous/unresolved · relevância auditável
 *   · bounded (depth 1, máx N context_sources). NÃO envia nada à IA; só estima o contexto futuro.
 *
 * A política de relevância NÃO é cristalizada: blocklist e score vêm de config; todo descarte
 * registra symbol/target/reason/relevance_score (nada é silenciosamente removido).
 */
class SourceContextResolver
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = (array) config('services.source_doc_ai.context_resolver', []);
    }

    /** @return array{context_sources:list<array>,resolved:list<array>,ambiguous:list<array>,unresolved:list<string>,discarded:list<array>,telemetry:array} */
    public function resolve(SourceDoc $doc, bool $persist = false): array
    {
        $ver = $doc->currentVersion;
        $det = is_array($ver?->deterministic_json) ? $ver->deterministic_json : [];

        $selfDefs = $this->selfDefinedSymbols($doc, $det);
        $totvs = array_map([$this, 'norm'], (array) ($det['dependencies']['totvs_framework_functions'] ?? []));
        $totvsSet = array_flip($totvs);

        // símbolos de SAÍDA: user_calls (topo) ∪ calls_user por função. Fora auto-ref e framework.
        $out = array_map([$this, 'norm'], (array) ($det['user_calls'] ?? []));
        foreach (($det['functions'] ?? []) as $f) {
            foreach ((array) ($f['calls_user'] ?? []) as $c) {
                $out[] = $this->norm((string) $c);
            }
        }
        $out = array_values(array_unique(array_filter($out, fn ($s) => $s !== '' && ! isset($selfDefs[$s]) && ! isset($totvsSet[$s]))));

        $resolved = [];
        $ambiguous = [];
        $unresolved = [];
        $discarded = [];
        $context = [];
        $edges = [];

        foreach ($out as $sym) {
            $rows = DB::table('source_symbol_definition')
                ->where('symbol_norm', $sym)
                ->where('source_doc_id', '!=', $doc->id)
                ->get(['source_doc_id', 'version_id', 'blob_sha', 'owner', 'repository', 'function_name', 'start_line', 'end_line', 'is_user_function', 'writes', 'touches_tables'])
                ->all();

            $sameRepo = array_values(array_filter($rows, fn ($r) => $r->repository === $doc->repository));
            $scope = $sameRepo ?: $rows;              // REPO-FIRST
            $scopeName = $sameRepo ? 'repo' : 'global';

            $candidates = count($scope);
            $byBlob = $this->dedupByBlob($scope);      // DEDUP por blob_sha
            $afterDedup = count($byBlob);

            if ($afterDedup === 0) {
                $unresolved[] = $sym;
                $edges[] = $this->edge($doc, null, $sym, 'unresolved', null, null, null, 'unresolved', $candidates, $afterDedup, null, false);
                continue;
            }
            if ($afterDedup > 1) {
                $ambiguous[] = ['symbol' => $sym, 'scope' => $scopeName, 'candidates' => array_map(fn ($r) => $r->source_doc_id, $byBlob)];
                $edges[] = $this->edge($doc, null, $sym, 'ambiguous', null, null, null, 'ambiguous_'.$scopeName, $candidates, $afterDedup, null, false);
                continue;
            }

            // RESOLVED (1 blob canônico)
            $tgt = $byBlob[0];
            [$score, $reason, $relevant] = $this->relevance($sym, $tgt);
            $estTok = $this->factsTokens() + $this->snippetTokens($tgt->start_line, $tgt->end_line); // potencial (facts+snippet)
            $rec = [
                'symbol' => $sym, 'scope' => $scopeName, 'target_doc_id' => $tgt->source_doc_id,
                'target_repo' => $tgt->owner.'/'.$tgt->repository, 'target_blob' => $tgt->blob_sha,
                'target_function' => $tgt->function_name, 'start_line' => $tgt->start_line, 'end_line' => $tgt->end_line,
                'writes' => (bool) $tgt->writes, 'touches_tables' => (int) $tgt->touches_tables,
                'relevance_score' => $score, 'relevant' => $relevant, 'reason' => $reason, 'est_context_tokens' => $estTok,
            ];
            $resolved[] = $rec;
            if (! $relevant) {
                $discarded[] = ['symbol' => $sym, 'target_doc' => $tgt->source_doc_id, 'reason_discarded' => $reason, 'relevance_score' => $score];
            }
            $edges[] = $this->edge($doc, (int) $tgt->source_doc_id, $sym, 'resolved', 'C', $tgt->blob_sha, $score, $relevant ? 'included' : $reason, $candidates, $afterDedup, $estTok, $relevant);
        }

        // BOUNDED + FACTS-FIRST: resolved+relevant SEMPRE entram (com facts mínimos); o snippet completo
        // é OPCIONAL, incluído só se couber no budget. Resolved NUNCA é descartado por o snippet ser
        // grande — some a perda artificial de ~29% que o gate mediu. Depth=1 (sem recursão).
        $eligible = array_values(array_filter($resolved, fn ($r) => $r['relevant']));
        usort($eligible, fn ($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
        $maxN = (int) ($this->cfg['max_context_sources'] ?? 3);
        $maxTok = (int) ($this->cfg['max_context_tokens'] ?? 2500);
        $factsTok = max(1, (int) ($this->cfg['facts_tokens'] ?? 120));
        $tok = 0;
        $snippetSkipped = 0;
        foreach ($eligible as $r) {
            if (count($context) >= $maxN) {
                $discarded[] = ['symbol' => $r['symbol'], 'target_doc' => $r['target_doc_id'], 'reason_discarded' => 'over_max_sources', 'relevance_score' => $r['relevance_score']];
                continue;
            }
            if ($tok + $factsTok > $maxTok) { // nem os facts mínimos cabem (raro) — aí sim fica de fora
                $discarded[] = ['symbol' => $r['symbol'], 'target_doc' => $r['target_doc_id'], 'reason_discarded' => 'over_max_tokens_facts', 'relevance_score' => $r['relevance_score']];
                continue;
            }
            $tok += $factsTok;
            $snippet = $this->snippetTokens($r['start_line'], $r['end_line']);
            $withSnippet = ($tok + $snippet <= $maxTok);
            if ($withSnippet) {
                $tok += $snippet;
            } else {
                $snippetSkipped++;
            }
            $context[] = [
                'symbol' => $r['symbol'], 'target_doc_id' => $r['target_doc_id'], 'target_repo' => $r['target_repo'],
                'target_function' => $r['target_function'], 'target_blob' => $r['target_blob'],
                'relevance_score' => $r['relevance_score'],
                'facts_included' => true,
                'snippet_included' => $withSnippet,
                'snippet_skipped_reason' => $withSnippet ? null : 'over_budget',
                'estimated_context_tokens' => $factsTok + ($withSnippet ? $snippet : 0),
            ];
        }

        if ($persist) {
            SourceSemanticContextEdge::where('dependent_source_doc_id', $doc->id)->delete();
            foreach ($edges as $e) {
                SourceSemanticContextEdge::create($e);
            }
        }

        return [
            'context_sources' => $context,
            'resolved' => $resolved,
            'ambiguous' => $ambiguous,
            'unresolved' => $unresolved,
            'discarded' => $discarded,
            'telemetry' => [
                'outbound_symbols' => count($out),
                'resolved' => count($resolved),
                'ambiguous' => count($ambiguous),
                'unresolved' => count($unresolved),
                'included_in_context' => count($context),
                'est_context_tokens' => $tok,
            ],
        ];
    }

    /** Símbolos definidos pelo PRÓPRIO doc (auto-referência não é cross-source). */
    private function selfDefinedSymbols(SourceDoc $doc, array $det): array
    {
        $set = [];
        foreach (($det['functions'] ?? []) as $f) {
            $set[$this->norm((string) ($f['name'] ?? ''))] = true;
        }
        foreach ((array) ($det['dependencies']['internal_functions'] ?? []) as $n) {
            $set[$this->norm((string) $n)] = true;
        }
        unset($set['']);
        return $set;
    }

    /** Dedup por blob_sha (colapsa arquivos duplicados no acervo → 1 candidato canônico por conteúdo). */
    private function dedupByBlob(array $rows): array
    {
        $byBlob = [];
        foreach ($rows as $r) {
            $key = $r->blob_sha ?: ('nodoc:'.$r->source_doc_id); // sem blob → não colapsa (conservador)
            if (! isset($byBlob[$key])) {
                $byBlob[$key] = $r;
            }
        }
        return array_values($byBlob);
    }

    /** Relevância auditável: blocklist de utilitários + score por sinais de negócio (escrita/tabelas). */
    private function relevance(string $sym, object $tgt): array
    {
        foreach ((array) ($this->cfg['utility_patterns'] ?? []) as $pat) {
            if ($pat !== '' && @preg_match('/'.$pat.'/i', $sym) === 1) {
                return [0.10, 'utility_blocklist', false];
            }
        }
        $score = 0.30 + ($tgt->writes ? 0.40 : 0.0) + min(0.30, 0.05 * (int) $tgt->touches_tables);
        $score = round($score, 3);
        $min = (float) ($this->cfg['relevance_min'] ?? 0.30);
        return $score >= $min ? [$score, 'relevant', true] : [$score, 'low_relevance', false];
    }

    /** FACTS-FIRST: custo mínimo (facts do alvo) que SEMPRE entra — pequeno e constante. */
    private function factsTokens(): int
    {
        return max(1, (int) ($this->cfg['facts_tokens'] ?? 120));
    }

    /** Custo do SNIPPET completo (opcional) — estimado por linhas; NÃO busca código. */
    private function snippetTokens(?int $sl, ?int $el): int
    {
        if (! $sl || ! $el || $el < $sl) {
            return 400; // sem faixa de linhas conhecida: estimativa conservadora
        }
        $lines = max(1, $el - $sl + 1);
        $cpl = (int) ($this->cfg['chars_per_line'] ?? 45);
        $cpt = (float) config('services.source_doc_ai.chars_per_token_code', 1.6);
        return (int) ceil($lines * $cpl / max($cpt, 1));
    }

    private function edge(SourceDoc $doc, ?int $target, string $sym, string $state, ?string $level, ?string $blob, ?float $score, ?string $reason, int $cand, int $dedup, ?int $estTok, bool $included): array
    {
        return [
            'dependent_source_doc_id' => $doc->id,
            'target_source_doc_id' => $target,
            'symbol' => $sym,
            'relation' => 'calls_user',
            'state' => $state,
            'evidence_level' => $level,
            'target_blob_sha' => $blob,
            'relevance_score' => $score,
            'included_in_context' => $included,
            'reason' => $reason,
            'candidates_count' => $cand,
            'candidates_after_dedup' => $dedup,
            'est_context_tokens' => $estTok,
        ];
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        return str_starts_with($s, 'u_') ? substr($s, 2) : $s;
    }
}
