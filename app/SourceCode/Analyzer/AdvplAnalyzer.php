<?php

namespace App\SourceCode\Analyzer;

/**
 * Extração DETERMINÍSTICA de AdvPL/TL++ (sem IA). Usa o AdvplLexer para não confundir código
 * com comentário/string, e extratores por padrão com atribuição por função (faixas de linha) e
 * EVIDÊNCIA (linhas). Produz um deterministic_json versionado. É a "descoberta" do código — a
 * camada semântica só EXPLICA o que já foi extraído aqui; o analyzer NÃO interpreta negócio.
 *
 * Regra anti-alucinação: só fatos técnicos. Sem evidência ⇒ null/[]/unknown. Nunca inventar.
 * Bloco 1 (v1.1): saída ADITIVA — mantém as chaves antigas (functions/tables/queries/integrations…)
 * e adiciona classificação de fonte, per-função (called_by/tables/effects), campos read/write/where,
 * SQL detalhado (executor/construção/risco), data_access, external_integrations, dependencies,
 * effects e technical_flow — todos com evidência quando viável.
 */
class AdvplAnalyzer
{
    public const SCHEMA_VERSION = 1;
    public const ANALYZER_VERSION = '1.1';

    /** Índice linha→função (O(1) lookup; evita O(eventos×funções) em fontes grandes). */
    private array $lineIndex = [];

    private const NON_CALLS = [
        'if', 'iif', 'elseif', 'else', 'endif', 'while', 'enddo', 'for', 'next', 'do', 'case', 'endcase',
        'otherwise', 'return', 'local', 'private', 'public', 'static', 'default', 'begin', 'end', 'sequence',
        'recover', 'try', 'catch', 'finally', 'function', 'method', 'class', 'endclass', 'switch', 'loop', 'exit',
        'and', 'or', 'not',
    ];

    /** Funções TOTVS/AdvPL relevantes (framework). */
    private const TOTVS = [
        'MsExecAuto', 'ExecAuto', 'FWRest', 'TRest', 'HttpGet', 'HttpPost', 'HttpQuote', 'WsMethod', 'WsService',
        'TWsdlManager', 'ApMsgYesNo', 'MsgBox', 'MsgInfo', 'MsgStop', 'MsgAlert', 'ConOut', 'MemoWrite', 'FCreate',
        'FWrite', 'MsFCreate', 'TCSQLExec', 'TCSqlExec', 'TCQuery', 'SqlToTrb', 'GetMv', 'SuperGetMv', 'GetNewPar',
        'Pergunte', 'ParamBox', 'RecLock', 'MsUnlock', 'MsUnLock', 'DbSelectArea', 'DbSetOrder', 'DbSeek',
        'DbSetFilter', 'DbGoTop', 'DbSkip', 'GetArea', 'RestArea', 'FieldGet', 'FieldPut', 'MSDIALOG',
        'FWFormFunction', 'JsonObject', 'FwJsonSerialize', 'FwJsonDeserialize', 'OemToAnsi', 'AnsiToOem', 'Alert',
        'FWMakeGet', 'FWMakePost', 'aAdd', 'SoapConnect', 'SoapRequest',
    ];

    /** Sinais de integração EXTERNA (não é acesso a banco). */
    private const INTEGRATION_SIGNS = [
        'REST'      => '/\b(FWRest|TRest|FWMakeGet|FWMakePost)\s*\(/i',
        'HTTP'      => '/\b(HttpGet|HttpPost|HttpQuote|HttpSoapXml)\s*\(/i',
        'SOAP/WS'   => '/\b(TWsdlManager|WSMethod|WSService|WsRestful|SoapConnect|SoapRequest)\b/i',
        'Arquivo'   => '/\b(FCreate|FWrite|MsFCreate|CpyT2S|CpyS2T|FT_F|MemoWrite|MemoRead)\s*\(/i',
        'FTP/SFTP'  => '/\b(FtpConnect|FtpUpLoad|FtpDownLoad|FtpSend)\s*\(/i',
        'Mensageria' => '/\b(RabbitMQ|Kafka|MQSeries|TMSQueue)\b/i',
    ];

