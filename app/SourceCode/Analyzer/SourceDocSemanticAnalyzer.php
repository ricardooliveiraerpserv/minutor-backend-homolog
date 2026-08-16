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
    public const SCHEMA_VERSION = 2;
    private const UNKNOWN = 'Não identificado automaticamente no código.';
    // Bloco 4.2 — quando NÃO houver evidência suficiente para um campo interpretativo, NÃO inventar.
    private const UNDETERMINED = 'Não foi possível determinar com segurança.';

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

    // ── INITIAL (Bloco 4.2): 3 blocos INDEPENDENTES, cada um com status próprio ──
    // Bloco 1 Entendimento Funcional (prioridade) · Bloco 2 Regras/Deps/Risco/Pontos ·
    // Aprofundamento Funções. Truncar/JSON inválido em um bloco NÃO descarta os válidos anteriores.
    private function initial(array $det, string $maskedCode, ?array $diff): array
    {
        $limit = (int) config('services.source_doc_ai.max_relevant_functions', 12);
        $relevant = $this->selectRelevant($det, $diff, $limit);
        $relNames = array_map(fn ($f) => $f['name'], $relevant);
        $compact = $this->buildCompactFacts($det, $relevant, $diff);
        $inlineCodeMax = (int) config('services.source_doc_ai.inline_code_max_chars', 8000);
        $inlineCode = mb_strlen($maskedCode) <= $inlineCodeMax ? $maskedCode : '';
        // Fonte grande: manda no INPUT o código dos ENTRYPOINTS (aterra objetivo/o_que_faz sem custo
        // de saída). Input não é o gargalo — o truncamento era de SAÍDA.
        $entCode = $inlineCode !== '' ? $inlineCode : $this->entrypointCode($det, $maskedCode);

        // Orçamentos POR BLOCO (não um teto global inflado). Ajustáveis; hard limit total = US$ 0,30.
        $entOut   = (int) config('services.source_doc_ai.max_output_tokens_entendimento', 2400);
        $rulesOut = (int) config('services.source_doc_ai.max_output_tokens_rules', 2600);
        $deepenOut = (int) config('services.source_doc_ai.max_output_tokens_per_call', 1800);

        $entUser   = $this->entendimentoUserPrompt($compact, $diff, $entCode);
        $rulesUser = $this->rulesUserPrompt($compact, $diff, $inlineCode);
        $deepItems = ! empty($relevant) ? $this->buildDeepItems($relevant, $det, $maskedCode) : [];

        // ── estimativa da ESTRATÉGIA COMPLETA antes de executar (hard limit; nada silencioso) ──
        $plan = [
            ['system' => $this->systemPrompt(), 'user' => $entUser,   'out' => $entOut,   'code' => ($entCode !== '')],
            ['system' => $this->systemPrompt(), 'user' => $rulesUser, 'out' => $rulesOut, 'code' => ($inlineCode !== '')],
        ];
        if (! empty($deepItems)) {
            $plan[] = ['system' => $this->systemPrompt(), 'user' => $this->deepenFinalidadesPrompt($deepItems), 'out' => $deepenOut, 'code' => true];
        }
        $plan = array_slice($plan, 0, (int) config('services.source_doc_ai.max_calls', 3));
        $est = $this->estimatePlan($plan);
        $this->usage['estimated_before_usd'] = round($est, 4);
        if ($est > (float) config('services.source_doc_ai.hard_limit_usd', 0.30)) {
            return $this->costSkipped($est, count($relNames));
        }

        $sem = [];
        $blocks = [];

        // ── BLOCO 1 — Entendimento Funcional (PRIORIDADE; preservado mesmo se o resto falhar) ──
        $g1 = $this->callJson($this->systemPrompt(), $entUser, $entOut);
        $j1 = is_array($g1['json']) ? $g1['json'] : [];
        if (! empty($j1['entendimento_funcional'])) {
            $sem['entendimento_funcional'] = $j1['entendimento_funcional'];
            $sem['objetivo'] = $j1['entendimento_funcional']['objetivo'] ?? ($j1['objetivo'] ?? null);
        }
        if (! empty($j1['fluxo'])) {
            $sem['fluxo'] = $j1['fluxo'];
        }
        $entOk = ! $g1['truncated'] && ! empty($j1['entendimento_funcional']);
        $blocks['entendimento'] = $entOk ? 'ok' : ($g1['raw_truncated'] ? 'truncated' : 'invalid_json');

        // ── BLOCO 2 — Regras / Dependências / Risco / Pontos (independente; respeita max_calls) ──
        $runRules = count($plan) >= 2;
        $rulesOk = false;
        if ($runRules) {
            $g2 = $this->callJson($this->systemPrompt(), $rulesUser, $rulesOut);
            $j2 = is_array($g2['json']) ? $g2['json'] : [];
            foreach (['regras_negocio', 'dependencias_criticas', 'pontos_atencao'] as $k) {
                if (! empty($j2[$k])) {
                    $sem[$k] = $j2[$k];
                }
            }
            if (! empty($j2['risco_alteracao'])) {
                $sem['risco_alteracao'] = $j2['risco_alteracao'];
            }
            if (! empty($j2['change_summary'])) {
                $sem['change_summary'] = $j2['change_summary'];
            }
            $rulesOk = ! $g2['truncated'] && $j2 !== [];
            $blocks['regras'] = $rulesOk ? 'ok' : ($g2['raw_truncated'] ? 'truncated' : 'invalid_json');
        } else {
            $blocks['regras'] = 'skipped';
        }

        // ── APROFUNDAMENTO — Funções relevantes (estratégia seletiva existente) ──
        $funcoes = [];
        $cachedN = 0;
        $deepTrunc = false;
        $deepRan = false;
        if (! empty($deepItems) && count($plan) >= 3) {
            $deepRan = true;
            [$funcoes, $deepRules, $deepPoints, $cachedN, $deepTrunc] = $this->runDeepeningFinalidades($deepItems, $det, $deepenOut);
            if (! empty($deepRules)) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $deepRules);
            }
            if (! empty($deepPoints)) {
                $sem['pontos_atencao'] = array_merge($sem['pontos_atencao'] ?? [], $deepPoints);
            }
        }
        $sem['funcoes'] = $funcoes;
        $blocks['funcoes'] = $deepTrunc ? 'truncated' : ($deepRan ? 'ok' : 'skipped');

        // ── status GLOBAL: completed só se tudo válido; senão partial preservando o válido ──
        $truncatedAny = $blocks['entendimento'] !== 'ok' || ! $rulesOk || $deepTrunc;
        if (! $entOk) {
            $status = 'partial';
            $reason = 'entendimento_' . $blocks['entendimento'];
        } elseif ($truncatedAny) {
            $status = 'partial';
            $reason = ! $rulesOk ? ('regras_' . $blocks['regras']) : 'functions_incomplete';
        } else {
            $status = 'completed';
            $reason = null;
        }
        $sem['status'] = $status;
        $sem['strategy'] = 'initial_blocks_v2';
        $sem['block_status'] = $blocks;
        if ($reason !== null) {
            $sem['partial_reason'] = $reason;
        }
        $this->coverage = [
            'relevant_functions_total'    => count($relNames),
            'relevant_functions_analyzed' => count($funcoes),
            'relevant_functions_cached'   => $cachedN,
            'relevant_functions_skipped'  => max(0, count($relNames) - count($funcoes)),
        ];
        return $sem;
    }

    /** Código dos ENTRYPOINTS (funções sem called_by), limitado por budget de chars. */
    private function entrypointCode(array $det, string $maskedCode): string
    {
        $lines = explode("\n", $maskedCode);
        $entries = array_values(array_filter($det['functions'] ?? [], fn ($f) => empty($f['called_by'])));
        if (empty($entries)) {
            $entries = array_slice($det['functions'] ?? [], 0, 1);
        }
        $budget = (int) config('services.source_doc_ai.entendimento_code_budget_chars', 14000);
        $out = [];
        $used = 0;
        foreach ($entries as $f) {
            $slice = $this->codeSlice($lines, $f);
            if ($used + mb_strlen($slice) > $budget) {
                break;
            }
            $out[] = "// função {$f['name']}\n" . $slice;
            $used += mb_strlen($slice);
        }
        return implode("\n\n", $out);
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
                    $entry = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? ''), 'confidence' => $f['confidence'] ?? null, 'evidence' => $f['evidence'] ?? []];
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
            $funcoes[] = [
                'name' => $name,
                'finalidade' => $this->str($f['finalidade'] ?? '') ?: self::UNDETERMINED,
                'confidence' => $this->conf($f['confidence'] ?? 'low'),
                'evidence' => $this->validateEvidence($f['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare) ?: [['type' => 'function', 'name' => $name]],
            ];
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

        // Bloco 4.2 — dependências conhecidas (para validar dependencias_criticas): user_calls +
        // integrações externas + funções chamadas NÃO definidas no próprio fonte.
        $depSet = $this->knownDependencySet($det, $fnSet);
        $entendimento = $this->buildEntendimento($sem['entendimento_funcional'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
        $depCriticas = $this->validateDependencias($sem['dependencias_criticas'] ?? [], $depSet, $fnSet, $tbSet, $fieldQ, $fieldBare);
        $risco = $this->buildRisco($sem['risco_alteracao'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'block_status'     => $sem['block_status'] ?? null,
            'entendimento_funcional' => $entendimento,
            'dependencias_criticas'  => $depCriticas,
            'risco_alteracao'        => $risco,
            'status'           => $sem['status'] ?? 'completed',
            'partial_reason'   => $sem['partial_reason'] ?? null,
            'strategy'         => $sem['strategy'] ?? 'initial_global_selective',
            'provider'         => $this->ai->name(),
            'model'            => $this->ai->model(),
            'objetivo'         => $this->str($sem['objetivo'] ?? $sem['overview'] ?? self::UNKNOWN),
            'fluxo'            => $this->arr($sem['fluxo'] ?? $sem['execution_flow'] ?? []),
            'funcoes'          => $funcoes,
            'tabelas'          => $tablePurposes,
            'regras_negocio'   => array_map(fn ($r) => ['id' => $r['id'], 'titulo' => $r['titulo'] ?? null, 'descricao' => $r['descricao'], 'condicao' => $r['condicao'] ?? null, 'efeito' => $r['efeito'] ?? null, 'confidence' => $r['confidence'], 'evidence' => $r['evidence']], $rulesShown),
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
            $out[] = [
                'id' => $r['id'] ?? ('RN' . str_pad((string) (++$i), 2, '0', STR_PAD_LEFT)),
                'titulo' => $this->str($r['titulo'] ?? null),
                'descricao' => $desc,
                'condicao' => $this->str($r['condicao'] ?? null),
                'efeito' => $this->str($r['efeito'] ?? null),
                'confidence' => $this->conf($r['confidence'] ?? 'low'),
                'evidence' => $ev,
            ];
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

    // ── Bloco 4.2 — Entendimento Funcional / Dependências / Risco (validados) ────
    /** Dependências conhecidas: user_calls + integrações externas + chamadas a funções não definidas no fonte. */
    private function knownDependencySet(array $det, array $fnSet): array
    {
        $set = [];
        foreach ((array) ($det['user_calls'] ?? []) as $u) {
            $set[strtolower((string) $u)] = true;
        }
        foreach ((array) ($det['external_integrations'] ?? []) as $i) {
            $name = is_array($i) ? ($i['name'] ?? $i['type'] ?? '') : (string) $i;
            if ($name !== '') {
                $set[strtolower((string) $name)] = true;
            }
        }
        foreach (($det['functions'] ?? []) as $f) {
            foreach (array_merge((array) ($f['calls_user'] ?? []), (array) ($f['calls_internal'] ?? [])) as $c) {
                $c = (string) $c;
                if (! isset($fnSet[strtolower($c)])) {   // chamada externa (não definida aqui)
                    $set[strtolower($c)] = true;
                }
            }
        }
        return $set;
    }

    /** Campo interpretativo: só aceita se tiver evidência rastreável; senão vira UNDETERMINED. */
    private function interpretive(?string $text, $evidenceRaw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare, string $label): array
    {
        $text = $this->str($text);
        $ev = $this->validateEvidence($evidenceRaw ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
        if ($text === null || $text === '' || $text === self::UNDETERMINED || empty($ev)) {
            if ($text !== null && $text !== '' && $text !== self::UNDETERMINED && empty($ev)) {
                $this->rejected[] = ['item' => $label, 'reason' => 'sem evidência rastreável'];
            }
            return ['texto' => self::UNDETERMINED, 'confidence' => 'low', 'evidence' => []];
        }
        return ['texto' => $text, 'confidence' => $this->conf(is_array($evidenceRaw) ? 'medium' : 'low'), 'evidence' => $ev];
    }

    private function buildEntendimento(array $ent, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $out = $this->emptyEntendimento();

        // uma_frase (exige evidência)
        $uf = (array) ($ent['uma_frase'] ?? []);
        $ufv = $this->interpretive($uf['texto'] ?? null, $uf['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, 'uma_frase');
        $out['uma_frase'] = ['texto' => $ufv['texto'], 'confidence' => $this->conf($uf['confidence'] ?? $ufv['confidence']), 'evidence' => $ufv['evidence']];

        // objetivo (texto de propósito; aceita sem evidence estruturada mas com fatos — objetivo é síntese)
        $obj = $this->str($ent['objetivo'] ?? null);
        $out['objetivo'] = ($obj !== null && $obj !== '') ? $obj : self::UNDETERMINED;

        // quando_usado
        $qu = $this->str($ent['quando_usado'] ?? null);
        $out['quando_usado'] = ($qu !== null && $qu !== '') ? $qu : self::UNDETERMINED;

        // processo_modulo (exige evidência)
        $pm = (array) ($ent['processo_modulo'] ?? []);
        $pmEv = $this->validateEvidence($pm['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
        if (! empty($pmEv) && $this->str($pm['modulo'] ?? null)) {
            $out['processo_modulo'] = [
                'processo' => $this->str($pm['processo'] ?? null) ?: self::UNDETERMINED,
                'modulo'   => $this->str($pm['modulo'] ?? null),
                'confidence' => $this->conf($pm['confidence'] ?? 'low'),
                'evidence' => $pmEv,
            ];
        }

        // entradas/saidas principais (item exige evidência)
        $out['entradas_principais'] = $this->ioItems($ent['entradas_principais'] ?? $ent['entradas'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, 'entrada');
        $out['saidas_principais']   = $this->ioItems($ent['saidas_principais'] ?? $ent['saidas'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, 'saida');

        // o_que_faz (passos com evidência)
        $steps = [];
        foreach ((array) ($ent['o_que_faz'] ?? []) as $s) {
            $passo = is_array($s) ? $this->str($s['passo'] ?? $s['descricao'] ?? null) : $this->str($s);
            if ($passo === null || $passo === '') {
                continue;
            }
            $ev = is_array($s) ? $this->validateEvidence($s['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare) : [];
            $steps[] = ['passo' => $passo, 'evidence' => $ev];
        }
        $out['o_que_faz'] = $steps;

        return $out;
    }

    private function ioItems($raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare, string $label): array
    {
        $out = [];
        foreach ((array) $raw as $it) {
            if (is_string($it)) {
                $it = ['descricao' => $it];
            }
            $desc = $this->str($it['descricao'] ?? $it['nome'] ?? null);
            if ($desc === null || $desc === '') {
                continue;
            }
            $out[] = [
                'tipo' => $this->str($it['tipo'] ?? null),
                'nome' => $this->str($it['nome'] ?? null),
                'descricao' => $desc,
                'evidence' => $this->validateEvidence($it['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare),
            ];
        }
        return $out;
    }

    /** dependencias_criticas — nome DEVE existir no conjunto de dependências determinísticas. */
    private function validateDependencias(array $raw, array $depSet, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $out = [];
        foreach ($raw as $d) {
            $nome = $this->str($d['nome'] ?? $d['name'] ?? null);
            if ($nome === null || $nome === '') {
                continue;
            }
            // normaliza U_FUNC / include: aceita se casar no depSet (com/sem prefixo) ou for função do fonte.
            $keys = [strtolower($nome), strtolower(ltrim($nome, 'uU_')), 'u_' . strtolower(ltrim($nome, 'uU_'))];
            $known = false;
            foreach ($keys as $k) {
                if (isset($depSet[$k]) || isset($fnSet[$k])) {
                    $known = true;
                    break;
                }
            }
            if (! $known) {
                $this->rejected[] = ['item' => 'dependencia:' . $nome, 'reason' => 'inexistente no determinístico'];
                continue;
            }
            $out[] = [
                'nome' => $nome,
                'como_participa' => $this->str($d['como_participa'] ?? null) ?: self::UNDETERMINED,
                'impacto_se_indisponivel' => $this->str($d['impacto_se_indisponivel'] ?? null) ?: self::UNDETERMINED,
                'onde_chamada' => $this->str($d['onde_chamada'] ?? null),
                'confidence' => $this->conf($d['confidence'] ?? 'low'),
                'evidence' => $this->validateEvidence($d['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare),
            ];
        }
        return $out;
    }

    /** risco_alteracao — fatores baseados em FATOS (cada fator exige evidência). */
    private function buildRisco(array $raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $tipos = ['dependencia', 'escrita', 'tabela', 'caller', 'integracao', 'complexidade'];
        $fatores = [];
        foreach ((array) ($raw['fatores'] ?? []) as $f) {
            $desc = $this->str($f['descricao'] ?? null);
            if ($desc === null || $desc === '') {
                continue;
            }
            $ev = $this->validateEvidence($f['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
            if (empty($ev)) {
                $this->rejected[] = ['item' => 'risco:' . mb_substr($desc, 0, 40), 'reason' => 'sem evidência rastreável'];
                continue;
            }
            $tipo = strtolower((string) ($f['tipo'] ?? ''));
            $fatores[] = [
                'tipo' => in_array($tipo, $tipos, true) ? $tipo : 'complexidade',
                'descricao' => $desc,
                'evidence' => $ev,
            ];
        }
        return [
            'resumo' => $this->str($raw['resumo'] ?? null) ?: ($fatores ? null : self::UNDETERMINED),
            'fatores' => $fatores,
        ];
    }

    private function validateEvidence($raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $out = [];
        foreach ((array) $raw as $ev) {
            if (! is_array($ev)) {
                continue;
            }
            $type = strtolower((string) ($ev['type'] ?? ''));
            // linhas: aceita {lines:[a,b]} ou {line_start,line_end}
            $ls = isset($ev['line_start']) ? (int) $ev['line_start'] : (is_array($ev['lines'] ?? null) ? (int) ($ev['lines'][0] ?? 0) : null);
            $le = isset($ev['line_end']) ? (int) $ev['line_end'] : (is_array($ev['lines'] ?? null) ? (int) ($ev['lines'][1] ?? 0) : null);
            if ($type === 'function' && isset($fnSet[strtolower((string) ($ev['name'] ?? ''))])) {
                $out[] = ['type' => 'function', 'name' => $ev['name'], 'line_start' => $ls ?: null, 'line_end' => $le ?: null];
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
        return 'Analista Protheus/AdvPL. Os FATOS determinísticos são a AUTORIDADE — você EXPLICA/INTERPRETA/'
            . 'ORGANIZA/CONTEXTUALIZA, NÃO descobre nem inventa. Proibido: supor, completar lacunas por conhecimento '
            . 'genérico de Protheus, ou deduzir finalidade só porque o NOME "parece" indicar algo. '
            . 'Não crie função/tabela/campo/integração/dependência fora dos fatos. '
            . 'TODO campo interpretativo (uma_frase, objetivo, quando_usado, processo_modulo, entradas/saidas, '
            . 'o_que_faz, finalidade de função, dependencias_criticas, risco_alteracao, regras, pontos) EXIGE evidence '
            . '(function/table/field/dependency dos fatos) + confidence (high|medium|low). '
            . 'SEM evidência suficiente ⇒ use exatamente "' . self::UNDETERMINED . '" e explique o motivo em "motivo". '
            . 'risk_flag é evidência técnica, não vulnerabilidade. Código pode vir com segredos mascarados. '
            . 'Explique PROPÓSITO de negócio, não a mecânica ("chama X, acessa Y") que já está no determinístico. '
            . 'Seja CONCISO. Devolva SÓ JSON válido (sem markdown).';
    }

    /** BLOCO 1 (prioridade) — só o Entendimento Funcional. Saída pequena e objetiva → não trunca. */
    private function entendimentoUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        if ($diff) {
            $u .= "\n\nDIFF:\n" . json_encode($this->diffForAi($diff), JSON_UNESCAPED_UNICODE);
        }
        $u .= "\n\nProduza SOMENTE o Entendimento Funcional (PROPÓSITO de negócio, não a mecânica). JSON:\n"
            . 'entendimento_funcional{'
            . 'uma_frase{texto,confidence,evidence[{type,name?,table?,field?}]}, '
            . 'objetivo (2–5 frases: o que resolve, responsabilidade principal, resultado que produz), '
            . 'quando_usado (evento/processo/rotina que dispara; indeterminável ⇒ "' . self::UNDETERMINED . '"), '
            . 'processo_modulo{processo,modulo,confidence,evidence[]} (módulo Protheus POR EVIDÊNCIA, não pelo nome do arquivo), '
            . 'entradas_principais[{tipo,nome,descricao,evidence[]}], '
            . 'saidas_principais[{tipo,nome,descricao,evidence[]}], '
            . 'o_que_faz[{passo,evidence[]}] (sequência FUNCIONAL, não a lista de chamadas)}'
            . ', fluxo[]. Sem evidência para um campo ⇒ "' . self::UNDETERMINED . '".';
        return $u;
    }

    /** BLOCO 2 — Regras / Dependências / Risco / Pontos. Independente do Bloco 1. */
    private function rulesUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        $u .= "\n\nProduza JSON {"
            . 'regras_negocio[{id,titulo,descricao,condicao,efeito,confidence,evidence[{type,name?,table?,field?,line_start?,line_end?}]}], '
            . 'dependencias_criticas[{nome,como_participa,impacto_se_indisponivel,onde_chamada,confidence,evidence[]}] (SÓ o que interfere materialmente; NÃO listar todo include/framework), '
            . 'risco_alteracao{resumo,fatores[{tipo(dependencia|escrita|tabela|caller|integracao|complexidade),descricao,evidence[]}]}, '
            . 'pontos_atencao[{interpretation,categoria?,severity?,recommendation?,confidence,evidence[]}], change_summary}. '
            . 'Cada item EXIGE evidence dos fatos; sem evidência ⇒ omita.';
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
            . "\n\nDê a finalidade FUNCIONAL (a responsabilidade da função no processo, 1–2 frases; NÃO 'chama X/acessa Y') "
            . 'de CADA função listada, com confidence + evidence. Sem base ⇒ finalidade="' . self::UNDETERMINED . '". '
            . 'Se houver base, adicione regra/ponto/dependência com evidence+confidence. '
            . 'Devolva JSON {funcoes[{name,finalidade,confidence,evidence[]}], regras_negocio[...], pontos_atencao[...], dependencias_criticas[...]}.';
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
            // Bloco 4.2 — Entendimento Funcional (novo topo).
            'entendimento_funcional' => $this->emptyEntendimento(),
            'dependencias_criticas' => [], 'risco_alteracao' => ['resumo' => null, 'fatores' => []],
            'objetivo' => null, 'fluxo' => [], 'funcoes' => [], 'tabelas' => [], 'regras_negocio' => [],
            'entradas' => [], 'saidas' => [], 'pontos_atencao' => [], 'resumo_alteracao' => null,
            'business_rules' => [], 'business_rules_low' => [], 'attention_points' => [], 'change_summary' => null, 'table_purposes' => [],
            'semantic_coverage' => ['relevant_functions_total' => 0, 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => 0],
            'usage' => $this->usageBlock(), 'validation' => ['rejected_count' => 0, 'rejected' => []],
        ];
    }

    private function emptyEntendimento(): array
    {
        return [
            'uma_frase' => ['texto' => self::UNDETERMINED, 'confidence' => 'low', 'evidence' => []],
            'objetivo' => self::UNDETERMINED,
            'quando_usado' => self::UNDETERMINED,
            'processo_modulo' => ['processo' => self::UNDETERMINED, 'modulo' => self::UNDETERMINED, 'confidence' => 'low', 'evidence' => []],
            'entradas_principais' => [],
            'saidas_principais' => [],
            'o_que_faz' => [],
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────────
    private function normFuncoes(array $raw): array
    {
        $out = [];
        foreach ($raw as $f) {
            if (! empty($f['name'])) {
                // Bloco 4.2 — preserva confidence + evidence (o finalize valida; sem isso vinham perdidos).
                $out[] = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? ''), 'confidence' => $f['confidence'] ?? null, 'evidence' => $f['evidence'] ?? []];
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
