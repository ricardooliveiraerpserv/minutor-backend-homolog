<?php

namespace App\SourceCode\Analyzer;

/**
 * Extração DETERMINÍSTICA de AdvPL/TL++ (sem IA). Usa o AdvplLexer para não confundir código
 * com comentário/string, e um conjunto de extratores por padrão. Produz um deterministic_json
 * estruturado e versionado (schema_version). É a "descoberta" do código — a Fase 2 (IA) só
 * explica o que já foi extraído aqui.
 */
class AdvplAnalyzer
{
    public const SCHEMA_VERSION = 1;
    public const ANALYZER_VERSION = '1.0';

    /** Palavras estruturais que aparecem como "NOME(" mas NÃO são chamada de função. */
    private const NON_CALLS = [
        'if', 'iif', 'elseif', 'else', 'endif', 'while', 'enddo', 'for', 'next', 'do', 'case', 'endcase',
        'otherwise', 'return', 'local', 'private', 'public', 'static', 'default', 'begin', 'end', 'sequence',
        'recover', 'try', 'catch', 'finally', 'function', 'method', 'class', 'endclass', 'switch', 'loop', 'exit',
        'and', 'or', 'not',
    ];

    /** Funções TOTVS/AdvPL relevantes para documentação (detecção estrutural). */
    private const TOTVS = [
        'MsExecAuto', 'ExecAuto', 'FWRest', 'TRest', 'HttpGet', 'HttpPost', 'HttpQuote', 'WsMethod', 'WsService',
        'TWsdlManager', 'ApMsgYesNo', 'MsgBox', 'MsgInfo', 'MsgStop', 'MsgAlert', 'ConOut', 'MemoWrite', 'FCreate',
        'FWrite', 'MsFCreate', 'TCSQLExec', 'TCQuery', 'SqlToTrb', 'GetMv', 'SuperGetMv', 'GetNewPar', 'Pergunte',
        'ParamBox', 'RecLock', 'MsUnlock', 'MsUnLock', 'DbSelectArea', 'DbSetOrder', 'DbSeek', 'DbSetFilter',
        'DbGoTop', 'DbSkip', 'GetArea', 'RestArea', 'FieldGet', 'FieldPut', 'MSDIALOG', 'FWFormFunction', 'JsonObject',
        'FwJsonSerialize', 'FwJsonDeserialize', 'OemToAnsi', 'AnsiToOem', 'Alert',
    ];

    public function analyze(string $code, array $meta = []): array
    {
        $lex = new AdvplLexer($code);
        $mc = $lex->maskCode;         // sem comentários e sem strings
        $mn = $lex->maskNoComments;   // sem comentários, com strings

        $functions = $this->extractFunctions($lex, $mc);
        $tables = $this->extractTables($lex, $mc, $mn);
        $queries = $this->extractQueries($lex);
        $sx6 = $this->extractSx6($mn);
        $endpoints = $this->extractEndpoints($lex);
        $paths = $this->extractPaths($lex);
        $includes = $this->extractIncludes($mn);
        $totvs = $this->extractTotvsCalls($mc);
        $userCalls = $this->extractUserCalls($mc);
        $integrations = $this->detectIntegrations($mc, $queries);
        $errorHandling = $this->detectErrorHandling($mc);
        $writeEffects = $this->detectWriteEffects($mc, $queries, $tables);
        $callGraph = $this->buildCallGraph($functions);

        return [
            'schema_version'  => self::SCHEMA_VERSION,
            'analyzer_version' => self::ANALYZER_VERSION,
            'language'        => $this->guessLang($meta['path'] ?? ($meta['filename'] ?? ''), $code),
            'file'            => [
                'path'       => $meta['path'] ?? null,
                'filename'   => $meta['filename'] ?? ($meta['path'] ? basename($meta['path']) : null),
                'lines'      => substr_count($lex->code, "\n") + 1,
                'size_bytes' => strlen($code),
            ],
            'includes'        => $includes,
            'functions'       => $functions,
            'call_graph'      => $callGraph,
            'tables'          => array_values($tables),
            'queries'         => $queries,
            'sx6_params'      => $sx6,
            'endpoints'       => $endpoints,
            'paths'           => $paths,
            'totvs_calls'     => $totvs,
            'user_calls'      => $userCalls,
            'integrations'    => $integrations,
            'error_handling'  => $errorHandling,
            'write_effects'   => $writeEffects,
            'stats'           => [
                'functions' => count($functions),
                'tables'    => count($tables),
                'queries'   => count($queries),
                'lines'     => substr_count($lex->code, "\n") + 1,
                'includes'  => count($includes),
                'endpoints' => count($endpoints),
            ],
        ];
    }

