<?php

namespace App\SourceCode\Analyzer;

use Illuminate\Support\Facades\Log;

/**
 * Fase 2 — camada SEMÂNTICA. Recebe os FATOS determinísticos (Camada 1) + o código SANITIZADO
 * (segredos mascarados) + o diff, e usa a IA (SourceDocAiProvider) só para EXPLICAR o que já foi
 * extraído — nunca para descobrir/inventar. Anti-alucinação: nomes de função/tabela vêm carimbados
 * da C1 e nomes inventados pela IA são descartados. Fallback: sem IA/erro ⇒ status 'pending'
 * (documentação determinística continua válida). Chunking p/ fontes grandes, marcando 'partial'.
 */
class SourceDocSemanticAnalyzer
{
    public const SCHEMA_VERSION = 1;
    private const UNKNOWN = 'Não identificado automaticamente no código.';

    public function __construct(private SourceDocAiProvider $ai)
    {
    }

    public function analyze(array $deterministic, string $maskedCode, ?array $diff = null): array
    {
        if (!$this->ai->isConfigured()) {
            return $this->pending('IA não configurada', $deterministic);
        }
        $maxChars = (int) config('services.source_doc_ai.max_chars', 40000);

        try {
            if (mb_strlen($maskedCode) <= $maxChars) {
                $sem = $this->singleCall($deterministic, $maskedCode, $diff);
                $sem['chunking'] = ['chunks' => 1, 'partial' => false];
            } else {
                $sem = $this->chunkedCall($deterministic, $maskedCode, $diff, $maxChars);
            }
        } catch (\Throwable $e) {
            Log::warning('source_doc_ai.analyze_failed', ['error' => $e->getMessage()]);
            return $this->failed($e->getMessage(), $deterministic);
        }

        return $this->finalize($sem, $deterministic);
    }

    // ── uma chamada (fonte cabe no orçamento) ──────────────────────────────────
    private function singleCall(array $det, string $code, ?array $diff): array
    {
        $user = $this->userPrompt($det, $diff, $code);
        $out = $this->ai->complete($this->systemPrompt(), $user, []);
        $json = $this->parseJson($out['text']);
        if ($json === null) {
            throw new \RuntimeException('Resposta da IA não é JSON válido.');
        }
        $json['status'] = 'completed';
        return $json;
    }

