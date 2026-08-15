<?php

namespace App\SourceCode\Analyzer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Bloco 4 + 4.1 — camada SEMÂNTICA subordinada ao determinístico, com CUSTO como requisito.
 *
 * Arquitetura (4.1):
 *  INITIAL  → resumo compacto de fatos + seleção determinística de funções relevantes →
 *             estimativa de custo → 1 chamada GLOBAL (+ ≤2 aprofundamentos só do CÓDIGO das
 *             funções críticas) → consolidação LOCAL → anti-alucinação.
 *  MODIFIED → semântica anterior + SourceDiff + só as funções alteradas → 0–1 chamada → MERGE local.
 *  structural_change=false → 0 chamadas (skipped_no_structural_change).
 *  blob inalterado / cache → 0 chamadas (reuse).
 *  estimativa > hard limit (US$ 0,30) → 0 chamadas (skipped_cost_limit); determinístico intacto.
 *
 * NUNCA envia o fonte inteiro. Código só das funções críticas (faixa de linhas, sanitizada).
 * Segurança inalterada (homolog-only, prod bloqueado, secrets mascarados, logs sem código/prompt).
 * Cache (Cache facade) NÃO é fonte da verdade — o semantic_json persistido é.
 */
class SourceDocSemanticAnalyzer
{
    public const SCHEMA_VERSION = 1;
    private const UNKNOWN = 'Não identificado automaticamente no código.';

    private array $usage = [];
    /** @var list<array{item:string,reason:string}> */
    private array $rejected = [];
    private array $coverage = [];
    private float $t0 = 0.0;

    public function __construct(private SourceDocAiProvider $ai)
    {
    }

    public function enabled(): bool
    {
        if (! (bool) config('services.source_doc_ai.enabled', false)) {
            return false;
        }
        $env = (string) config('services.source_doc_ai.environment', app()->environment());
        return in_array($env, (array) config('services.source_doc_ai.allowed_environments', ['homolog']), true);
    }

    /**
     * @param array $ctx ['previous_semantic'=>?array, 'blob_sha'=>?string]
     */
    public function analyze(array $deterministic, string $maskedCode, ?array $diff = null, array $ctx = []): array
    {
        $this->resetState();
        $prevSem = is_array($ctx['previous_semantic'] ?? null) ? $ctx['previous_semantic'] : null;

        if (! $this->enabled()) {
            return $this->skeleton('pending') + ['note' => 'IA desabilitada neste ambiente (gate homolog).'];
        }
        if (! $this->ai->isConfigured()) {
            return $this->skeleton('pending') + ['note' => 'IA não configurada.'];
        }

        // (E) sem alteração estrutural → 0 chamadas.
        $ds = (array) ($diff['diff_stats'] ?? []);
        if (($diff !== null) && array_key_exists('structural_change', $ds) && $ds['structural_change'] === false) {
            $out = $prevSem ?: $this->skeleton('completed');
            $out['status'] = 'skipped_no_structural_change';
            $out['resumo_alteracao'] = 'Não foram identificadas alterações estruturais relevantes.';
            $out['strategy'] = 'skipped_no_structural_change';
            $out['usage'] = $this->usageBlock();
            return $out;
        }

        // (F/18) reuso por blob/conteúdo — mesma versão já analisada → 0 chamadas.
        $blob = (string) ($ctx['blob_sha'] ?? sha1($maskedCode));
        $reuseKey = $this->versionCacheKey($blob);
        if ($this->cacheEnabled()) {
            $cached = Cache::get($reuseKey);
            if (is_array($cached)) {
                $cached['strategy'] = 'reuse_blob';
                $this->usage['cache_hits']++;
                $cached['usage'] = $this->usageBlock();
                return $cached;
            }
        }

        try {
            $changeType = $ds['change_type'] ?? ($diff !== null && ($diff['is_creation'] ?? false) ? 'initial' : ($prevSem ? 'modified' : 'initial'));
            if ($changeType === 'modified' && $prevSem) {
                $result = $this->incremental($deterministic, $maskedCode, $diff, $prevSem);
            } else {
                $result = $this->initial($deterministic, $maskedCode, $diff);
            }
        } catch (\Throwable $e) {
            Log::warning('source_doc_ai.analyze_failed', ['error' => $this->sanitizeLog($e->getMessage())]);
            return $this->skeleton('failed') + ['error' => 'Falha na análise semântica — reprocessável.', 'usage' => $this->usageBlock()];
        }

        // Estados "skip" já vêm prontos (esqueleto) — não passam pelo finalize (que reformata e perde note/strategy).
        if (in_array($result['status'] ?? '', ['skipped_cost_limit', 'skipped_no_structural_change'], true)) {
            $result['usage'] = $this->usageBlock();
            return $result;
        }
        $final = $this->finalize($result, $deterministic, $diff);
        if ($this->cacheEnabled() && in_array($final['status'], ['completed', 'partial'], true)) {
            Cache::put($reuseKey, $final, (int) config('services.source_doc_ai.cache_ttl', 2592000));
        }
        return $final;
    }

