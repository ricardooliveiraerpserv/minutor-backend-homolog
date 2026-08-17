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
    // Ponto 4 — TOP-UP: custo JÁ gasto em execuções anteriores desta MESMA fonte. A guarda de custo
    // por fonte (≤ hard_limit) deve considerar o acumulado, não só o gasto do top-up atual.
    private float $costBaseUsd = 0.0;
    // motivos de missing considerados FALHA TÉCNICA (recuperáveis por top-up) — o resto é not_identified.
    private const TECH_MISS = ['cost_budget', 'truncated_unrecovered', 'deepen_call_budget', 'simple_truncated'];

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
            } elseif ($this->isSimpleSource($deterministic, $maskedCode)) {
                // Bloco 4.2.1-C: fonte simples → 1 chamada; se truncar/vier vazio, FALLBACK p/ 4 blocos.
                $result = $this->simple($deterministic, $maskedCode, $diff);
                if (! empty($result['__fallback'])) {
                    $result = $this->initial($deterministic, $maskedCode, $diff);
                }
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
        $entOut     = (int) config('services.source_doc_ai.max_output_tokens_entendimento', 4000);
        $regrasOut  = (int) config('services.source_doc_ai.max_output_tokens_regras', 2600);
        $depRiscoOut = (int) config('services.source_doc_ai.max_output_tokens_deprisco', 3000);
        $deepenOut  = (int) config('services.source_doc_ai.max_output_tokens_per_call', 2600);

        $entUser     = $this->entendimentoUserPrompt($compact, $diff, $entCode);
        $regrasUser  = $this->regrasUserPrompt($compact, $diff, $inlineCode);
        $depRiscoUser = $this->depRiscoUserPrompt($compact, $diff, $inlineCode);
        $deepItems   = ! empty($relevant) ? $this->buildDeepItems($relevant, $det, $maskedCode) : [];

        // ── 4 BLOCOS INDEPENDENTES: Entendimento · Regras · Deps+Risco · Funções ──
        // estimativa da ESTRATÉGIA COMPLETA antes de executar (hard limit; nada silencioso).
        $plan = [
            ['system' => $this->systemPrompt(), 'user' => $entUser,      'out' => $entOut,      'code' => ($entCode !== '')],
            ['system' => $this->systemPrompt(), 'user' => $regrasUser,   'out' => $regrasOut,   'code' => ($inlineCode !== '')],
            ['system' => $this->systemPrompt(), 'user' => $depRiscoUser, 'out' => $depRiscoOut, 'code' => ($inlineCode !== '')],
        ];
        if (! empty($deepItems)) {
            $plan[] = ['system' => $this->systemPrompt(), 'user' => $this->deepenFinalidadesPrompt($deepItems), 'out' => $deepenOut, 'code' => true];
        }
        // a estratégia de 4 blocos exige ao menos 4 chamadas (piso), respeitando um cap maior se houver.
        $plan = array_slice($plan, 0, max(4, (int) config('services.source_doc_ai.max_calls', 4)));
        $est = $this->estimatePlan($plan);
        $this->usage['estimated_before_usd'] = round($est, 4);
        if ($est > (float) config('services.source_doc_ai.hard_limit_usd', 0.30)) {
            return $this->costSkipped($est, count($relNames));
        }

        $sem = [];
        $blocks = [];
        $hardLimit = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);

        // ── POLÍTICA C — ordem: Entendimento → Funções → Regras → Deps/Risco, com orçamento dinâmico.
        // Reserva estimada (payload real) dos complementares p/ as funções não os faminta.
        $reserveReg = $this->estimateCallUsd($regrasUser, $regrasOut, $inlineCode !== '');

        // ── BLOCO 1 — Entendimento Funcional (PRIORIDADE MÁXIMA; roda 1º; protegido) ──
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

        // ── BLOCO 2 — FUNÇÕES (prioridade sobre os complementares; ANTES de regras/deps) ──
        // teto = hard_limit − reserva(regras); regras fica protegida, deps usa a folga + redistribuição.
        $funcoes = [];
        $cachedN = 0;
        $deepTrunc = false;
        $deepRan = false;
        $deepTrace = null;
        if (! empty($deepItems)) {
            $deepRan = true;
            $funcCeiling = max($this->currentCostUsd() + 0.005, $hardLimit - $reserveReg);
            [$funcoes, $deepRules, $deepPoints, $cachedN, $deepTrunc, $deepTrace] = $this->runDeepeningFinalidades($deepItems, $det, $deepenOut, $funcCeiling);
            if (! empty($deepRules)) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $deepRules);
            }
            if (! empty($deepPoints)) {
                $sem['pontos_atencao'] = array_merge($sem['pontos_atencao'] ?? [], $deepPoints);
            }
        }

        // ── BLOCO 3 — Regras de Negócio (após funções; reserva protegida) ──
        $regrasOk = false;
        $g2 = $this->callJson($this->systemPrompt(), $regrasUser, $regrasOut);
        $j2 = is_array($g2['json']) ? $g2['json'] : [];
        if (! empty($j2['regras_negocio'])) {
            $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $j2['regras_negocio']);
        }
        if (! empty($j2['change_summary'])) {
            $sem['change_summary'] = $j2['change_summary'];
        }
        $regrasOk = ! $g2['truncated'] && $j2 !== [];
        $blocks['regras'] = $regrasOk ? 'ok' : ($g2['raw_truncated'] ? 'truncated' : 'invalid_json');

        // ── BLOCO 4 — Dependências Críticas + Risco + Pontos (após regras; folga restante) ──
        $depRiscoOk = false;
        $g3 = $this->callJson($this->systemPrompt(), $depRiscoUser, $depRiscoOut);
        $j3 = is_array($g3['json']) ? $g3['json'] : [];
        foreach (['dependencias_criticas', 'pontos_atencao'] as $k) {
            if (! empty($j3[$k])) {
                $sem[$k] = $j3[$k];
            }
        }
        if (! empty($j3['risco_alteracao'])) {
            $sem['risco_alteracao'] = $j3['risco_alteracao'];
        }
        $depRiscoOk = ! $g3['truncated'] && $j3 !== [];
        $blocks['deps_risco'] = $depRiscoOk ? 'ok' : ($g3['raw_truncated'] ? 'truncated' : 'invalid_json');

        // ── POLÍTICA C — REDISTRIBUIÇÃO: a sobra dos complementares (mais baratos que a reserva) volta
        // p/ as funções em missing técnico, dentro do hard_limit real. Mantém top-up como exceção.
        if ($deepRan && is_array($deepTrace) && $this->currentCostUsd() < $hardLimit - 0.01) {
            $techMissNames = [];
            foreach (($deepTrace['missing'] ?? []) as $m) {
                if (in_array($m['reason'] ?? '', self::TECH_MISS, true) && ! empty($m['name'])) {
                    $techMissNames[strtolower((string) $m['name'])] = true;
                }
            }
            if (! empty($techMissNames)) {
                $missItems = array_values(array_filter($deepItems, fn ($it) => isset($techMissNames[strtolower((string) ($it['name'] ?? ''))])));
                if (! empty($missItems)) {
                    [$more, $mr, $mp, , $mtr, $moreTrace] = $this->runDeepeningFinalidades($missItems, $det, $deepenOut, $hardLimit);
                    $funcoes = $this->mergeFuncoes($funcoes, $more);
                    if (! empty($mr)) {
                        $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $mr);
                    }
                    if (! empty($mp)) {
                        $sem['pontos_atencao'] = array_merge($sem['pontos_atencao'] ?? [], $mp);
                    }
                    $deepTrace = $this->mergeTrace($deepTrace, $moreTrace, $deepTrace['requested'] ?? []);
                }
            }
        }

        // ── Ponto 3 — RETRY SELETIVO de bloco quebrado (truncado/JSON inválido), preservando os
        // válidos e sem refazer os demais. Só dispara se o bloco falhou E há folga no teto (US$ 0,30).
        if (! $entOk) {
            [$ok, $j] = $this->retryBlockCall($entUser, $entOut, $entCode !== '', $hardLimit, ($blocks['entendimento'] ?? '') === 'truncated');
            if ($ok && ! empty($j['entendimento_funcional'])) {
                $sem['entendimento_funcional'] = $j['entendimento_funcional'];
                $sem['objetivo'] = $j['entendimento_funcional']['objetivo'] ?? ($sem['objetivo'] ?? null);
                if (! empty($j['fluxo'])) {
                    $sem['fluxo'] = $j['fluxo'];
                }
                $entOk = true;
                $blocks['entendimento'] = 'ok';
            }
        }
        if (! $regrasOk) {
            [$ok, $j] = $this->retryBlockCall($regrasUser, $regrasOut, $inlineCode !== '', $hardLimit, ($blocks['regras'] ?? '') === 'truncated');
            if ($ok) {
                if (! empty($j['regras_negocio'])) {
                    $sem['regras_negocio'] = $j['regras_negocio'];
                }
                if (! empty($j['change_summary'])) {
                    $sem['change_summary'] = $j['change_summary'];
                }
                $regrasOk = true;
                $blocks['regras'] = 'ok';
            }
        }
        if (! $depRiscoOk) {
            [$ok, $j] = $this->retryBlockCall($depRiscoUser, $depRiscoOut, $inlineCode !== '', $hardLimit, ($blocks['deps_risco'] ?? '') === 'truncated');
            if ($ok) {
                foreach (['dependencias_criticas', 'pontos_atencao'] as $k) {
                    if (! empty($j[$k])) {
                        $sem[$k] = $j[$k];
                    }
                }
                if (! empty($j['risco_alteracao'])) {
                    $sem['risco_alteracao'] = $j['risco_alteracao'];
                }
                $depRiscoOk = true;
                $blocks['deps_risco'] = 'ok';
            }
        }

        // (Funções já foram aprofundadas ANTES de regras/deps — Política C — e redistribuídas acima.)
        $sem['funcoes'] = $funcoes;
        $sem['funcoes_trace'] = $deepTrace; // requested/completed/not_identified/missing p/ reprocesso seletivo
        // 'ok' só se aprofundou E não ficou missing TÉCNICO; not_identified honesto não penaliza.
        $missingN = 0;
        foreach ((is_array($deepTrace) ? ($deepTrace['missing'] ?? []) : []) as $m) {
            if (in_array($m['reason'] ?? '', self::TECH_MISS, true)) {
                $missingN++;
            }
        }
        $blocks['funcoes'] = ! $deepRan ? 'skipped' : ($missingN > 0 ? 'partial' : 'ok');

        // ── status GLOBAL: completed só se todos válidos; senão partial preservando o válido ──
        if (! $entOk) {
            $status = 'partial';
            $reason = 'entendimento_' . $blocks['entendimento'];
        } elseif (! $regrasOk) {
            $status = 'partial';
            $reason = 'regras_' . $blocks['regras'];
        } elseif (! $depRiscoOk) {
            $status = 'partial';
            $reason = 'deps_risco_' . $blocks['deps_risco'];
        } elseif ($missingN > 0) {
            $status = 'partial';
            $reason = 'functions_incomplete';
        } else {
            $status = 'completed';
            $reason = null;
        }
        $sem['status'] = $status;
        $sem['strategy'] = 'initial_blocks_v3';
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

    /** Fonte SIMPLES: código cabe inline + poucas funções + poucas queries. */
    private function isSimpleSource(array $det, string $maskedCode): bool
    {
        if (! (bool) config('services.source_doc_ai.simple_route_enabled', true)) {
            return false;
        }
        $inlineMax = (int) config('services.source_doc_ai.inline_code_max_chars', 8000);
        $maxFn = (int) config('services.source_doc_ai.simple_max_functions', 3);
        $maxQ = (int) config('services.source_doc_ai.simple_max_queries', 2);
        return mb_strlen($maskedCode) <= $inlineMax
            && count($det['functions'] ?? []) <= $maxFn
            && count($det['queries'] ?? []) <= $maxQ;
    }

    /** Bloco 4.2.1-C — ROTA SIMPLES: 1 chamada com o contrato inteiro (saída pequena p/ fonte simples). */
    private function simple(array $det, string $maskedCode, ?array $diff): array
    {
        $relevant = $this->selectRelevant($det, $diff, (int) config('services.source_doc_ai.max_relevant_functions', 12));
        $relNames = array_map(fn ($f) => (string) $f['name'], $relevant);
        $compact = $this->buildCompactFacts($det, $relevant, $diff);
        $out = (int) config('services.source_doc_ai.max_output_tokens_simple', 3000);

        $plan = [['system' => $this->systemPrompt(), 'user' => $this->simpleUserPrompt($compact, $maskedCode), 'out' => $out, 'code' => true]];
        $est = $this->estimatePlan($plan);
        $this->usage['estimated_before_usd'] = round($est, 4);
        if ($est > (float) config('services.source_doc_ai.hard_limit_usd', 0.30)) {
            return $this->costSkipped($est, count($relNames));
        }

        $g = $this->callJson($this->systemPrompt(), $this->simpleUserPrompt($compact, $maskedCode), $out);
        $j = is_array($g['json']) ? $g['json'] : [];
        // fallback se veio vazio OU truncou sem entendimento aproveitável.
        if (empty($j) || ($g['truncated'] && empty($j['entendimento_funcional']))) {
            return ['__fallback' => true];
        }

        $sem = $j;
        $funcoes = $this->normFuncoes($j['funcoes'] ?? []);
        $sem['funcoes'] = $funcoes;
        $done = array_map('strtolower', array_map(fn ($f) => (string) $f['name'], $funcoes));
        $missing = array_values(array_filter($relNames, fn ($n) => ! in_array(strtolower($n), $done, true)));
        $sem['funcoes_trace'] = [
            'requested' => $relNames,
            'completed' => array_values(array_map(fn ($f) => (string) $f['name'], $funcoes)),
            'missing'   => array_map(fn ($n) => ['name' => $n, 'reason' => 'simple_truncated'], $missing),
            'calls'     => 1,
        ];
        $st = $g['truncated'] ? 'entendimento' : 'ok';
        $sem['block_status'] = ['entendimento' => empty($j['entendimento_funcional']) ? 'invalid_json' : ($g['truncated'] ? 'truncated' : 'ok'),
            'regras' => 'ok', 'deps_risco' => 'ok', 'funcoes' => empty($missing) ? 'ok' : 'partial'];
        $sem['status'] = ($g['truncated'] || ! empty($missing)) ? 'partial' : 'completed';
        if ($sem['status'] === 'partial') {
            $sem['partial_reason'] = $g['truncated'] ? 'simple_truncated' : 'functions_incomplete';
        }
        $sem['strategy'] = 'simple_single_call';
        $this->coverage = [
            'relevant_functions_total'    => count($relNames),
            'relevant_functions_analyzed' => count($funcoes),
            'relevant_functions_cached'   => 0,
            'relevant_functions_skipped'  => count($missing),
        ];
        return $sem;
    }

    /** Prompt monolítico ENXUTO — só p/ fontes simples (saída pequena, sem risco de truncar). */
    private function simpleUserPrompt(array $compact, string $inlineCode): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inlineCode !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $inlineCode;
        }
        $u .= "\n\nFonte SIMPLES: documente o essencial SEM inventar. Se não houver regra/dependência/risco "
            . 'reais, deixe as listas VAZIAS (não invente). Produza JSON {'
            . 'entendimento_funcional{uma_frase{texto,confidence,evidence[≤1]}, objetivo (1–3 frases), quando_usado, '
            . 'processo_modulo{processo,modulo,confidence,evidence[≤1]}, entradas_principais[≤4], saidas_principais[≤4], o_que_faz[≤6 {passo,evidence[≤1]}]}, '
            . 'funcoes[{name,finalidade,confidence,evidence[≤1]}] (só as dos fatos), '
            . 'regras_negocio[{id,titulo,descricao,condicao,efeito,confidence,evidence[≤2]}] (só se houver regra REAL), '
            . 'dependencias_criticas[{nome,como_participa,confidence,evidence[≤1]}] (só o que interfere), '
            . 'risco_alteracao{resumo,fatores[{tipo,descricao,evidence[≤1]}]}, change_summary}. '
            . 'Sem evidência ⇒ "' . self::UNDETERMINED . '".';
        return $u;
    }

    /** Código dos ENTRYPOINTS (funções sem called_by), limitado por budget de chars. */
    private function entrypointCode(array $det, string $maskedCode): string
    {
        $lines = explode("\n", $maskedCode);
        $entries = array_values(array_filter($det['functions'] ?? [], fn ($f) => empty($f['called_by'])));
        if (empty($entries)) {
            $entries = array_slice($det['functions'] ?? [], 0, 1);
        }
        $budget = (int) config('services.source_doc_ai.entendimento_code_budget_chars', 9000);
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

    /**
     * Nomes de função DUPLICADOS no determinístico (fontes orientados a classe onde todos os métodos
     * saem com o mesmo nome). Refinamento 3: identidade estável precisa de name + start_line nesses casos.
     */
    private function fnDupNames(array $det): array
    {
        $count = [];
        foreach (($det['functions'] ?? []) as $f) {
            $n = strtolower((string) ($f['name'] ?? ''));
            $count[$n] = ($count[$n] ?? 0) + 1;
        }
        return array_filter($count, fn ($c) => $c > 1);
    }

    /** Identidade ESTÁVEL exibível: só o nome quando é único; name@start_line quando o nome se repete. */
    private function fnDisplayName(array $f, array $dup): string
    {
        $name = (string) ($f['name'] ?? '');
        if (isset($dup[strtolower($name)])) {
            $line = $f['start_line'] ?? ($f['evidence']['line_start'] ?? '?');
            return $name . '@' . $line;
        }
        return $name;
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
        $dup = $this->fnDupNames($det); // refinamento 3 — identidade estável em fontes-classe
        $lines = explode("\n", $maskedCode);
        $budgetTokens = (int) config('services.source_doc_ai.deepen_code_budget_tokens', 12000);
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
            // 'name' = identidade estável (name@line em fontes-classe) — distingue métodos homônimos no
            // matching/trace/dedup e é o rótulo que o modelo ecoa de volta.
            $items[] = ['name' => $this->fnDisplayName($f, $dup), 'base_name' => $f['name'], 'facts' => $this->fnFact($f), 'code' => $code];
        }
        return $items;
    }

    /** Aprofundamento: cache por função (miss → 1 chamada) → finalidades + regras/pontos extras. */
    /**
     * Bloco 4.2.1-B — aprofundamento ROBUSTO: chunks pequenos + retry adaptativo do subconjunto
     * NÃO recuperado (subdivide até 1) + acúmulo incremental. Truncar um chunk nunca zera o todo.
     * Rastreabilidade: requested → completed → missing (com motivo), p/ reprocessar só as faltantes.
     * @return array{0:array,1:array,2:array,3:int,4:bool,5:array} funcoes, rules, points, cachedN, truncated, trace
     */
    private function runDeepeningFinalidades(array $items, array $det, int $out, ?float $budgetCeiling = null): array
    {
        $requested = array_values(array_filter(array_map(fn ($it) => (string) ($it['name'] ?? ''), $items)));
        $funcoes = [];
        $toCall = [];
        $cachedN = 0;
        foreach ($items as $it) {
            $hit = $this->cacheEnabled() ? Cache::get($this->functionCacheKey($det, $it)) : null;
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
        $anyTrunc = false;
        $chunkSize = max(1, (int) config('services.source_doc_ai.deepen_chunk_size', 4));
        $maxCalls  = (int) config('services.source_doc_ai.deepen_max_calls', 12);
        $calls = 0;

        // GUARDA DE CUSTO ACUMULADO por FONTE (≤ hard_limit). Ponto 1: a reserva é estimada pelo
        // PAYLOAD REAL de cada sub-lote (não 12k fixo). Ponto 2: chunk ELÁSTICO — se o chunk cheio
        // não couber, reduz 4→2→1; só marca cost_budget quando NEM UMA função cabe no restante.
        // Política C — teto de orçamento das FUNÇÕES: por padrão o hard_limit por fonte, mas o initial()
        // passa um teto MENOR (reservando regras+deps) para as funções não faminta os complementares.
        $hardLimit = $budgetCeiling ?? (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        $costBudgetHit = false;

        $attempted = [];    // name(lower) => true : o sub-lote da função foi efetivamente chamado
        $truncName = [];    // name(lower) => true : última tentativa truncou e não recuperou (missing técnico)

        // pilha de subconjuntos a processar (retry adaptativo empilha metades do não-recuperado).
        $stack = array_chunk($toCall, $chunkSize);
        while (! empty($stack)) {
            if ($calls >= $maxCalls) {
                $anyTrunc = true; // orçamento de chamadas esgotado → resto vira missing (deepen_call_budget)
                break;
            }
            $cur = array_shift($stack);
            if (empty($cur)) {
                continue;
            }
            // FIT ELÁSTICO: reduz o sub-lote até caber no orçamento restante (ou nada cabe).
            $fit = $this->deepenFitCount($cur, $out, $hardLimit);
            $proc = $fit > 0 ? array_slice($cur, 0, $fit) : [];
            if (empty($proc)) {
                // nem 1 função cabe no restante → teto de custo por fonte atingido; resto vira missing.
                $anyTrunc = true;
                $costBudgetHit = true;
                break;
            }
            $leftover = array_slice($cur, count($proc));
            if (! empty($leftover)) {
                array_unshift($stack, $leftover); // processa o excedente depois (se couber)
            }

            foreach ($proc as $it) {
                $attempted[strtolower((string) ($it['name'] ?? ''))] = true;
            }
            $calls++;
            // Refinamento 4 — output PROPORCIONAL ao tamanho do chunk (não o piso fixo de 2600).
            $callOut = $this->deepenOutFor(count($proc), $out);
            [$got, $r, $p, $trunc] = $this->deepenCall($proc, $det, $callOut);
            // Refinamento 4 (guarda) — se o output menor TRUNCOU, retry SÓ deste chunk com budget maior,
            // desde que ainda caiba na folga do teto (não subdivide antes de tentar mais saída).
            if ($trunc && $calls < $maxCalls) {
                $affOut = $this->affordableOutTokens($this->deepenFinalidadesPrompt($proc), true, $hardLimit);
                if ($affOut > $callOut + 300) {
                    $bigOut = min($affOut, max($callOut * 2, $callOut + 800));
                    [$got2, $r2, $p2, $trunc2] = $this->deepenCall($proc, $det, $bigOut);
                    $calls++;
                    if (count($got2) >= count($got)) {
                        [$got, $r, $p, $trunc] = [$got2, $r2, $p2, $trunc2];
                    }
                }
            }
            // Ponto 6 — colisão de nome (fontes orientados a classe): sub-lote unitário ⇒ a finalidade
            // retornada pertence, sem ambiguidade, à função canônica do determinístico. Só quando NÃO
            // truncou e a finalidade é real (não mascara truncamento como conclusão).
            if (count($proc) === 1 && ! $trunc && ! empty($got) && $this->isRealFinalidade($got[0]['finalidade'] ?? '')) {
                $g0 = $got[0];
                $g0['name'] = $proc[0]['name'];
                $got = [$g0];
            }
            $rules = array_merge($rules, $r);
            $points = array_merge($points, $p);
            $gotNames = array_map(fn ($f) => strtolower((string) $f['name']), $got);
            foreach ($got as $entry) {
                $funcoes[] = $entry;
                if ($this->cacheEnabled() && $this->isRealFinalidade($entry['finalidade'] ?? '')) {
                    $c = $this->findByName($proc, $entry['name']);
                    if ($c) {
                        Cache::put($this->functionCacheKey($det, $c), $entry, (int) config('services.source_doc_ai.cache_ttl', 2592000));
                    }
                }
            }
            // não recuperados NESTE sub-lote → retry subdividido (até 1 função) se truncou.
            $unrec = array_values(array_filter($proc, fn ($it) => ! in_array(strtolower((string) ($it['name'] ?? '')), $gotNames, true)));
            if (! empty($unrec)) {
                $anyTrunc = $anyTrunc || $trunc;
                if ($trunc && count($proc) > 1) {
                    $half = (int) ceil(count($unrec) / 2);
                    array_unshift($stack, array_slice($unrec, $half));
                    array_unshift($stack, array_slice($unrec, 0, $half));
                } elseif ($trunc) {
                    // count($proc)==1 e truncou → função grande demais; missing técnico rastreado.
                    $truncName[strtolower((string) ($unrec[0]['name'] ?? ''))] = true;
                }
                // não truncou e não retornou ⇒ o modelo não determinou finalidade → not_identified (abaixo).
            }
        }

        // trace: requested → completed / not_identified / missing(técnico). Ponto 5: rejeição/ausência
        // de evidência é not_identified (resultado honesto), NÃO missing. Missing = só falha técnica.
        $byName = [];
        foreach ($funcoes as $f) {
            $byName[strtolower((string) $f['name'])] = $f;
        }
        $completed = [];
        $notIdentified = [];
        $missing = [];
        foreach ($requested as $rname) {
            $low = strtolower($rname);
            $f = $byName[$low] ?? null;
            if ($f && $this->isRealFinalidade($f['finalidade'] ?? '')) {
                $completed[] = $rname;
            } elseif (isset($truncName[$low])) {
                $missing[] = ['name' => $rname, 'reason' => 'truncated_unrecovered'];
            } elseif (isset($attempted[$low])) {
                // analisada, mas sem finalidade determinável → not_identified honesto (não penaliza).
                $notIdentified[] = $rname;
                if (! $f) {
                    $funcoes[] = ['name' => $rname, 'finalidade' => self::UNDETERMINED, 'confidence' => 'low', 'evidence' => []];
                }
            } else {
                // nunca tentada por orçamento (custo/chamadas) → missing técnico recuperável por top-up.
                $missing[] = ['name' => $rname, 'reason' => $costBudgetHit ? 'cost_budget' : ($calls >= $maxCalls ? 'deepen_call_budget' : 'truncated_unrecovered')];
            }
        }
        $trace = [
            'requested'      => $requested,
            'completed'      => $completed,
            'not_identified' => $notIdentified,
            'missing'        => $missing,
            'calls'          => $calls,
        ];
        return [$funcoes, $rules, $points, $cachedN, $anyTrunc, $trace];
    }

    /** Uma finalidade "real" (documentada) — não vazia e não o marcador de indeterminação. */
    private function isRealFinalidade(?string $f): bool
    {
        $f = trim((string) $f);
        return $f !== '' && $f !== self::UNDETERMINED;
    }

    /** Refinamento 4 — output do aprofundamento PROPORCIONAL ao nº de funções do chunk (limitado pelo cap). */
    private function deepenOutFor(int $n, int $cap): int
    {
        $base = (int) config('services.source_doc_ai.deepen_out_base', 300);
        $per  = (int) config('services.source_doc_ai.deepen_out_per_function', 450);
        return min(max(1, $cap), $base + $per * max(1, $n));
    }

    /**
     * Ponto 1+2 (+Refinamento 4) — quantas funções do sub-lote CABEM no orçamento restante (custo
     * acumulado por fonte + reserva do PAYLOAD REAL, com output ADAPTATIVO ao tamanho, ≤ hard_limit).
     * Reduz 4 → 2 → 1; 0 = nem uma unidade cabe (cost_budget). $cap = teto de output por chamada.
     */
    private function deepenFitCount(array $cur, int $cap, float $hardLimit): int
    {
        $n = count($cur);
        while ($n >= 1) {
            $sub = array_slice($cur, 0, $n);
            $reserve = $this->estimateCallUsd($this->deepenFinalidadesPrompt($sub), $this->deepenOutFor($n, $cap), true) + 0.005;
            if ($this->currentCostUsd() + $reserve <= $hardLimit) {
                return $n;
            }
            $n = intdiv($n, 2); // 4 → 2 → 1
        }
        return 0;
    }

    /** Refinamento 1 — quantos tokens de SAÍDA cabem na folga restante do fonte p/ esta chamada. */
    private function affordableOutTokens(string $user, bool $code, float $hardLimit): int
    {
        $cpt = $code ? (float) config('services.source_doc_ai.chars_per_token_code', 1.6) : (float) config('services.source_doc_ai.chars_per_token_text', 3.2);
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $inTok = ceil((mb_strlen($this->systemPrompt()) + mb_strlen($user)) / $cpt);
        $room = $hardLimit - $this->currentCostUsd() - ($inTok / 1e6 * $ci) - 0.005;
        if ($room <= 0) {
            return 0;
        }
        return (int) floor($room * 1e6 / max($co, 1e-9));
    }

    /**
     * Ponto 3 + Refinamento 1 — reexecuta UM bloco (sem refazer os demais) com budget de saída ADAPTATIVO.
     * Se o bloco já TRUNCOU, não repete com o mesmo max_output_tokens: amplia dentro da folga; se não
     * houver folga p/ ampliar (nem p/ um mínimo útil), NÃO gasta chamada. Retorna [sucesso, json].
     */
    private function retryBlockCall(string $user, int $baseOut, bool $code, float $hardLimit, bool $wasTruncated): array
    {
        if (! (bool) config('services.source_doc_ai.block_retry_enabled', true)) {
            return [false, []];
        }
        $aff = $this->affordableOutTokens($user, $code, $hardLimit);
        $minOut = (int) config('services.source_doc_ai.block_retry_min_out', 1200);
        if ($aff < $minOut) {
            return [false, []]; // sem folga p/ uma chamada útil → não gasta (fica honesto)
        }
        if ($wasTruncated) {
            if ($aff <= $baseOut) {
                return [false, []]; // truncou antes e não dá p/ ampliar além do base → chamada inútil, pula
            }
            $out = min($aff, max($baseOut * 2, $baseOut + 800)); // amplia a saída dentro da folga
        } else {
            $out = min($aff, $baseOut); // invalid_json (transiente): mesma faixa basta
        }
        $g = $this->callJson($this->systemPrompt(), $user, $out);
        $j = is_array($g['json']) ? $g['json'] : [];
        return [! $g['truncated'] && $j !== [], $j];
    }

    /**
     * Ponto 4 — TOP-UP / RECOVERY: enriquece um semantic_json EXISTENTE sem refazer do zero.
     * Reexecuta SÓ (a) blocos quebrados e (b) funções em funcoes_trace.missing por FALHA TÉCNICA,
     * aproveitando o function cache. Custo/chamadas adicionais entram no acumulado por fonte (≤ US$ 0,30)
     * e são registrados separadamente (usage.topup_*). Preserva finalidades/blocos já válidos.
     * Caminho ESPECÍFICO de enriquecimento — não é o reprocesso genérico.
     */
    public function topUp(array $existing, array $det, string $maskedCode, ?array $diff = null): array
    {
        $this->resetState();
        $this->costBaseUsd = (float) (($existing['usage']['actual_cost_usd'] ?? 0.0));
        $hardLimit = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);

        $blocks = (array) ($existing['block_status'] ?? []);
        $trace  = (array) ($existing['funcoes_trace'] ?? []);
        $techMissNames = [];
        foreach (($trace['missing'] ?? []) as $m) {
            if (in_array($m['reason'] ?? '', self::TECH_MISS, true) && ! empty($m['name'])) {
                $techMissNames[strtolower((string) $m['name'])] = (string) $m['name'];
            }
        }
        $blocksBroken = array_filter(['entendimento', 'regras', 'deps_risco'], fn ($b) => ($blocks[$b] ?? 'ok') !== 'ok');
        if (empty($techMissNames) && empty($blocksBroken)) {
            return $existing; // nada a recuperar — no-op (não gera chamada)
        }

        // contexto de prompts (idêntico ao initial), para retry de bloco e aprofundamento seletivo.
        $limit = (int) config('services.source_doc_ai.max_relevant_functions', 12);
        $relevant = $this->selectRelevant($det, $diff, $limit);
        $compact = $this->buildCompactFacts($det, $relevant, $diff);
        $inlineCodeMax = (int) config('services.source_doc_ai.inline_code_max_chars', 8000);
        $inlineCode = mb_strlen($maskedCode) <= $inlineCodeMax ? $maskedCode : '';
        $entCode = $inlineCode !== '' ? $inlineCode : $this->entrypointCode($det, $maskedCode);
        $entOut     = (int) config('services.source_doc_ai.max_output_tokens_entendimento', 4000);
        $regrasOut  = (int) config('services.source_doc_ai.max_output_tokens_regras', 2600);
        $depRiscoOut = (int) config('services.source_doc_ai.max_output_tokens_deprisco', 3000);
        $deepenOut  = (int) config('services.source_doc_ai.max_output_tokens_per_call', 2600);

        $sem = $existing;
        // conjunto RELEVANTE atual com identidade estável (refinamento 3) — base de um trace limpo.
        $dup = $this->fnDupNames($det);
        $freshRequested = array_values(array_map(fn ($f) => $this->fnDisplayName($f, $dup), $relevant));

        // (b PRIMEIRO) Refinamento 2 — PRIORIDADE DE ORÇAMENTO: as funções em missing técnico vêm antes
        // dos retries de bloco, para um retry não faminta finalidades que caberiam no teto.
        if (! empty($techMissNames)) {
            // Casa pela identidade ESTÁVEL (name@line em fontes-classe), pois o trace guarda o display,
            // não o nome-base — senão em fonte-classe o missFns fica vazio e o top-up não recupera nada.
            $dupTop = $this->fnDupNames($det);
            $missFns = array_values(array_filter($det['functions'] ?? [], fn ($f) => isset($techMissNames[strtolower($this->fnDisplayName($f, $dupTop))])));
            $items = $this->buildDeepItems($missFns, $det, $maskedCode);
            [$newFuncoes, $newRules, $newPoints, , , $newTrace] = $this->runDeepeningFinalidades($items, $det, $deepenOut);
            $sem['funcoes'] = $this->mergeFuncoes($existing['funcoes'] ?? [], $newFuncoes);
            if (! empty($newRules)) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $newRules);
            }
            if (! empty($newPoints)) {
                $sem['pontos_atencao'] = array_merge($sem['pontos_atencao'] ?? [], $newPoints);
            }
            $trace = $this->mergeTrace($trace, $newTrace, $freshRequested);
        }

        // (a DEPOIS) retry seletivo dos blocos quebrados, com a FOLGA restante — refinamento 1: budget
        // de saída ADAPTATIVO (bloco que já truncou não é repetido com o mesmo max_output_tokens).
        if (($blocks['entendimento'] ?? 'ok') !== 'ok') {
            [$ok, $j] = $this->retryBlockCall($this->entendimentoUserPrompt($compact, $diff, $entCode), $entOut, $entCode !== '', $hardLimit, $blocks['entendimento'] === 'truncated');
            if ($ok && ! empty($j['entendimento_funcional'])) {
                $sem['entendimento_funcional'] = $j['entendimento_funcional'];
                $sem['objetivo'] = $j['entendimento_funcional']['objetivo'] ?? ($sem['objetivo'] ?? null);
                if (! empty($j['fluxo'])) {
                    $sem['fluxo'] = $j['fluxo'];
                }
                $blocks['entendimento'] = 'ok';
            }
        }
        if (($blocks['regras'] ?? 'ok') !== 'ok') {
            [$ok, $j] = $this->retryBlockCall($this->regrasUserPrompt($compact, $diff, $inlineCode), $regrasOut, $inlineCode !== '', $hardLimit, $blocks['regras'] === 'truncated');
            if ($ok) {
                if (! empty($j['regras_negocio'])) {
                    $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $j['regras_negocio']);
                }
                if (! empty($j['change_summary'])) {
                    $sem['change_summary'] = $j['change_summary'];
                }
                $blocks['regras'] = 'ok';
            }
        }
        if (($blocks['deps_risco'] ?? 'ok') !== 'ok') {
            [$ok, $j] = $this->retryBlockCall($this->depRiscoUserPrompt($compact, $diff, $inlineCode), $depRiscoOut, $inlineCode !== '', $hardLimit, $blocks['deps_risco'] === 'truncated');
            if ($ok) {
                foreach (['dependencias_criticas', 'pontos_atencao'] as $k) {
                    if (! empty($j[$k])) {
                        $sem[$k] = $j[$k];
                    }
                }
                if (! empty($j['risco_alteracao'])) {
                    $sem['risco_alteracao'] = $j['risco_alteracao'];
                }
                $blocks['deps_risco'] = 'ok';
            }
        }

        // block_status + status recomputados sobre o resultado do top-up.
        $newTechMiss = 0;
        foreach (($trace['missing'] ?? []) as $m) {
            if (in_array($m['reason'] ?? '', self::TECH_MISS, true)) {
                $newTechMiss++;
            }
        }
        $blocks['funcoes'] = empty($trace['requested'] ?? []) ? ($blocks['funcoes'] ?? 'skipped') : ($newTechMiss > 0 ? 'partial' : 'ok');
        $sem['block_status'] = $blocks;
        $sem['funcoes_trace'] = $trace;

        if (($blocks['entendimento'] ?? 'ok') !== 'ok') {
            $sem['status'] = 'partial';
            $sem['partial_reason'] = 'entendimento_' . $blocks['entendimento'];
        } elseif (($blocks['regras'] ?? 'ok') !== 'ok') {
            $sem['status'] = 'partial';
            $sem['partial_reason'] = 'regras_' . $blocks['regras'];
        } elseif (($blocks['deps_risco'] ?? 'ok') !== 'ok') {
            $sem['status'] = 'partial';
            $sem['partial_reason'] = 'deps_risco_' . $blocks['deps_risco'];
        } elseif ($newTechMiss > 0) {
            $sem['status'] = 'partial';
            $sem['partial_reason'] = 'functions_incomplete';
        } else {
            $sem['status'] = 'completed';
            $sem['partial_reason'] = null;
        }
        $sem['strategy'] = 'topup_recovery';
        $this->coverage = [
            'relevant_functions_total'    => count($trace['requested'] ?? []) ?: (int) ($existing['semantic_coverage']['relevant_functions_total'] ?? 0),
            'relevant_functions_analyzed' => count($trace['completed'] ?? []),
            'relevant_functions_cached'   => (int) ($existing['semantic_coverage']['relevant_functions_cached'] ?? 0),
            'relevant_functions_skipped'  => $newTechMiss,
        ];

        return $this->finalize($sem, $det, $diff);
    }

    /**
     * Recomputa o trace do top-up SOBRE o conjunto relevante ATUAL ($freshRequested, identidade estável),
     * evitando o missing INFLADO por nomes duplicados de fontes-classe. completed acumula (anterior +
     * novo) restrito ao conjunto atual; not_identified idem (menos os que completaram); missing = o que
     * sobrou, com o motivo técnico do top-up. Tudo por chave de identidade estável (name@line quando dup).
     */
    private function mergeTrace(array $prev, array $new, array $freshRequested): array
    {
        $reqSet = array_flip(array_map('strtolower', $freshRequested));
        $inReq = fn ($n) => isset($reqSet[strtolower((string) $n)]);

        $completed = array_values(array_unique(array_filter(
            array_merge($prev['completed'] ?? [], $new['completed'] ?? []),
            $inReq
        )));
        $done = array_flip(array_map('strtolower', $completed));

        $notId = array_values(array_unique(array_filter(
            array_merge($prev['not_identified'] ?? [], $new['not_identified'] ?? []),
            fn ($n) => $inReq($n) && ! isset($done[strtolower((string) $n)])
        )));
        $notIdSet = array_flip(array_map('strtolower', $notId));

        // motivos técnicos por nome, vindos do top-up (fallback cost_budget).
        $reasonByName = [];
        foreach ($new['missing'] ?? [] as $m) {
            if (! empty($m['name'])) {
                $reasonByName[strtolower((string) $m['name'])] = $m['reason'] ?? 'cost_budget';
            }
        }
        // missing = relevante atual que não completou nem virou not_identified.
        $missing = [];
        foreach ($freshRequested as $n) {
            $low = strtolower((string) $n);
            if (isset($done[$low]) || isset($notIdSet[$low])) {
                continue;
            }
            $missing[] = ['name' => $n, 'reason' => $reasonByName[$low] ?? 'cost_budget'];
        }
        return [
            'requested'      => array_values($freshRequested),
            'completed'      => $completed,
            'not_identified' => $notId,
            'missing'        => $missing,
            'calls'          => (int) ($prev['calls'] ?? 0) + (int) ($new['calls'] ?? 0),
        ];
    }

    /** Uma chamada de aprofundamento para um sub-lote de funções. */
    private function deepenCall(array $items, array $det, int $out): array
    {
        $d = $this->callJson($this->systemPrompt(), $this->deepenFinalidadesPrompt($items), $out);
        $j = is_array($d['json']) ? $d['json'] : [];
        $got = [];
        foreach (($j['funcoes'] ?? []) as $f) {
            if (! empty($f['name'])) {
                $got[] = ['name' => $f['name'], 'finalidade' => $this->str($f['finalidade'] ?? ''), 'confidence' => $f['confidence'] ?? null, 'evidence' => $f['evidence'] ?? []];
            }
        }
        return [$got, $j['regras_negocio'] ?? [], $j['pontos_atencao'] ?? [], $d['truncated']];
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
            // Refinamento 3 — identidade estável name@line: valida pelo nome-BASE (sem o sufixo de linha),
            // mas deduplica pelo nome COMPLETO (métodos homônimos de fonte-classe são distintos).
            $base = preg_replace('/@\d+$/', '', (string) $name);
            if (! isset($fnSet[strtolower($base)])) {
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
        $risco = $this->buildRisco($sem['risco_alteracao'] ?? [], $det, $fnSet, $tbSet, $fieldQ, $fieldBare);

        // Bloco 4.2 — QUALIDADE DOCUMENTAL (o conteúdo cobre o que o contrato exige?) é conceito
        // SEPARADO da EXECUÇÃO DA IA (status: houve truncamento?). Um doc pode ter execução 'partial'
        // (modelo bateu o teto) e ainda assim estar documentalmente COMPLETO. Não mascara truncamento:
        // se um bloco realmente perdeu conteúdo exigido, a qualidade cai para 'parcial' com o que falta.
        $docCompleteness = $this->documentaryCompleteness($entendimento, $rulesShown, $funcoes, $risco, $depCriticas, $det, (array) ($sem['block_status'] ?? []), $sem['funcoes_trace'] ?? null);

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'block_status'     => $sem['block_status'] ?? null,
            'funcoes_trace'    => $sem['funcoes_trace'] ?? null,
            'documentary_completeness' => $docCompleteness,
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

    /**
     * QUALIDADE DOCUMENTAL — o conteúdo entrega as camadas exigidas? (conceito à parte do status
     * de execução da IA). 'completa' se todas as camadas essenciais têm conteúdo real; senão
     * 'parcial' com a lista do que falta. NÃO mascara truncamento: se um bloco perdeu conteúdo
     * exigido (ex.: objetivo indeterminado, sem função explicada), a qualidade cai.
     */
    /**
     * Bloco 4.2.1-C — QUALIDADE DOCUMENTAL por APLICABILIDADE, 4 estados por dimensão:
     *   present         — evidência existe e foi documentada;
     *   not_applicable  — os fatos indicam que a dimensão não se aplica (resultado VÁLIDO);
     *   not_identified  — poderia existir, mas os fatos não permitem concluir (resultado VÁLIDO);
     *   missing         — deveria ter sido produzida, mas houve FALHA/TRUNCAMENTO (problema).
     * level = 'parcial' SOMENTE se houver algum 'missing'. not_applicable/not_identified NÃO penalizam.
     * Condicional/query NÃO é, por si, evidência de regra de negócio (evita falso positivo).
     */
    private function documentaryCompleteness(array $ent, array $regras, array $funcoes, array $risco, array $depCriticas, array $det, array $blocks, ?array $trace): array
    {
        $dim = [];
        $bOk = fn (string $b) => ($blocks[$b] ?? 'ok') === 'ok'; // bloco produziu sem truncar?

        // objetivo (bloco entendimento)
        $obj = $this->str($ent['objetivo'] ?? null);
        $dim['objetivo'] = ($obj && $obj !== self::UNDETERMINED) ? 'present'
            : (! $bOk('entendimento') ? 'missing' : 'not_identified');

        // o_que_faz (bloco entendimento)
        $dim['o_que_faz'] = ! empty($ent['o_que_faz']) ? 'present'
            : (! $bOk('entendimento') ? 'missing' : 'not_identified');

        // finalidades de funções — usa o TRACE. Ponto 5/7: só FALHA TÉCNICA (missing) penaliza; funções
        // analisadas sem evidência (not_identified) são resultado HONESTO e NÃO viram 'missing'.
        $reqN  = is_array($trace) ? count($trace['requested'] ?? []) : (int) ($this->coverage['relevant_functions_total'] ?? 0);
        $techMissN = 0;
        if (is_array($trace)) {
            foreach (($trace['missing'] ?? []) as $m) {
                if (in_array($m['reason'] ?? '', self::TECH_MISS, true)) {
                    $techMissN++;
                }
            }
        }
        $completedN = is_array($trace) ? count($trace['completed'] ?? []) : count($funcoes);
        if ($reqN === 0) {
            $dim['finalidades_funcoes'] = empty($funcoes) ? 'not_applicable' : 'present';
        } elseif ($techMissN > 0) {
            $dim['finalidades_funcoes'] = 'missing'; // recuperável via top-up seletivo
        } elseif ($completedN > 0) {
            $dim['finalidades_funcoes'] = 'present';  // documentou o que tinha evidência (resto not_identified)
        } else {
            $dim['finalidades_funcoes'] = 'not_identified'; // todas analisadas, nenhuma determinável
        }

        // regras — condicional/query NÃO conta como regra. Aplicabilidade: só ESCRITA de dados é sinal
        // de que regra de negócio PODE existir (não_identified); leitura pura ⇒ not_applicable.
        $hasWrite = false;
        foreach (($det['tables'] ?? []) as $t) {
            if (array_intersect(['UPDATE', 'INSERT', 'DELETE'], (array) ($t['access'] ?? []))) {
                $hasWrite = true;
                break;
            }
        }
        if (! empty($regras)) {
            $dim['regras_negocio'] = 'present';
        } elseif (! $bOk('regras')) {
            $dim['regras_negocio'] = 'missing';
        } else {
            $dim['regras_negocio'] = $hasWrite ? 'not_identified' : 'not_applicable';
        }

        // dependências — aplicável se há dep externa nos fatos (user_calls/integrações/custom).
        $depSet = $this->knownDependencySet($det, array_flip(array_map('strtolower', array_column($det['functions'] ?? [], 'name'))));
        if (! empty($depCriticas)) {
            $dim['dependencias'] = 'present';
        } elseif (! $bOk('deps_risco')) {
            $dim['dependencias'] = 'missing';
        } else {
            $dim['dependencias'] = ! empty($depSet) ? 'not_identified' : 'not_applicable';
        }

        // integrações — aplicável só se o determinístico registrou integração externa.
        $hasIntegr = ! empty($det['external_integrations'] ?? []);
        $dim['integracoes'] = $hasIntegr ? 'present' : 'not_applicable';

        // risco — SEMPRE avaliado (determinístico). 0 fatores = 'nenhum fator relevante' (not_applicable).
        $dim['risco'] = ! empty($risco['fatores'] ?? []) ? 'present' : 'not_applicable';

        $missing = array_keys(array_filter($dim, fn ($s) => $s === 'missing'));

        return [
            'level'      => empty($missing) ? 'completa' : 'parcial',
            'dimensions' => $dim,
            'missing'    => array_values($missing),
        ];
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
            // Aceita se casar no depSet (user_calls/integrações/chamadas externas, com/sem prefixo U_),
            // se for função do fonte, OU se for uma TABELA (dependência de dados crítica — spec inclui
            // "tabela crítica"). Só rejeita nome que não existe em NADA do determinístico.
            $keys = [strtolower($nome), strtolower(ltrim($nome, 'uU_')), 'u_' . strtolower(ltrim($nome, 'uU_'))];
            $known = isset($tbSet[strtoupper($nome)]);
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
            // classifica o "kind" p/ o leitor: tabela vs função/rotina.
            $kind = isset($tbSet[strtoupper($nome)]) ? 'tabela' : 'rotina';
            $out[] = [
                'nome' => $nome,
                'kind' => $kind,
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
    /**
     * risco_alteracao — fatores DETERMINÍSTICOS (do índice; sempre com evidência real) + resumo da IA.
     * Não depende da IA acertar a evidência: os fatores de risco são fatos do próprio código
     * (tamanho, escrita, nº de tabelas, isolamento, integrações, dependências customizadas).
     */
    private function buildRisco(array $raw, array $det, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare): array
    {
        $fatores = [];

        // (complexidade) maior função
        $maxLines = 0;
        $maxFn = '';
        foreach (($det['functions'] ?? []) as $f) {
            $l = (int) ($f['end_line'] ?? 0) - (int) ($f['start_line'] ?? 0);
            if ($l > $maxLines) {
                $maxLines = $l;
                $maxFn = (string) ($f['name'] ?? '');
            }
        }
        if ($maxLines >= 300 && $maxFn !== '') {
            $fatores[] = ['tipo' => 'complexidade', 'descricao' => "Função {$maxFn} extensa (~{$maxLines} linhas) — maior superfície de alteração.", 'evidence' => [['type' => 'function', 'name' => $maxFn]]];
        }

        // (escrita) tabelas gravadas
        $writes = [];
        $allTables = [];
        foreach (($det['tables'] ?? []) as $t) {
            $name = (string) ($t['table'] ?? $t['alias'] ?? '');
            if ($name === '') {
                continue;
            }
            $allTables[strtoupper($name)] = true;
            if (array_intersect(['UPDATE', 'INSERT', 'DELETE'], (array) ($t['access'] ?? []))) {
                $writes[strtoupper($name)] = true;
            }
        }
        if (! empty($writes)) {
            $w = array_keys($writes);
            $fatores[] = ['tipo' => 'escrita', 'descricao' => 'Grava em ' . count($w) . ' tabela(s): ' . implode(', ', array_slice($w, 0, 8)) . '.', 'evidence' => array_map(fn ($t) => ['type' => 'table', 'table' => $t], array_slice($w, 0, 3))];
        }

        // (tabela) volume de tabelas acessadas
        if (count($allTables) >= 10) {
            $fatores[] = ['tipo' => 'tabela', 'descricao' => 'Acessa ' . count($allTables) . ' tabelas distintas — amplo acoplamento a dados.', 'evidence' => array_map(fn ($t) => ['type' => 'table', 'table' => $t], array_slice(array_keys($allTables), 0, 3))];
        }

        // (integracao) integrações externas
        $integr = (array) ($det['external_integrations'] ?? []);
        if (! empty($integr)) {
            $fatores[] = ['tipo' => 'integracao', 'descricao' => count($integr) . ' integração(ões) externa(s) — alteração pode afetar sistemas externos.', 'evidence' => []];
        }

        // (dependencia) funções customizadas externas
        $custom = array_values(array_filter(array_map(fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : (string) $c, (array) ($det['dependencies']['custom_external_functions'] ?? []))));
        if (! empty($custom)) {
            $fatores[] = ['tipo' => 'dependencia', 'descricao' => 'Depende de customização externa: ' . implode(', ', array_slice($custom, 0, 6)) . '.', 'evidence' => []];
        }

        // enriquecimento OPCIONAL da IA: só fatores com evidência válida que ainda não cobrimos.
        $tipos = ['dependencia', 'escrita', 'tabela', 'caller', 'integracao', 'complexidade'];
        $have = array_flip(array_column($fatores, 'tipo'));
        foreach ((array) ($raw['fatores'] ?? []) as $f) {
            $desc = $this->str($f['descricao'] ?? null);
            $tipo = strtolower((string) ($f['tipo'] ?? ''));
            if ($desc === null || $desc === '' || ! in_array($tipo, $tipos, true) || isset($have[$tipo])) {
                continue;
            }
            $ev = $this->validateEvidence($f['evidence'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare);
            if (empty($ev)) {
                continue; // sem evidência → descarta (não rejeita ruidosamente; determinístico já cobre)
            }
            $fatores[] = ['tipo' => $tipo, 'descricao' => $desc, 'evidence' => $ev];
        }

        return [
            'resumo' => $this->str($raw['resumo'] ?? null) ?: ($fatores ? 'Fatores de risco derivados do código (determinísticos).' : self::UNDETERMINED),
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
        $u .= "\n\nProduza SOMENTE o Entendimento Funcional (PROPÓSITO de negócio, não a mecânica). "
            . 'MUITO ENXUTO: evidence só {type,name/table/field}, no MÁX. 1 por item; descrições curtas. JSON:\n'
            . 'entendimento_funcional{'
            . 'uma_frase{texto,confidence,evidence[≤1]}, '
            . 'objetivo (2–3 frases curtas: o que resolve, responsabilidade, resultado), '
            . 'quando_usado (1 frase; indeterminável ⇒ "' . self::UNDETERMINED . '"), '
            . 'processo_modulo{processo,modulo,confidence,evidence[≤1]} (módulo POR EVIDÊNCIA, não pelo nome do arquivo), '
            . 'entradas_principais[≤4 {tipo,nome,descricao(≤10 palavras),evidence[≤1]}], '
            . 'saidas_principais[≤4 {tipo,nome,descricao(≤10 palavras),evidence[≤1]}], '
            . 'o_que_faz[≤7 {passo(≤14 palavras),evidence[≤1]}] (sequência FUNCIONAL, não a lista de chamadas)}'
            . ', fluxo[≤6 strings curtas]. Ordene os campos EXATAMENTE nessa sequência. Sem evidência ⇒ "' . self::UNDETERMINED . '".';
        return $u;
    }

    /** BLOCO 2 — SÓ Regras de Negócio. Independente. */
    private function regrasUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        $u .= "\n\nSEJA ENXUTO (≤2 evidence por item). Produza JSON {"
            . 'regras_negocio[≤10 {id,titulo(≤8 palavras),descricao(≤20 palavras),condicao,efeito,confidence,evidence[≤2 {type,name?,table?,field?,line_start?,line_end?}]}], '
            . 'change_summary}. Cada regra EXIGE evidence dos fatos; sem evidência ⇒ omita.';
        return $u;
    }

    /** BLOCO 3 — SÓ Dependências Críticas + Risco de Alteração + Pontos. Independente. */
    private function depRiscoUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        // risco_alteracao PRIMEIRO (é pequeno e valioso) → sobrevive ao salvamento se a cauda truncar.
        $u .= "\n\nSEJA ENXUTO (≤1 evidence por item; descrições curtas). Produza JSON {"
            . 'risco_alteracao{resumo(≤20 palavras),fatores[≤5 {tipo(dependencia|escrita|tabela|caller|integracao|complexidade),descricao(≤12 palavras),evidence[≤1]}]}, '
            . 'dependencias_criticas[≤6 {nome,como_participa(≤12 palavras),impacto_se_indisponivel(≤10 palavras),onde_chamada,confidence,evidence[≤1]}] (SÓ o que interfere materialmente; NÃO listar todo include/framework), '
            . 'pontos_atencao[≤5 {interpretation(≤15 palavras),categoria?,severity?,recommendation?,confidence,evidence[≤1]}]}. '
            . 'Cada item EXIGE evidence dos fatos (nome de dependência DEVE existir nos fatos); sem evidência ⇒ omita.';
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

    /** Custo real acumulado até agora (US$), p/ a guarda de orçamento por fonte no aprofundamento. */
    private function currentCostUsd(): float
    {
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        // acumulado por FONTE = base (execuções anteriores) + gasto desta execução.
        return $this->costBaseUsd + ($this->usage['input_tokens'] ?? 0) / 1e6 * $ci + ($this->usage['output_tokens'] ?? 0) / 1e6 * $co;
    }

    /** Custo estimado de UMA chamada a partir do payload REAL (sem premissa fixa de 12k) — Ponto 1. */
    private function estimateCallUsd(string $user, int $out, bool $code): float
    {
        $cpt = $code ? (float) config('services.source_doc_ai.chars_per_token_code', 1.6) : (float) config('services.source_doc_ai.chars_per_token_text', 3.2);
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $in = ceil((mb_strlen($this->systemPrompt()) + mb_strlen($user)) / $cpt);
        return $in / 1e6 * $ci + $out / 1e6 * $co;
    }

    private function usageBlock(): array
    {
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $thisRun = $this->usage['input_tokens'] / 1e6 * $ci + $this->usage['output_tokens'] / 1e6 * $co;
        $extra = [
            'duration_ms'    => (int) ((microtime(true) - $this->t0) * 1000),
            'actual_cost_usd' => round($this->costBaseUsd + $thisRun, 4), // acumulado por fonte (≤ hard_limit)
            'hard_limit_usd' => (float) config('services.source_doc_ai.hard_limit_usd', 0.30),
        ];
        // Ponto 4 — top-up registra custo/chamadas ADICIONAIS separadamente do acumulado.
        if ($this->costBaseUsd > 0.0) {
            $extra['base_cost_usd']  = round($this->costBaseUsd, 4);
            $extra['topup_cost_usd'] = round($thisRun, 4);
            $extra['topup_calls']    = (int) ($this->usage['calls'] ?? 0);
        }
        return $this->usage + $extra;
    }

    private function resetState(): void
    {
        $this->t0 = microtime(true);
        $this->usage = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'cache_hits' => 0, 'cache_misses' => 0, 'estimated_before_usd' => 0.0];
        $this->rejected = [];
        $this->coverage = [];
        $this->costBaseUsd = 0.0;
    }

    private function skeleton(string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION, 'status' => $status, 'strategy' => null, 'provider' => $this->ai->name(), 'model' => $this->ai->model(),
            // Bloco 4.2 — Entendimento Funcional (novo topo).
            'documentary_completeness' => ['level' => 'parcial', 'missing' => ['objetivo', 'o_que_faz', 'regras_negocio', 'finalidades_funcoes', 'fatores_risco']],
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
        if ($a === false) {
            return null;
        }
        // 1) tentativa normal (do 1º { ao último })
        $b = strrpos($text, '}');
        if ($b !== false && $b > $a) {
            $j = json_decode(substr($text, $a, $b - $a + 1), true);
            if (is_array($j)) {
                return $j;
            }
        }
        // 2) SALVAMENTO de JSON truncado (stop=max_tokens): fecha strings/estruturas abertas
        // e descarta o último par chave/valor incompleto. Preserva os campos JÁ completos.
        return $this->repairTruncatedJson(substr($text, $a));
    }

    /** Repara JSON truncado balanceando aspas/colchetes/chaves e cortando o resíduo incompleto. */
    private function repairTruncatedJson(string $s): ?array
    {
        $stack = [];
        $inStr = false;
        $esc = false;
        $lastSafe = -1; // posição logo após um valor completo em profundidade >= 1
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c === '{' ? '}' : ']';
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
                if (count($stack) >= 1) {
                    $lastSafe = $i;
                }
            } elseif ($c === ',' && count($stack) >= 1) {
                $lastSafe = $i - 1; // corta ANTES da vírgula (último elemento completo)
            }
        }
        if ($lastSafe < 0) {
            return null;
        }
        // mantém até o último ponto seguro e fecha as estruturas que ficaram abertas.
        $frag = substr($s, 0, $lastSafe + 1);
        // recomputa a pilha aberta no fragmento cortado.
        $stack = [];
        $inStr = false;
        $esc = false;
        for ($i = 0, $n = strlen($frag); $i < $n; $i++) {
            $c = $frag[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c === '{' ? '}' : ']';
            } elseif ($c === '}' || $c === ']') {
                array_pop($stack);
            }
        }
        $frag .= implode('', array_reverse($stack));
        $j = json_decode($frag, true);
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