    // ── chunking: funções em grupos + consolidação por fatos (marca partial) ──
    private function chunkedCall(array $det, string $code, ?array $diff, int $maxChars): array
    {
        $lines = explode("\n", $code);
        $funcoes = [];
        $chunks = 0;
        $group = [];
        $groupLen = 0;
        $flush = function () use (&$group, &$groupLen, &$funcoes, &$chunks, $det, $diff): void {
            if (empty($group)) {
                return;
            }
            $chunks++;
            $user = $this->userPrompt($det, null, implode("\n", $group)) . "\n\nAnalise APENAS a finalidade das funções presentes neste trecho.";
            try {
                $out = $this->ai->complete($this->systemPrompt(), $user, []);
                $j = $this->parseJson($out['text']);
                foreach (($j['funcoes'] ?? []) as $f) {
                    if (!empty($f['name'])) {
                        $funcoes[] = $f;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('source_doc_ai.chunk_failed', ['chunk' => $chunks, 'error' => $e->getMessage()]);
            }
            $group = [];
            $groupLen = 0;
        };

        foreach ($det['functions'] as $fn) {
            $slice = implode("\n", array_slice($lines, max(0, ($fn['start_line'] ?? 1) - 1), max(1, ($fn['end_line'] ?? 1) - ($fn['start_line'] ?? 1) + 1)));
            if (mb_strlen($slice) > $maxChars) {
                $slice = mb_substr($slice, 0, $maxChars) . "\n// […função truncada para análise…]";
            }
            if ($groupLen + mb_strlen($slice) > $maxChars) {
                $flush();
            }
            $group[] = $slice;
            $groupLen += mb_strlen($slice);
        }
        $flush();

        // Consolidação (narrativa) a partir dos FATOS + diff, sem o código completo → partial.
        $chunks++;
        $consol = ['objetivo' => self::UNKNOWN, 'fluxo' => [], 'regras_negocio' => [], 'integracoes' => [], 'entradas' => [], 'saidas' => [], 'tratamento_erros' => self::UNKNOWN, 'efeitos_colaterais' => [], 'pontos_atencao' => [], 'resumo_alteracao' => $diff && ($diff['is_creation'] ?? false) ? 'Criação inicial do fonte.' : self::UNKNOWN];
        try {
            $user = $this->userPrompt($det, $diff, '') . "\n\nO fonte é grande e foi analisado por partes. Produza a visão geral (objetivo, fluxo, regras, integrações, entradas/saídas, erros, efeitos, pontos de atenção) a partir dos FATOS e do diff.";
            $out = $this->ai->complete($this->systemPrompt(), $user, []);
            $j = $this->parseJson($out['text']) ?? [];
            $consol = array_merge($consol, array_intersect_key($j, $consol));
        } catch (\Throwable $e) {
            Log::warning('source_doc_ai.consolidation_failed', ['error' => $e->getMessage()]);
        }

        $consol['funcoes'] = $funcoes;
        $consol['tabelas'] = [];
        $consol['status'] = 'partial';
        $consol['chunking'] = ['chunks' => $chunks, 'partial' => true, 'note' => 'Fonte grande: analisado por partes; narrativa consolidada a partir dos fatos.'];
        return $consol;
    }

    // ── anti-alucinação + normalização final ──────────────────────────────────
    private function finalize(array $sem, array $det): array
    {
        $fnNames = array_map('strtolower', array_column($det['functions'], 'name'));
        $tbAliases = array_map('strtoupper', array_column($det['tables'], 'alias'));

        // funções: só as que existem na C1 (descarta nomes inventados)
        $sem['funcoes'] = array_values(array_filter($sem['funcoes'] ?? [], fn ($f) => !empty($f['name']) && in_array(strtolower($f['name']), $fnNames, true)));
        // tabelas: só as que existem na C1
        $sem['tabelas'] = array_values(array_filter($sem['tabelas'] ?? [], fn ($t) => !empty($t['alias']) && in_array(strtoupper($t['alias']), $tbAliases, true)));

        return [
            'schema_version'     => self::SCHEMA_VERSION,
            'status'             => $sem['status'] ?? 'completed',
            'provider'           => $this->ai->name(),
            'model'              => $this->ai->model(),
            'objetivo'           => $this->str($sem['objetivo'] ?? self::UNKNOWN),
            'fluxo'              => $this->arr($sem['fluxo'] ?? []),
            'funcoes'            => $sem['funcoes'],
            'tabelas'            => $sem['tabelas'],
            'regras_negocio'     => array_values($sem['regras_negocio'] ?? []),
            'integracoes'        => $this->arr($sem['integracoes'] ?? []),
            'entradas'           => $this->arr($sem['entradas'] ?? []),
            'saidas'             => $this->arr($sem['saidas'] ?? []),
            'tratamento_erros'   => $this->str($sem['tratamento_erros'] ?? self::UNKNOWN),
            'efeitos_colaterais' => $this->arr($sem['efeitos_colaterais'] ?? []),
            'pontos_atencao'     => $this->arr($sem['pontos_atencao'] ?? []),
            'resumo_alteracao'   => $this->str($sem['resumo_alteracao'] ?? self::UNKNOWN),
            'chunking'           => $sem['chunking'] ?? ['chunks' => 1, 'partial' => false],
        ];
    }

    private function pending(string $reason, array $det): array
    {
        return $this->skeleton('pending') + ['note' => "Análise semântica pendente ({$reason}) — reprocessável. A documentação determinística permanece válida."];
    }

    private function failed(string $error, array $det): array
    {
        return $this->skeleton('failed') + ['error' => 'Falha na análise semântica — reprocessável.'];
    }

    private function skeleton(string $status): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION, 'status' => $status, 'provider' => $this->ai->name(), 'model' => $this->ai->model(),
            'objetivo' => null, 'fluxo' => [], 'funcoes' => [], 'tabelas' => [], 'regras_negocio' => [], 'integracoes' => [],
            'entradas' => [], 'saidas' => [], 'tratamento_erros' => null, 'efeitos_colaterais' => [], 'pontos_atencao' => [],
            'resumo_alteracao' => null, 'chunking' => ['chunks' => 0, 'partial' => false],
        ];
    }