    public function analyze(string $code, array $meta = []): array
    {
        $lex = new AdvplLexer($code);
        $mc = $lex->maskCode;         // sem comentários e sem strings
        $mn = $lex->maskNoComments;   // sem comentários, com strings

        [$sourceType, $language] = $this->classify($meta['path'] ?? ($meta['filename'] ?? ''), $code);

        $functions = $this->extractFunctions($lex, $mc);           // base: nome/tipo/params/retorno/linhas/calls
        $fnRanges = $this->functionRanges($functions);
        $this->lineIndex = $this->buildLineIndex($functions);       // índice O(1) p/ functionAt

        // Eventos de acesso a dados (nativo + SQL), com linha → função e papel de campo.
        $access = $this->collectAccessEvents($lex, $mc, $mn, $fnRanges);
        $queries = $this->buildQueries($access['queries'], $fnRanges, $mc);
        $tables = $this->buildTables(array_merge($access['events'], $this->sqlEventsFromQueries($queries)));

        $sx6 = $this->extractSx6($mn);
        $endpoints = $this->extractEndpoints($lex);
        $paths = $this->extractPaths($lex);
        $includes = $this->extractIncludes($mn);
        $totvs = $this->extractTotvsCalls($mc);
        $userCalls = $this->extractUserCalls($mc);
        $externalIntegrations = $this->detectExternalIntegrations($mc, $endpoints);
        $dataAccess = $this->buildDataAccess($queries, $access['events']);
        $dependencies = $this->buildDependencies($includes, $functions, $userCalls, $totvs, $mc);
        $errorHandling = $this->detectErrorHandling($mc);
        $effects = $this->buildEffects($lex, $mc, $mn, $fnRanges, $queries);
        $callGraph = $this->buildCallGraph($functions);
        $functions = $this->enrichFunctions($functions, $callGraph, $tables, $queries, $effects);
        $technicalFlow = $this->buildTechnicalFlow($functions, $queries);

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'analyzer_version' => self::ANALYZER_VERSION,
            'language'         => $language,                 // "AdvPL" / "TLPP"
            'source_type'      => $sourceType,               // "Fonte Protheus" quando aplicável
            'file'             => [
                'path'       => $meta['path'] ?? null,
                'filename'   => $meta['filename'] ?? ($meta['path'] ? basename($meta['path']) : null),
                'lines'      => substr_count($lex->code, "\n") + 1,
                'size_bytes' => strlen($code),
            ],
            'includes'         => $includes,
            'functions'        => $functions,
            'call_graph'       => $callGraph,
            'tables'           => $tables,
            'queries'          => $queries,
            'data_access'      => $dataAccess,
            'external_integrations' => $externalIntegrations,
            'dependencies'     => $dependencies,
            'effects'          => $effects,
            'technical_flow'   => $technicalFlow,
            'sx6_params'       => $sx6,
            'endpoints'        => $endpoints,
            'paths'            => $paths,
            'totvs_calls'      => $totvs,
            'user_calls'       => $userCalls,
            // ── compat (chaves antigas mantidas p/ o renderer atual não quebrar) ──
            'integrations'     => $this->legacyIntegrations($externalIntegrations, $dataAccess),
            'error_handling'   => $errorHandling,
            'write_effects'    => $this->legacyWriteEffects($effects),
            'stats'            => [
                'functions' => count($functions),
                'tables'    => count($tables),
                'queries'   => count($queries),
                'lines'     => substr_count($lex->code, "\n") + 1,
                'includes'  => count($includes),
                'endpoints' => count($endpoints),
                'external_integrations' => count($externalIntegrations),
            ],
        ];
    }

    // ── classificação do fonte ────────────────────────────────────────────────
    private function classify(string $path, string $code): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $advpl = ['prw', 'prx', 'prg', 'apl', 'apo', 'apw', 'aph', 'apu', 'ppr', 'ppx', 'ppp', 'ch', 'th'];
        $tlpp = ['tlpp', 'tlp'];
        if (in_array($ext, $tlpp, true)) {
            return ['Fonte Protheus', 'TLPP'];
        }
        if (in_array($ext, $advpl, true)) {
            return ['Fonte Protheus', 'AdvPL'];
        }
        // heurística por conteúdo (arquivo sem extensão reconhecida)
        if (preg_match('/\b(User|Static)\s+Function\b/i', $code)) {
            return ['Fonte Protheus', 'AdvPL'];
        }
        return ['unknown', 'unknown'];
    }

    // ── funções (base) + faixas ───────────────────────────────────────────────
    private function extractFunctions(AdvplLexer $lex, string $mc): array
    {
        $re = '/\b(user\s+function|static\s+function|wsmethod|wsrestful|wsservice|function|method|class)\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(([^)]*)\))?/i';
        preg_match_all($re, $mc, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $decls = [];
        foreach ($m as $x) {
            $decls[] = ['type' => $this->normFnType($x[1][0]), 'name' => $x[2][0], 'params' => $this->splitParams($x[3][0] ?? ''), 'offset' => $x[0][1]];
        }
        $names = array_map(fn ($d) => strtolower($d['name']), $decls);
        $namesSet = array_flip($names); // lookup O(1) — evita in_array O(matches×funções)
        $out = [];
        $n = count($decls);
        for ($i = 0; $i < $n; $i++) {
            $d = $decls[$i];
            $from = $d['offset'];
            $to = $i + 1 < $n ? $decls[$i + 1]['offset'] : strlen($mc);
            $body = substr($mc, $from, $to - $from);
            $startLine = $lex->lineAt($from);
            $endLine = max($startLine, $lex->lineAt($to) - 1);
            $out[] = [
                'name'           => $d['name'],
                'type'           => $d['type'],
                'params'         => $d['params'],
                'returns'        => $this->extractReturns($body),
                'start_line'     => $startLine,
                'end_line'       => $endLine,
                'calls_internal' => $this->callsInternal($body, $namesSet, $d['name']),
                'calls_user'     => $this->userCallsIn($body),
                'writes'         => (bool) preg_match('/\b(RecLock|FieldPut|MsUnlock|MsExecAuto|FCreate|FWrite|TCSQLExec|TCSqlExec)\b/i', $body),
                'evidence'       => ['line_start' => $startLine, 'line_end' => $endLine],
            ];
        }
        return $out;
    }

    private function functionRanges(array $functions): array
    {
        $r = [];
        foreach ($functions as $f) {
            $r[] = ['name' => $f['name'], 'start' => $f['start_line'], 'end' => $f['end_line']];
        }
        return $r;
    }

    private function functionAt(array $ranges, int $line): ?string
    {
        if (!empty($this->lineIndex)) {
            return $this->lineIndex[$line] ?? null;
        }
        foreach ($ranges as $r) {
            if ($line >= $r['start'] && $line <= $r['end']) {
                return $r['name'];
            }
        }
        return null;
    }

    /** Índice linha→nome-da-função (funções são sequenciais e não se sobrepõem). */
    private function buildLineIndex(array $functions): array
    {
        $idx = [];
        foreach ($functions as $f) {
            for ($l = $f['start_line']; $l <= $f['end_line']; $l++) {
                $idx[$l] = $f['name'];
            }
        }
        return $idx;
    }

    private function normFnType(string $t): string
    {
        $t = strtolower(preg_replace('/\s+/', ' ', trim($t)));
        return match ($t) {
            'user function'   => 'User Function',
            'static function' => 'Static Function',
            'function'        => 'Function',
            'method'          => 'Method',
            'class'           => 'Class',
            'wsmethod'        => 'WsMethod',
            'wsrestful'       => 'WsRestful',
            'wsservice'       => 'WsService',
            default           => ucfirst($t),
        };
    }

    private function splitParams(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(function ($p) {
            $p = preg_replace('/\s+as\s+\w+/i', '', trim($p));
            $p = preg_replace('/\s*:?=.*/', '', $p);
            return trim($p);
        }, explode(',', $raw)), fn ($p) => $p !== ''));
    }

    private function extractReturns(string $body): array
    {
        preg_match_all('/\breturn\b[ \t]*([^\n]*)/i', $body, $m);
        $out = [];
        foreach ($m[1] as $r) {
            $r = rtrim(trim($r), '; ');
            if ($r !== '' && !in_array($r, $out, true)) {
                $out[] = $r;
            }
        }
        return array_slice($out, 0, 12);
    }

    private function callsInternal(string $body, array $namesSet, string $self): array
    {
        static $nonCalls = null;
        $nonCalls ??= array_flip(self::NON_CALLS);
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $m);
        $out = [];
        $seen = [];
        $selfLc = strtolower($self);
        foreach ($m[1] as $name) {
            $l = strtolower($name);
            if ($l === $selfLc || isset($nonCalls[$l]) || !isset($namesSet[$l]) || isset($seen[$l])) {
                continue;
            }
            $seen[$l] = true;
            $out[] = $name;
        }
        return $out;
    }

    private function userCallsIn(string $body): array
    {
        preg_match_all('/\bU_([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $body, $m);
        return array_values(array_unique(array_map(fn ($x) => 'U_' . $x, $m[1])));
    }

    /** called_by + tabelas/acessos/efeitos por função. */
    private function enrichFunctions(array $functions, array $callGraph, array $tables, array $queries, array $effects): array
    {
        $calledBy = [];
        foreach ($callGraph as $e) {
            $to = strtolower(preg_replace('/^U_/i', '', $e['to'])); // 'to' já é normalizado; strip U_ é defensivo
            $calledBy[$to][] = $e['from'];
        }
        foreach ($functions as &$f) {
            $lc = strtolower($f['name']);
            $f['called_by'] = array_values(array_unique($calledBy[$lc] ?? []));
            // tabelas acessadas nesta função (via queries + eventos)
            $tb = [];
            $acc = [];
            foreach ($queries as $q) {
                if (strcasecmp((string) ($q['function'] ?? ''), $f['name']) === 0) {
                    foreach ($q['tables'] as $t) {
                        $tb[$t] = true;
                        $acc[$q['operation']] = true;
                    }
                }
            }
            foreach ($tables as $t) {
                if (in_array($f['name'], $t['functions'] ?? [], true)) {
                    $tb[$t['table']] = true;
                    foreach ($t['access'] as $a) {
                        $acc[$a] = true;
                    }
                }
            }
            $f['tables'] = array_keys($tb);
            $f['accesses'] = array_keys($acc);
            $f['effects'] = array_values(array_unique(array_map(fn ($e) => $e['type'], array_filter($effects, fn ($e) => strcasecmp((string) ($e['function'] ?? ''), $f['name']) === 0))));
        }
        unset($f);
        return $functions;
    }

    // ── eventos de acesso a dados (nativo + SQL) ──────────────────────────────
    private function collectAccessEvents(AdvplLexer $lex, string $mc, string $mn, array $fnRanges): array
    {
        $events = [];   // {table, access, field?, field_role?, source, line, function, evidence}
        $emit = function (array $ev) use (&$events, $lex, $fnRanges): void {
            $ev['function'] = $this->functionAt($fnRanges, $ev['line']);
            $ev['evidence'] = ['line' => $ev['line']];
            $events[] = $ev;
        };

        // DbSelectArea("SXX") — leitura (abre tabela)
        foreach ($this->matchAll('/\bDbSelectArea\s*\(\s*["\']([A-Za-z0-9]{2,10})["\']/i', $mn) as $x) {
            $emit(['table' => strtoupper($x['g'][1]), 'access' => 'READ', 'source' => 'native', 'line' => $lex->lineAt($x['offset'])]);
        }
        // RecLock("SXX", .T./.F.) — INSERT(.T.) / UPDATE(.F.)
        foreach ($this->matchAll('/\bRecLock\s*\(\s*["\']([A-Za-z0-9]{2,10})["\']\s*,\s*(\.[TtFf]\.)/i', $mn) as $x) {
            $emit(['table' => strtoupper($x['g'][1]), 'access' => strtoupper($x['g'][2]) === '.T.' ? 'INSERT' : 'UPDATE', 'source' => 'native', 'line' => $lex->lineAt($x['offset'])]);
        }
        // alias->CAMPO — leitura ou escrita (se seguido de :=)
        foreach ($this->matchAll('/\b([A-Za-z][A-Za-z0-9_]{1,9})->([A-Za-z][A-Za-z0-9_]*)\s*(:=|\()?/', $mc) as $x) {
            if (($x['g'][3] ?? '') === '(') {
                continue; // obj->Metodo()
            }
            $role = ($x['g'][3] ?? '') === ':=' ? 'write' : 'read';
            $emit(['table' => strtoupper($x['g'][1]), 'access' => $role === 'write' ? 'UPDATE' : 'READ', 'field' => strtoupper($x['g'][2]), 'field_role' => $role, 'source' => 'native', 'line' => $lex->lineAt($x['offset'])]);
        }

        // SQL por literais de string (TCSQLExec/TCQuery) + SQL embutido BeginSql/EndSql (não-string).
        $queries = array_merge($this->assembleSql($lex), $this->assembleBeginSql($lex, $mn));
        return ['events' => $events, 'queries' => $queries];
    }

    /**
     * SQL embutido em blocos BeginSql … EndSql (a instrução NÃO é string; fica no código).
     * Resolve macros TOTVS: %Table:XXX% → nome físico; demais %…% → marcador embedded_macro.
     * Evidência com line_start (BeginSql) e line_end (EndSql) reais.
     */
    private function assembleBeginSql(AdvplLexer $lex, string $mn): array
    {
        $out = [];
        if (!preg_match_all('/\bBeginSql\b(.*?)\bEndSql\b/is', $mn, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($m as $x) {
            $inner = $x[1][0];
            $offset = $x[0][1];
            $startLine = $lex->lineAt($offset);
            $endLine = $lex->lineAt($offset + strlen($x[0][0]));
            $hadMacro = (bool) preg_match('/%[^%]+%/', $inner);
            // %Table:SB1% → " SB1 " (nome físico); %xFilial:...% e demais macros → espaço.
            $sql = preg_replace('/%\s*Table\s*:\s*([A-Za-z0-9_]+)\s*%/i', ' $1 ', $inner);
            $sql = preg_replace('/%[^%]*%/', ' ', (string) $sql);
            $out[] = [
                'text'       => trim(preg_replace('/\s+/', ' ', (string) $sql)),
                'line'       => $startLine,
                'line_start' => $startLine,
                'line_end'   => $endLine,
                'fragments'  => 1,
                'executor'   => 'BeginSql',
                'embedded_macro' => $hadMacro,
            ];
        }
        return $out;
    }

    /** matchAll com offset → [{g:groups, offset}] */
    private function matchAll(string $re, string $subject): array
    {
        preg_match_all($re, $subject, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        return array_map(fn ($x) => ['g' => array_map(fn ($c) => $c[0], $x), 'offset' => $x[0][1]], $m);
    }

    private function buildTables(array $events): array
    {
        $t = [];
        foreach ($events as $e) {
            $a = $e['table'];
            $t[$a] ??= ['table' => $a, 'alias' => $a, 'access' => [], 'functions' => [], 'fields' => [], 'read_fields' => [], 'write_fields' => [], 'where_fields' => [], 'source' => [], 'evidence' => ['lines' => []], 'dynamic' => str_starts_with($a, '(')];
            if (!in_array($e['access'], $t[$a]['access'], true)) {
                $t[$a]['access'][] = $e['access'];
            }
            if ($e['function'] && !in_array($e['function'], $t[$a]['functions'], true)) {
                $t[$a]['functions'][] = $e['function'];
            }
            if (!in_array($e['source'], $t[$a]['source'], true)) {
                $t[$a]['source'][] = $e['source'];
            }
            if (!empty($e['field'])) {
                $f = $e['field'];
                $bucket = ($e['field_role'] ?? 'read') === 'write' ? 'write_fields' : (($e['field_role'] ?? '') === 'where' ? 'where_fields' : 'read_fields');
                foreach ([$bucket, 'fields'] as $k) {
                    if (!in_array($f, $t[$a][$k], true)) {
                        $t[$a][$k][] = $f;
                    }
                }
            }
            $t[$a]['evidence']['lines'][] = $e['line'];
        }
        // normaliza evidence (min/max)
        foreach ($t as &$tb) {
            $ls = $tb['evidence']['lines'];
            $tb['evidence'] = $ls ? ['line_start' => min($ls), 'line_end' => max($ls)] : null;
        }
        unset($tb);
        return array_values($t);
    }

    // ── SQL ───────────────────────────────────────────────────────────────────
    private function assembleSql(AdvplLexer $lex): array
    {
        $startKw = '/\b(SELECT|UPDATE|INSERT\s+INTO|DELETE\s+FROM)\b/i';
        $out = [];
        $buf = null;
        $frags = 0;
        $flush = function () use (&$buf, &$frags, &$out): void {
            if ($buf !== null) {
                $out[] = [
                    'text'       => trim(preg_replace('/\s+/', ' ', $buf['text'])),
                    'line'       => $buf['start'],   // compat
                    'line_start' => $buf['start'],
                    'line_end'   => $buf['last'],    // última linha REAL da expressão (não onde o parser terminou)
                    'fragments'  => $frags,
                ];
                $buf = null;
                $frags = 0;
            }
        };
        foreach ($lex->strings as $s) {
            $v = $s['value'];
            $sLine = $s['line'];
            $eLine = $sLine + substr_count($v, "\n"); // string literal pode abranger várias linhas físicas
            if ($buf === null) {
                if (preg_match($startKw, $v)) {
                    $buf = ['text' => $v, 'start' => $sLine, 'last' => $eLine];
                    $frags = 1;
                }
                continue;
            }
            if ($sLine - $buf['last'] <= 3) {
                $buf['text'] .= ' ' . $v;
                $buf['last'] = max($buf['last'], $eLine);
                $frags++;
            } else {
                $flush();
                if (preg_match($startKw, $v)) {
                    $buf = ['text' => $v, 'start' => $sLine, 'last' => $eLine];
                    $frags = 1;
                }
            }
        }
        $flush();
        return $out;
    }

    private function buildQueries(array $rawQueries, array $fnRanges, string $mc): array
    {
        $fileExecutor = preg_match('/\bTCSQLExec\s*\(/i', $mc) ? 'TCSQLExec'
            : (preg_match('/\bTCQuery\b/i', $mc) ? 'TCQuery'
            : (preg_match('/\bBeginSql\b/i', $mc) ? 'BeginSql' : 'SQL'));
        $out = [];
        foreach ($rawQueries as $q) {
            $sql = $q['text'];
            $executor = $q['executor'] ?? $fileExecutor; // BeginSql traz executor próprio
            $op = preg_match('/^\s*UPDATE/i', $sql) ? 'UPDATE'
                : (preg_match('/INSERT\s+INTO/i', $sql) ? 'INSERT'
                : (preg_match('/DELETE\s+FROM/i', $sql) ? 'DELETE' : 'SELECT'));
            $tables = [];
            if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+([A-Za-z0-9_%]+)/i', $sql, $mt)) {
                foreach ($mt[1] as $t) {
                    $t = strtoupper(trim($t, '%'));
                    if ($t !== '' && !in_array($t, $tables, true)) {
                        $tables[] = $t;
                    }
                }
            }
            [$readF, $writeF, $whereF] = $this->classifySqlFields($sql, $op);
            $construction = !empty($q['embedded_macro']) ? 'embedded_macro'
                : ($q['fragments'] > 1 ? 'concatenation'
                : (preg_match('/%\w/', $sql) ? 'embedded_macro' : 'static'));
            $hasWhere = (bool) preg_match('/\bWHERE\b/i', $sql);
            $fn = $this->functionAt($fnRanges, $q['line']);
            $risk = [];
            if ($construction === 'concatenation' && $fn) {
                $risk[] = 'dynamic_sql_by_concatenation';
            }
            if (in_array($op, ['UPDATE', 'DELETE'], true) && !$hasWhere) {
                $risk[] = 'write_without_where';
            }
            $out[] = [
                'operation'    => $op,
                'table'        => $tables[0] ?? null,
                'tables'       => $tables,
                'function'     => $fn,
                'executor'     => $executor,
                'construction' => $construction,
                'read_fields'  => $readF,
                'write_fields' => $writeF,
                'where_fields' => $whereF,
                'fields'       => array_values(array_unique(array_merge($readF, $writeF, $whereF))),
                'has_where'    => $hasWhere,
                'risk_flags'   => $risk,
                'evidence'     => [
                    'line'       => $q['line'],                        // compat
                    'line_start' => $q['line_start'] ?? $q['line'],
                    'line_end'   => $q['line_end'] ?? $q['line'],
                ],
            ];
        }
        return $out;
    }

    private function classifySqlFields(string $sql, string $op): array
    {
        $kw = ['SELECT', 'UPDATE', 'INSERT', 'DELETE', 'FROM', 'WHERE', 'SET', 'INTO', 'VALUES', 'JOIN', 'AND', 'OR', 'ON', 'AS'];
        $read = $write = $where = [];
        $ident = fn ($s) => array_values(array_filter(array_map('strtoupper', preg_match_all('/\b([A-Z][A-Z0-9_]{2,})\s*(?:=|<>|>=|<=|>|<|\bLIKE\b|\bIN\b)/i', $s, $mm) ? $mm[1] : []), fn ($f) => !in_array($f, $kw, true)));
        // SET ... WHERE ...
        if (preg_match('/\bSET\b(.*?)(?:\bWHERE\b(.*))?$/is', $sql, $mm)) {
            $write = $ident($mm[1] ?? '');
            $where = $ident($mm[2] ?? '');
        }
        if ($op === 'DELETE' && preg_match('/\bWHERE\b(.*)$/is', $sql, $mm)) {
            $where = $ident($mm[1]);
        }
        if ($op === 'INSERT' && preg_match('/INTO\s+[A-Za-z0-9_%]+\s*\(([^)]*)\)/i', $sql, $mm)) {
            foreach (preg_split('/\s*,\s*/', trim($mm[1])) as $c) {
                $c = strtoupper(trim($c));
                if ($c !== '') {
                    $write[] = $c;
                }
            }
        }
        if ($op === 'SELECT') {
            if (preg_match('/SELECT\s+(.*?)\s+FROM/is', $sql, $mm) && !str_contains($mm[1], '*')) {
                foreach (preg_split('/\s*,\s*/', trim($mm[1])) as $c) {
                    $c = strtoupper(trim(preg_replace('/\s+AS\s+.*/i', '', $c)));
                    if ($c !== '' && preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $c)) {
                        $read[] = $c;
                    }
                }
            }
            if (preg_match('/\bWHERE\b(.*)$/is', $sql, $mm)) {
                $where = $ident($mm[1]);
            }
        }
        $u = fn ($a) => array_values(array_unique($a));
        return [$u($read), $u($write), $u($where)];
    }

    /** Converte as queries em eventos de acesso (tabela + campos por papel) p/ alimentar buildTables. */
    private function sqlEventsFromQueries(array $queries): array
    {
        $events = [];
        foreach ($queries as $q) {
            $line = $q['evidence']['line'] ?? 1;
            foreach ($q['tables'] as $t) {
                $events[] = ['table' => $t, 'access' => $q['operation'], 'source' => 'sql', 'line' => $line, 'function' => $q['function'], 'evidence' => ['line' => $line]];
                foreach ([['write_fields', 'write'], ['where_fields', 'where'], ['read_fields', 'read']] as [$key, $role]) {
                    foreach ($q[$key] as $f) {
                        $events[] = ['table' => $t, 'access' => $q['operation'], 'field' => $f, 'field_role' => $role, 'source' => 'sql', 'line' => $line, 'function' => $q['function'], 'evidence' => ['line' => $line]];
                    }
                }
            }
        }
        return $events;
    }

    // ── data_access vs external_integrations ──────────────────────────────────
    private function buildDataAccess(array $queries, array $events): array
    {
        $out = [];
        foreach ($queries as $q) {
            $out[] = ['type' => 'sql', 'operation' => $q['operation'], 'table' => $q['table'], 'function' => $q['function'], 'executor' => $q['executor'], 'construction' => $q['construction'], 'evidence' => $q['evidence']];
        }
        // Acessos nativos (DbSelectArea/RecLock) como acesso a dados
        foreach ($events as $e) {
            if (($e['source'] ?? '') === 'native' && in_array($e['access'], ['READ', 'INSERT', 'UPDATE', 'DELETE'], true)) {
                $out[] = ['type' => 'native', 'operation' => $e['access'], 'table' => $e['table'], 'function' => $e['function'], 'executor' => 'DBAccess (alias)', 'evidence' => $e['evidence']];
            }
        }
        return $out;
    }

    private function detectExternalIntegrations(string $mc, array $endpoints): array
    {
        $out = [];
        foreach (self::INTEGRATION_SIGNS as $type => $re) {
            if (preg_match($re, $mc)) {
                $out[] = ['type' => $type, 'evidence' => 'padrão detectado no código'];
            }
        }
        if (!empty($endpoints) && !in_array('HTTP', array_column($out, 'type'), true) && !in_array('REST', array_column($out, 'type'), true)) {
            $out[] = ['type' => 'HTTP/URL', 'evidence' => 'endpoint http detectado em string'];
        }
        return $out;
    }

    // ── dependências ──────────────────────────────────────────────────────────
    private function buildDependencies(array $includes, array $functions, array $userCalls, array $totvs, string $mc): array
    {
        $defined = array_map('strtolower', array_column($functions, 'name'));
        $custom = [];
        foreach ($userCalls as $uc) {
            $bare = strtolower(preg_replace('/^U_/i', '', $uc));
            $custom[] = ['name' => $uc, 'defined_here' => in_array($bare, $defined, true)];
        }
        // chamadas a funções do próprio arquivo que não são U_ nem TOTVS
        preg_match_all('/\bClass\s+([A-Za-z_][A-Za-z0-9_]*)/i', $mc, $cm);
        return [
            'includes'                   => $includes,
            'internal_functions'         => array_values(array_column($functions, 'name')),
            'custom_external_functions'  => array_values(array_filter($custom, fn ($c) => !$c['defined_here'])),
            'totvs_framework_functions'  => $totvs,
            'classes'                    => array_values(array_unique($cm[1])),
            'apis'                       => [], // preenchido pela detecção de integração externa quando houver endpoint nomeado
        ];
    }

    // ── efeitos (comprovados deterministicamente) ─────────────────────────────
    private function buildEffects(AdvplLexer $lex, string $mc, string $mn, array $fnRanges, array $queries): array
    {
        $out = [];
        $emit = function (string $type, ?string $target, int $line) use (&$out, $fnRanges): void {
            $out[] = ['type' => $type, 'target' => $target, 'function' => $this->functionAt($fnRanges, $line), 'evidence' => ['line' => $line]];
        };
        foreach ($queries as $q) {
            if ($q['operation'] === 'UPDATE' || $q['operation'] === 'INSERT') {
                $emit('database_write', $q['table'], $q['evidence']['line'] ?? 1);
            } elseif ($q['operation'] === 'DELETE') {
                $emit('database_delete', $q['table'], $q['evidence']['line'] ?? 1);
            }
        }
        foreach ($this->matchAll('/\bRecLock\s*\(\s*["\']([A-Za-z0-9]{2,10})["\']/i', $mn) as $x) {
            $emit('database_write', strtoupper($x['g'][1]), $lex->lineAt($x['offset']));
        }
        foreach ($this->matchAll('/\b(FCreate|FWrite|MsFCreate|MemoWrite)\s*\(/i', $mc) as $x) {
            $emit('file_write', null, $lex->lineAt($x['offset']));
        }
        foreach ($this->matchAll('/\b(FWRest|HttpGet|HttpPost|HttpQuote|SoapRequest)\s*\(/i', $mc) as $x) {
            $emit('external_call', null, $lex->lineAt($x['offset']));
        }
        foreach ($this->matchAll('/\bMsExecAuto\s*\(/i', $mc) as $x) {
            $emit('routine_execution', null, $lex->lineAt($x['offset']));
        }
        // variáveis PRIVATE/PUBLIC (efeito de escopo)
        foreach ($this->matchAll('/\b(Private|Public)\s+([A-Za-z_][A-Za-z0-9_]*)/i', $mc) as $x) {
            $emit('scoped_variable', strtoupper($x['g'][1]) . ' ' . $x['g'][2], $lex->lineAt($x['offset']));
        }
        return $out;
    }

    // ── call graph categorizado ───────────────────────────────────────────────
    private function buildCallGraph(array $functions): array
    {
        // mapa nome-normalizado (casing da DEFINIÇÃO local) por chave minúscula sem U_.
        $nameByLc = [];
        foreach ($functions as $f) {
            $nameByLc[strtolower($f['name'])] = $f['name'];
        }
        $edges = [];
        foreach ($functions as $f) {
            foreach ($f['calls_internal'] as $c) {
                $lc = strtolower($c);
                $edges[] = [
                    'from'      => $f['name'],
                    'to'        => $nameByLc[$lc] ?? $c, // identidade normalizada (casing da definição)
                    'called_as' => $c,                   // como foi escrito no fonte
                    'kind'      => 'internal',
                ];
            }
            foreach ($f['calls_user'] as $c) {
                $bare = preg_replace('/^U_/i', '', $c);
                $lc = strtolower($bare);
                $defined = isset($nameByLc[$lc]);
                $edges[] = [
                    'from'      => $f['name'],
                    // normaliza p/ o nome da função quando a definição local é conhecida (U_FOO → FOO);
                    // se for externa (sem definição no fonte), usa o nome sem o prefixo U_ como identidade.
                    'to'        => $defined ? $nameByLc[$lc] : $bare,
                    'called_as' => $c,                   // sintaxe AdvPL da chamada (U_FOO)
                    'kind'      => $defined ? 'internal' : 'custom_external',
                ];
            }
        }
        return $edges;
    }

    // ── mapa técnico estruturado ──────────────────────────────────────────────
    private function buildTechnicalFlow(array $functions, array $queries): array
    {
        $flow = [];
        // ponto de entrada = função não chamada por ninguém (ou 1ª User Function)
        $calledNames = [];
        foreach ($functions as $f) {
            foreach (array_merge($f['calls_internal'], $f['calls_user']) as $c) {
                $calledNames[strtolower(preg_replace('/^U_/i', '', $c))] = true;
            }
        }
        $entries = array_values(array_filter($functions, fn ($f) => !isset($calledNames[strtolower($f['name'])])));
        if (empty($entries)) {
            $entries = array_slice($functions, 0, 1);
        }
        $byName = [];
        foreach ($functions as $f) {
            $byName[strtolower($f['name'])] = $f;
        }
        $qByFn = [];
        foreach ($queries as $q) {
            if ($q['function']) {
                $qByFn[strtolower($q['function'])][] = $q;
            }
        }
        $seen = [];
        $walk = function (string $name, int $depth) use (&$walk, &$flow, &$seen, $byName, $qByFn): void {
            $lc = strtolower(preg_replace('/^U_/i', '', $name));
            if (isset($seen[$lc]) || $depth > 8) {
                return;
            }
            $seen[$lc] = true;
            $f = $byName[$lc] ?? null;
            if (!$f) {
                return;
            }
            $flow[] = ['type' => 'function', 'name' => $f['name']];
            // entrada de usuário (GETs de tela) dentro da função — heurística determinística
            $inputs = $this->userInputsIn($f);
            if (!empty($inputs)) {
                $flow[] = ['type' => 'user_input', 'function' => $f['name'], 'fields' => $inputs];
            }
            foreach (array_merge($f['calls_internal'], $f['calls_user']) as $c) {
                $lcTo = strtolower(preg_replace('/^U_/i', '', $c));
                $flow[] = ['type' => 'function_call', 'from' => $f['name'], 'to' => $byName[$lcTo]['name'] ?? preg_replace('/^U_/i', '', $c), 'called_as' => $c];
            }
            foreach ($qByFn[$lc] ?? [] as $q) {
                $flow[] = ['type' => 'database_operation', 'operation' => $q['operation'], 'table' => $q['table'], 'function' => $f['name']];
            }
            foreach (array_merge($f['calls_internal'], $f['calls_user']) as $c) {
                $walk($c, $depth + 1);
            }
        };
        foreach ($entries as $e) {
            $walk($e['name'], 0);
        }
        return $flow;
    }

    /** GETs de tela dentro do corpo da função (entrada de usuário determinística). Best-effort. */
    private function userInputsIn(array $f): array
    {
        // não temos o corpo aqui de forma limpa; deixamos vazio quando não determinável.
        return $f['_inputs'] ?? [];
    }

    // ── extratores auxiliares (mantidos) ──────────────────────────────────────
    private function extractSx6(string $mn): array
    {
        preg_match_all('/\b(?:GetMv|SuperGetMv|GetNewPar)\s*\(\s*["\'](MV_[A-Za-z0-9_]+)["\']/i', $mn, $m);
        return array_values(array_unique(array_map('strtoupper', $m[1])));
    }

    private function extractEndpoints(AdvplLexer $lex): array
    {
        $out = [];
        foreach ($lex->strings as $s) {
            if (preg_match_all('#(https?://[^\s"\'\\\\]+)#i', $s['value'], $m)) {
                foreach ($m[1] as $u) {
                    if (!in_array($u, $out, true)) {
                        $out[] = $u;
                    }
                }
            }
        }
        return $out;
    }

    private function extractPaths(AdvplLexer $lex): array
    {
        $out = [];
        foreach ($lex->strings as $s) {
            $v = trim($s['value']);
            if ($v === '') {
                continue;
            }
            $isPath = preg_match('#^[A-Za-z]:\\\\#', $v) || preg_match('#^\\\\\\\\#', $v)
                || (preg_match('/\.(xml|txt|pdf|log|dbf|csv|json|zip|xls|xlsx)$/i', $v) && (str_contains($v, '\\') || str_contains($v, '/')));
            if ($isPath && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private function extractIncludes(string $mn): array
    {
        preg_match_all('/#\s*include\s+["<]([^">]+)[">]/i', $mn, $m);
        return array_values(array_unique($m[1]));
    }

    private function extractTotvsCalls(string $mc): array
    {
        $out = [];
        foreach (self::TOTVS as $fn) {
            if (preg_match('/\b' . preg_quote($fn, '/') . '\s*\(/i', $mc)) {
                $out[] = $fn;
            }
        }
        return array_values(array_unique($out));
    }

    private function extractUserCalls(string $mc): array
    {
        preg_match_all('/\bU_([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $mc, $m);
        return array_values(array_unique(array_map(fn ($x) => 'U_' . $x, $m[1])));
    }

    private function detectErrorHandling(string $mc): array
    {
        return [
            'try_catch'      => (bool) preg_match('/\btry\b[\s\S]{0,4000}?\bcatch\b/i', $mc),
            'begin_sequence' => (bool) preg_match('/\bbegin\s+sequence\b/i', $mc),
            'error_block'    => (bool) preg_match('/\b(ErrorBlock|SetErrorBlock)\s*\(/i', $mc),
            'validations'    => preg_match_all('/\bif\b[^\n]*\n[^\n]*\breturn\b/i', $mc),
            'logs'           => preg_match_all('/\b(ConOut|MemoWrite)\s*\(/i', $mc),
        ];
    }

    // ── compat (chaves antigas p/ o renderer atual) ───────────────────────────
    private function legacyIntegrations(array $external, array $dataAccess): array
    {
        $out = array_map(fn ($x) => ['type' => $x['type'], 'tech' => $x['type']], $external);
        if (!empty($dataAccess)) {
            $out[] = ['type' => 'Banco de dados (SQL direto)', 'tech' => 'TCSQLExec/TCQuery'];
        }
        return $out;
    }

    private function legacyWriteEffects(array $effects): array
    {
        $out = [];
        foreach ($effects as $e) {
            if (in_array($e['type'], ['database_write', 'database_delete', 'file_write', 'routine_execution'], true)) {
                $out[] = ['type' => str_replace('database_', 'sql_', $e['type']), 'target' => $e['target'] ?? '(sql)'];
            }
        }
        return $out;
    }
}