    // ── INITIAL (A): global compacto + aprofundamento seletivo ──────────────────
    private function initial(array $det, string $maskedCode, ?array $diff): array
    {
        $limit = (int) config('services.source_doc_ai.max_relevant_functions', 12);
        $relevant = $this->selectRelevant($det, $diff, $limit);
        $relNames = array_map(fn ($f) => $f['name'], $relevant);
        $compact = $this->buildCompactFacts($det, $relevant, $diff);
        $inlineCodeMax = (int) config('services.source_doc_ai.inline_code_max_chars', 8000);
        $inlineCode = mb_strlen($maskedCode) <= $inlineCodeMax ? $maskedCode : '';

        // Fonte pequeno (código cabe inline) → 1 chamada com funcoes na global (saída pequena, sem
        // risco de truncar). Fonte grande → global só NARRATIVA + aprofundamento traz as finalidades
        // (evita empacotar narrativa + N funções numa saída só = truncava).
        $small = $inlineCode !== '';
        $globalOut = (int) config('services.source_doc_ai.max_output_tokens_global', 3500);
        $deepenOut = (int) config('services.source_doc_ai.max_output_tokens_per_call', 1800);

        // ── monta prompts, estima ANTES de executar (hard limit), depois executa ──
        $globalUser = $this->globalUserPrompt($compact, $diff, $inlineCode, $small);
        $deepItems = (! $small && ! empty($relevant)) ? $this->buildDeepItems($relevant, $det, $maskedCode) : [];
        $plan = [['system' => $this->systemPrompt(), 'user' => $globalUser, 'out' => $globalOut, 'code' => $small]];
        if (! empty($deepItems)) {
            $plan[] = ['system' => $this->systemPrompt(), 'user' => $this->deepenFinalidadesPrompt($deepItems), 'out' => $deepenOut, 'code' => true];
        }
        $plan = array_slice($plan, 0, (int) config('services.source_doc_ai.max_calls', 3));
        $est = $this->estimatePlan($plan);
        $this->usage['estimated_before_usd'] = round($est, 4);
        if ($est > (float) config('services.source_doc_ai.hard_limit_usd', 0.30)) {
            return $this->costSkipped($est, count($relNames));
        }

        // ── CALL 1 — narrativa global (funcoes só se pequeno) ──
        $g = $this->callJson($plan[0]['system'], $plan[0]['user'], $globalOut);
        $sem = is_array($g['json']) ? $g['json'] : [];
        $funcoes = $small ? $this->normFuncoes($sem['funcoes'] ?? []) : [];
        $cachedN = 0;
        $deepRan = false;
        $deepTrunc = false;

        // narrativa mínima válida? (objetivo OU fluxo OU regras) e não truncada
        $narrativeValid = ! $g['truncated'] && ($this->str($sem['objetivo'] ?? $sem['overview'] ?? '') !== '' || ! empty($sem['fluxo'] ?? $sem['execution_flow'] ?? []) || ! empty($sem['regras_negocio'] ?? []));

        // ── CALL 2 — finalidades das funções relevantes (fonte grande) ──
        if (! empty($deepItems) && count($plan) > 1) {
            $deepRan = true;
            [$deepFuncoes, $deepRules, $deepPoints, $cachedN, $deepTrunc] = $this->runDeepeningFinalidades($deepItems, $det, $deepenOut);
            $funcoes = $this->mergeFuncoes($funcoes, $deepFuncoes);
            if (! empty($deepRules)) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $deepRules);
            }
            if (! empty($deepPoints)) {
                $sem['pontos_atencao'] = array_merge($sem['pontos_atencao'] ?? [], $deepPoints);
            }
        }

        // ── validador de completude (nunca completed vazio) ──
        [$status, $partialReason] = $this->completionStatus($narrativeValid, $g, $deepRan, $deepTrunc);
        $sem['funcoes'] = $funcoes;
        $sem['status'] = $status;
        $sem['strategy'] = 'initial_global_selective';
        if ($partialReason !== null) {
            $sem['partial_reason'] = $partialReason;
        }
        $this->coverage = [
            'relevant_functions_total'    => count($relNames),
            'relevant_functions_analyzed' => count($funcoes),
            'relevant_functions_cached'   => $cachedN,
            'relevant_functions_skipped'  => max(0, count($relNames) - count($funcoes)),
        ];
        return $sem;
    }

    /** Decide status: completed só com narrativa mínima válida e sem truncamento. */
    private function completionStatus(bool $narrativeValid, array $g, bool $deepRan, bool $deepTrunc): array
    {
        if (! $narrativeValid) {
            $reason = $g['raw_truncated'] ? 'output_truncated' : (($g['json'] ?? null) === null ? 'invalid_json' : 'empty_semantic');
            return ['partial', $reason];
        }
        if ($deepRan && $deepTrunc) {
            return ['partial', 'functions_incomplete'];
        }
        return ['completed', null];
    }

    // ── MODIFIED (C): diff-first + merge local ──────────────────────────────────
    private function incremental(array $det, string $maskedCode, ?array $diff, array $prev): array
    {
        $changed = $this->changedFunctionNames($diff);
        if (empty($changed)) {
            // mudança estrutural sem função alterada (ex.: só tabela) → preserva prev + resumo do diff.
            $prev['status'] = 'completed';
            $prev['strategy'] = 'incremental_diff';
            $prev['resumo_alteracao'] = $this->deterministicChangeSummary($diff) ?? ($prev['resumo_alteracao'] ?? self::UNKNOWN);
            $this->coverage = ['relevant_functions_total' => 0, 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => 0];
            return $prev;
        }
        $changedFns = array_values(array_filter($det['functions'] ?? [], fn ($f) => in_array(strtolower($f['name'] ?? ''), array_map('strtolower', $changed), true)));
        $lines = explode("\n", $maskedCode);
        $withCode = array_map(fn ($f) => ['name' => $f['name'], 'facts' => $this->fnFact($f), 'code' => $this->codeSlice($lines, $f)], array_slice($changedFns, 0, (int) config('services.source_doc_ai.max_relevant_functions', 12)));

        $outBudget = (int) config('services.source_doc_ai.max_output_tokens_per_call', 2000);
        $user = $this->incrementalUserPrompt($prev, $diff, $withCode);
        $plan = [['system' => $this->systemPrompt(), 'user' => $user, 'out' => $outBudget, 'code' => true]];
        $est = $this->estimatePlan($plan);
        $this->usage['estimated_before_usd'] = round($est, 4);
        if ($est > (float) config('services.source_doc_ai.hard_limit_usd', 0.30)) {
            // sobre o limite: preserva prev + resumo determinístico (não reprocessa), sem chamar IA.
            $prev['status'] = 'completed';
            $prev['strategy'] = 'incremental_diff';
            $prev['resumo_alteracao'] = $this->deterministicChangeSummary($diff) ?? ($prev['resumo_alteracao'] ?? self::UNKNOWN);
            $prev['usage'] = ($prev['usage'] ?? []) + ['estimated_before_usd' => round($est, 4), 'note_cost' => 'atualização incremental adiada por hard limit'];
            $this->coverage = ['relevant_functions_total' => count($changed), 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => count($changed)];
            return $prev;
        }

        $dc = $this->callJson($plan[0]['system'], $plan[0]['user'], $plan[0]['out']);
        $delta = is_array($dc['json']) ? $dc['json'] : [];
        $merged = $this->mergeIncremental($prev, $delta);
        $merged['status'] = $dc['truncated'] ? 'partial' : 'completed';
        $merged['strategy'] = 'incremental_diff';
        if ($dc['truncated']) {
            $merged['partial_reason'] = 'output_truncated';
        }
        $this->coverage = [
            'relevant_functions_total'    => count($changed),
            'relevant_functions_analyzed' => count($withCode),
            'relevant_functions_cached'   => 0,
            'relevant_functions_skipped'  => max(0, count($changed) - count($withCode)),
        ];
        return $merged;
    }

    // ── seleção determinística de funções relevantes ────────────────────────────
    private function selectRelevant(array $det, ?array $diff, int $limit): array
    {
        $fns = $det['functions'] ?? [];
        $changed = array_map('strtolower', $this->changedFunctionNames($diff));
        $riskFns = [];
        foreach (($det['queries'] ?? []) as $q) {
            if (! empty($q['risk_flags'])) {
                $riskFns[strtolower((string) ($q['function'] ?? ''))] = true;
            }
        }
        $scored = [];
        foreach ($fns as $f) {
            $name = $f['name'] ?? '';
            $type = strtolower((string) ($f['type'] ?? ''));
            $degree = count($f['called_by'] ?? []) + count($f['calls_internal'] ?? []) + count($f['calls_user'] ?? []);
            $effects = (array) ($f['effects'] ?? []);
            $writes = (bool) array_intersect(['database_write', 'database_delete', 'file_write', 'routine_execution'], $effects);
            $ext = in_array('external_call', $effects, true);
            $s = 0;
            if (empty($f['called_by'])) {
                $s += 100; // entrypoint
            }
            if (str_contains($type, 'user function')) {
                $s += 80;
            }
            if (in_array(strtolower($name), $changed, true)) {
                $s += 90; // alterada no diff
            }
            if ($writes) {
                $s += 60;
            }
            if ($ext) {
                $s += 60;
            }
            if (isset($riskFns[strtolower($name)])) {
                $s += 60;
            }
            $s += min(40, $degree * 8); // grau (médio)
            $scored[] = ['f' => $f, 's' => $s];
        }
        usort($scored, fn ($a, $b) => $b['s'] <=> $a['s']);
        return array_map(fn ($x) => $x['f'], array_slice($scored, 0, max(1, $limit)));
    }

    /** Funções críticas cujo CÓDIGO vale enviar (escritoras/risco/entrypoint), dentro do orçamento. */
    /** Itens do aprofundamento: TODAS as funções relevantes (facts) + CÓDIGO só das críticas (≤N, budget). */
    private function buildDeepItems(array $relevant, array $det, string $maskedCode): array
    {
        $riskFns = [];
        foreach (($det['queries'] ?? []) as $q) {
            if (! empty($q['risk_flags'])) {
                $riskFns[strtolower((string) ($q['function'] ?? ''))] = true;
            }
        }
        $lines = explode("\n", $maskedCode);
        $budgetTokens = (int) config('services.source_doc_ai.deepen_code_budget_tokens', 20000);
        $maxCode = (int) config('services.source_doc_ai.max_deepen_functions', 6);
        $cptCode = (float) config('services.source_doc_ai.chars_per_token_code', 1.6);
        $items = [];
        $codeCount = 0;
        $tok = 0;
        foreach ($relevant as $f) {
            $eff = (array) ($f['effects'] ?? []);
            $crit = empty($f['called_by']) || (bool) array_intersect(['database_write', 'database_delete', 'file_write', 'external_call', 'routine_execution'], $eff) || isset($riskFns[strtolower($f['name'] ?? '')]);
            $code = '';
            if ($crit && $codeCount < $maxCode) {
                $slice = $this->codeSlice($lines, $f);
                $t = (int) ceil(mb_strlen($slice) / $cptCode);
                if ($tok + $t <= $budgetTokens) {
                    $code = $slice;
                    $tok += $t;
                    $codeCount++;
                }
            }
            $items[] = ['name' => $f['name'], 'facts' => $this->fnFact($f), 'code' => $code];
        }
        return $items;
    }

    /** Aprofundamento: cache por função (miss → 1 chamada) → finalidades + regras/pontos extras. */
    private function runDeepeningFinalidades(array $items, array $det, int $out): array
    {
        $funcoes = [];
        $toCall = [];
        $cachedN = 0;
        foreach ($items as $it) {
            $key = $this->functionCacheKey($det, $it);
            $hit = $this->cacheEnabled() ? Cache::get($key) : null;
            if (is_array($hit) && ! empty($hit['name'])) {
                $funcoes[] = $hit;
                $cachedN++;
                $this->usage['cache_hits']++;
            } else {
                $toCall[] = $it;
                $this->usage['cache_misses']++;
            }
        }
        $rules = [];
        $points = [];
        $truncated = false;
        if (! empty($toCall)) {
            $d = $this->callJson($this->systemPrompt(), $this->deepenFinalidadesPrompt($toCall), $out);
            $truncated = $d['truncated'];
            $j = is_array($d['json']) ? $d['json'] : [];
            foreach (($j['funcoes'] ?? []) as $f) {
                if (! empty($f['name'])) {
                    $entry = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? '')];
                    $funcoes[] = $entry;
                    if ($this->cacheEnabled()) {
                        $c = $this->findByName($toCall, $f['name']);
                        if ($c) {
                            Cache::put($this->functionCacheKey($det, $c), $entry, (int) config('services.source_doc_ai.cache_ttl', 2592000));
                        }
                    }
                }
            }
            $rules = $j['regras_negocio'] ?? [];
            $points = $j['pontos_atencao'] ?? [];
        }
        return [$funcoes, $rules, $points, $cachedN, $truncated];
    }

    // ── compact facts (sem código, sem data_access, sem listas gigantes) ─────────
    private function buildCompactFacts(array $det, array $relevant, ?array $diff): array
    {
        $tables = $det['tables'] ?? [];
        $written = [];
        $read = [];
        foreach ($tables as $t) {
            $name = $t['table'] ?? $t['alias'] ?? null;
            if (! $name) {
                continue;
            }
            if (array_intersect(['UPDATE', 'INSERT', 'DELETE'], (array) ($t['access'] ?? []))) {
                $written[$name] = array_slice((array) ($t['write_fields'] ?? []), 0, 8);
            }
            if (in_array('READ', (array) ($t['access'] ?? []), true)) {
                $read[$name] = true;
            }
        }
        $risk = [];
        foreach (($det['queries'] ?? []) as $q) {
            foreach ((array) ($q['risk_flags'] ?? []) as $rf) {
                $risk[$rf] = true;
            }
        }
        return [
            'source'      => ['filename' => ($det['file']['filename'] ?? null), 'language' => $det['language'] ?? null, 'source_type' => $det['source_type'] ?? null],
            'entrypoints' => array_values(array_map(fn ($f) => $f['name'], array_filter($det['functions'] ?? [], fn ($f) => empty($f['called_by'])))),
            'functions'   => array_map(fn ($f) => $this->fnFact($f), $relevant),
            'data_summary' => [
                'tables_written' => $written,
                'tables_read'    => array_slice(array_keys($read), 0, 25),
                'external_integrations' => $det['external_integrations'] ?? [],
                'risk_flags'     => array_keys($risk),
            ],
            'flow_summary' => array_slice(array_map(fn ($n) => [$n['type'] ?? '', $n['name'] ?? ($n['table'] ?? ($n['to'] ?? ''))], $det['technical_flow'] ?? []), 0, 24),
            'diff'        => $diff ? $this->diffForAi($diff) : null,
            'utility_functions_count' => max(0, count($det['functions'] ?? []) - count($relevant)),
        ];
    }

    private function fnFact(array $f): array
    {
        return [
            'name' => $f['name'] ?? null, 'type' => $f['type'] ?? null, 'params' => $f['params'] ?? [],
            'calls' => array_slice(array_merge($f['calls_internal'] ?? [], $f['calls_user'] ?? []), 0, 10),
            'called_by' => array_slice($f['called_by'] ?? [], 0, 8),
            'tables' => $f['tables'] ?? [], 'accesses' => $f['accesses'] ?? [], 'effects' => $f['effects'] ?? [],
            'evidence' => $f['evidence'] ?? null,
        ];
    }

    private function codeSlice(array $lines, array $f): string
    {
        $a = max(0, (int) ($f['start_line'] ?? 1) - 1);
        $b = max(1, (int) ($f['end_line'] ?? 1) - (int) ($f['start_line'] ?? 1) + 1);
        $slice = implode("\n", array_slice($lines, $a, $b));
        $cap = (int) config('services.source_doc_ai.max_input_tokens_per_call', 60000) * (int) config('services.source_doc_ai.chars_per_token_code', 1.6);
        return mb_strlen($slice) > $cap ? mb_substr($slice, 0, (int) $cap) . "\n// […função truncada…]" : $slice;
    }

    // ── estimativa de custo (antes de chamar) ───────────────────────────────────
    private function estimatePlan(array $plan): float
    {
        $cptText = (float) config('services.source_doc_ai.chars_per_token_text', 3.2);
        $cptCode = (float) config('services.source_doc_ai.chars_per_token_code', 1.6);
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $total = 0.0;
        foreach ($plan as $p) {
            $cpt = ! empty($p['code']) ? $cptCode : $cptText;
            $in = ceil((mb_strlen($p['system']) + mb_strlen($p['user'])) / $cpt);
            $total += $in / 1e6 * $ci + (int) $p['out'] / 1e6 * $co;
        }
        return $total;
    }

    private function costSkipped(float $est, int $relTotal): array
    {
        $this->coverage = ['relevant_functions_total' => $relTotal, 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => $relTotal];
        $sk = $this->skeleton('skipped_cost_limit');
        $sk['strategy'] = 'skipped_cost_limit';
        $sk['semantic_coverage'] = $this->coverage;
        $sk['note'] = 'Análise semântica não executada: estimativa de custo (US$ ' . round($est, 4) . ') acima do limite de US$ ' . config('services.source_doc_ai.hard_limit_usd', 0.30) . '. A documentação determinística permanece válida.';
        return $sk;
    }

    // ── merge incremental (ADD/UPDATE/REMOVE/KEEP) ──────────────────────────────
    private function mergeIncremental(array $prev, array $delta): array
    {
        // funções: UPDATE as alteradas, KEEP as demais
        $byName = [];
        foreach (($prev['funcoes'] ?? []) as $f) {
            $byName[strtolower($f['name'] ?? '')] = $f;
        }
        foreach (($delta['updated_functions'] ?? []) as $f) {
            if (! empty($f['name'])) {
                $byName[strtolower($f['name'])] = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? '')];
            }
        }
        $prev['funcoes'] = array_values($byName);

        // regras: ADD / UPDATE / REMOVE por id
        $rules = [];
        foreach (($prev['business_rules'] ?? $prev['regras_negocio'] ?? []) as $r) {
            $rules[strtolower($r['id'] ?? '')] = $r;
        }
        foreach (($delta['rules_remove'] ?? []) as $id) {
            unset($rules[strtolower((string) $id)]);
        }
        foreach (array_merge($delta['rules_update'] ?? [], $delta['rules_add'] ?? []) as $r) {
            if (! empty($r['id'] ?? null) || ! empty($r['descricao'] ?? null)) {
                $rules[strtolower($r['id'] ?? ('rn' . (count($rules) + 1)))] = $r;
            }
        }
        $prev['regras_negocio'] = array_values($rules);
        $prev['business_rules'] = array_values($rules);

        foreach (($delta['attention_add'] ?? []) as $a) {
            $prev['pontos_atencao'][] = $a;
        }
        $prev['resumo_alteracao'] = $this->str($delta['change_summary'] ?? $prev['resumo_alteracao'] ?? self::UNKNOWN);
        $prev['change_summary'] = $prev['resumo_alteracao'];
        return $prev;
    }

    private function changedFunctionNames(?array $diff): array
    {
        if (! $diff) {
            return [];
        }
        $s = $diff['structural']['functions'] ?? [];
        $names = array_merge(
            array_map(fn ($x) => is_array($x) ? ($x['function'] ?? $x['name'] ?? '') : (string) $x, $s['changed'] ?? []),
            array_map(fn ($x) => is_array($x) ? ($x['name'] ?? '') : (string) $x, $s['added'] ?? [])
        );
        // compat: campos planos
        $names = array_merge($names, (array) ($diff['functions_changed'] ?? []), (array) ($diff['functions_added'] ?? []));
        return array_values(array_unique(array_filter($names)));
    }

    private function deterministicChangeSummary(?array $diff): ?string
    {
        $ds = (array) ($diff['diff_stats'] ?? []);
        if (($ds['change_type'] ?? null) === 'initial') {
            return 'Documentação inicial desta versão do fonte.';
        }
        if (array_key_exists('structural_change', $ds) && $ds['structural_change'] === false) {
            return 'Não foram identificadas alterações estruturais relevantes.';
        }
        return null;
    }

    // ── finalize (anti-alucinação + shape) ──────────────────────────────────────
    private function finalize(array $sem, array $det, ?array $diff): array
    {
        $fnSet = array_flip(array_map('strtolower', array_column($det['functions'] ?? [], 'name')));
        $tbSet = array_flip(array_map('strtoupper', array_map(fn ($t) => $t['table'] ?? $t['alias'] ?? '', $det['tables'] ?? [])));
        [$fieldQ, $fieldBare] = $this->fieldSets($det);
        $userCalls = array_flip(array_map('strtolower', $det['user_calls'] ?? []));

        // funcoes (dedupe + só existentes)
        $funcoes = [];
        $seen = [];
        foreach ($sem['funcoes'] ?? [] as $f) {
            $name = $f['name'] ?? '';
            if ($name === '' || isset($seen[strtolower($name)])) {
                continue;
            }
            if (! isset($fnSet[strtolower($name)])) {
                $this->rejected[] = ['item' => 'funcao:' . $name, 'reason' => 'inexistente no determinístico'];
                continue;
            }
            $seen[strtolower($name)] = true;
            $funcoes[] = ['name' => $name, 'finalidade' => $this->str($f['finalidade'] ?? '')];
        }

        $tablePurposes = array_values(array_filter($sem['table_purposes'] ?? $sem['tabelas'] ?? [], function ($t) use ($tbSet) {
            $ok = ! empty($t['alias']) && isset($tbSet[strtoupper($t['alias'])]);
            if (! $ok && ! empty($t['alias'])) {
                $this->rejected[] = ['item' => 'tabela:' . $t['alias'], 'reason' => 'inexistente no determinístico'];
            }
            return $ok;
        }));

        $rules = $this->validateRules($sem['regras_negocio'] ?? $sem['business_rules'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, $userCalls);
        $attention = $this->validateAttention($sem['pontos_atencao'] ?? $sem['attention_points'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, $userCalls);

        $changeSummary = $this->str($sem['change_summary'] ?? $sem['resumo_alteracao'] ?? self::UNKNOWN);
        $detSummary = $this->deterministicChangeSummary($diff);
        if ($detSummary !== null) {
            $changeSummary = $detSummary;
        }

        $rulesShown = array_values(array_filter($rules, fn ($r) => in_array($r['confidence'], ['high', 'medium'], true)));
        $rulesLow = array_values(array_filter($rules, fn ($r) => $r['confidence'] === 'low'));
        $attnShown = array_values(array_filter($attention, fn ($a) => in_array($a['confidence'], ['high', 'medium'], true)));

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'status'           => $sem['status'] ?? 'completed',
            'partial_reason'   => $sem['partial_reason'] ?? null,
            'strategy'         => $sem['strategy'] ?? 'initial_global_selective',
            'provider'         => $this->ai->name(),
            'model'            => $this->ai->model(),
            'objetivo'         => $this->str($sem['objetivo'] ?? $sem['overview'] ?? self::UNKNOWN),
            'fluxo'            => $this->arr($sem['fluxo'] ?? $sem['execution_flow'] ?? []),
            'funcoes'          => $funcoes,
            'tabelas'          => $tablePurposes,
            'regras_negocio'   => array_map(fn ($r) => ['id' => $r['id'], 'descricao' => $r['descricao'], 'confidence' => $r['confidence'], 'evidence' => $r['evidence']], $rulesShown),
            'entradas'         => $this->arr($sem['entradas'] ?? $sem['inputs'] ?? []),
            'saidas'           => $this->arr($sem['saidas'] ?? $sem['outputs'] ?? []),
            'pontos_atencao'   => array_map(fn ($a) => $this->attnToString($a), $attnShown),
            'resumo_alteracao' => $changeSummary,
            'business_rules'   => $rules,
            'business_rules_low' => $rulesLow,
            'attention_points' => $attention,
            'change_summary'   => $changeSummary,
            'table_purposes'   => $tablePurposes,
            'semantic_coverage' => $this->coverage ?: ['relevant_functions_total' => count($funcoes), 'relevant_functions_analyzed' => count($funcoes), 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => 0],
            'usage'            => $this->usageBlock(),
            'validation'       => ['rejected_count' => count($this->rejected), 'rejected' => array_slice($this->rejected, 0, 50)],
        ];
    }

    // ── validação (reuso do Bloco 4) ────────────────────────────────────────────
    private function fieldSets(array $det): array
    {
        $q = [];
        $bare = [];
        foreach (($det['tables'] ?? []) as $t) {
            $tab = strtoupper($t['table'] ?? $t['alias'] ?? '');
            foreach (['read_fields', 'write_fields', 'where_fields', 'fields'] as $k) {
                foreach ((array) ($t[$k] ?? []) as $f) {
                    $q[$tab . '.' . strtoupper($f)] = true;
                    $bare[strtoupper($f)] = true;
                }
            }
        }
        foreach (($det['queries'] ?? []) as $qq) {
            $tab = strtoupper((string) ($qq['table'] ?? ''));
            foreach (['read_fields', 'write_fields', 'where_fields', 'fields'] as $k) {
                foreach ((array) ($qq[$k] ?? []) as $f) {
                    $q[$tab . '.' . strtoupper($f)] = true;
                    $bare[strtoupper($f)] = true;
                }
            }
        }
        return [$q, $bare];
    }

    private function validateRules(array $raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare, array $userCalls): array
    {
        $out = [];
        $i = 0;
        foreach ($raw as $r) {
            $desc = $this->str($r['descricao'] ?? $r['description'] ?? '');
            if ($desc === null || $desc === '') {
                continue;
            }
            $ev = $this->validateEvidence($r['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
            if (empty($ev)) {
                $this->rejected[] = ['item' => 'regra:' . mb_substr($desc, 0, 40), 'reason' => 'sem evidência rastreável'];
                continue;
            }
            if ($this->mentionsInventedFn($desc, $fnSet, $userCalls)) {
                $this->rejected[] = ['item' => 'regra:' . mb_substr($desc, 0, 40), 'reason' => 'menção a função inexistente'];
                continue;
            }
            $out[] = ['id' => $r['id'] ?? ('RN' . str_pad((string) (++$i), 2, '0', STR_PAD_LEFT)), 'descricao' => $desc, 'confidence' => $this->conf($r['confidence'] ?? 'low'), 'evidence' => $ev];
        }
        return $out;
    }

    private function validateAttention(array $raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare, array $userCalls): array
    {
        $out = [];
        foreach ($raw as $a) {
            if (is_string($a)) {
                $a = ['interpretation' => $a, 'confidence' => 'medium'];
            }
            $interp = $this->str($a['interpretation'] ?? $a['descricao'] ?? '');
            if ($interp === null || $interp === '') {
                continue;
            }
            if ($this->mentionsInventedFn($interp, $fnSet, $userCalls)) {
                $this->rejected[] = ['item' => 'ponto:' . mb_substr($interp, 0, 40), 'reason' => 'menção a função inexistente'];
                continue;
            }
            $out[] = [
                'interpretation' => $interp,
                'evidence'       => $this->validateEvidence($a['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare),
                'severity'       => $this->str($a['severity'] ?? null),
                'recommendation' => $this->str($a['recommendation'] ?? null),
                'confidence'     => $this->conf($a['confidence'] ?? 'medium'),
            ];
        }
        return $out;
    }

    private function validateEvidence($raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $out = [];
        foreach ((array) $raw as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $type = strtolower((string) ($ev['type'] ?? ''));
            if ($type === 'function' && isset($fnSet[strtolower((string) ($ev['name'] ?? ''))])) {
                $out[] = ['type' => 'function', 'name' => $ev['name'], 'lines' => $ev['lines'] ?? null];
            } elseif ($type === 'table' && isset($tbSet[strtoupper((string) ($ev['table'] ?? $ev['alias'] ?? ''))])) {
                $out[] = ['type' => 'table', 'table' => strtoupper((string) ($ev['table'] ?? $ev['alias']))];
            } elseif ($type === 'field') {
                $tab = strtoupper((string) ($ev['table'] ?? ''));
                $fld = strtoupper((string) ($ev['field'] ?? ''));
                if (isset($fieldQ[$tab . '.' . $fld]) || ($tab === '' && isset($fieldBare[$fld]))) {
                    $out[] = ['type' => 'field', 'table' => $tab ?: null, 'field' => $fld];
                } else {
                    $this->rejected[] = ['item' => 'campo:' . ($tab ? $tab . '.' : '') . $fld, 'reason' => 'campo inexistente no determinístico'];
                }
            }
        }
        return $out;
    }

    private function mentionsInventedFn(string $text, array $fnSet, array $userCalls): bool
    {
        if (! preg_match_all('/\bU_([A-Za-z_][A-Za-z0-9_]*)/', $text, $m)) {
            return false;
        }
        foreach ($m[1] as $name) {
            $bare = strtolower($name);
            if (! isset($fnSet[$bare]) && ! isset($userCalls['u_' . $bare])) {
                return true;
            }
        }
        return false;
    }

    // ── prompts (enxutos) ───────────────────────────────────────────────────────
    private function systemPrompt(): string
    {
        return 'Analista Protheus/AdvPL. Os FATOS fornecidos são a AUTORIDADE — explique-os, não descubra nem invente. '
            . 'Não crie função/tabela/campo/integração fora dos fatos. Sem evidência ⇒ "' . self::UNKNOWN . '". '
            . 'Regras de negócio e pontos de atenção EXIGEM evidence (function/table/field dos fatos) + confidence (high|medium|low). '
            . 'risk_flag é evidência técnica, não vulnerabilidade. Código pode vir com segredos mascarados. '
            . 'Seja CONCISO (objetivo 2–4 frases; finalidade 1–2; regra 1 frase). Devolva SÓ JSON válido (sem markdown).';
    }

    private function globalUserPrompt(array $compact, ?array $diff, string $inlineCode, bool $withFuncoes): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inlineCode !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $inlineCode;
        }
        // Narrativa enxuta. Em fonte grande, NÃO pedir as finalidades das funções aqui (vêm no
        // aprofundamento) — evita saída densa que trunca o JSON.
        $funcoesPart = $withFuncoes ? 'funcoes[{name,finalidade}] (só as funções dos fatos), ' : '';
        $u .= "\n\nProduza JSON {objetivo (2–4 frases), fluxo[], " . $funcoesPart
            . 'regras_negocio[{id,descricao,confidence,evidence[{type,name?,table?,field?}]}], entradas[], saidas[], '
            . 'pontos_atencao[{interpretation,severity?,recommendation?,confidence,evidence[]}], change_summary}.';
        return $u;
    }

    /** Aprofundamento: finalidade de CADA função relevante (facts de todas + código só das críticas). */
    private function deepenFinalidadesPrompt(array $items): string
    {
        $blocks = array_map(function ($c) {
            $b = "### {$c['name']}\nFATOS: " . json_encode($c['facts'], JSON_UNESCAPED_UNICODE);
            if (! empty($c['code'])) {
                $b .= "\nCÓDIGO (mascarado):\n" . $c['code'];
            }
            return $b;
        }, $items);
        return "FUNÇÕES RELEVANTES:\n" . implode("\n\n", $blocks)
            . "\n\nDê a finalidade (1–2 frases) de CADA função listada. Se houver base, adicione regra/ponto com evidence+confidence. "
            . 'Devolva JSON {funcoes[{name,finalidade}], regras_negocio[...], pontos_atencao[...]}.';
    }

    private function incrementalUserPrompt(array $prev, ?array $diff, array $changed): string
    {
        $prevSummary = [
            'objetivo' => $prev['objetivo'] ?? null,
            'regras'   => array_map(fn ($r) => ['id' => $r['id'] ?? null, 'descricao' => $r['descricao'] ?? null], $prev['regras_negocio'] ?? []),
        ];
        $blocks = array_map(fn ($c) => "### {$c['name']}\nFATOS: " . json_encode($c['facts'], JSON_UNESCAPED_UNICODE) . "\nCÓDIGO:\n" . $c['code'], $changed);
        return "SEMÂNTICA ANTERIOR (resumo):\n" . json_encode($prevSummary, JSON_UNESCAPED_UNICODE)
            . "\n\nDIFF:\n" . json_encode($this->diffForAi($diff ?? []), JSON_UNESCAPED_UNICODE)
            . "\n\nFUNÇÕES ALTERADAS (código mascarado):\n" . implode("\n\n", $blocks)
            . "\n\nResponda SOMENTE o que muda: JSON {change_summary, updated_functions[{name,finalidade}], "
            . 'rules_add[{id,descricao,confidence,evidence}], rules_update[{id,descricao,confidence,evidence}], rules_remove[id], attention_add[{interpretation,severity?,recommendation?,confidence,evidence}]}.';
    }

    private function diffForAi(array $diff): array
    {
        return [
            'change_type'       => $diff['change_type'] ?? ($diff['diff_stats']['change_type'] ?? null),
            'structural_change' => $diff['structural_change'] ?? ($diff['diff_stats']['structural_change'] ?? null),
            'diff_stats'        => $diff['diff_stats'] ?? [],
        ];
    }

    // ── caches ──────────────────────────────────────────────────────────────────
    private function cacheEnabled(): bool
    {
        return (bool) config('services.source_doc_ai.cache_enabled', true);
    }

    private function versionCacheKey(string $blob): string
    {
        return 'srcdoc:sem:' . sha1($blob . '|' . self::SCHEMA_VERSION . '|' . config('services.source_doc_ai.prompt_version', 2) . '|' . $this->ai->model());
    }

    private function functionCacheKey(array $det, array $c): string
    {
        $norm = preg_replace('/\s+/', ' ', trim((string) ($c['code'] ?? '')));
        $payload = ($det['language'] ?? '') . '|' . $norm . '|' . json_encode($c['facts'] ?? []) . '|' . self::SCHEMA_VERSION . '|' . config('services.source_doc_ai.prompt_version', 2) . '|' . $this->ai->model();
        return 'srcdoc:semfn:' . sha1($payload);
    }

    // ── provider + usage ────────────────────────────────────────────────────────
    /** @return array{text:string,truncated:bool} truncated = provider parou por max_tokens. */
    private function call(string $system, string $user, ?int $out = null): array
    {
        $r = $this->ai->complete($system, $user, ['max_tokens' => $out ?: (int) config('services.source_doc_ai.max_output_tokens_per_call', 1800)]);
        $u = (array) ($r['usage'] ?? []);
        $this->usage['input_tokens'] += (int) ($u['input_tokens'] ?? 0);
        $this->usage['output_tokens'] += (int) ($u['output_tokens'] ?? 0);
        $this->usage['calls']++;
        return ['text' => (string) ($r['text'] ?? ''), 'truncated' => ($r['stop'] ?? null) === 'max_tokens'];
    }

    /** Chama + parseia. truncated = max_tokens OU JSON inválido (parse null). */
    private function callJson(string $system, string $user, ?int $out = null): array
    {
        $c = $this->call($system, $user, $out);
        $json = $this->parseJson($c['text']);
        return ['json' => $json, 'truncated' => $c['truncated'] || $json === null, 'raw_truncated' => $c['truncated']];
    }

    private function usageBlock(): array
    {
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $actual = $this->usage['input_tokens'] / 1e6 * $ci + $this->usage['output_tokens'] / 1e6 * $co;
        return $this->usage + [
            'duration_ms'    => (int) ((microtime(true) - $this->t0) * 1000),
            'actual_cost_usd' => round($actual, 4),
            'hard_limit_usd' => (float) config('services.source_doc_ai.hard_limit_usd', 0.30),
        ];
    }

    private function resetState(): void
    {
        $this->t0 = microtime(true);
        $this->usage = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'cache_hits' => 0, 'cache_misses' => 0, 'estimated_before_usd' => 0.0];
        $this->rejected = [];
        $this->coverage = [];
    }

    private function skeleton(string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION, 'status' => $status, 'strategy' => null, 'provider' => $this->ai->name(), 'model' => $this->ai->model(),
            'objetivo' => null, 'fluxo' => [], 'funcoes' => [], 'tabelas' => [], 'regras_negocio' => [],
            'entradas' => [], 'saidas' => [], 'pontos_atencao' => [], 'resumo_alteracao' => null,
            'business_rules' => [], 'business_rules_low' => [], 'attention_points' => [], 'change_summary' => null, 'table_purposes' => [],
            'semantic_coverage' => ['relevant_functions_total' => 0, 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => 0],
            'usage' => $this->usageBlock(), 'validation' => ['rejected_count' => 0, 'rejected' => []],
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────
    private function normFuncoes(array $raw): array
    {
        $out = [];
        foreach ($raw as $f) {
            if (! empty($f['name'])) {
                $out[] = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? '')];
            }
        }
        return $out;
    }

    private function mergeFuncoes(array $a, array $b): array
    {
        $by = [];
        foreach (array_merge($a, $b) as $f) {
            $k = strtolower($f['name'] ?? '');
            if ($k === '') {
                continue;
            }
            if (! isset($by[$k]) || (empty($by[$k]['finalidade']) && ! empty($f['finalidade']))) {
                $by[$k] = $f;
            }
        }
        return array_values($by);
    }

    private function findByName(array $list, string $name): ?array
    {
        foreach ($list as $c) {
            if (strcasecmp($c['name'] ?? '', $name) === 0) {
                return $c;
            }
        }
        return null;
    }

    private function conf($v): string
    {
        $v = strtolower((string) $v);
        return in_array($v, ['high', 'medium', 'low'], true) ? $v : 'low';
    }

    private function attnToString(array $a): string
    {
        $s = $a['interpretation'];
        if (! empty($a['severity'])) {
            $s .= ' (severidade: ' . $a['severity'] . ')';
        }
        if (! empty($a['recommendation'])) {
            $s .= ' — recomendação: ' . $a['recommendation'];
        }
        return $s;
    }

    private function parseJson(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        $j = json_decode(substr($text, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }

    private function sanitizeLog(string $msg): string
    {
        $msg = (string) preg_replace('/\b(gh[posru]|github_pat)_[A-Za-z0-9_]+/', '[REDACTED]', $msg);
        $msg = (string) preg_replace('/sk-[A-Za-z0-9\-_]+/', '[REDACTED]', $msg);
        return mb_substr($msg, 0, 300);
    }

    private function str($v): ?string
    {
        return is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : null);
    }

    private function arr($v): array
    {
        return is_array($v) ? array_values(array_filter($v, fn ($x) => is_string($x) ? $x !== '' : $x !== null)) : [];
    }
}