    // ── prompts ────────────────────────────────────────────────────────────────
    private function systemPrompt(): string
    {
        return 'Você é um analista de sistemas Protheus/AdvPL/TL++. Recebe FATOS já extraídos '
            . 'deterministicamente de um fonte (funções, tabelas, campos, queries, chamadas) + o CÓDIGO '
            . '(com segredos mascarados como «REDACTED:...») + um diff. Sua função é EXPLICAR, em português '
            . "do Brasil, o que o código faz — NUNCA descobrir fatos novos nem inventar. Regras absolutas:\n"
            . "1) Use SOMENTE os fatos e o código fornecidos.\n"
            . "2) NÃO invente tabela, campo, função, integração, parâmetro, regra ou comportamento que não "
            . "esteja nos fatos/código.\n"
            . "3) Sem evidência suficiente, escreva EXATAMENTE: \"" . self::UNKNOWN . "\".\n"
            . "4) Nas listas 'funcoes' e 'tabelas', use EXATAMENTE os nomes/aliases fornecidos nos fatos.\n"
            . "5) É melhor documentação incompleta do que tecnicamente falsa.\n"
            . 'Devolva EXCLUSIVAMENTE um JSON válido (sem markdown, sem cercas) com as chaves: '
            . 'objetivo (string), fluxo (array de passos string), funcoes (array de {name, finalidade}), '
            . 'tabelas (array de {alias, finalidade}), regras_negocio (array de {id, descricao}), '
            . 'integracoes (array string), entradas (array string), saidas (array string), '
            . 'tratamento_erros (string), efeitos_colaterais (array string), pontos_atencao (array string), '
            . 'resumo_alteracao (string).';
    }

    private function userPrompt(array $det, ?array $diff, string $code): string
    {
        $facts = $this->factsForAi($det);
        $u = "FATOS DETERMINÍSTICOS (Camada 1):\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($diff !== null) {
            $u .= "\n\nDIFF (estrutural, Camada 1):\n" . json_encode($diff, JSON_UNESCAPED_UNICODE);
        }
        if ($code !== '') {
            $u .= "\n\nCÓDIGO (segredos mascarados):\n" . $code;
        }
        return $u;
    }

    /** Fatos enviados à IA: nomes estruturados, SEM previews de SQL crus (governança). */
    private function factsForAi(array $det): array
    {
        $q = array_map(fn ($x) => ['operation' => $x['operation'] ?? null, 'tables' => $x['tables'] ?? [], 'fields' => $x['fields'] ?? [], 'has_where' => $x['has_where'] ?? null], $det['queries'] ?? []);
        return [
            'file'           => $det['file'] ?? null,
            'includes'       => $det['includes'] ?? [],
            'functions'      => array_map(fn ($f) => ['name' => $f['name'], 'type' => $f['type'], 'params' => $f['params'], 'returns' => $f['returns'], 'calls_internal' => $f['calls_internal'], 'calls_user' => $f['calls_user'], 'writes' => $f['writes']], $det['functions'] ?? []),
            'call_graph'     => $det['call_graph'] ?? [],
            'tables'         => $det['tables'] ?? [],
            'queries'        => $q,
            'sx6_params'     => $det['sx6_params'] ?? [],
            'endpoints'      => $det['endpoints'] ?? [],
            'paths'          => $det['paths'] ?? [],
            'totvs_calls'    => $det['totvs_calls'] ?? [],
            'user_calls'     => $det['user_calls'] ?? [],
            'integrations'   => $det['integrations'] ?? [],
            'error_handling' => $det['error_handling'] ?? [],
            'write_effects'  => $det['write_effects'] ?? [],
            'security_findings_count' => count($det['security_findings'] ?? []),
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

    private function str($v): ?string
    {
        return is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : null);
    }

    private function arr($v): array
    {
        return is_array($v) ? array_values(array_filter($v, fn ($x) => $x !== null && $x !== '')) : [];
    }
}
