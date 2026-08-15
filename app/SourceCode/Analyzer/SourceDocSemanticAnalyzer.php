<?php

namespace App\SourceCode\Analyzer;

use Illuminate\Support\Facades\Log;

/**
 * Bloco 4 — camada SEMÂNTICA (subordinada ao determinístico). Recebe os FATOS da Camada 1
 * (deterministic_json enriquecido) + o diff (Camada 2) + o CÓDIGO SANITIZADO (segredos mascarados)
 * e usa a IA (SourceDocAiProvider) só para EXPLICAR — nunca descobrir/inventar.
 *
 * Analyzer = fatos · SourceDiff = mudanças · IA = interpretação · Renderer = apresentação.
 *
 * Garantias:
 *  - GATE homolog-only (config; prod bloqueado tecnicamente — nunca envia código à IA em prod).
 *  - ANTI-ALUCINAÇÃO pós-resposta: toda entidade citada (função/tabela/campo) é confrontada com o
 *    deterministic_json; o que não existe é REJEITADO (contado). Sem aproximar nomes.
 *  - EVIDÊNCIA + CONFIANÇA em regras/pontos de atenção; LOW não é renderizado como fato.
 *  - FALLBACK: falha/gate ⇒ status pending/failed; determinístico permanece válido.
 *  - CHUNKING por função em fontes grandes; sem truncar em silêncio (status partial + contagem).
 *  - OBSERVABILIDADE: tokens in/out, chamadas, ms, custo estimado. Logs sem código/prompt/segredo.
 *
 * A saída preserva as chaves que o Renderer (Bloco 5, TRAVADO) consome
 * (objetivo/fluxo/funcoes/tabelas/regras_negocio/entradas/saidas/pontos_atencao/resumo_alteracao/status)
 * e ADICIONA a estrutura rica (business_rules c/ evidence+confidence, attention_points, change_summary,
 * usage, validation) para governança e Central futura — sem alterar o Renderer.
 */
class SourceDocSemanticAnalyzer
{
    public const SCHEMA_VERSION = 1;
    private const UNKNOWN = 'Não identificado automaticamente no código.';

