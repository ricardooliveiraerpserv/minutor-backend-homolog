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
    // P0 — trilha por chamada: estimated / reserved / actual / ratio (reconcilia estimador com o real).
    /** @var list<array{block:string,estimated:float,reserved:float,actual:float,ratio:?float,skipped:bool}> */
    private array $callLog = [];
    // Fase 2 — id do source_doc DEPENDENTE (origem), p/ validar evidência cross-source contra o grafo/edges.
    private ?int $contextDocId = null;
    // Fase 3 — contexto cross-source BOUNDED (materializado pelo pipeline) injetado no prompt; fingerprint
    // determinístico do contexto usado (entra na chave de cache); trilha de evidence C aceita/rejeitada.
    private array $crossSource = [];
    private string $contextFingerprint = '';
    private array $crossSourceMeta = []; // saída completa do builder (resolved/telemetry), p/ proveniência real
    /** @var list<array> */ private array $xsrcAccepted = [];
    /** @var list<array> */ private array $xsrcRejected = [];
    // Fase 3 (Gate 3B) — contabilidade da injeção EFETIVA: contada no choke-point call() pelo marcador do
    // bloco, cobrindo TODA rota (simple/multi-bloco/deepen/incremental/topUp) — presente ou futura.
    private int $xsrcInjectedCalls = 0;
    private int $xsrcInjectedChars = 0;
    private const XSRC_MARKER = 'CONTEXTO CROSS-SOURCE (AUXILIAR';
    // v4 — candidatos a REGRA MATERIAL detectados DETERMINISTICAMENTE (sinais MV_/autorização/limite/status);
    // não são regras prontas — a IA investiga cada um. Evita overfit (não ensina parâmetro específico).
    private array $ruleCandidates = [];
    // v5 — trechos de CÓDIGO dos candidatos (p/ o bloco de regras CONFIRMAR regra code-gated em fonte GRANDE,
    // onde o código inteiro não cabe inline). Bounded: só as linhas ao redor dos sinais detectados.
    private string $ruleCandidateCode = '';
    // GMUD — entendimento (objetivo/uma_frase/passos) do V0 ficou stale por remoção → precisa RE-EXPRESSÃO.
    private bool $gmudEntendimentoStale = false;
    // v4 — custo dos PASSOS anteriores (ex.: initial), informativo. NÃO entra no orçamento deste passo:
    // o hard-limit US$ 0,30 é POR PASSO SEMÂNTICO (initial normalmente basta; top-up é excepcional).
    private float $stepBaseCostUsd = 0.0;
    // motivos de missing considerados FALHA TÉCNICA (recuperáveis por top-up) — o resto é not_identified.
    private const TECH_MISS = ['cost_budget', 'truncated_unrecovered', 'deepen_call_budget', 'simple_truncated'];
    // v3 GAP 4 — processo/módulo por EVIDÊNCIA (não adivinhar; não NI quando há sinais suficientes).
    private const MODULE_HINT = 'Para processo_modulo: infira por EVIDÊNCIA — tabelas típicas (SA1/SA2/SD2/SF2=Faturamento/Vendas; '
        . 'SE1/SE2/SEE/SA6=Financeiro; SB1/SB2/SC5/SC6/SC7=Estoque/Compras; SRA/SPI/SP9/SPB=RH/Ponto; SUA/SUB=Televendas/TMK; '
        . 'Z*/customizadas=conforme uso), operações SQL, caminho do arquivo/repositório, funções-padrão, includes e contexto cross-source. '
        . 'Preencha quando houver sinais suficientes (cite a evidência); só use "' . self::UNDETERMINED . '" quando REALMENTE ambíguo. ';
    // v3 GAP 2 — regras por COBERTURA MATERIAL (semântica), não quantidade/redação.
    private const RULES_COVERAGE = 'COBERTURA MATERIAL obrigatória: não omita regra OBSERVÁVEL — cubra, quando existir nos fatos/código, '
        . 'autorização/permissão (parâmetros MV_ de usuário, checagens de usuário), limites/tetos (MV_ de percentual/valor), '
        . 'bloqueios por status, cálculos, validações e mudanças de estado. Prefira perder redação a perder uma regra material. ';

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
        $this->contextDocId = isset($ctx['source_doc_id']) ? (int) $ctx['source_doc_id'] : null; // Fase 2 — cross-source
        // Fase 3 — contexto cross-source já resolvido/materializado DETERMINISTICAMENTE pelo pipeline. A IA
        // recebe SÓ isto (não navega). Fingerprint entra na chave de cache (blob A + ctx B ≠ blob A + ctx C).
        $this->loadCrossSource($ctx);
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
        $this->ruleCandidates = $this->detectCriticalRuleCandidates($det, $maskedCode); // v4 — candidatos p/ o bloco de regras
        $this->ruleCandidateCode = $this->criticalRuleCandidateCode($det, $maskedCode); // v5 — trechos de código dos candidatos
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

        // v5 — fonte grande (inlineCode='') → o bloco de regras recebe os TRECHOS de código dos candidatos
        // (autorização/teto/bloqueio) p/ CONFIRMAR regras code-gated; fonte pequena usa o código inteiro.
        $regrasCode = $inlineCode !== '' ? $inlineCode : $this->ruleCandidateCode;
        $entUser     = $this->entendimentoUserPrompt($compact, $diff, $entCode);
        $regrasUser  = $this->regrasUserPrompt($compact, $diff, $regrasCode);
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
        // Reserva CONSERVADORA (P0) de regras p/ as funções não a faminta; deps usa a folga + top-up.
        $reserveReg = $this->reservedCallUsd($regrasUser, $regrasOut, $regrasCode !== '');

        // ── BLOCO 1 — Entendimento Funcional (PRIORIDADE MÁXIMA; roda 1º; protegido; guarda P0) ──
        $g1 = $this->guardedCallJson($entUser, $entOut, $entCode !== '', 'entendimento');
        $entOk = false;
        if ($g1 === null) {
            $blocks['entendimento'] = 'skipped_budget';
        } else {
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
        }

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

        // ── BLOCO 3 — Regras de Negócio (após funções; reserva protegida; guarda P0) ──
        $regrasOk = false;
        $g2 = $this->guardedCallJson($regrasUser, $regrasOut, $regrasCode !== '', 'regras');
        if ($g2 === null) {
            $blocks['regras'] = 'skipped_budget'; // sem reserva segura → deixa p/ top-up (P2)
        } else {
            $j2 = is_array($g2['json']) ? $g2['json'] : [];
            if (! empty($j2['regras_negocio'])) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $j2['regras_negocio']);
            }
            if (! empty($j2['change_summary'])) {
                $sem['change_summary'] = $j2['change_summary'];
            }
            $regrasOk = ! $g2['truncated'] && $j2 !== [];
            $blocks['regras'] = $regrasOk ? 'ok' : ($g2['raw_truncated'] ? 'truncated' : 'invalid_json');
        }

        // ── BLOCO 4 — Dependências Críticas + Risco + Pontos (após regras; folga restante; guarda P0) ──
        // P2 — se não houver orçamento SEGURO para deps, NÃO executa (fica p/ top-up); risco é determinístico.
        $depRiscoOk = false;
        $g3 = $this->guardedCallJson($depRiscoUser, $depRiscoOut, $inlineCode !== '', 'deps_risco');
        if ($g3 === null) {
            $blocks['deps_risco'] = 'skipped_budget';
        } else {
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
        }

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
            [$ok, $j] = $this->retryBlockCall($regrasUser, $regrasOut, $regrasCode !== '', $hardLimit, ($blocks['regras'] ?? '') === 'truncated');
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

        // ── GAP 2 — REDE DE SEGURANÇA de dimensão crítica ──────────────────────────────────────────
        // Um bloco crítico VAZIO por truncamento/skip/invalid NÃO pode virar 0 silencioso quando ainda há
        // orçamento seguro: última tentativa com output REDUZIDO (floor menor que o retry normal). Sem
        // orçamento ⇒ marca missing_cost_budget (honesto, recuperável por top-up). NÃO muda a ordem/reserva
        // da Política C — só recupera o que o fechamento deixaria zerado.
        if (! $entOk && empty($sem['entendimento_funcional'])) {
            [$st, $j] = $this->criticalRecover($entUser, $entCode !== '', $hardLimit);
            if ($st === 'ok' && ! empty($j['entendimento_funcional'])) {
                $sem['entendimento_funcional'] = $j['entendimento_funcional'];
                $sem['objetivo'] = $j['entendimento_funcional']['objetivo'] ?? ($sem['objetivo'] ?? null);
                $entOk = true;
                $blocks['entendimento'] = 'recovered';
            } elseif ($st === 'no_budget') {
                $blocks['entendimento'] = 'missing_cost_budget';
            } // 'failed' → preserva o block_status original (invalid_json/truncated) — não é problema de orçamento
        }
        if (! $regrasOk && empty($sem['regras_negocio'])) {
            // v3 GAP 3 — recuperação FOCADA e barata (só facts) p/ caber no saldo real remanescente.
            [$st, $j] = $this->criticalRecover($this->regrasFocusedPrompt($compact), false, $hardLimit);
            if ($st === 'ok' && ! empty($j['regras_negocio'])) {
                $sem['regras_negocio'] = array_merge($sem['regras_negocio'] ?? [], $j['regras_negocio']);
                $regrasOk = true;
                $blocks['regras'] = 'recovered';
            } elseif ($st === 'no_budget' && $this->hasBusinessOps($det)) {
                // só é "perda por orçamento" se HAVIA operação de negócio; fonte sem regra real fica legítimo.
                $blocks['regras'] = 'missing_cost_budget';
            } // 'failed' → preserva block_status original
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
        $this->ruleCandidates = $this->detectCriticalRuleCandidates($det, $maskedCode); // v4 — candidatos p/ regras
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
        // v3 GAP 1 — fallback p/ multi-bloco SEMPRE que uma DIMENSÃO CRÍTICA truncar/faltar, INDEPENDENTE de
        //  hasBusinessOps (o determinístico não reconhece toda semântica de negócio; não pode impedir recuperação).
        //  Fonte pequeno tem folga enorme até US$ 0,30 — melhor gastar que documentar pobre. hasBusinessOps deixa
        //  de ser gate; segue só como sinal (o fonte sem regra real produz regras=[] no multi-bloco, legítimo).
        $incompleto = empty($j)
            || $g['truncated']                          // qualquer truncamento de bloco crítico
            || empty($j['entendimento_funcional'])      // entendimento ausente
            || empty($j['regras_negocio']);             // regras ausente → recupera na multi-bloco
        if ($incompleto) {
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
        // GAP 1 — simple_complete_single_call: 1 chamada, mas o contrato EXIGE o conjunto semântico
        // ESSENCIAL COMPLETO (não uma versão resumida). Fonte pequeno cabe folgado no output; a economia
        // vem de ser 1 chamada, não de documentar menos. NÃO inventar — só omitir se realmente sem base.
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($inlineCode !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $inlineCode;
        }
        $u .= $this->crossSourceBlock(); // Fase 3 — rota simples produz TODAS as afirmações numa chamada
        $u .= "\n\nFonte pequeno — documente o ESSENCIAL COMPLETO (PROPÓSITO de negócio, não a mecânica), "
            . 'com evidence + confidence em CADA campo interpretativo. Extraia o que EXISTE nos fatos/código; '
            . 'só use "' . self::UNDETERMINED . '" quando realmente NÃO houver base (não use como atalho). '
            . 'IMPORTANTE: se o código tem operação de negócio (grava/atualiza tabela, consulta com filtro, '
            . 'decisão condicional, integração), ela DEVE virar regra_negocio com condicao+efeito+evidence — '
            . 'NÃO deixe regras_negocio vazio quando há lógica real. Produza JSON {'
            . 'entendimento_funcional{uma_frase{texto,confidence,evidence[≤2]}, objetivo (2–3 frases: o que resolve, responsabilidade, resultado), '
            . 'quando_usado, processo_modulo{processo,modulo,confidence,evidence[≤2]} (módulo POR EVIDÊNCIA, não pelo nome do arquivo), '
            . 'entradas_principais[≤5 {tipo,nome,descricao,evidence[≤1]}], saidas_principais[≤5 {tipo,nome,descricao,evidence[≤1]}], '
            . 'o_que_faz[≤8 {passo(sequência FUNCIONAL),evidence[≤1]}]}, '
            . 'funcoes[{name,finalidade(responsabilidade no processo),confidence,evidence[≤1]}] (TODAS as dos fatos), '
            . 'regras_negocio[{id,titulo,descricao,condicao,efeito,confidence,evidence[≤2]}] (toda operação/decisão material vira regra; sem base ⇒ omita, mas NÃO ignore lógica existente), '
            . 'dependencias_criticas[{nome,como_participa,impacto_se_indisponivel,onde_chamada,confidence,evidence[≤1]}] (o que interfere materialmente), '
            . 'risco_alteracao{resumo,fatores[≤5 {tipo,descricao,evidence[≤1]}]}, pontos_atencao[≤5 {interpretation,severity?,recommendation?,confidence,evidence[≤1]}], change_summary}. '
            . 'Cada item EXIGE evidence dos fatos; sem evidência ⇒ omita o item (não invente). '
            . self::RULES_COVERAGE . self::MODULE_HINT // v3 GAP 2+4
            . $this->ruleCandidatesBlock(); // v4 — candidatos determinísticos a investigar
        return $u;
    }

    /** GAP 1 — sinal DETERMINÍSTICO de que a fonte tem operação de negócio (p/ detectar regras=0 indevido). */
    private function hasBusinessOps(array $det): bool
    {
        if (! empty($det['queries']) || ! empty($det['write_effects']) || ! empty($det['effects'])) {
            return true;
        }
        foreach (($det['tables'] ?? []) as $t) {
            $acc = strtoupper(json_encode($t['access'] ?? []));
            if (str_contains($acc, 'WRITE') || str_contains($acc, 'UPDATE') || str_contains($acc, 'INSERT') || str_contains($acc, 'DELETE')) {
                return true;
            }
            if (! empty($t['write_fields'])) {
                return true;
            }
        }
        return false;
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
        // GMUD — Impact Resolver: fatos removidos (claims stale) + fatos ADICIONADOS (candidatos a rules_add).
        $removed = $this->gmudRemovedTokens($diff);
        $added = $this->gmudAddedTokens($diff);
        $stale = $this->staleRulesByGmud($prev, $removed);
        $changed = $this->changedFunctionNames($diff);
        if (empty($changed)) {
            // mudança estrutural sem função alterada (ex.: só tabela) → preserva prev + INVALIDA dependentes.
            $prev['status'] = 'completed';
            $prev['strategy'] = 'incremental_diff';
            $prev['resumo_alteracao'] = $this->deterministicChangeSummary($diff) ?? ($prev['resumo_alteracao'] ?? self::UNKNOWN);
            $prev = $this->applyGmudInvalidation($prev, $removed); // patrimônio dependente do removido não sobrevive por herança
            $this->coverage = ['relevant_functions_total' => 0, 'relevant_functions_analyzed' => 0, 'relevant_functions_cached' => 0, 'relevant_functions_skipped' => 0];
            return $prev;
        }
        $changedFns = array_values(array_filter($det['functions'] ?? [], fn ($f) => in_array(strtolower($f['name'] ?? ''), array_map('strtolower', $changed), true)));
        $lines = explode("\n", $maskedCode);
        $withCode = array_map(fn ($f) => ['name' => $f['name'], 'facts' => $this->fnFact($f), 'code' => $this->codeSlice($lines, $f)], array_slice($changedFns, 0, (int) config('services.source_doc_ai.max_relevant_functions', 12)));

        $outBudget = (int) config('services.source_doc_ai.max_output_tokens_per_call', 2000);
        $user = $this->incrementalUserPrompt($prev, $diff, $withCode, $stale, $added);
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
        // GMUD — invalidação DETERMINÍSTICA pós-merge: claim que ainda cita fato removido é stale → podada
        // (a IA teve a chance de re-expressar sem o token; herança do V0 não preserva o obsoleto).
        $merged = $this->applyGmudInvalidation($merged, $removed);
        if ($this->gmudEntendimentoStale) {
            // O Entendimento V0 citava fato removido → RE-EXPRESSA sobre o V1 (não pode ser herdado stale).
            $rel = $this->selectRelevant($det, $diff, (int) config('services.source_doc_ai.max_relevant_functions', 12));
            $compact = $this->buildCompactFacts($det, $rel, $diff);
            $entCode = mb_strlen($maskedCode) <= (int) config('services.source_doc_ai.inline_code_max_chars', 8000) ? $maskedCode : $this->entrypointCode($det, $maskedCode);
            $entOut = (int) config('services.source_doc_ai.max_output_tokens_entendimento', 2600);
            $g = $this->guardedCallJson($this->entendimentoUserPrompt($compact, null, $entCode), $entOut, $entCode !== '', 'gmud_entendimento');
            if ($g !== null && ! empty($g['json']['entendimento_funcional'])) {
                $merged['entendimento_funcional'] = $g['json']['entendimento_funcional'];
                $merged['objetivo'] = $g['json']['entendimento_funcional']['objetivo'] ?? ($merged['objetivo'] ?? null);
                if (! empty($g['json']['fluxo'])) {
                    $merged['fluxo'] = $g['json']['fluxo'];
                }
            }
        }
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
            $reserve = $this->reservedCallUsd($this->deepenFinalidadesPrompt($sub), $this->deepenOutFor($n, $cap), true); // P0 — reserva conservadora
            if ($this->currentCostUsd() + $reserve <= $hardLimit) {
                return $n;
            }
            $n = intdiv($n, 2); // 4 → 2 → 1
        }
        return 0;
    }

    /** Refinamento 1 + P0 — tokens de SAÍDA que cabem na folga, com RESERVA CONSERVADORA (fator). */
    private function affordableOutTokens(string $user, bool $code, float $hardLimit): int
    {
        $cpt = $code ? (float) config('services.source_doc_ai.chars_per_token_code', 1.6) : (float) config('services.source_doc_ai.chars_per_token_text', 3.2);
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $factor = (float) config('services.source_doc_ai.cost_reserve_factor', 1.9);
        $inTok = ceil((mb_strlen($this->systemPrompt()) + mb_strlen($user)) / $cpt);
        // reserved = (inCost + outCost) × fator + 0,005 ≤ hard_limit − custo_acumulado
        $room = $hardLimit - $this->currentCostUsd() - 0.005;
        $outCost = $room / max($factor, 0.001) - ($inTok / 1e6 * $ci);
        if ($outCost <= 0) {
            return 0;
        }
        return (int) floor($outCost * 1e6 / max($co, 1e-9));
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
        // passa pela guarda central P0 (reserva conservadora + log + reconciliação).
        $g = $this->guardedCallJson($user, $out, $code, 'retry', $hardLimit);
        if ($g === null) {
            return [false, []];
        }
        $j = is_array($g['json']) ? $g['json'] : [];
        return [! $g['truncated'] && $j !== [], $j];
    }

    /**
     * GAP 2 — ÚLTIMA recuperação de dimensão crítica que ficaria ZERADA. Usa um floor de output MENOR que
     * o retry normal (min_out) para caber na folga restante e trazer AO MENOS o conteúdo essencial da
     * dimensão. Retorna [false,[]] se não houver orçamento seguro (o caller marca missing_cost_budget).
     * Passa pela guarda P0 (nunca ultrapassa o hard-limit).
     * @return array{0:bool,1:array}
     */
    private function criticalRecover(string $user, bool $code, float $hardLimit): array
    {
        $aff = $this->affordableOutTokens($user, $code, $hardLimit);
        $floor = (int) config('services.source_doc_ai.critical_recover_min_out', 700);
        if ($aff < $floor) {
            return ['no_budget', []]; // sem orçamento seguro → caller marca cost_budget (honesto)
        }
        $g = $this->guardedCallJson($user, min($aff, 1600), $code, 'critical_recover', $hardLimit);
        if ($g === null) {
            return ['no_budget', []]; // guarda P0 recusou por orçamento
        }
        $j = is_array($g['json']) ? $g['json'] : [];
        // 'failed' = tentou mas a IA veio vazia/inválida (NÃO é problema de orçamento → não mislabel).
        return [$j !== [] ? 'ok' : 'failed', $j];
    }

    /**
     * Ponto 4 — TOP-UP / RECOVERY: enriquece um semantic_json EXISTENTE sem refazer do zero.
     * Reexecuta SÓ (a) blocos quebrados e (b) funções em funcoes_trace.missing por FALHA TÉCNICA,
     * aproveitando o function cache. Custo/chamadas adicionais entram no acumulado por fonte (≤ US$ 0,30)
     * e são registrados separadamente (usage.topup_*). Preserva finalidades/blocos já válidos.
     * Caminho ESPECÍFICO de enriquecimento — não é o reprocesso genérico.
     */
    public function topUp(array $existing, array $det, string $maskedCode, ?array $diff = null, array $ctx = []): array
    {
        $this->resetState();
        // Fase 3 — top-up pode RE-PRODUZIR blocos/finalidades dependentes de contexto externo → carrega o
        // mesmo contexto cross-source do initial (senão o retry/deepening sairia sem o contexto).
        $this->loadCrossSource($ctx);
        $this->ruleCandidates = $this->detectCriticalRuleCandidates($det, $maskedCode); // v4 — candidatos p/ retry de regras
        $this->ruleCandidateCode = $this->criticalRuleCandidateCode($det, $maskedCode); // v5 — código dos candidatos
        // v4 — TETO PRÓPRIO POR PASSO: o top-up recebe US$ 0,30 FRESCOS (costBaseUsd=0), não o acumulado do
        // initial. O custo do initial fica só como informação (stepBaseCostUsd). "US$ 0,30 por passo semântico".
        $this->stepBaseCostUsd = (float) (($existing['usage']['actual_cost_usd'] ?? $existing['usage']['total_cost_usd'] ?? 0.0));
        $this->costBaseUsd = 0.0;
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
        $regrasCode = $inlineCode !== '' ? $inlineCode : $this->ruleCandidateCode; // v5 — código dos candidatos p/ regras
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
        // v5 — NÃO refaz Entendimento já VÁLIDO (objetivo presente): preserva orçamento do passo p/ as
        // dimensões críticas realmente faltantes (Regras/Deps). Só retenta se estiver de fato pobre.
        if (($blocks['entendimento'] ?? 'ok') !== 'ok' && ! $this->entendimentoValidEnough($sem)) {
            [$ok, $j] = $this->retryBlockCall($this->entendimentoUserPrompt($compact, $diff, $entCode), $entOut, $entCode !== '', $hardLimit, $blocks['entendimento'] === 'truncated');
            if ($ok && ! empty($j['entendimento_funcional'])) {
                $sem['entendimento_funcional'] = $j['entendimento_funcional'];
                $sem['objetivo'] = $j['entendimento_funcional']['objetivo'] ?? ($sem['objetivo'] ?? null);
                if (! empty($j['fluxo'])) {
                    $sem['fluxo'] = $j['fluxo'];
                }
                $blocks['entendimento'] = 'ok';
            }
        } // se válido o suficiente e ainda não-ok: preserva o parcial (honesto) e economiza a chamada p/ regras
        if (($blocks['regras'] ?? 'ok') !== 'ok') {
            [$ok, $j] = $this->retryBlockCall($this->regrasUserPrompt($compact, $diff, $regrasCode), $regrasOut, $regrasCode !== '', $hardLimit, $blocks['regras'] === 'truncated');
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

    /**
     * GMUD — Impact Resolver: tokens REMOVIDOS estruturalmente pela alteração (tabelas/campos/funções),
     * a partir do SourceDiff. Base determinística p/ invalidar claims dependentes do patrimônio V0.
     */
    private function gmudRemovedTokens(?array $diff): array
    {
        $s = (array) ($diff['structural'] ?? []);
        $tables = [];
        $fields = [];
        $funcs = [];
        foreach ((array) ($s['tables']['removed'] ?? []) as $t) {
            $n = strtoupper((string) (is_array($t) ? ($t['table'] ?? $t['alias'] ?? '') : $t));
            if ($n !== '') {
                $tables[$n] = true;
            }
        }
        foreach ((array) ($s['functions']['removed'] ?? []) as $f) {
            $n = strtolower((string) (is_array($f) ? ($f['function'] ?? $f['name'] ?? '') : $f));
            if ($n !== '') {
                $funcs[$n] = true;
            }
        }
        foreach ((array) ($s['fields']['removed'] ?? []) as $fl) {
            if (is_array($fl)) {
                $fields[strtoupper((string) ($fl['field'] ?? ''))] = true;
            } elseif (is_string($fl)) {
                $fields[strtoupper($fl)] = true;
            }
        }
        // remoções DENTRO de funções alteradas (ex.: tables_removed no changed).
        foreach ((array) ($s['functions']['changed'] ?? []) as $f) {
            foreach ((array) ($f['changes']['tables_removed'] ?? []) as $tr) {
                $tables[strtoupper((string) $tr)] = true;
            }
            foreach ((array) ($f['changes']['fields_removed'] ?? []) as $fr) {
                $fields[strtoupper((string) (is_array($fr) ? ($fr['field'] ?? '') : $fr))] = true;
            }
        }
        unset($tables[''], $funcs[''], $fields['']);
        return ['tables' => $tables, 'fields' => $fields, 'functions' => $funcs];
    }

    /**
     * Contrato do BASELINE (Executor A / Claude) — validação de ENTRADA: normaliza confidence NUMÉRICA
     * (ex.: 0.95) p/ o enum high|medium|low ANTES de o V0 virar patrimônio. Não silencia p/ low (o finalize
     * fazia isso); registra o que converteu em baseline_normalization. Deve ser chamado na ingestão do baseline.
     */
    public function normalizeBaseline(array $sem): array
    {
        $converted = 0;
        $walk = function (&$node) use (&$walk, &$converted) {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => &$v) {
                if ($k === 'confidence' && is_numeric($v)) {
                    $n = (float) $v;
                    $v = $n >= 0.8 ? 'high' : ($n >= 0.55 ? 'medium' : 'low');
                    $converted++;
                } elseif (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($sem);
        if ($converted > 0) {
            $sem['baseline_normalization'] = ['confidence_numeric_to_enum' => $converted];
        }
        return $sem;
    }

    /** GMUD — tokens ADICIONADOS estruturalmente (tabelas/campos/funções) — candidatos a rules_add FUNDAMENTADO. */
    private function gmudAddedTokens(?array $diff): array
    {
        $s = (array) ($diff['structural'] ?? []);
        $tables = [];
        $fields = [];
        $funcs = [];
        foreach ((array) ($s['tables']['added'] ?? []) as $t) {
            $n = strtoupper((string) (is_array($t) ? ($t['table'] ?? $t['alias'] ?? '') : $t));
            if ($n !== '') {
                $tables[$n] = true;
            }
        }
        foreach ((array) ($s['functions']['added'] ?? []) as $f) {
            $n = (string) (is_array($f) ? ($f['function'] ?? $f['name'] ?? '') : $f);
            if ($n !== '') {
                $funcs[$n] = true;
            }
        }
        foreach ((array) ($s['fields']['added'] ?? []) as $fl) {
            $fields[strtoupper((string) (is_array($fl) ? (($fl['table'] ?? '') . '.' . ($fl['field'] ?? '')) : $fl))] = true;
        }
        foreach ((array) ($s['functions']['changed'] ?? []) as $f) {
            foreach ((array) ($f['changes']['tables_added'] ?? []) as $ta) {
                $tables[strtoupper((string) $ta)] = true;
            }
            foreach ((array) ($f['changes']['fields_added'] ?? []) as $fa) {
                $fields[strtoupper((string) (is_array($fa) ? (($fa['table'] ?? '') . '.' . ($fa['field'] ?? '')) : $fa))] = true;
            }
        }
        unset($tables[''], $funcs[''], $fields[''], $fields['.']);
        return ['tables' => array_keys($tables), 'fields' => array_keys($fields), 'functions' => array_keys($funcs)];
    }

    /** Uma claim REFERENCIA um token removido? (evidência OU texto) — critério de "stale por GMUD". */
    private function claimRefsRemoved(array $item, array $removed): bool
    {
        foreach ((array) ($item['evidence'] ?? []) as $e) {
            if (! is_array($e)) {
                continue;
            }
            if (isset($removed['tables'][strtoupper((string) ($e['table'] ?? $e['alias'] ?? ''))])) {
                return true;
            }
            if (isset($removed['functions'][strtolower((string) ($e['name'] ?? ''))])) {
                return true;
            }
            $f = strtoupper((string) ($e['field'] ?? ''));
            if ($f !== '' && isset($removed['fields'][$f])) {
                return true;
            }
        }
        $txt = mb_strtoupper(json_encode([
            $item['descricao'] ?? '', $item['condicao'] ?? '', $item['efeito'] ?? '', $item['titulo'] ?? '',
            $item['nome'] ?? '', $item['passo'] ?? '', $item['como_participa'] ?? '', $item['descricao'] ?? '',
        ], JSON_UNESCAPED_UNICODE));
        foreach (array_keys($removed['tables']) as $t) {
            if ($t !== '' && str_contains($txt, $t)) {
                return true;
            }
        }
        foreach (array_keys($removed['fields']) as $f) {
            if (strlen($f) >= 4 && str_contains($txt, $f)) { // campos ≥4 chars p/ evitar falso-positivo curto
                return true;
            }
        }
        return false;
    }

    /** V0 claims (regras) STALE por GMUD — p/ o prompt incremental obrigar a re-decisão (não herança). */
    private function staleRulesByGmud(array $prev, array $removed): array
    {
        if (! $removed['tables'] && ! $removed['fields'] && ! $removed['functions']) {
            return [];
        }
        $out = [];
        foreach ((array) ($prev['regras_negocio'] ?? $prev['business_rules'] ?? []) as $r) {
            if ($this->claimRefsRemoved($r, $removed)) {
                $out[] = ['id' => $r['id'] ?? '?', 'descricao' => $this->str($r['descricao'] ?? '')];
            }
        }
        return $out;
    }

    /**
     * GMUD — INVALIDAÇÃO determinística pós-merge: nenhuma claim que dependa de fato REMOVIDO sobrevive por
     * herança do V0. A IA teve a chance de RE-EXPRESSAR (prompt) sem o token removido; o que restar citando o
     * removido é stale → podado. Não é "apagar toda regra que cita a tabela": a re-expressão válida (sem o
     * token) sobrevive; o finalize ainda revalida a evidência contra o V1.
     */
    private function applyGmudInvalidation(array $sem, array $removed): array
    {
        if (! $removed['tables'] && ! $removed['fields'] && ! $removed['functions']) {
            return $sem;
        }
        $invalidated = [];
        foreach (['regras_negocio', 'business_rules'] as $key) {
            if (! empty($sem[$key])) {
                $sem[$key] = array_values(array_filter($sem[$key], function ($r) use ($removed, &$invalidated) {
                    if ($this->claimRefsRemoved((array) $r, $removed)) {
                        $invalidated[] = 'regra:' . ($r['id'] ?? '?');
                        return false;
                    }
                    return true;
                }));
            }
        }
        $ef = (array) ($sem['entendimento_funcional'] ?? []);
        foreach (['entradas_principais', 'saidas_principais', 'o_que_faz'] as $k) {
            if (! empty($ef[$k])) {
                $before = count($ef[$k]);
                $ef[$k] = array_values(array_filter($ef[$k], fn ($it) => ! $this->claimRefsRemoved((array) $it, $removed)));
                if (count($ef[$k]) < $before) {
                    $invalidated[] = "entendimento:$k";
                    $this->gmudEntendimentoStale = true; // itens podados → re-expressar o entendimento
                }
            }
        }
        // strings interpretativas (objetivo/uma_frase/quando_usado) que citam o removido → re-expressão obrigatória.
        $safe = fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        $uf = is_array($ef['uma_frase'] ?? null) ? ($ef['uma_frase']['texto'] ?? '') : ($ef['uma_frase'] ?? '');
        $objs = [$safe($ef['objetivo'] ?? ''), $safe($sem['objetivo'] ?? ''), $safe($uf), $safe($ef['quando_usado'] ?? '')];
        foreach ($objs as $o) {
            if ($o !== '' && $this->textRefsRemoved($o, $removed)) {
                $this->gmudEntendimentoStale = true;
                $invalidated[] = 'entendimento:objetivo/uma_frase';
                break;
            }
        }
        $sem['entendimento_funcional'] = $ef;
        if (! empty($sem['dependencias_criticas'])) {
            $before = count($sem['dependencias_criticas']);
            $sem['dependencias_criticas'] = array_values(array_filter($sem['dependencias_criticas'], fn ($d) => ! $this->claimRefsRemoved((array) $d, $removed)));
            if (count($sem['dependencias_criticas']) < $before) {
                $invalidated[] = 'dependencias';
            }
        }
        if (! empty($invalidated)) {
            $rm = array_merge(array_keys($removed['tables']), array_keys($removed['fields']), array_map('strtoupper', array_keys($removed['functions'])));
            $sem['gmud_invalidation'] = ['removed' => array_values(array_unique($rm)), 'invalidated_claims' => $invalidated];
            // o resumo NÃO pode dizer "sem mudança": houve remoção estrutural com invalidação de patrimônio.
            $sem['change_summary'] = 'GMUD removeu fato(s) [' . implode(', ', array_slice(array_values(array_unique($rm)), 0, 8))
                . ']; conhecimento dependente foi invalidado/atualizado (' . count($invalidated) . ' claim(s)).';
            $sem['resumo_alteracao'] = $sem['change_summary'];
        }
        return $sem;
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
            'cross_source'     => $this->crossSourceProvenance(),
        ] + (isset($sem['gmud_invalidation']) ? ['gmud_invalidation' => $sem['gmud_invalidation']] : []); // GMUD — proveniência da invalidação sobrevive ao finalize
    }

    /**
     * Fase 3 — PROVENIÊNCIA cross-source no semantic_json: fingerprint do contexto usado, fontes
     * injetadas (facts-first), evidências C aceitas/rejeitadas pelo validador e a estimativa de custo
     * ADICIONAL do contexto. Self-contained ⇒ enabled=false, fingerprint '' (rastro neutro, sem ruído).
     * A afirmação fica rastreável: doc origem → símbolo → alvo → blob → facts → evidence C → ACCEPT/REJECT.
     */
    private function crossSourceProvenance(): array
    {
        $resolvedN = (int) ($this->crossSourceMeta['telemetry']['resolved'] ?? 0);
        if (! $this->crossSource && $this->contextFingerprint === '' && ! $this->xsrcRejected && $resolvedN === 0) {
            return ['enabled' => false, 'context_fingerprint' => ''];
        }
        // CUSTO/TOKENS = SÓ o que foi EFETIVAMENTE enviado (contado no choke-point call()). Contexto resolvido
        // mas NÃO injetado (ex.: rota não coberta) ⇒ injected=false, tokens/custo = 0. Nada de intenção.
        $cpt = (float) config('services.source_doc_ai.chars_per_token_text', 3.2);
        $ci  = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $addedInput = (int) ceil($this->xsrcInjectedChars / max($cpt, 1));
        $injected = $this->xsrcInjectedCalls > 0;
        $emitted = count($this->xsrcAccepted) + count($this->xsrcRejected);
        return [
            'enabled'              => (bool) $this->crossSource,
            'context_fingerprint'  => $this->contextFingerprint,
            // ETAPAS REAIS do circuito (execução, não intenção):
            'resolved'             => $resolvedN,
            'materialized'         => count($this->crossSource),
            'injected'             => $injected,
            'injected_calls'       => $this->xsrcInjectedCalls,
            'used_by_model'        => $emitted > 0, // sinal determinístico de uso: emitiu evidência estruturada
            'evidence_emitted'     => $emitted,
            'evidence_accepted'    => $this->xsrcAccepted,
            'evidence_rejected'    => $this->xsrcRejected,
            'sources'              => array_map(fn ($s) => [
                'source_doc_id' => $s['source_doc_id'], 'path' => $s['path'], 'blob_sha' => $s['blob_sha'],
                'symbol' => $s['symbol'], 'relation' => $s['relation'],
                'facts_strategy' => $s['facts_strategy'] ?? null,
                'facts_included' => $s['facts_included'], 'snippet_included' => $s['snippet_included'],
                'snippet_skipped_reason' => $s['snippet_skipped_reason'],
                'estimated_context_tokens' => $s['estimated_context_tokens'],
            ], $this->crossSource),
            'cost'                 => [
                'added_input_tokens'     => $addedInput,          // efetivo (0 se não injetado)
                'injected_in_calls'      => $this->xsrcInjectedCalls,
                'added_cost_usd'         => round($addedInput / 1e6 * $ci, 6),
            ],
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
            // Fase 2 — evidência CROSS-SOURCE (aponta p/ outro source_doc): mesmo juiz, rota determinística.
            // Local (sem source_doc_id) segue o comportamento atual, intacto.
            if (isset($ev['source_doc_id'])) {
                $r = app(\App\SourceCode\CrossSourceEvidenceValidator::class)->validate($ev, $this->contextDocId);
                if ($r['accepted']) {
                    $out[] = $r['evidence'];
                    $this->xsrcAccepted[] = $r['evidence']; // Fase 3 — proveniência (evidência externa validada)
                } else {
                    $this->rejected[] = ['item' => 'xsrc:' . ($ev['symbol'] ?? '?') . '@doc' . ($ev['source_doc_id'] ?? '?'), 'reason' => 'cross_source_' . $r['reason']];
                    $this->xsrcRejected[] = ['source_doc_id' => $ev['source_doc_id'] ?? null, 'symbol' => $ev['symbol'] ?? null, 'reason' => $r['reason']];
                }
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
    /**
     * CRITICAL RULES PASS — passo operacional DEDICADO e ESTREITO. Dispara quando há candidatos críticos
     * NÃO cobertos por regra validada (ou regras truncou deixando candidatos sem decisão). Para CADA
     * candidato o modelo DECIDE: confirmed_rule | not_a_rule | not_identified — sem competir com dezenas de
     * regras num prompt geral. TETO PRÓPRIO ≤ US$ 0,30 (passo semântico). Anti-alucinação: as regras
     * confirmadas passam pela mesma validação determinística (evidence tem de existir nos fatos).
     */
    public function criticalRulesPass(array $existing, array $det, string $maskedCode, array $ctx = []): array
    {
        $this->resetState();
        $this->loadCrossSource($ctx);
        $this->stepBaseCostUsd = (float) (($existing['usage']['total_cost_usd'] ?? $existing['usage']['actual_cost_usd'] ?? 0.0));
        $this->costBaseUsd = 0.0; // passo próprio ≤ US$ 0,30
        $hardLimit = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);

        $candidates = $this->detectCriticalRuleCandidates($det, $maskedCode);
        $existingRules = (array) ($existing['regras_negocio'] ?? $existing['business_rules'] ?? []);
        $uncovered = $this->uncoveredCandidates($candidates, $existingRules);
        $sem = $existing;
        $prov = ['triggered' => false, 'candidates' => $candidates, 'uncovered' => $uncovered, 'decisions' => [], 'confirmed' => 0];

        if (! (bool) config('services.source_doc_ai.critical_rules_pass_enabled', true) || empty($uncovered)) {
            $prov['reason'] = empty($uncovered) ? 'no_uncovered_candidate' : 'disabled';
            $sem['critical_rules_pass'] = $prov;
            $sem['usage'] = $this->usageBlock();
            return $sem;
        }

        $prov['triggered'] = true;
        $prov['reason'] = ($existing['block_status']['regras'] ?? 'ok') !== 'ok' ? 'regras_' . ($existing['block_status']['regras'] ?? '') : 'uncovered_critical_candidates';
        $relevant = $this->selectRelevant($det, null, (int) config('services.source_doc_ai.max_relevant_functions', 12));
        $compact = $this->buildCompactFacts($det, $relevant, null);
        $code = $this->criticalRuleCandidateCode($det, $maskedCode);
        $out = (int) config('services.source_doc_ai.max_output_tokens_critical_rules', 2400);

        $g = $this->guardedCallJson($this->criticalRulesPassPrompt($uncovered, $code, $compact), $out, $code !== '', 'critical_rules', $hardLimit);
        if ($g === null) {
            $prov['skipped'] = 'cost_budget'; // não coube no passo → fica partial_recoverable (on-demand)
            $sem['critical_rules_pass'] = $prov;
            $sem['usage'] = $this->usageBlock();
            return $sem;
        }
        $decisions = is_array($g['json']['decisions'] ?? null) ? $g['json']['decisions'] : [];
        $prov['decisions'] = $decisions;

        // extrai as regras CONFIRMADAS e valida contra os fatos (anti-alucinação — mesmo juiz das demais).
        $newRaw = [];
        foreach ($decisions as $d) {
            if (($d['decision'] ?? '') === 'confirmed_rule' && ! empty($d['rule']) && is_array($d['rule'])) {
                $r = $d['rule'];
                $r['id'] = $r['id'] ?? ('RC' . (count($newRaw) + 1));
                $newRaw[] = $r;
            }
        }
        $fnSet = array_flip(array_map('strtolower', array_column($det['functions'] ?? [], 'name')));
        $tbSet = array_flip(array_map('strtoupper', array_map(fn ($t) => $t['table'] ?? $t['alias'] ?? '', $det['tables'] ?? [])));
        [$fieldQ, $fieldBare] = $this->fieldSets($det);
        $userCalls = array_flip(array_map('strtolower', $det['user_calls'] ?? []));
        $validated = $this->validateRules($newRaw, $fnSet, $tbSet, $fieldQ, $fieldBare, $userCalls);

        if (! empty($validated)) {
            $sem['regras_negocio'] = $this->mergeRulesById(array_values((array) ($sem['regras_negocio'] ?? [])), $validated);
            $sem['business_rules'] = $this->mergeRulesById(array_values((array) ($sem['business_rules'] ?? [])), $validated);
            if (($sem['block_status']['regras'] ?? '') !== 'ok') {
                $sem['block_status']['regras'] = 'critical_rules_recovered';
            }
        }
        $prov['confirmed'] = count($validated);
        $sem['critical_rules_pass'] = $prov;
        $sem['strategy'] = 'critical_rules_pass';
        $sem['usage'] = $this->usageBlock();
        return $sem;
    }

    /** Candidatos ainda NÃO cobertos por regra validada existente (evita re-perguntar o que já virou regra). */
    private function uncoveredCandidates(array $candidates, array $existingRules): array
    {
        $hay = strtolower(json_encode($existingRules, JSON_UNESCAPED_UNICODE));
        $out = [];
        foreach ($candidates as $c) {
            if (preg_match('/MV_[A-Z0-9_]{2,}/i', $c, $m)) {
                if (! str_contains($hay, strtolower($m[0]))) {
                    $out[] = $c; // parâmetro material ainda não referenciado por nenhuma regra
                }
            } else {
                $out[] = $c; // sinal por categoria — barato perguntar; a decisão pode ser not_a_rule
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 14);
    }

    /** Merge de regras por id/titulo (não duplica o que já existe). */
    private function mergeRulesById(array $base, array $add): array
    {
        $seen = [];
        foreach ($base as $r) {
            $seen[strtolower((string) ($r['titulo'] ?? $r['descricao'] ?? $r['id'] ?? ''))] = true;
        }
        foreach ($add as $r) {
            $k = strtolower((string) ($r['titulo'] ?? $r['descricao'] ?? $r['id'] ?? ''));
            if ($k !== '' && ! isset($seen[$k])) {
                $base[] = $r;
                $seen[$k] = true;
            }
        }
        return $base;
    }

    /** Prompt ESTREITO do Critical Rules Pass — decisão obrigatória por candidato. */
    private function criticalRulesPassPrompt(array $candidates, string $code, array $compact): string
    {
        $u = "FATOS COMPACTOS (autoridade):\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $u .= $code; // trechos de código dos candidatos
        $u .= $this->crossSourceBlock();
        $u .= "\n\nPASSO DE REGRAS CRÍTICAS — decisão ESTREITA e OBRIGATÓRIA. Para CADA candidato abaixo, com base "
            . 'NOS FATOS e no TRECHO DE CÓDIGO, decida EXATAMENTE uma opção: "confirmed_rule" | "not_a_rule" | "'
            . self::UNDETERMINED . '". Se confirmed_rule, entregue a regra material: '
            . '{titulo,descricao,condicao,efeito,operacoes_protegidas[],confidence,evidence[≤2 {type,name?,table?,field?}]}. '
            . 'NÃO invente: sem trecho/fato que COMPROVE ⇒ not_a_rule ou "' . self::UNDETERMINED . '". '
            . 'Priorize AUTORIZAÇÃO/PERMISSÃO, LIMITE/TETO, BLOQUEIO, MUDANÇA DE ESTADO. '
            . 'Devolva SÓ JSON {decisions:[{candidato,decision,rule?}]}.' . "\n\nCANDIDATOS:\n- " . implode("\n- ", $candidates);
        return $u;
    }

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

    /**
     * Fase 3 — CONTEXTO CROSS-SOURCE (auxiliar). Bloco ADITIVO e isolado: apresenta APENAS os facts das
     * dependências resolved que o Minutor selecionou. A IA não escolhe, não busca, não resolve. Regras
     * explícitas: contexto é auxiliar; afirmação cross-source EXIGE evidence estruturada; sem base ⇒
     * not_identified; nunca completar por conhecimento geral. '' quando não há contexto (self-contained).
     */
    private function crossSourceBlock(): string
    {
        if (! $this->crossSource) {
            return '';
        }
        $b = "\n\nCONTEXTO CROSS-SOURCE (AUXILIAR — dependências resolvidas DETERMINISTICAMENTE pelo Minutor):\n"
            . json_encode(array_map(fn ($s) => [
                'source_doc_id' => $s['source_doc_id'], 'path' => $s['path'], 'blob_sha' => $s['blob_sha'],
                'symbol' => $s['symbol'], 'relation' => $s['relation'], 'facts' => $s['facts'],
            ], $this->crossSource), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b .= "\n\nREGRAS DO CONTEXTO EXTERNO (OBRIGATÓRIAS): "
            . "(a) é AUXILIAR — não substitui o fonte principal. "
            . "(b) SEMPRE que uma afirmação (objetivo, regra, finalidade de função, dependência crítica ou risco) "
            . "usar informação vinda deste contexto externo, o evidence do item DEVE conter um objeto de EVIDÊNCIA C "
            . "estruturada com EXATAMENTE estes campos: {source_doc_id, blob_sha, symbol, relation, evidence_type} "
            . "(evidence_type ∈ function|table|field) apontando o alvo de onde a informação veio. "
            . "(c) Se a afirmação DEPENDE do contexto externo e você NÃO consegue emitir uma evidência C válida, "
            . "NÃO mantenha a afirmação como fato: use \"" . self::UNDETERMINED . "\" (ou rebaixe a confidence) e não a inclua. "
            . "(d) NUNCA complete lacunas por conhecimento geral de Protheus nem transforme dependência em fato sem a evidência C acima.";
        return $b;
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
        $u .= $this->crossSourceBlock();
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
            . ', fluxo[≤6 strings curtas]. Ordene os campos EXATAMENTE nessa sequência. Sem evidência ⇒ "' . self::UNDETERMINED . '". '
            . self::MODULE_HINT; // v3 GAP 4 — módulo por evidência, reduzir NI Miss
        return $u;
    }

    /** BLOCO 2 — SÓ Regras de Negócio. Independente. */
    private function regrasUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        $u .= $this->crossSourceBlock(); // Fase 3 — regra é tipo de afirmação; pode depender de dependência externa
        $u .= "\n\nProduza JSON {"
            . 'regras_negocio[{id,titulo(≤8 palavras),descricao(≤24 palavras),condicao,efeito,confidence,evidence[≤2 {type,name?,table?,field?,line_start?,line_end?}]}], '
            . 'change_summary}. Cada regra EXIGE evidence dos fatos; sem evidência ⇒ omita. ' . self::RULES_COVERAGE // v3 GAP 2
            . $this->ruleCandidatesBlock(); // v4 — candidatos determinísticos a investigar
        return $u;
    }

    /**
     * v3 GAP 3 — recuperação FOCADA de regras: prompt ENXUTO (só facts, sem código inline) p/ caber no saldo
     * real remanescente até US$ 0,30. Pede EXCLUSIVAMENTE as regras materiais ainda ausentes.
     */
    private function regrasFocusedPrompt(array $compact): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $u .= $this->ruleCandidateCode; // v5 — trechos de código dos candidatos (confirma regra code-gated)
        $u .= $this->crossSourceBlock();
        $u .= "\n\nRECUPERAÇÃO FOCADA: produza SOMENTE as regras de negócio MATERIAIS observáveis nos fatos. "
            . 'JSON {regras_negocio[{id,titulo,descricao,condicao,efeito,confidence,evidence[≤2 {type,name?,table?,field?}]}]}. '
            . 'Cada regra EXIGE evidence dos fatos; sem evidência ⇒ omita. ' . self::RULES_COVERAGE
            . $this->ruleCandidatesBlock(); // v4 — na recuperação focada também
        return $u;
    }

    /** BLOCO 3 — SÓ Dependências Críticas + Risco de Alteração + Pontos. Independente. */
    private function depRiscoUserPrompt(array $compact, ?array $diff, string $code): string
    {
        $u = "FATOS COMPACTOS:\n" . json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        $u .= $this->crossSourceBlock(); // Fase 3 — dependências externas resolvidas ajudam dependencias_criticas/risco
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
            . $this->crossSourceBlock() // Fase 3 — finalidade/deps são afirmações que podem depender do contexto externo
            . "\n\nDê a finalidade FUNCIONAL (a responsabilidade da função no processo, 1–2 frases; NÃO 'chama X/acessa Y') "
            . 'de CADA função listada, com confidence + evidence. Sem base ⇒ finalidade="' . self::UNDETERMINED . '". '
            . 'Se houver base, adicione regra/ponto/dependência com evidence+confidence. '
            . 'Devolva JSON {funcoes[{name,finalidade,confidence,evidence[]}], regras_negocio[...], pontos_atencao[...], dependencias_criticas[...]}.';
    }

    private function incrementalUserPrompt(array $prev, ?array $diff, array $changed, array $stale = [], array $added = []): string
    {
        $prevSummary = [
            'objetivo' => $prev['objetivo'] ?? null,
            'regras'   => array_map(fn ($r) => ['id' => $r['id'] ?? null, 'descricao' => $r['descricao'] ?? null], $prev['regras_negocio'] ?? []),
        ];
        $blocks = array_map(fn ($c) => "### {$c['name']}\nFATOS: " . json_encode($c['facts'], JSON_UNESCAPED_UNICODE) . "\nCÓDIGO:\n" . $c['code'], $changed);
        $u = "SEMÂNTICA ANTERIOR (resumo):\n" . json_encode($prevSummary, JSON_UNESCAPED_UNICODE)
            . "\n\nDIFF:\n" . json_encode($this->diffForAi($diff ?? []), JSON_UNESCAPED_UNICODE)
            . "\n\nFUNÇÕES ALTERADAS (código mascarado):\n" . implode("\n\n", $blocks)
            . $this->crossSourceBlock(); // Fase 3 — regras/finalidades alteradas podem depender do contexto externo
        if (! empty($stale)) {
            // GMUD — claims INVALIDADAS: dependem de fatos REMOVIDOS pela alteração. NÃO podem ser herdadas.
            $u .= "\n\nCLAIMS INVALIDADAS PELA GMUD (dependem de fatos REMOVIDOS no DIFF — NÃO pode mantê-las como estão): "
                . json_encode(array_map(fn ($s) => ['id' => $s['id'], 'descricao' => $s['descricao']], $stale), JSON_UNESCAPED_UNICODE)
                . '. Para CADA uma decida: rules_update (re-expresse SEM o fato removido, se o comportamento persistir por outra implementação) OU rules_remove (se ficou obsoleta). Não invente; sem base no V1 ⇒ rules_remove.';
        }
        if (! empty(array_filter($added))) {
            // FUNDAMENTADO NO DIFF/FATOS (não no resumo): fatos NOVOS no V1 são candidatos a regra material.
            $u .= "\n\nFATOS ADICIONADOS no V1 (do DIFF): " . json_encode($added, JSON_UNESCAPED_UNICODE)
                . '. Se algum implementa uma REGRA de negócio material (condição/efeito), emita-a em rules_add com '
                . 'evidence CITANDO o fato adicionado (tabela/campo/função do V1). NÃO derive regra do change_summary '
                . 'nem invente: sem evidência no código/fatos do V1 ⇒ não emita.';
        }
        $u .= "\n\nResponda SOMENTE o que muda: JSON {change_summary, updated_functions[{name,finalidade}], "
            . 'rules_add[{id,descricao,confidence,evidence}], rules_update[{id,descricao,condicao,efeito,confidence,evidence}], rules_remove[id], attention_add[{interpretation,severity?,recommendation?,confidence,evidence}]}.';
        return $u;
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
        // Fase 3 — o contexto EXTERNO faz parte da chave: fingerprint '' (self-contained) preserva a chave
        // atual; contexto distinto gera chave distinta (nunca reusa semântica de contexto incompatível).
        return 'srcdoc:sem:' . sha1($blob . '|' . self::SCHEMA_VERSION . '|' . config('services.source_doc_ai.prompt_version', 2) . '|' . $this->ai->model() . '|' . $this->contextFingerprint);
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
        // Fase 3 — injeção EFETIVA: se o prompt REALMENTE enviado carrega o bloco cross-source, conta a
        // chamada e os chars do bloco (proveniência = o que foi enviado, não intenção). Route-agnostic.
        if ($this->crossSource && str_contains($user, self::XSRC_MARKER)) {
            $this->xsrcInjectedCalls++;
            $this->xsrcInjectedChars += mb_strlen($this->crossSourceBlock());
        }
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

    /** P0 — RESERVA CONSERVADORA de uma chamada: estimativa × fator (> erro observado) + margem fixa. */
    private function reservedCallUsd(string $user, int $out, bool $code): float
    {
        $factor = (float) config('services.source_doc_ai.cost_reserve_factor', 1.9);
        return $this->estimateCallUsd($user, $out, $code) * $factor + 0.005;
    }

    /**
     * P0 — GUARDA CENTRAL: única porta para toda chamada Anthropic paga do fluxo semântico. Só inicia a
     * chamada se custo_acumulado + RESERVA (conservadora) ≤ teto (hard_limit por padrão, ou um teto menor
     * p/ reservar blocos posteriores). Reconcilia o custo REAL após a chamada e registra a trilha por
     * chamada (estimated/reserved/actual/ratio). Retorna null quando NÃO pode iniciar (o chamador pula).
     */
    private function guardedCallJson(string $user, int $out, bool $code, string $block, ?float $ceiling = null): ?array
    {
        $ceil = $ceiling ?? (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        $hard = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        $ceil = min($ceil, $hard); // o teto efetivo NUNCA excede o hard_limit
        $est = $this->estimateCallUsd($user, $out, $code);
        $reserved = $this->reservedCallUsd($user, $out, $code);
        if ($this->currentCostUsd() + $reserved > $ceil) {
            $this->callLog[] = ['block' => $block, 'estimated' => round($est, 5), 'reserved' => round($reserved, 5), 'actual' => 0.0, 'ratio' => null, 'skipped' => true];
            return null; // sem reserva segura → não inicia (mantém o hard_limit inviolável)
        }
        $before = $this->currentCostUsd();
        $g = $this->callJson($this->systemPrompt(), $user, $out);
        $actual = $this->currentCostUsd() - $before;
        $this->callLog[] = [
            'block' => $block,
            'estimated' => round($est, 5),
            'reserved' => round($reserved, 5),
            'actual' => round($actual, 5),
            'ratio' => $est > 0 ? round($actual / $est, 2) : null,          // actual/estimated
            'ratio_reserved' => $reserved > 0 ? round($actual / $reserved, 2) : null, // actual/reserved (≤1 = reserva segura)
            'over_reserve' => $actual > $reserved + 1e-9,                    // chamada ultrapassou a própria reserva?
            'skipped' => false,
        ];
        return $g;
    }

    private function usageBlock(): array
    {
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $thisRun = $this->usage['input_tokens'] / 1e6 * $ci + $this->usage['output_tokens'] / 1e6 * $co;
        $stepCost = $this->costBaseUsd + $thisRun; // custo DESTE passo semântico (≤ hard_limit por passo)
        $extra = [
            'duration_ms'    => (int) ((microtime(true) - $this->t0) * 1000),
            'actual_cost_usd' => round($stepCost, 4),          // custo DESTE passo (initial: total; top-up: só o passo)
            'hard_limit_usd' => (float) config('services.source_doc_ai.hard_limit_usd', 0.30),
        ];
        // v4 — CUSTO POR PASSO SEMÂNTICO (não "por fonte"). Num top-up, o custo do initial fica explícito e o
        // TOTAL da fonte é a soma dos passos — cada passo respeita o hard-limit de US$ 0,30 individualmente.
        if ($this->stepBaseCostUsd > 0.0) {
            $extra['cost_model']       = 'per_semantic_step';
            $extra['step']             = 'top_up';
            $extra['initial_cost_usd'] = round($this->stepBaseCostUsd, 4);
            $extra['topup_cost_usd']   = round($stepCost, 4);
            $extra['topup_calls']      = (int) ($this->usage['calls'] ?? 0);
            $extra['total_cost_usd']   = round($this->stepBaseCostUsd + $stepCost, 4); // soma dos passos da fonte
            $extra['step_hard_limit_usd'] = (float) config('services.source_doc_ai.hard_limit_usd', 0.30);
        }
        // P1 — trilha por chamada (estimated/reserved/actual + ratios) p/ reconciliar o estimador e checar
        // se a reserva é suficiente (actual/reserved ≤ 1 = segura).
        if (! empty($this->callLog)) {
            $done = array_values(array_filter($this->callLog, fn ($c) => empty($c['skipped'])));
            $ratios = array_values(array_filter(array_map(fn ($c) => $c['ratio'] ?? null, $done), fn ($r) => $r !== null && $r > 0));
            $rr = array_values(array_filter(array_map(fn ($c) => $c['ratio_reserved'] ?? null, $done), fn ($r) => $r !== null));
            $extra['cost_calls'] = $this->callLog;
            $extra['est_error_ratio_avg'] = $ratios ? round(array_sum($ratios) / count($ratios), 2) : null;
            $extra['ratio_reserved_max'] = $rr ? max($rr) : null;              // >1 = alguma chamada estourou a reserva
            $extra['calls_over_reserve'] = count(array_filter($done, fn ($c) => ! empty($c['over_reserve'])));
            $extra['calls_skipped_budget'] = count(array_filter($this->callLog, fn ($c) => ! empty($c['skipped'])));
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
        $this->callLog = [];
        $this->xsrcAccepted = [];
        $this->xsrcRejected = [];
        $this->xsrcInjectedCalls = 0;
        $this->xsrcInjectedChars = 0;
        $this->ruleCandidates = [];
        $this->ruleCandidateCode = '';
        $this->stepBaseCostUsd = 0.0;
        $this->gmudEntendimentoStale = false;
    }

    /** Texto (string) referencia um token removido? (p/ objetivo/uma_frase/quando_usado do entendimento). */
    private function textRefsRemoved(string $txt, array $removed): bool
    {
        $t = mb_strtoupper($txt);
        foreach (array_keys($removed['tables']) as $x) {
            if ($x !== '' && str_contains($t, $x)) {
                return true;
            }
        }
        foreach (array_keys($removed['fields']) as $x) {
            if (strlen($x) >= 4 && str_contains($t, $x)) {
                return true;
            }
        }
        return false;
    }

    /**
     * v5 — CÓDIGO BOUNDED dos candidatos a regra material: extrai só as linhas ao redor dos sinais
     * (MV_/autorização/limite/bloqueio/status/validação) p/ o bloco de regras CONFIRMAR uma regra
     * code-gated mesmo em fonte GRANDE (onde o código inteiro não entra inline). NÃO manda o arquivo todo.
     */
    private function criticalRuleCandidateCode(array $det, string $maskedCode, int $budget = 3500): string
    {
        if ($maskedCode === '') {
            return '';
        }
        $lines = explode("\n", $maskedCode);
        $re = '/MV_[A-Z0-9_]{2,}|usu[aá]rio|permiss|al[çc]ada|autoriz|perfil|limite|teto|m[aá]ximo|percentual|'
            . 'desconto|Return\s*\.F\.|MsgStop|MsgAlert|bloque|impede|n[aã]o\s+permit|_STATUS|aprov|reabr|finaliz|'
            . 'ICMS|PIS|COFINS|al[íi]quota|isen[çc]/i';
        $keep = [];
        $n = count($lines);
        foreach ($lines as $i => $ln) {
            if (preg_match($re, $ln)) {
                for ($j = max(0, $i - 1); $j <= min($n - 1, $i + 1); $j++) {
                    $keep[$j] = true;
                }
            }
        }
        if (! $keep) {
            return '';
        }
        ksort($keep);
        $out = [];
        $chars = 0;
        $prev = -2;
        foreach (array_keys($keep) as $i) {
            if ($i > $prev + 1) {
                $out[] = '...';
            }
            $line = 'L' . ($i + 1) . ': ' . trim($lines[$i]);
            if (($chars += mb_strlen($line)) > $budget) {
                $out[] = '...(demais trechos omitidos)';
                break;
            }
            $out[] = $line;
            $prev = $i;
        }
        return "\n\nTRECHOS DE CÓDIGO DAS REGRAS CANDIDATAS (confirme cada regra material AQUI; se o trecho comprovar, "
            . "produza a regra com evidence; senão, omita):\n" . implode("\n", $out);
    }

    /** v5 — entendimento já é "bom o suficiente" (não vale re-truncar/refazer no top-up gastando orçamento). */
    private function entendimentoValidEnough(array $sem): bool
    {
        $ef = $sem['entendimento_funcional'] ?? [];
        $obj = (string) ($ef['objetivo'] ?? $sem['objetivo'] ?? '');
        return $ef !== [] && $obj !== '' && ! str_contains($obj, self::UNDETERMINED);
    }

    /**
     * v4 — detecção DETERMINÍSTICA de CANDIDATOS a regra material (não gera a regra; sinaliza onde investigar).
     * Evita overfitting: não conhece MV_XUSRZ07 — reconhece CATEGORIAS (autorização, limite/teto, bloqueio,
     * mudança de estado, validação). Varre o código mascarado + parâmetros SX6 dos fatos.
     */
    private function detectCriticalRuleCandidates(array $det, string $code): array
    {
        $hints = [];
        $mv = [];
        if (preg_match_all('/\bMV_[A-Z0-9_]{2,}/i', $code, $m)) {
            $mv = array_map('strtoupper', $m[0]);
        }
        foreach (($det['sx6_params'] ?? []) as $p) {
            $n = strtoupper((string) ($p['param'] ?? $p['name'] ?? $p['mv'] ?? ''));
            if ($n !== '') {
                $mv[] = $n;
            }
        }
        foreach (array_values(array_unique($mv)) as $p) {
            if (preg_match('/USR|USER|PERM|PERF|ALCAD|APROV|LIBER/i', $p)) {
                $hints[] = "$p (parâmetro) — possível regra de AUTORIZAÇÃO/PERMISSÃO";
            } elseif (preg_match('/PC|PCT|PERC|VLR|VAL|MAX|TETO|LIM|DESC/i', $p)) {
                $hints[] = "$p (parâmetro) — possível regra de LIMITE/TETO/VALOR";
            }
        }
        $sig = [
            'AUTORIZAÇÃO por usuário/perfil/alçada' => '/usu[aá]rio|permiss|al[çc]ada|autoriz|perfil/i',
            'LIMITE/TETO/percentual/desconto' => '/limite|teto|m[aá]ximo|percentual|desconto/i',
            'BLOQUEIO/validação que impede operação' => '/Return\s*\.F\.|MsgStop|MsgAlert|bloque|impede|n[aã]o\s+permit|inconsist/i',
            'MUDANÇA DE STATUS condicionada' => '/_STATUS|aprov|reabr|finaliz|cancel/i',
            'EXCEÇÃO fiscal/financeira' => '/ICMS|PIS|COFINS|imposto|al[íi]quota|isen[çc]/i',
        ];
        foreach ($sig as $label => $re) {
            if (preg_match($re, $code)) {
                $hints[] = "sinal de $label — investigue se há regra material comprovável";
            }
        }
        return array_slice(array_values(array_unique($hints)), 0, 14);
    }

    /** v4 — bloco de candidatos p/ os prompts de regras (comum a multi-bloco/simple/recuperação focada). */
    private function ruleCandidatesBlock(): string
    {
        if (empty($this->ruleCandidates)) {
            return '';
        }
        return "\n\nCANDIDATOS A REGRA MATERIAL (detectados deterministicamente — NÃO são regras prontas; "
            . 'INVESTIGUE cada um nos fatos/código e, se CONFIRMAR, produza a regra com condicao+efeito+evidence '
            . '(prioridade a autorização/permissão, limite/teto, bloqueio, mudança de estado); se NÃO confirmar, '
            . 'omita — não invente): ' . implode('; ', $this->ruleCandidates) . '.';
    }

    /** Fase 3 — carrega o contexto cross-source do $ctx (usado por analyze e topUp; resetState já rodou). */
    private function loadCrossSource(array $ctx): void
    {
        $this->crossSource = is_array($ctx['cross_source']['sources'] ?? null) ? $ctx['cross_source']['sources'] : [];
        $this->contextFingerprint = (string) ($ctx['cross_source']['fingerprint'] ?? $ctx['context_fingerprint'] ?? '');
        $this->crossSourceMeta = is_array($ctx['cross_source'] ?? null) ? $ctx['cross_source'] : [];
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
        // Robustez: confidence NUMÉRICA (ex.: baseline com 0.95) mapeia p/ enum — não silencia p/ low.
        if (is_numeric($v)) {
            $n = (float) $v;
            return $n >= 0.8 ? 'high' : ($n >= 0.55 ? 'medium' : 'low');
        }
        $v = strtolower((string) $v);
        return in_array($v, ['high', 'medium', 'low'], true) ? $v : 'medium';
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