    // ── funções + corpo + chamadas ────────────────────────────────────────────
    private function extractFunctions(AdvplLexer $lex, string $mc): array
    {
        $re = '/\b(user\s+function|static\s+function|wsmethod|wsrestful|wsservice|function|method|class)\s+([A-Za-z_][A-Za-z0-9_]*)\s*(?:\(([^)]*)\))?/i';
        preg_match_all($re, $mc, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $decls = [];
        foreach ($m as $x) {
            $decls[] = [
                'type'   => $this->normFnType($x[1][0]),
                'name'   => $x[2][0],
                'params' => $this->splitParams($x[3][0] ?? ''),
                'offset' => $x[0][1],
            ];
        }
        $names = array_map(fn ($d) => strtolower($d['name']), $decls);
        $out = [];
        $count = count($decls);
        for ($i = 0; $i < $count; $i++) {
            $d = $decls[$i];
            $from = $d['offset'];
            $to = $i + 1 < $count ? $decls[$i + 1]['offset'] : strlen($mc);
            $body = substr($mc, $from, $to - $from);
            $out[] = [
                'name'           => $d['name'],
                'type'           => $d['type'],
                'params'         => $d['params'],
                'returns'        => $this->extractReturns($body),
                'start_line'     => $lex->lineAt($from),
                'end_line'       => max($lex->lineAt($from), $lex->lineAt($to) - 1),
                'calls_internal' => $this->callsInternal($body, $names, $d['name']),
                'calls_user'     => $this->userCallsIn($body),
                'writes'         => (bool) preg_match('/\b(RecLock|FieldPut|MsUnlock|MsExecAuto|FCreate|FWrite|TCSQLExec)\b/i', $body),
            ];
        }
        return $out;
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
            $p = trim($p);
            // remove tipagem TL++ "cVar as character" / valores default "x := y"
            $p = preg_replace('/\s+as\s+\w+/i', '', $p);
            $p = preg_replace('/\s*:?=.*/', '', $p);
            return trim($p);
        }, explode(',', $raw)), fn ($p) => $p !== ''));
    }

    private function extractReturns(string $body): array
    {
        preg_match_all('/\breturn\b[ \t]*([^\n]*)/i', $body, $m);
        $out = [];
        foreach ($m[1] as $r) {
            $r = trim($r);
            $r = rtrim($r, '; ');
            if ($r !== '' && !in_array(strtolower($r), $out, true)) {
                $out[] = $r;
            }
        }
        return array_slice($out, 0, 12);
    }

    private function callsInternal(string $body, array $names, string $self): array
    {
        preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $body, $m);
        $out = [];
        foreach ($m[1] as $name) {
            $l = strtolower($name);
            if ($l === strtolower($self) || in_array($l, self::NON_CALLS, true)) {
                continue;
            }
            if (in_array($l, $names, true) && !in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function userCallsIn(string $body): array
    {
        preg_match_all('/\bU_([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $body, $m);
        return array_values(array_unique(array_map(fn ($x) => 'U_' . $x, $m[1])));
    }

    private function buildCallGraph(array $functions): array
    {
        $edges = [];
        foreach ($functions as $f) {
            foreach ($f['calls_internal'] as $c) {
                $edges[] = ['from' => $f['name'], 'to' => $c, 'kind' => 'internal'];
            }
            foreach ($f['calls_user'] as $c) {
                $edges[] = ['from' => $f['name'], 'to' => $c, 'kind' => 'user'];
            }
        }
        return $edges;
    }

    // ── tabelas / aliases / campos ────────────────────────────────────────────
    private function extractTables(AdvplLexer $lex, string $mc, string $mn): array
    {
        $tables = [];
        $ensure = function (string $alias) use (&$tables): void {
            $a = strtoupper($alias);
            $tables[$a] ??= ['alias' => $a, 'access' => [], 'fields' => [], 'orders' => [], 'via' => [], 'dynamic' => false];
        };
        $addAccess = function (string $alias, string $acc) use (&$tables): void {
            $a = strtoupper($alias);
            if (!in_array($acc, $tables[$a]['access'], true)) {
                $tables[$a]['access'][] = $acc;
            }
        };
        $addVia = function (string $alias, string $via) use (&$tables): void {
            $a = strtoupper($alias);
            if (!in_array($via, $tables[$a]['via'], true)) {
                $tables[$a]['via'][] = $via;
            }
        };

        // DbSelectArea("SXX") — precisa da string → maskNoComments
        if (preg_match_all('/\bDbSelectArea\s*\(\s*["\']([A-Za-z0-9]{2,10})["\']/i', $mn, $m)) {
            foreach ($m[1] as $t) {
                $ensure($t);
                $addAccess($t, 'read');
                $addVia($t, 'native');
            }
        }
        // RecLock("SXX", .T./.F.) → inclusão (.T.) / alteração (.F.)
        if (preg_match_all('/\bRecLock\s*\(\s*["\']([A-Za-z0-9]{2,10})["\']\s*,\s*(\.[TtFf]\.)/i', $mn, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $ensure($x[1]);
                $addAccess($x[1], strtoupper($x[2]) === '.T.' ? 'insert' : 'update');
                $addVia($x[1], 'native');
            }
        }
        // alias->CAMPO (não seguido de '(' = não é método de objeto) — maskCode (fora de string/coment)
        if (preg_match_all('/\b([A-Za-z][A-Za-z0-9_]{1,9})->([A-Za-z][A-Za-z0-9_]*)\s*(\(?)/', $mc, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if ($x[3] === '(') {
                    continue; // obj->Metodo() → não é campo de tabela
                }
                $alias = strtoupper($x[1]);
                $field = strtoupper($x[2]);
                // heurística: campo Protheus costuma ser XN_... ; ainda assim registramos o par
                $ensure($alias);
                $addVia($alias, 'native');
                if (!in_array($field, $tables[$alias]['fields'], true)) {
                    $tables[$alias]['fields'][] = $field;
                }
            }
        }
        // (cAlias)->CAMPO dinâmico
        if (preg_match_all('/\)\s*->([A-Za-z][A-Za-z0-9_]*)/', $mc, $m)) {
            // marca que há acesso por alias dinâmico (não sabemos a tabela)
            if (!empty($m[1])) {
                $tables['(dinâmico)'] ??= ['alias' => '(alias dinâmico)', 'access' => ['read'], 'fields' => [], 'orders' => [], 'via' => ['native'], 'dynamic' => true];
                foreach ($m[1] as $f) {
                    $f = strtoupper($f);
                    if (!in_array($f, $tables['(dinâmico)']['fields'], true)) {
                        $tables['(dinâmico)']['fields'][] = $f;
                    }
                }
            }
        }
        // DbSetOrder(n) associado ao último alias — registramos globalmente as ordens vistas
        if (preg_match_all('/\bDbSetOrder\s*\(\s*(\d+)\s*\)/i', $mc, $m)) {
            $orders = array_values(array_unique(array_map('intval', $m[1])));
            foreach ($tables as $a => &$t) {
                // não temos o alias exato do DbSetOrder sem análise de fluxo; registramos as ordens no nível do arquivo
            }
            unset($t);
        }
        // Tabelas + campos citados em SQL (FROM/UPDATE/INTO/JOIN + SET/WHERE) — via query
        foreach ($this->extractQueries($lex) as $q) {
            foreach ($q['tables'] as $t) {
                $ensure($t);
                $addAccess($t, $this->sqlOpToAccess($q['operation']));
                $addVia($t, 'sql');
                $tu = strtoupper($t);
                foreach ($q['fields'] ?? [] as $f) {
                    if (!in_array($f, $tables[$tu]['fields'], true)) {
                        $tables[$tu]['fields'][] = $f;
                    }
                }
            }
        }
        return $tables;
    }

    private function sqlOpToAccess(string $op): string
    {
        return match (strtoupper($op)) {
            'UPDATE' => 'update',
            'INSERT' => 'insert',
            'DELETE' => 'delete',
            default  => 'read',
        };
    }

    // ── queries / SQL ─────────────────────────────────────────────────────────
    private function extractQueries(AdvplLexer $lex): array
    {
        // Monta a SQL concatenada: começa num literal com verbo SQL (SELECT/UPDATE/INSERT/DELETE)
        // e acumula os literais SEGUINTES próximos (≤3 linhas) — mesmo sem keyword — porque os
        // fragmentos de campo/valor ("EMAIL = '", "'1'"...) não têm keyword mas fazem parte da query.
        $startKw = '/\b(SELECT|UPDATE|INSERT\s+INTO|DELETE\s+FROM)\b/i';
        $out = [];
        $buf = null; // ['text','start','last']
        $flush = function () use (&$buf, &$out): void {
            if ($buf !== null) {
                $out[] = $this->parseSql(trim(preg_replace('/\s+/', ' ', $buf['text'])), $buf['start']);
                $buf = null;
            }
        };
        foreach ($lex->strings as $s) {
            $v = $s['value'];
            if ($buf === null) {
                if (preg_match($startKw, $v)) {
                    $buf = ['text' => $v, 'start' => $s['line'], 'last' => $s['line']];
                }
                continue;
            }
            if ($s['line'] - $buf['last'] <= 3) {
                $buf['text'] .= ' ' . $v;
                $buf['last'] = $s['line'];
            } else {
                $flush();
                if (preg_match($startKw, $v)) {
                    $buf = ['text' => $v, 'start' => $s['line'], 'last' => $s['line']];
                }
            }
        }
        $flush();
        return $out;
    }

    private function parseSql(string $sql, ?int $line): array
    {
        $op = 'SELECT';
        if (preg_match('/^\s*UPDATE/i', $sql)) {
            $op = 'UPDATE';
        } elseif (preg_match('/INSERT\s+INTO/i', $sql)) {
            $op = 'INSERT';
        } elseif (preg_match('/DELETE\s+FROM/i', $sql)) {
            $op = 'DELETE';
        }
        $tables = [];
        if (preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+([A-Za-z0-9_%]+)/i', $sql, $m)) {
            foreach ($m[1] as $t) {
                $t = strtoupper(trim($t, '%'));
                if ($t !== '' && !in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }
        // Campos candidatos: identificadores antes de '=' (SET/WHERE) + colunas de INSERT INTO (...).
        $fields = [];
        $sqlKw = ['SELECT', 'UPDATE', 'INSERT', 'DELETE', 'FROM', 'WHERE', 'SET', 'INTO', 'VALUES', 'JOIN', 'AND', 'OR', 'ON', 'AS'];
        if (preg_match_all('/\b([A-Z][A-Z0-9_]{2,})\s*(?:=|<>|>=|<=|>|<|\bLIKE\b|\bIN\b)/i', $sql, $fm)) {
            foreach ($fm[1] as $f) {
                $f = strtoupper($f);
                if (!in_array($f, $sqlKw, true) && !in_array($f, $tables, true) && !in_array($f, $fields, true)) {
                    $fields[] = $f;
                }
            }
        }
        if (preg_match('/INSERT\s+INTO\s+[A-Za-z0-9_%]+\s*\(([^)]*)\)/i', $sql, $cm)) {
            foreach (preg_split('/\s*,\s*/', trim($cm[1])) as $col) {
                $col = strtoupper(trim($col));
                if ($col !== '' && !in_array($col, $fields, true)) {
                    $fields[] = $col;
                }
            }
        }
        return [
            'operation'   => $op,
            'tables'      => $tables,
            'fields'      => $fields,
            'line'        => $line,
            'has_where'   => (bool) preg_match('/\bWHERE\b/i', $sql),
            'preview'     => mb_substr($sql, 0, 160),
        ];
    }

    // ── demais extratores ─────────────────────────────────────────────────────
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
            $isPath = preg_match('#^[A-Za-z]:\\\\#', $v)            // C:\...
                || preg_match('#^\\\\\\\\#', $v)                     // \\server
                || (str_contains($v, '/') && preg_match('/\.(xml|txt|pdf|log|dbf|csv|json|zip|xls|xlsx)$/i', $v))
                || preg_match('/\.(xml|txt|pdf|log|dbf|csv|json|zip|xls|xlsx)$/i', $v) && (str_contains($v, '\\') || str_contains($v, '/'));
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

    private function detectIntegrations(string $mc, array $queries): array
    {
        $out = [];
        $add = function (string $type, string $tech) use (&$out): void {
            $out[] = ['type' => $type, 'tech' => $tech];
        };
        if (preg_match('/\b(FWRest|TRest|HttpGet|HttpPost|HttpQuote|FWMakeGet|FWMakePost)\s*\(/i', $mc)) {
            $add('REST/HTTP', 'FWRest/HttpGet/HttpPost');
        }
        if (preg_match('/\b(TWsdlManager|WsMethod|WsService|WsRestful|SOAP)\b/i', $mc)) {
            $add('WebService/SOAP', 'WSDL/WSMethod');
        }
        if (preg_match('/\b(MsExecAuto|ExecAuto)\s*\(/i', $mc)) {
            $add('Automação de rotina Protheus', 'MsExecAuto');
        }
        if (!empty($queries)) {
            $add('Banco de dados (SQL direto)', 'TCSQLExec/TCQuery');
        }
        return $out;
    }

    private function detectErrorHandling(string $mc): array
    {
        return [
            'try_catch'       => (bool) preg_match('/\btry\b[\s\S]{0,4000}?\bcatch\b/i', $mc),
            'begin_sequence'  => (bool) preg_match('/\bbegin\s+sequence\b/i', $mc),
            'error_block'     => (bool) preg_match('/\b(ErrorBlock|SetErrorBlock)\s*\(/i', $mc),
            'validations'     => preg_match_all('/\bif\b[^\n]*\n[^\n]*\breturn\b/i', $mc),
            'logs'            => preg_match_all('/\b(ConOut|MemoWrite)\s*\(/i', $mc),
        ];
    }

    private function detectWriteEffects(string $mc, array $queries, array $tables): array
    {
        $out = [];
        foreach ($queries as $q) {
            if (in_array($q['operation'], ['UPDATE', 'INSERT', 'DELETE'], true)) {
                $out[] = ['type' => 'sql_' . strtolower($q['operation']), 'target' => implode(',', $q['tables']) ?: '(sql)'];
            }
        }
        if (preg_match('/\bRecLock\s*\(/i', $mc) && preg_match('/\b(FieldPut|MsUnlock|MsUnLock)\s*\(/i', $mc)) {
            $out[] = ['type' => 'native_write', 'target' => 'RecLock/FieldPut'];
        }
        if (preg_match('/\bMsExecAuto\s*\(/i', $mc)) {
            $out[] = ['type' => 'routine_write', 'target' => 'MsExecAuto'];
        }
        if (preg_match('/\b(FCreate|FWrite|MsFCreate|MemoWrite)\s*\(/i', $mc)) {
            $out[] = ['type' => 'file_write', 'target' => 'arquivo'];
        }
        return $out;
    }

    private function guessLang(string $path, string $code): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['tlpp', 'tlp'], true)) {
            return 'tlpp';
        }
        if (preg_match('/\b(namespace|using|class\s+\w+\s*(?:from|-)\s*)/i', $code) && $ext === '') {
            return 'tlpp';
        }
        return 'advpl';
    }
}