    /** @var array{input_tokens:int,output_tokens:int,calls:int,ms:int} */
    private array $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'ms' => 0];
    /** @var list<array{item:string,reason:string}> */
    private array $rejected = [];

    public function __construct(private SourceDocAiProvider $ai)
    {
    }

    /** Gate homolog-only: ENABLED=true E ambiente autorizado. Prod (sem vars) ⇒ false. */
    public function enabled(): bool
    {
        if (! (bool) config('services.source_doc_ai.enabled', false)) {
            return false;
        }
        $env = (string) config('services.source_doc_ai.environment', app()->environment());
        $allowed = (array) config('services.source_doc_ai.allowed_environments', ['homolog']);
        return in_array($env, $allowed, true);
    }

    public function analyze(array $deterministic, string $maskedCode, ?array $diff = null): array
    {
        $this->usage = ['input_tokens' => 0, 'output_tokens' => 0, 'calls' => 0, 'ms' => 0];
        $this->rejected = [];

        if (! $this->enabled()) {
            return $this->skeleton('pending') + ['note' => 'IA desabilitada neste ambiente (gate homolog). A documentação determinística permanece válida.'];
        }
        if (! $this->ai->isConfigured()) {
            return $this->skeleton('pending') + ['note' => 'IA não configurada (provider ausente). A documentação determinística permanece válida.'];
        }

        $maxChars = (int) config('services.source_doc_ai.max_chars', 40000);
        try {
            if (mb_strlen($maskedCode) <= $maxChars) {
                $sem = $this->singleCall($deterministic, $maskedCode, $diff);
                $sem['chunking'] = ['chunks' => 1, 'processed' => 1, 'failed' => 0, 'partial' => false];
            } else {
                $sem = $this->chunkedCall($deterministic, $maskedCode, $diff, $maxChars);
            }
        } catch (\Throwable $e) {
            Log::warning('source_doc_ai.analyze_failed', ['error' => $this->sanitizeLog($e->getMessage())]);
            return $this->skeleton('failed') + ['error' => 'Falha na análise semântica — reprocessável.', 'usage' => $this->usageBlock()];
        }

        return $this->finalize($sem, $deterministic, $diff);
    }

    // ── uma chamada ─────────────────────────────────────────────────────────────
    private function singleCall(array $det, string $code, ?array $diff): array
    {
        $out = $this->call($this->systemPrompt(), $this->userPrompt($det, $diff, $code));
        $json = $this->parseJson($out);
        if ($json === null) {
            throw new \RuntimeException('Resposta da IA não é JSON válido.');
        }
        $json['status'] = 'completed';
        return $json;
    }

    // ── chunking por função (fonte grande) — sem truncar em silêncio ─────────────
    private function chunkedCall(array $det, string $code, ?array $diff, int $maxChars): array
    {
        $lines = explode("\n", $code);
        $funcoes = [];
        $chunks = 0;
        $processed = 0;
        $failed = 0;
        $group = [];
        $groupLen = 0;

        $flush = function () use (&$group, &$groupLen, &$funcoes, &$chunks, &$processed, &$failed, $det): void {
            if (empty($group)) {
                return;
            }
            $chunks++;
            // Contexto preservado no chunk: cada bloco carrega função + linhas + chamadas + tabelas + efeitos.
            $user = "FATOS (Camada 1) das funções deste trecho:\n" . json_encode($this->factsForAi($det, array_column($group, 'name')), JSON_UNESCAPED_UNICODE)
                . "\n\nCÓDIGO (segredos mascarados) das funções deste trecho:\n" . implode("\n", array_column($group, 'code'))
                . "\n\nAnalise APENAS a finalidade das funções presentes neste trecho. Devolva JSON {funcoes:[{name,finalidade}]}.";
            try {
                $j = $this->parseJson($this->call($this->systemPrompt(), $user)) ?? [];
                foreach (($j['funcoes'] ?? []) as $f) {
                    if (! empty($f['name'])) {
                        $funcoes[] = $f;
                    }
                }
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('source_doc_ai.chunk_failed', ['chunk' => $chunks, 'error' => $this->sanitizeLog($e->getMessage())]);
            }
            $group = [];
            $groupLen = 0;
        };

        foreach (($det['functions'] ?? []) as $fn) {
            $slice = implode("\n", array_slice($lines, max(0, ($fn['start_line'] ?? 1) - 1), max(1, ($fn['end_line'] ?? 1) - ($fn['start_line'] ?? 1) + 1)));
            if (mb_strlen($slice) > $maxChars) {
                $slice = mb_substr($slice, 0, $maxChars) . "\n// […função truncada para análise…]";
            }
            if ($groupLen + mb_strlen($slice) > $maxChars) {
                $flush();
            }
            $group[] = ['name' => $fn['name'], 'code' => $slice];
            $groupLen += mb_strlen($slice);
        }
        $flush();

        // Consolidação (narrativa) a partir dos FATOS + diff, SEM o código completo → partial.
        $chunks++;
        $consol = ['objetivo' => self::UNKNOWN, 'fluxo' => [], 'regras_negocio' => [], 'entradas' => [], 'saidas' => [], 'pontos_atencao' => [], 'table_purposes' => [], 'change_summary' => self::UNKNOWN];
        try {
            $user = $this->userPrompt($det, $diff, '') . "\n\nO fonte é grande e foi analisado por partes. Produza a visão geral (objetivo, fluxo, regras_negocio, entradas, saidas, pontos_atencao, table_purposes, change_summary) SOMENTE a partir dos FATOS e do diff.";
            $j = $this->parseJson($this->call($this->systemPrompt(), $user)) ?? [];
            $consol = array_merge($consol, array_intersect_key($j, $consol));
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('source_doc_ai.consolidation_failed', ['error' => $this->sanitizeLog($e->getMessage())]);
        }

        $consol['funcoes'] = $funcoes;
        $consol['status'] = 'partial';
        $consol['chunking'] = [
            'chunks' => $chunks, 'processed' => $processed, 'failed' => $failed, 'partial' => true,
            'note' => 'Fonte grande: analisado por partes; narrativa consolidada a partir dos fatos.',
        ];
        return $consol;
    }

    // ── anti-alucinação + normalização + evidência/confiança ────────────────────
    private function finalize(array $sem, array $det, ?array $diff): array
    {
        $fnSet = array_flip(array_map('strtolower', array_column($det['functions'] ?? [], 'name')));
        $tbSet = array_flip(array_map('strtoupper', array_map(fn ($t) => $t['table'] ?? $t['alias'] ?? '', $det['tables'] ?? [])));
        [$fieldQ, $fieldBare] = $this->fieldSets($det);
        $userCalls = array_flip(array_map('strtolower', $det['user_calls'] ?? []));

        // funcoes: só nomes existentes na C1
        $funcoes = array_values(array_filter($sem['funcoes'] ?? [], function ($f) use ($fnSet) {
            $ok = ! empty($f['name']) && isset($fnSet[strtolower($f['name'])]);
            if (! $ok && ! empty($f['name'])) {
                $this->rejected[] = ['item' => 'funcao:' . $f['name'], 'reason' => 'função inexistente no determinístico'];
            }
            return $ok;
        }));

        // table_purposes: só aliases existentes
        $tablePurposes = array_values(array_filter($sem['table_purposes'] ?? $sem['tabelas'] ?? [], function ($t) use ($tbSet) {
            $ok = ! empty($t['alias']) && isset($tbSet[strtoupper($t['alias'])]);
            if (! $ok && ! empty($t['alias'])) {
                $this->rejected[] = ['item' => 'tabela:' . $t['alias'], 'reason' => 'tabela inexistente no determinístico'];
            }
            return $ok;
        }));

        // business_rules: evidência rastreável obrigatória + confiança; entidades inventadas rejeitam
        $rules = $this->validateRules($sem['regras_negocio'] ?? $sem['business_rules'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, $userCalls);
        // pontos de atenção: evidência + severidade + recomendação + confiança
        $attention = $this->validateAttention($sem['pontos_atencao'] ?? $sem['attention_points'] ?? [], $fnSet, $tbSet, $fieldQ, $fieldBare, $userCalls);

        // change_summary determinístico p/ initial / não-estrutural
        $ds = (array) ($diff['diff_stats'] ?? []);
        $changeSummary = $this->str($sem['change_summary'] ?? $sem['resumo_alteracao'] ?? self::UNKNOWN);
        if (($ds['change_type'] ?? ($diff['is_creation'] ?? false ? 'initial' : null)) === 'initial') {
            $changeSummary = 'Documentação inicial desta versão do fonte.';
        } elseif (array_key_exists('structural_change', $ds) && $ds['structural_change'] === false) {
            $changeSummary = 'Não foram identificadas alterações estruturais relevantes.';
        }

        // regras/pontos RENDERIZÁVEIS = high/medium (LOW fica só p/ diagnóstico)
        $rulesShown = array_values(array_filter($rules, fn ($r) => in_array($r['confidence'], ['high', 'medium'], true)));
        $rulesLow = array_values(array_filter($rules, fn ($r) => $r['confidence'] === 'low'));
        $attnShown = array_values(array_filter($attention, fn ($a) => in_array($a['confidence'], ['high', 'medium'], true)));

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'status'           => $sem['status'] ?? 'completed',
            'provider'         => $this->ai->name(),
            'model'            => $this->ai->model(),
            // ── chaves consumidas pelo Renderer (Bloco 5, travado) ──
            'objetivo'         => $this->str($sem['objetivo'] ?? $sem['overview'] ?? self::UNKNOWN),
            'fluxo'            => $this->arr($sem['fluxo'] ?? $sem['execution_flow'] ?? []),
            'funcoes'          => $funcoes,
            'tabelas'          => $tablePurposes, // {alias, finalidade}
            'regras_negocio'   => array_map(fn ($r) => ['id' => $r['id'], 'descricao' => $r['descricao'], 'confidence' => $r['confidence'], 'evidence' => $r['evidence']], $rulesShown),
            'entradas'         => $this->arr($sem['entradas'] ?? $sem['inputs'] ?? []),
            'saidas'           => $this->arr($sem['saidas'] ?? $sem['outputs'] ?? []),
            'pontos_atencao'   => array_map(fn ($a) => $this->attnToString($a), $attnShown),
            'resumo_alteracao' => $changeSummary,
            // ── estrutura RICA (governança / Central futura) ──
            'business_rules'   => $rules,
            'business_rules_low' => $rulesLow,
            'attention_points' => $attention,
            'change_summary'   => $changeSummary,
            'table_purposes'   => $tablePurposes,
            'usage'            => $this->usageBlock(),
            'validation'       => ['rejected_count' => count($this->rejected), 'rejected' => array_slice($this->rejected, 0, 50)],
            'chunking'         => $sem['chunking'] ?? ['chunks' => 1, 'processed' => 1, 'failed' => 0, 'partial' => false],
        ];
    }

    /** @return array{0:array<string,bool>,1:array<string,bool>} [fieldsPorTabela, fieldsBare] */
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
            $conf = $this->conf($r['confidence'] ?? 'low');
            $out[] = ['id' => $r['id'] ?? ('RN' . str_pad((string) (++$i), 2, '0', STR_PAD_LEFT)), 'descricao' => $desc, 'confidence' => $conf, 'evidence' => $ev];
        }
        return $out;
    }

    private function validateAttention(array $raw, array $fnSet, array $tbSet, array $fieldQ, array $fieldBare, array $userCalls): array
    {
        $out = [];
        foreach ($raw as $a) {
            // aceita string simples ou estrutura {interpretation,severity,recommendation,evidence,confidence}
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

    /** Mantém só evidências que apontam entidades REAIS do determinístico. */
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

    /** Detecta menção a função U_XXX que não existe no determinístico (invenção clara). */
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

    private function conf($v): string
    {
        $v = strtolower((string) $v);
        return in_array($v, ['high', 'medium', 'low'], true) ? $v : 'low';
    }

    // ── chamada ao provider + acumulação de usage ───────────────────────────────
    private function call(string $system, string $user): string
    {
        $out = $this->ai->complete($system, $user, []);
        $u = (array) ($out['usage'] ?? []);
        $this->usage['input_tokens'] += (int) ($u['input_tokens'] ?? 0);
        $this->usage['output_tokens'] += (int) ($u['output_tokens'] ?? 0);
        $this->usage['calls']++;
        return (string) ($out['text'] ?? '');
    }

    private function usageBlock(): array
    {
        $ci = (float) config('services.source_doc_ai.cost_input_per_mtok', 3.0);
        $co = (float) config('services.source_doc_ai.cost_output_per_mtok', 15.0);
        $cost = $this->usage['input_tokens'] / 1e6 * $ci + $this->usage['output_tokens'] / 1e6 * $co;
        return $this->usage + ['estimated_cost_usd' => round($cost, 4)];
    }

    private function skeleton(string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION, 'status' => $status, 'provider' => $this->ai->name(), 'model' => $this->ai->model(),
            'objetivo' => null, 'fluxo' => [], 'funcoes' => [], 'tabelas' => [], 'regras_negocio' => [],
            'entradas' => [], 'saidas' => [], 'pontos_atencao' => [], 'resumo_alteracao' => null,
            'business_rules' => [], 'business_rules_low' => [], 'attention_points' => [], 'change_summary' => null,
            'table_purposes' => [], 'usage' => $this->usageBlock(),
            'validation' => ['rejected_count' => 0, 'rejected' => []], 'chunking' => ['chunks' => 0, 'processed' => 0, 'failed' => 0, 'partial' => false],
        ];
    }

    // ── prompts ────────────────────────────────────────────────────────────────
    private function systemPrompt(): string
    {
        return 'Você é um analista de sistemas Protheus/AdvPL/TL++. Recebe FATOS já extraídos '
            . 'deterministicamente (funções, call graph, tabelas, campos por papel, SQL, dependências, efeitos, '
            . 'technical_flow) + o CÓDIGO com segredos MASCARADOS + um diff estrutural. Sua função é EXPLICAR, em '
            . "português do Brasil, o que o código faz — NUNCA descobrir fatos novos nem inventar. Regras absolutas:\n"
            . "1) Use SOMENTE os fatos e o código fornecidos.\n"
            . "2) NÃO invente tabela, campo, função, integração, parâmetro, regra ou comportamento fora dos fatos.\n"
            . "3) Sem evidência suficiente, escreva EXATAMENTE: \"" . self::UNKNOWN . "\".\n"
            . "4) Em 'funcoes' e 'table_purposes' use EXATAMENTE os nomes/aliases dos fatos.\n"
            . "5) Toda regra de negócio e ponto de atenção DEVE ter evidence rastreável (função/tabela/campo dos fatos) "
            . "e confidence (high|medium|low). high=evidência direta; medium=inferência forte pelo fluxo; low=incerto.\n"
            . "6) NÃO extrapole o diff (ex.: não afirme 'envia e-mail' se os fatos não sustentam).\n"
            . "7) risk_flag é EVIDÊNCIA técnica, não vulnerabilidade — só sugira severidade/recomendação com base.\n"
            . "8) É melhor documentação incompleta do que tecnicamente falsa.\n"
            . 'Devolva EXCLUSIVAMENTE um JSON válido (sem markdown/cercas) com as chaves: '
            . 'objetivo (string), fluxo (array de passos string), funcoes (array de {name, finalidade}), '
            . 'table_purposes (array de {alias, finalidade}), regras_negocio (array de {id, descricao, confidence, '
            . 'evidence:[{type:"function|table|field", name?, table?, field?, lines?}]}), entradas (array string), '
            . 'saidas (array string), pontos_atencao (array de {interpretation, severity?, recommendation?, confidence, '
            . 'evidence:[...]}), change_summary (string).';
    }

    private function userPrompt(array $det, ?array $diff, string $code): string
    {
        $u = "FATOS DETERMINÍSTICOS (Camada 1):\n" . json_encode($this->factsForAi($det), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($diff !== null) {
            $u .= "\n\nDIFF (estrutural, Camada 2):\n" . json_encode($this->diffForAi($diff), JSON_UNESCAPED_UNICODE);
        }
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        return $u;
    }

    /** Fatos enviados à IA (Bloco 1 enriquecido) — nomes estruturados, SEM texto SQL cru (governança). */
    private function factsForAi(array $det, ?array $onlyFns = null): array
    {
        $fns = $det['functions'] ?? [];
        if ($onlyFns !== null) {
            $set = array_flip(array_map('strtolower', $onlyFns));
            $fns = array_values(array_filter($fns, fn ($f) => isset($set[strtolower($f['name'] ?? '')])));
        }
        return [
            'file'         => $det['file'] ?? null,
            'source_type'  => $det['source_type'] ?? null,
            'language'     => $det['language'] ?? null,
            'includes'     => $det['includes'] ?? [],
            'functions'    => array_map(fn ($f) => [
                'name' => $f['name'] ?? null, 'type' => $f['type'] ?? null, 'params' => $f['params'] ?? [],
                'returns' => $f['returns'] ?? [], 'called_by' => $f['called_by'] ?? [],
                'calls_internal' => $f['calls_internal'] ?? [], 'calls_user' => $f['calls_user'] ?? [],
                'tables' => $f['tables'] ?? [], 'accesses' => $f['accesses'] ?? [], 'effects' => $f['effects'] ?? [],
                'evidence' => $f['evidence'] ?? null,
            ], $fns),
            'call_graph'   => $det['call_graph'] ?? [],
            'tables'       => array_map(fn ($t) => [
                'table' => $t['table'] ?? $t['alias'] ?? null, 'access' => $t['access'] ?? [],
                'read_fields' => $t['read_fields'] ?? [], 'write_fields' => $t['write_fields'] ?? [],
                'where_fields' => $t['where_fields'] ?? [], 'functions' => $t['functions'] ?? [], 'evidence' => $t['evidence'] ?? null,
            ], $det['tables'] ?? []),
            'queries'      => array_map(fn ($q) => [
                'operation' => $q['operation'] ?? null, 'table' => $q['table'] ?? null, 'executor' => $q['executor'] ?? null,
                'construction' => $q['construction'] ?? null, 'function' => $q['function'] ?? null,
                'read_fields' => $q['read_fields'] ?? [], 'write_fields' => $q['write_fields'] ?? [], 'where_fields' => $q['where_fields'] ?? [],
                'has_where' => $q['has_where'] ?? null, 'risk_flags' => $q['risk_flags'] ?? [], 'evidence' => $q['evidence'] ?? null,
            ], $det['queries'] ?? []),
            'data_access'  => $det['data_access'] ?? [],
            'external_integrations' => $det['external_integrations'] ?? [],
            'dependencies' => $det['dependencies'] ?? [],
            'effects'      => $det['effects'] ?? [],
            'technical_flow' => $det['technical_flow'] ?? [],
            'sx6_params'   => $det['sx6_params'] ?? [],
            'endpoints'    => $det['endpoints'] ?? [],
            'security_findings_count' => count($det['security_findings'] ?? []),
        ];
    }

    /** Diff enviado à IA: só os fatos estruturais (contagens + estrutura), sem ruído. */
    private function diffForAi(array $diff): array
    {
        return [
            'change_type'       => $diff['change_type'] ?? ($diff['diff_stats']['change_type'] ?? null),
            'structural_change' => $diff['structural_change'] ?? ($diff['diff_stats']['structural_change'] ?? null),
            'diff_stats'        => $diff['diff_stats'] ?? [],
            'structural'        => $diff['structural'] ?? null,
        ];
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?|```$/m', '', $text);
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
