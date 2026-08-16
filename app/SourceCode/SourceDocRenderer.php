<?php

namespace App\SourceCode;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;

/**
 * Bloco 5 — RENDERER. Apresenta (não descobre) a documentação técnica a partir dos dados já
 * estruturados: deterministic_json (Analyzer) + diff (SourceDiff) + semantic_json (quando houver)
 * + status (SourceDocStatusResolver) + histórico (source_doc_versions). NÃO recalcula funções,
 * tabelas, SQL, dependências, risco ou status. Sem IA. Tolerante a documentos antigos (chaves
 * ausentes viram [] / — sem quebrar). Nunca imprime segredo (só type/location/severity).
 *
 * Formatos: docx (principal, PhpWord — headings nativos p/ TOC + keepNext + page-break nas seções
 * pesadas; conteúdo via Html com estilos inline), html/pdf/markdown (mesma composição).
 *
 * $context (opcional, retrocompatível): ['status' => <resolver result>, 'versions' => [<rows>]].
 */
class SourceDocRenderer
{
    private const SEM_PENDING = 'Descrição funcional ainda não disponível (análise semântica pendente). Os dados técnicos abaixo permanecem válidos.';
    private const SEM_RULES   = 'Regras funcionais ainda não analisadas semanticamente.';
    private const UNDET       = 'Não foi possível determinar com segurança.';

    private const TABLE = 'style="border-collapse:collapse;width:100%;margin:3pt 0;font-size:9.5pt"';
    private const TH    = 'style="background:#e8f1f2;border:1px solid #b9cdd1;padding:3pt 6pt;text-align:left;color:#0d4766"';
    private const TD    = 'style="border:1px solid #b9cdd1;padding:3pt 6pt;text-align:left;vertical-align:top"';
    private const NI    = 'style="color:#6b7d80;font-style:italic"';
    private const TDL   = 'style="border:1px solid #b9cdd1;padding:3pt 6pt;vertical-align:top;width:24%;font-weight:bold;color:#3a4a4d"';
    private const TDR   = 'style="border:1px solid #b9cdd1;padding:3pt 6pt;vertical-align:top;width:24%;font-weight:bold;color:#a04318"';

    private const REASON_FRIENDLY = [
        'missing_documented_sha' => 'SHA de conteúdo não disponível para esta versão.',
        'source_not_found'       => 'O arquivo não foi encontrado no repositório atual.',
        'repository_inactive'    => 'Repositório desativado no Minutor.',
        'repository_not_found'   => 'Repositório inacessível no momento.',
        'authentication_error'   => 'Falha de autenticação ao acessar o repositório.',
        'timeout'                => 'Tempo excedido ao consultar o repositório.',
        'github_unavailable'     => 'Serviço de repositório indisponível no momento.',
        'resolution_error'       => 'Não foi possível resolver o estado do arquivo.',
    ];

    // ── acesso tolerante ───────────────────────────────────────────────────────
    private function g(array $a, string $k, $d = null)
    {
        return array_key_exists($k, $a) && $a[$k] !== null ? $a[$k] : $d;
    }

    private function e(string $x): string
    {
        return htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    }

    private function short(?string $sha, int $n = 12): string
    {
        return $sha ? substr($sha, 0, $n) : '—';
    }

    // ── modelo de apresentação (normaliza tudo o que as seções consomem) ────────
    private function model(array $doc, array $context, array $customer): array
    {
        $d = (array) $this->g($doc, 'deterministic', []);
        $s = $this->g($doc, 'semantic', null);
        $id = (array) $this->g($doc, 'identity', []);
        $v = (array) $this->g($doc, 'version', []);
        $diff = (array) $this->g($doc, 'diff', []);
        $findings = (array) $this->g($doc, 'security_findings', $this->g($d, 'security_findings', []));
        $status = (array) $this->g($context, 'status', []);
        $versions = (array) $this->g($context, 'versions', []);
        $semPending = !is_array($s) || in_array($this->g((array) $s, 'status', ''), ['pending', 'failed'], true);

        return [
            'd' => $d, 's' => is_array($s) ? $s : [], 'id' => $id, 'v' => $v, 'diff' => $diff,
            'findings' => $findings, 'status' => $status, 'versions' => $versions,
            'semPending' => $semPending, 'customer' => $customer,
        ];
    }

    // ── HTML (usado por pdf/preview; docx reusa as seções) ──────────────────────
    public function html(array $doc, bool $isOutdated = false, array $customer = [], array $context = []): string
    {
        $m = $this->model($doc, $context, $customer);
        $h = '<style>body{font-family:Arial,Helvetica,sans-serif;color:#1b2b31;font-size:10.5pt;line-height:1.4}'
            . 'h1{color:#0d4766;font-size:19pt;margin:0} h2{color:#0d4766;font-size:13pt;margin:14pt 0 4pt;border-bottom:1px solid #cfe0e3;padding-bottom:2pt}'
            . 'h3{color:#12232b;font-size:11pt;margin:10pt 0 2pt}</style>';
        $h .= $this->cover($m);
        foreach ($this->sections($m) as $sec) {
            $h .= '<h2>' . $sec['n'] . '. ' . $this->e($sec['title']) . '</h2>' . $sec['html'];
        }
        $h .= '<p style="color:#6b7d80;font-size:8pt;margin-top:16pt">Documentação gerada automaticamente pelo Minutor — representação do estado estruturado do fonte. As informações técnicas são determinísticas (extraídas do código); descrições funcionais dependem da análise semântica.</p>';
        return $h;
    }

    // ── capa / cabeçalho ────────────────────────────────────────────────────────
    private function cover(array $m): string
    {
        $id = $m['id'];
        $v = $m['v'];
        $st = $m['status'];
        $blob = $this->g($st, 'documented_blob_sha', $this->g($v, 'source_blob_sha'));
        $h = '<h1>Documentação Técnica de Fonte</h1>';
        $h .= '<div style="font-size:15pt;color:#0e7c86;margin:2pt 0 8pt"><b>' . $this->e((string) $this->g($id, 'filename', '—')) . '</b></div>';
        $rows = [
            ['Cliente', $this->g($m['customer'], 'name', ($this->g($id, 'customer_id') ? '#' . $this->g($id, 'customer_id') : '—'))],
            ['Produto', 'Protheus'],
            ['Linguagem', $this->g($id, 'lang', '—')],
            ['Repositório', $this->g($id, 'owner', '') . '/' . $this->g($id, 'repository', '')],
            ['Branch', $this->g($id, 'branch', '—')],
            ['Caminho', $this->g($id, 'path', '—')],
            ['Commit documentado', $this->short($this->g($v, 'source_commit_sha'))],
            ['Blob SHA', $this->short($blob)],
            ['GMUD', $this->g($v, 'ticket_number', '—')],
            ['Responsável', $this->g($v, 'responsavel', '—')],
        ];
        $h .= '<table ' . self::TABLE . '>';
        foreach ($rows as [$k, $val]) {
            $h .= '<tr><td ' . self::TDL . '>' . $this->e($k) . '</td><td ' . self::TD . '>' . $this->e((string) $val) . '</td></tr>';
        }
        $h .= '</table>';
        $h .= $this->statusBox($m);
        return $h;
    }

    private function statusBox(array $m): string
    {
        $st = $m['status'];
        $status = (string) $this->g($st, 'status', '');
        [$label, $color, $bg] = match ($status) {
            'ATUALIZADA'    => ['ATUALIZADA', '1c6b45', 'e0f0e8'],
            'DESATUALIZADA' => ['DESATUALIZADA', 'a04318', 'f6e8d6'],
            'NAO_VALIDADO'  => ['NÃO VALIDADA', '5a5f6a', 'eceef0'],
            default         => [null, null, null],
        };
        if ($label === null) {
            return '';
        }
        $h = '<div style="margin:8pt 0;padding:6pt 10pt;background:#' . $bg . ';border-left:3px solid #' . $color . '">';
        $h .= '<b style="color:#' . $color . '">Status da documentação: ' . $label . '</b>';
        if ($status === 'DESATUALIZADA') {
            $h .= '<div style="font-size:9pt;color:#3a4a4d;margin-top:2pt">O conteúdo do arquivo mudou no repositório desde esta versão.'
                . ' Blob documentado ' . $this->short($this->g($st, 'documented_blob_sha'), 8)
                . ' · blob atual ' . $this->short($this->g($st, 'current_blob_sha'), 8)
                . ($this->g($st, 'checked_at') ? ' · validado em ' . $this->e(substr((string) $this->g($st, 'checked_at'), 0, 19)) : '') . '</div>';
        } elseif ($status === 'NAO_VALIDADO') {
            $reason = (string) $this->g($st, 'reason', '');
            $h .= '<div style="font-size:9pt;color:#3a4a4d;margin-top:2pt">Não foi possível validar se esta documentação corresponde ao código atual. '
                . $this->e(self::REASON_FRIENDLY[$reason] ?? 'Motivo técnico não especificado.') . '</div>';
        }
        $h .= '</div>';
        return $h;
    }

    // ── seções (cada uma: n, title, html, heavy) ────────────────────────────────
    /** @return list<array{n:int,title:string,html:string,heavy:bool}> */
    private function sections(array $m): array
    {
        // Bloco 4.2 — Entendimento Funcional PRIMEIRO (por que existe), depois o mapa técnico.
        return [
            ['n' => 1,  'title' => 'Entendimento Funcional',   'html' => $this->secEntendimento($m),  'heavy' => false],
            ['n' => 2,  'title' => 'Resumo para Manutenção',   'html' => $this->secResumoManutencao($m), 'heavy' => false],
            ['n' => 3,  'title' => 'Fluxo Funcional',          'html' => $this->secFlow($m),         'heavy' => false],
            ['n' => 4,  'title' => 'Regras de Negócio',        'html' => $this->secRules($m),        'heavy' => false],
            ['n' => 5,  'title' => 'Mapa Técnico',             'html' => $this->secTechFlow($m),     'heavy' => false],
            ['n' => 6,  'title' => 'Funções',                  'html' => $this->secFunctions($m),    'heavy' => true],
            ['n' => 7,  'title' => 'Call Graph',               'html' => $this->secCallGraph($m),    'heavy' => false],
            ['n' => 8,  'title' => 'Tabelas e Campos',         'html' => $this->secTables($m),       'heavy' => true],
            ['n' => 9,  'title' => 'SQL',                      'html' => $this->secSql($m),          'heavy' => false],
            ['n' => 10, 'title' => 'Acesso a Dados e Integrações', 'html' => $this->secDataVsIntegr($m), 'heavy' => false],
            ['n' => 11, 'title' => 'Dependências',             'html' => $this->secDependencies($m), 'heavy' => false],
            ['n' => 12, 'title' => 'Impactos / Efeitos',       'html' => $this->secEffects($m),      'heavy' => false],
            ['n' => 13, 'title' => 'Pontos de Atenção',        'html' => $this->secAttention($m),    'heavy' => false],
            ['n' => 14, 'title' => 'Histórico de Alterações',  'html' => $this->secHistory($m),      'heavy' => true],
            ['n' => 15, 'title' => 'Diff da Versão',           'html' => $this->secDiff($m),         'heavy' => false],
        ];
    }

    private function ni(string $msg): string
    {
        return '<p ' . self::NI . '>' . $this->e($msg) . '</p>';
    }

    // ── Bloco 4.2 — Entendimento Funcional (topo) ───────────────────────────────
    private function secEntendimento(array $m): string
    {
        if ($m['semPending']) {
            return $this->ni(self::SEM_PENDING);
        }
        $ent = (array) $this->g($m['s'], 'entendimento_funcional', []);
        // compat: schema antigo (sem entendimento_funcional) → usa objetivo/entradas/saidas soltos.
        $objetivo = (string) $this->g($ent, 'objetivo', (string) $this->g($m['s'], 'objetivo', ''));
        $uf = (array) $this->g($ent, 'uma_frase', []);
        $frase = (string) $this->g($uf, 'texto', '');

        if ($objetivo === '' && $frase === '' && empty($ent)) {
            return $this->ni('Entendimento funcional depende da análise semântica (pendente).');
        }

        $h = '';
        if ($frase !== '') {
            $h .= '<p style="font-size:12pt"><b>Em uma frase:</b> ' . $this->e($frase) . ' ' . $this->confBadge((string) $this->g($uf, 'confidence', '')) . '</p>';
        }
        $h .= '<h3>Objetivo</h3><p>' . ($objetivo !== '' ? $this->e($objetivo) : $this->undet()) . '</p>';
        $h .= '<h3>Quando é utilizado</h3><p>' . $this->e((string) $this->g($ent, 'quando_usado', self::UNDET)) . '</p>';

        $pm = (array) $this->g($ent, 'processo_modulo', []);
        $h .= '<h3>Processo / Módulo</h3><p>Processo: <b>' . $this->e((string) $this->g($pm, 'processo', self::UNDET)) . '</b>'
            . ' · Módulo: <b>' . $this->e((string) $this->g($pm, 'modulo', self::UNDET)) . '</b> ' . $this->confBadge((string) $this->g($pm, 'confidence', '')) . '</p>';

        $h .= $this->ioBlock('Entradas principais', (array) $this->g($ent, 'entradas_principais', []), (array) $this->g($m['s'], 'entradas', []));
        $h .= $this->ioBlock('Saídas principais', (array) $this->g($ent, 'saidas_principais', []), (array) $this->g($m['s'], 'saidas', []));

        $steps = (array) $this->g($ent, 'o_que_faz', []);
        if (! empty($steps)) {
            $h .= '<h3>O que faz</h3><ol>';
            foreach ($steps as $s) {
                $h .= '<li>' . $this->e((string) $this->g($s, 'passo', '')) . '</li>';
            }
            $h .= '</ol>';
        }
        return $h;
    }

    /** Bloco enxuto p/ quem vai manter o fonte: o essencial em um só lugar. */
    private function secResumoManutencao(array $m): string
    {
        $d = $m['d'];
        $entrypoints = array_values(array_filter((array) $this->g($d, 'functions', []), fn ($f) => empty($this->g($f, 'called_by', []))));
        $principal = $entrypoints[0]['name'] ?? ($this->g($d, 'functions', [])[0]['name'] ?? '—');

        $written = [];
        foreach ((array) $this->g($d, 'tables', []) as $t) {
            if (array_intersect(['UPDATE', 'INSERT', 'DELETE'], (array) $this->g($t, 'access', []))) {
                $written[] = (string) ($t['table'] ?? $t['alias'] ?? '');
            }
        }
        $deps = array_map(fn ($x) => (string) $this->g($x, 'nome', ''), (array) $this->g($m['s'], 'dependencias_criticas', []));
        $integr = array_map(fn ($x) => is_array($x) ? (string) ($x['name'] ?? $x['type'] ?? '') : (string) $x, (array) $this->g($d, 'external_integrations', []));
        $regras = (array) $this->g($m['s'], 'regras_negocio', []);
        $risco = (array) $this->g($m['s'], 'risco_alteracao', []);

        $rows = [
            ['Função principal', $this->e($principal)],
            ['Tabelas gravadas', $this->e(implode(', ', array_filter(array_unique($written))) ?: '—')],
            ['Dependências críticas', $this->e(implode(', ', array_filter($deps)) ?: ($m['semPending'] ? 'análise semântica pendente' : '—'))],
            ['Integrações', $this->e(implode(', ', array_filter($integr)) ?: '—')],
            ['Regras principais', (string) count($regras) . ($regras ? ' (ver seção Regras de Negócio)' : ($m['semPending'] ? ' — pendente' : ' — nenhuma com evidência'))],
        ];
        $h = '<table ' . self::TABLE . '>';
        foreach ($rows as [$k, $v]) {
            $h .= '<tr><td ' . self::TDL . '>' . $this->e($k) . '</td><td ' . self::TD . '>' . $v . '</td></tr>';
        }
        $h .= '</table>';

        $fatores = (array) $this->g($risco, 'fatores', []);
        if (! empty($fatores)) {
            $h .= '<h3>Fatores de risco de alteração</h3><ul>';
            foreach ($fatores as $f) {
                $h .= '<li><b>' . $this->e((string) $this->g($f, 'tipo', '')) . ':</b> ' . $this->e((string) $this->g($f, 'descricao', '')) . '</li>';
            }
            $h .= '</ul>';
        } elseif (! $m['semPending']) {
            $h .= '<p ' . self::NI . '>' . $this->e(self::UNDET) . ' (fatores de risco)</p>';
        }
        return $h;
    }

    private function ioBlock(string $title, array $rich, array $legacy): string
    {
        if (! empty($rich)) {
            $h = '<h3>' . $this->e($title) . '</h3><ul>';
            foreach ($rich as $it) {
                $nome = (string) $this->g($it, 'nome', '');
                $tipo = (string) $this->g($it, 'tipo', '');
                $desc = (string) $this->g($it, 'descricao', '');
                $h .= '<li>' . ($nome !== '' ? '<b>' . $this->e($nome) . '</b> — ' : '') . $this->e($desc) . ($tipo !== '' ? ' <span style="color:#6b7d80">(' . $this->e($tipo) . ')</span>' : '') . '</li>';
            }
            return $h . '</ul>';
        }
        if (! empty($legacy)) {
            return '<h3>' . $this->e($title) . '</h3><ul>' . implode('', array_map(fn ($x) => '<li>' . $this->e((string) $x) . '</li>', $legacy)) . '</ul>';
        }
        return '<h3>' . $this->e($title) . '</h3><p ' . self::NI . '>' . $this->e(self::UNDET) . '</p>';
    }

    private function confBadge(string $c): string
    {
        $c = strtolower($c);
        if ($c === 'low') {
            return '<span style="font-size:8pt;color:#a04318">(possível — baixa confiança)</span>';
        }
        if ($c === 'medium') {
            return '<span style="font-size:8pt;color:#6b7d80">(confiança média)</span>';
        }
        return '';
    }

    private function undet(): string
    {
        return '<span ' . self::NI . '>' . $this->e(self::UNDET) . '</span>';
    }

    private function secOverview(array $m): string
    {
        if ($m['semPending']) {
            return $this->ni(self::SEM_PENDING);
        }
        $obj = (string) $this->g($m['s'], 'objetivo', '');
        return $obj !== '' ? '<p>' . $this->e($obj) . '</p>' : $this->ni('Descrição funcional ainda não disponível.');
    }

    private function secTechFlow(array $m): string
    {
        $flow = (array) $this->g($m['d'], 'technical_flow', []);
        if (empty($flow)) {
            return $this->ni('Fluxo técnico não identificado no código.');
        }
        $cap = 40;
        $shown = array_slice($flow, 0, $cap);
        $lines = [];
        foreach ($shown as $n) {
            $t = (string) $this->g($n, 'type', '');
            $lines[] = match ($t) {
                'function'           => '<b>' . $this->e((string) $this->g($n, 'name', '')) . '</b> <span style="color:#6b7d80">(função)</span>',
                'user_input'         => 'Entrada do usuário <span style="color:#6b7d80">(' . $this->e(implode(', ', (array) $this->g($n, 'fields', []))) . ')</span>',
                'function_call'      => '→ chamada: ' . $this->e((string) $this->g($n, 'to', '')) . (($this->g($n, 'called_as') && $this->g($n, 'called_as') !== $this->g($n, 'to')) ? ' <span style="color:#6b7d80">(' . $this->e((string) $this->g($n, 'called_as')) . ')</span>' : ''),
                'database_operation' => '→ banco: <b>' . $this->e((string) $this->g($n, 'operation', '')) . '</b> ' . $this->e((string) $this->g($n, 'table', '')),
                default              => $this->e($t),
            };
        }
        $h = '<div style="font-size:10pt;line-height:1.6">' . implode(' <span style="color:#0e7c86">↓</span> ', $lines) . '</div>';
        if (count($flow) > $cap) {
            $h .= '<p ' . self::NI . '>Fluxo resumido — ' . count($flow) . ' nós no total (exibindo os ' . $cap . ' primeiros).</p>';
        }
        return $h;
    }

    private function secFlow(array $m): string
    {
        if (!$m['semPending'] && !empty($this->g($m['s'], 'fluxo', []))) {
            $h = '<ol>';
            foreach ((array) $this->g($m['s'], 'fluxo', []) as $step) {
                $h .= '<li>' . $this->e((string) $step) . '</li>';
            }
            return $h . '</ol>';
        }
        // fallback determinístico: deriva do technical_flow
        $flow = (array) $this->g($m['d'], 'technical_flow', []);
        if (empty($flow)) {
            return $this->ni(self::SEM_PENDING);
        }
        $h = '<p ' . self::NI . '>Narrativa funcional pendente — sequência determinística abaixo.</p><ol>';
        foreach (array_slice($flow, 0, 30) as $n) {
            $t = (string) $this->g($n, 'type', '');
            if ($t === 'function') {
                $h .= '<li>Executa <b>' . $this->e((string) $this->g($n, 'name', '')) . '</b></li>';
            } elseif ($t === 'function_call') {
                $h .= '<li>Chama ' . $this->e((string) $this->g($n, 'to', '')) . '</li>';
            } elseif ($t === 'database_operation') {
                $h .= '<li>' . $this->e((string) $this->g($n, 'operation', '')) . ' em ' . $this->e((string) $this->g($n, 'table', '')) . '</li>';
            }
        }
        return $h . '</ol>';
    }

    private function secFunctions(array $m): string
    {
        $fns = (array) $this->g($m['d'], 'functions', []);
        if (empty($fns)) {
            return $this->ni('Nenhuma função identificada.');
        }
        $semFn = [];
        foreach ((array) $this->g($m['s'], 'funcoes', []) as $f) {
            $semFn[strtolower((string) $this->g($f, 'name', ''))] = (string) $this->g($f, 'finalidade', '');
        }
        // resumo (sempre) — compacto
        $h = '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Função</th><th ' . self::TH . '>Tipo</th><th ' . self::TH . '>Linhas</th><th ' . self::TH . '>Acessos</th><th ' . self::TH . '>Tabelas</th></tr>';
        foreach ($fns as $f) {
            $ls = $this->g($f, 'start_line'); $le = $this->g($f, 'end_line');
            $h .= '<tr><td ' . self::TD . '><b>' . $this->e((string) $this->g($f, 'name', '')) . '</b></td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($f, 'type', '—')) . '</td>'
                . '<td ' . self::TD . '>' . ($ls ? $this->e($ls . '–' . $le) : '—') . '</td>'
                . '<td ' . self::TD . '>' . $this->e(implode(', ', (array) $this->g($f, 'accesses', [])) ?: '—') . '</td>'
                . '<td ' . self::TD . '>' . $this->e(implode(', ', (array) $this->g($f, 'tables', [])) ?: '—') . '</td></tr>';
        }
        $h .= '</table>';

        // detalhes — para as funções relevantes (evita 79 cards). Pequeno: todas.
        $relevant = array_values(array_filter($fns, fn ($f) => !empty($this->g($f, 'tables', [])) || !empty($this->g($f, 'effects', [])) || !empty(array_merge((array) $this->g($f, 'calls_internal', []), (array) $this->g($f, 'calls_user', []))) || !empty($this->g($f, 'called_by', []))));
        $detail = count($fns) <= 12 ? $fns : $relevant;
        // Bloco 4.2 — sem omitir informação silenciosamente: a tabela-resumo acima já lista TODAS
        // as funções (fatos técnicos). O detalhe estendido cobre as relevantes com folga.
        $cap = count($fns) > 12 ? 60 : 60;
        $capped = array_slice($detail, 0, $cap);
        if (!empty($capped)) {
            $h .= '<h3>Detalhe das funções</h3>';
        }
        foreach ($capped as $f) {
            $chama = array_merge((array) $this->g($f, 'calls_internal', []), (array) $this->g($f, 'calls_user', []));
            $rows = [
                ['Tipo', $this->g($f, 'type', '—')],
                ['Linhas', ($this->g($f, 'start_line') ? $this->g($f, 'start_line') . '–' . $this->g($f, 'end_line') : '—')],
                ['Parâmetros', implode(', ', (array) $this->g($f, 'params', [])) ?: '—'],
                ['Retorno', implode(' · ', (array) $this->g($f, 'returns', [])) ?: '—'],
                ['Chamado por', implode(', ', (array) $this->g($f, 'called_by', [])) ?: '—'],
                ['Chama', implode(', ', $chama) ?: '—'],
                ['Tabelas', implode(', ', (array) $this->g($f, 'tables', [])) ?: '—'],
                ['Acessos', implode(', ', (array) $this->g($f, 'accesses', [])) ?: '—'],
                ['Efeitos', implode(', ', (array) $this->g($f, 'effects', [])) ?: '—'],
            ];
            $fin = $semFn[strtolower((string) $this->g($f, 'name', ''))] ?? '';
            $h .= '<h3>' . $this->e((string) $this->g($f, 'name', '')) . '</h3><table ' . self::TABLE . '>';
            foreach ($rows as [$k, $val]) {
                $h .= '<tr><td ' . self::TDL . '>' . $this->e($k) . '</td><td ' . self::TD . '>' . $this->e((string) $val) . '</td></tr>';
            }
            $h .= '<tr><td ' . self::TDL . '>Finalidade</td><td ' . self::TD . '>' . ($fin !== '' ? $this->e($fin) : '<span ' . self::NI . '>pendente (análise semântica)</span>') . '</td></tr>';
            $h .= '</table>';
        }
        $rest = count($detail) - count($capped);
        $utils = count($fns) - count($detail);
        // Nada é "omitido por limite": a tabela-resumo acima contém TODAS as funções. O bloco de
        // detalhe apenas aprofunda as relevantes; deixamos isso explícito (sem perda de informação).
        if ($rest > 0 || $utils > 0) {
            $h .= '<p ' . self::NI . '>Todas as ' . count($fns) . ' funções constam na tabela-resumo acima (fatos técnicos completos). '
                . ($utils > 0 ? $utils . ' função(ões) utilitária(s) sem detalhe estendido. ' : '')
                . ($rest > 0 ? $rest . ' função(ões) relevante(s) adicional(is) na tabela-resumo.' : '') . '</p>';
        }
        return $h;
    }

    private function secCallGraph(array $m): string
    {
        $edges = (array) $this->g($m['d'], 'call_graph', []);
        $dep = (array) $this->g($m['d'], 'dependencies', []);
        $internal = [];
        $external = [];
        foreach ($edges as $ed) {
            $line = $this->e((string) $this->g($ed, 'from', '')) . ' → ' . $this->e((string) $this->g($ed, 'to', ''));
            if (($this->g($ed, 'kind') === 'custom_external')) {
                $ca = (string) $this->g($ed, 'called_as', '');
                $external[] = $line . ($ca && $ca !== $this->g($ed, 'to') ? ' <span style="color:#6b7d80">(' . $this->e($ca) . ')</span>' : '');
            } else {
                $internal[] = $line;
            }
        }
        $totvs = (array) $this->g($dep, 'totvs_framework_functions', []);
        if (empty($internal) && empty($external) && empty($totvs)) {
            return $this->ni('Nenhuma chamada entre funções identificada.');
        }
        $h = '';
        if (!empty($internal)) {
            $h .= '<h3>Internas (' . count($internal) . ')</h3><ul><li>' . implode('</li><li>', array_slice($internal, 0, 60)) . '</li></ul>';
            if (count($internal) > 60) {
                $h .= '<p ' . self::NI . '>+' . (count($internal) - 60) . ' chamadas internas.</p>';
            }
        }
        if (!empty($external)) {
            $h .= '<h3>Customizadas externas (' . count($external) . ')</h3><ul><li>' . implode('</li><li>', array_slice($external, 0, 40)) . '</li></ul>';
        }
        if (!empty($totvs)) {
            $h .= '<h3>Framework TOTVS (' . count($totvs) . ')</h3><p>' . $this->e(implode(', ', array_slice($totvs, 0, 40))) . (count($totvs) > 40 ? ' …' : '') . '</p>';
        }
        return $h;
    }

    private function secRules(array $m): string
    {
        if ($m['semPending'] || empty($this->g($m['s'], 'regras_negocio', []))) {
            return $this->ni(self::SEM_RULES);
        }
        $h = '';
        foreach ((array) $this->g($m['s'], 'regras_negocio', []) as $r) {
            $titulo = (string) $this->g($r, 'titulo', '');
            $h .= '<p style="margin:4pt 0 1pt"><b>' . $this->e((string) $this->g($r, 'id', 'RN')) . ($titulo !== '' ? ' — ' . $this->e($titulo) : '') . '</b> ' . $this->confBadge((string) $this->g($r, 'confidence', '')) . '</p>';
            $h .= '<p style="margin:0 0 3pt 12pt">' . $this->e((string) $this->g($r, 'descricao', ''));
            $cond = (string) $this->g($r, 'condicao', '');
            $efe = (string) $this->g($r, 'efeito', '');
            if ($cond !== '') {
                $h .= '<br><span style="color:#6b7d80">Condição:</span> ' . $this->e($cond);
            }
            if ($efe !== '') {
                $h .= '<br><span style="color:#6b7d80">Efeito:</span> ' . $this->e($efe);
            }
            $h .= '</p>';
        }
        return $h;
    }

    private function secTables(array $m): string
    {
        $tables = (array) $this->g($m['d'], 'tables', []);
        if (empty($tables)) {
            return $this->ni('Nenhuma tabela identificada no código.');
        }
        $h = '';
        // Fonte grande: resumo compacto (todas) + detalhe só das relevantes (escrita/filtro).
        $big = count($tables) > 20;
        if ($big) {
            $h .= '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Tabela</th><th ' . self::TH . '>Acesso</th><th ' . self::TH . '>Funções</th><th ' . self::TH . '>Origem</th></tr>';
            foreach ($tables as $t) {
                $h .= '<tr><td ' . self::TD . '><b>' . $this->e((string) ($this->g($t, 'table') ?? $this->g($t, 'alias', ''))) . '</b></td>'
                    . '<td ' . self::TD . '>' . $this->e(implode(', ', (array) $this->g($t, 'access', [])) ?: '—') . '</td>'
                    . '<td ' . self::TD . '>' . $this->e(implode(', ', array_slice((array) $this->g($t, 'functions', []), 0, 4)) ?: '—') . '</td>'
                    . '<td ' . self::TD . '>' . $this->e(implode(', ', (array) $this->g($t, 'source', [])) ?: '—') . '</td></tr>';
            }
            $h .= '</table>';
            $detail = array_values(array_filter($tables, fn ($t) => !empty($this->g($t, 'write_fields', [])) || !empty($this->g($t, 'where_fields', [])) || array_intersect(['UPDATE', 'INSERT', 'DELETE'], (array) $this->g($t, 'access', []))));
            $detail = array_slice($detail, 0, 25);
            if (!empty($detail)) {
                $h .= '<h3>Detalhe (tabelas com escrita/filtro)</h3>';
            }
            $tables = $detail;
        }
        foreach ($tables as $t) {
            $name = (string) ($this->g($t, 'table') ?? $this->g($t, 'alias', ''));
            $ev = (array) $this->g($t, 'evidence', []);
            $rows = [
                ['Acesso', implode(', ', (array) $this->g($t, 'access', [])) ?: '—'],
                ['Funções', implode(', ', (array) $this->g($t, 'functions', [])) ?: '—'],
                ['Campos lidos', implode(', ', (array) $this->g($t, 'read_fields', [])) ?: '—'],
                ['Campos alterados', implode(', ', (array) $this->g($t, 'write_fields', [])) ?: '—'],
                ['Campos em filtro', implode(', ', (array) $this->g($t, 'where_fields', [])) ?: '—'],
                ['Origem', implode(', ', (array) $this->g($t, 'source', [])) ?: '—'],
                ['Evidência', ($this->g($ev, 'line_start') ? 'linhas ' . $this->g($ev, 'line_start') . '–' . $this->g($ev, 'line_end') : '—')],
            ];
            $dyn = $this->g($t, 'dynamic') ? ' <span style="color:#6b7d80">(dinâmico)</span>' : '';
            $h .= '<h3>' . $this->e($name) . $dyn . '</h3><table ' . self::TABLE . '>';
            foreach ($rows as [$k, $val]) {
                $h .= '<tr><td ' . self::TDL . '>' . $this->e($k) . '</td><td ' . self::TD . '>' . $this->e((string) $val) . '</td></tr>';
            }
            $h .= '</table>';
        }
        return $h;
    }

    private function secSql(array $m): string
    {
        $queries = (array) $this->g($m['d'], 'queries', []);
        if (empty($queries)) {
            return $this->ni('Nenhuma query SQL identificada.');
        }
        $h = '';
        foreach ($queries as $q) {
            $ev = (array) $this->g($q, 'evidence', []);
            $evTxt = $this->g($ev, 'line_start') ? 'linhas ' . $this->g($ev, 'line_start') . '–' . $this->g($ev, 'line_end') : ($this->g($ev, 'line') ? 'linha ' . $this->g($ev, 'line') : '—');
            $rows = [
                ['Operação', $this->g($q, 'operation', '—')],
                ['Tabela', $this->g($q, 'table') ?: (implode(', ', (array) $this->g($q, 'tables', [])) ?: '—')],
                ['Executor', $this->g($q, 'executor', '—')],
                ['Função', $this->g($q, 'function', '—')],
                ['Construção', $this->g($q, 'construction', '—')],
                ['Campos lidos', implode(', ', (array) $this->g($q, 'read_fields', [])) ?: '—'],
                ['Campos alterados', implode(', ', (array) $this->g($q, 'write_fields', [])) ?: '—'],
                ['Filtro (WHERE)', implode(', ', (array) $this->g($q, 'where_fields', [])) ?: ($this->g($q, 'has_where') ? 'sim' : '—')],
                ['Evidência', $evTxt],
            ];
            $h .= '<h3>' . $this->e((string) $this->g($q, 'operation', 'SQL')) . ' ' . $this->e((string) ($this->g($q, 'table') ?? '')) . '</h3><table ' . self::TABLE . '>';
            foreach ($rows as [$k, $val]) {
                $h .= '<tr><td ' . self::TDL . '>' . $this->e($k) . '</td><td ' . self::TD . '>' . $this->e((string) $val) . '</td></tr>';
            }
            $risks = (array) $this->g($q, 'risk_flags', []);
            if (!empty($risks)) {
                $h .= '<tr><td ' . self::TDR . '>Risco técnico</td><td ' . self::TD . '>' . $this->e(implode(', ', $risks)) . ' <span style="color:#6b7d80">(evidência técnica — não classificada como vulnerabilidade)</span></td></tr>';
            }
            $h .= '</table>';
        }
        return $h;
    }

    private function secDataVsIntegr(array $m): string
    {
        $da = (array) $this->g($m['d'], 'data_access', []);
        $ext = (array) $this->g($m['d'], 'external_integrations', []);
        $h = '<h3>Acesso a Dados</h3>';
        if (!empty($da)) {
            // Agrega por (tipo, operação, tabela, executor) — evita centenas de linhas de acesso nativo.
            $agg = [];
            foreach ($da as $x) {
                $key = implode('|', [(string) $this->g($x, 'type', ''), (string) $this->g($x, 'operation', ''), (string) $this->g($x, 'table', ''), (string) $this->g($x, 'executor', '')]);
                if (!isset($agg[$key])) {
                    $agg[$key] = ['type' => $this->g($x, 'type', '—'), 'operation' => $this->g($x, 'operation', '—'), 'table' => $this->g($x, 'table', '—'), 'executor' => $this->g($x, 'executor', '—'), 'count' => 0, 'fns' => []];
                }
                $agg[$key]['count']++;
                $fn = (string) $this->g($x, 'function', '');
                if ($fn !== '' && !in_array($fn, $agg[$key]['fns'], true) && count($agg[$key]['fns']) < 5) {
                    $agg[$key]['fns'][] = $fn;
                }
            }
            $h .= '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Tipo</th><th ' . self::TH . '>Operação</th><th ' . self::TH . '>Tabela</th><th ' . self::TH . '>Executor</th><th ' . self::TH . '>Qtd</th><th ' . self::TH . '>Funções</th></tr>';
            foreach (array_values($agg) as $x) {
                $h .= '<tr><td ' . self::TD . '>' . $this->e((string) $x['type']) . '</td>'
                    . '<td ' . self::TD . '>' . $this->e((string) $x['operation']) . '</td>'
                    . '<td ' . self::TD . '>' . $this->e((string) $x['table']) . '</td>'
                    . '<td ' . self::TD . '>' . $this->e((string) $x['executor']) . '</td>'
                    . '<td ' . self::TD . '>' . (int) $x['count'] . '</td>'
                    . '<td ' . self::TD . '>' . $this->e(implode(', ', $x['fns']) ?: '—') . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= $this->ni('Nenhum acesso a dados identificado.');
        }
        $h .= '<h3>Integrações Externas</h3>';
        if (!empty($ext)) {
            $h .= '<ul>';
            foreach ($ext as $x) {
                $h .= '<li><b>' . $this->e((string) $this->g($x, 'type', '')) . '</b>' . ($this->g($x, 'evidence') ? ' — ' . $this->e((string) $this->g($x, 'evidence')) : '') . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= $this->ni('Nenhuma integração externa identificada automaticamente.');
        }
        return $h;
    }

    private function secDependencies(array $m): string
    {
        $h = '';
        // Bloco 4.2 — dependências CRÍTICAS interpretadas (semântico) primeiro, com explicação.
        $criticas = (array) $this->g($m['s'], 'dependencias_criticas', []);
        if (! empty($criticas)) {
            $h .= '<h3>Dependências críticas</h3>';
            foreach ($criticas as $c) {
                $h .= '<p style="margin:3pt 0"><b>' . $this->e((string) $this->g($c, 'nome', '')) . '</b> ' . $this->confBadge((string) $this->g($c, 'confidence', ''));
                $part = (string) $this->g($c, 'como_participa', '');
                $imp = (string) $this->g($c, 'impacto_se_indisponivel', '');
                if ($part !== '' && $part !== self::UNDET) {
                    $h .= '<br>' . $this->e($part);
                }
                if ($imp !== '' && $imp !== self::UNDET) {
                    $h .= '<br><span style="color:#6b7d80">Se indisponível:</span> ' . $this->e($imp);
                }
                $h .= '</p>';
            }
            $h .= '<h3>Inventário de dependências</h3>';
        }

        $dep = (array) $this->g($m['d'], 'dependencies', []);
        $custom = array_map(fn ($c) => (string) $this->g($c, 'name', ''), (array) $this->g($dep, 'custom_external_functions', []));
        $groups = [
            'Includes'             => (array) $this->g($dep, 'includes', []),
            'Funções internas'     => (array) $this->g($dep, 'internal_functions', []),
            'Funções customizadas externas' => $custom,
            'Framework TOTVS'      => (array) $this->g($dep, 'totvs_framework_functions', []),
            'Classes'              => (array) $this->g($dep, 'classes', []),
            'APIs'                 => (array) $this->g($dep, 'apis', []),
        ];
        $any = false;
        $h .= '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Grupo</th><th ' . self::TH . '>Qtd</th><th ' . self::TH . '>Itens</th></tr>';
        foreach ($groups as $name => $items) {
            if (empty($items)) {
                continue;
            }
            $any = true;
            $shown = array_slice($items, 0, 30);
            $txt = implode(', ', $shown) . (count($items) > 30 ? ' … (+' . (count($items) - 30) . ')' : '');
            $h .= '<tr><td ' . self::TD . '><b>' . $this->e($name) . '</b></td><td ' . self::TD . '>' . count($items) . '</td><td ' . self::TD . '>' . $this->e($txt) . '</td></tr>';
        }
        $h .= '</table>';
        return ($any || ! empty($criticas)) ? $h : $this->ni('Nenhuma dependência identificada.');
    }

    private function secIO(array $m): string
    {
        if (!$m['semPending'] && (!empty($this->g($m['s'], 'entradas', [])) || !empty($this->g($m['s'], 'saidas', [])))) {
            $h = '<h3>Entradas</h3><ul>' . implode('', array_map(fn ($x) => '<li>' . $this->e((string) $x) . '</li>', (array) $this->g($m['s'], 'entradas', []))) . '</ul>';
            $h .= '<h3>Saídas</h3><ul>' . implode('', array_map(fn ($x) => '<li>' . $this->e((string) $x) . '</li>', (array) $this->g($m['s'], 'saidas', []))) . '</ul>';
            return $h;
        }
        return $this->ni('Entradas e saídas dependem da análise semântica (pendente).');
    }

    private function secEffects(array $m): string
    {
        $risco = $this->riscoBlock($m);
        $effects = (array) $this->g($m['d'], 'effects', []);
        $relevant = array_values(array_filter($effects, fn ($e) => $this->g($e, 'type') !== 'scoped_variable'));
        if (empty($relevant)) {
            return $this->ni('Nenhum efeito de escrita/integração identificado.') . $risco;
        }
        $labels = ['database_write' => 'Escrita em banco', 'database_delete' => 'Exclusão em banco', 'file_write' => 'Escrita em arquivo', 'external_call' => 'Chamada externa', 'routine_execution' => 'Execução de rotina'];
        // Fonte grande: resumo por tipo (contagem) + amostra, em vez de centenas de linhas.
        if (count($relevant) > 40) {
            $byType = [];
            foreach ($relevant as $ef) {
                $byType[(string) $this->g($ef, 'type', '')][] = (string) $this->g($ef, 'target', '');
            }
            $h = '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Efeito</th><th ' . self::TH . '>Qtd</th><th ' . self::TH . '>Alvos (amostra)</th></tr>';
            foreach ($byType as $type => $targets) {
                $uniq = array_values(array_unique(array_filter($targets)));
                $h .= '<tr><td ' . self::TD . '>' . $this->e($labels[$type] ?? $type) . '</td><td ' . self::TD . '>' . count($targets) . '</td><td ' . self::TD . '>' . $this->e(implode(', ', array_slice($uniq, 0, 15)) . (count($uniq) > 15 ? ' …' : '')) . '</td></tr>';
            }
            return $h . '</table><p ' . self::NI . '>' . count($relevant) . ' efeitos no total — resumo por tipo (detalhe por linha disponível no deterministic_json).</p>' . $risco;
        }
        $h = '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Efeito</th><th ' . self::TH . '>Alvo</th><th ' . self::TH . '>Função</th><th ' . self::TH . '>Evidência</th></tr>';
        foreach ($relevant as $ef) {
            $ev = (array) $this->g($ef, 'evidence', []);
            $h .= '<tr><td ' . self::TD . '>' . $this->e($labels[(string) $this->g($ef, 'type', '')] ?? (string) $this->g($ef, 'type', '')) . '</td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($ef, 'target', '—')) . '</td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($ef, 'function', '—')) . '</td>'
                . '<td ' . self::TD . '>' . ($this->g($ev, 'line') ? 'linha ' . $this->g($ev, 'line') : '—') . '</td></tr>';
        }
        return $h . '</table>' . $risco;
    }

    /** Bloco de risco de alteração (semântico, baseado em fatores com evidência). */
    private function riscoBlock(array $m): string
    {
        $risco = (array) $this->g($m['s'], 'risco_alteracao', []);
        $fatores = (array) $this->g($risco, 'fatores', []);
        if (empty($fatores)) {
            return '';
        }
        $resumo = (string) $this->g($risco, 'resumo', '');
        $h = '<h3>Risco de alteração</h3>';
        if ($resumo !== '' && $resumo !== self::UNDET) {
            $h .= '<p>' . $this->e($resumo) . '</p>';
        }
        $h .= '<ul>';
        foreach ($fatores as $f) {
            $h .= '<li><b>' . $this->e((string) $this->g($f, 'tipo', '')) . ':</b> ' . $this->e((string) $this->g($f, 'descricao', '')) . '</li>';
        }
        return $h . '</ul>';
    }

    private function secAttention(array $m): string
    {
        $items = [];
        // risk_flags das queries (evidência técnica, sem juízo de negócio)
        foreach ((array) $this->g($m['d'], 'queries', []) as $q) {
            foreach ((array) $this->g($q, 'risk_flags', []) as $rf) {
                $ev = (array) $this->g($q, 'evidence', []);
                $loc = $this->g($q, 'function') ?: ($this->g($q, 'table') ?: '');
                $items[] = $this->e($rf) . ' — ' . $this->e((string) $loc) . ($this->g($ev, 'line_start') ? ' (linhas ' . $this->g($ev, 'line_start') . '–' . $this->g($ev, 'line_end') . ')' : '');
            }
        }
        // findings de segurança (só type/location/severity — nunca o segredo)
        foreach ((array) $m['findings'] as $f) {
            $items[] = $this->e((string) $this->g($f, 'type', '')) . ' — ' . $this->e((string) $this->g($f, 'severity', '')) . ($this->g($f, 'location') ? ' (linha ' . $this->e((string) $this->g($f, 'location')) . ')' : '');
        }
        if (!$m['semPending']) {
            foreach ((array) $this->g($m['s'], 'pontos_atencao', []) as $p) {
                $items[] = $this->e((string) $p);
            }
        }
        if (empty($items)) {
            return $this->ni('Nenhum ponto de atenção técnico identificado.');
        }
        return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    }

    private function secHistory(array $m): string
    {
        $versions = (array) $m['versions'];
        if (empty($versions)) {
            // fallback: só a versão vigente (documento antigo sem histórico passado)
            $v = $m['v'];
            $versions = [[
                'created_at' => null, 'ticket_number' => $this->g($v, 'ticket_number'),
                'responsavel' => $this->g($v, 'responsavel'), 'source_commit_sha' => $this->g($v, 'source_commit_sha'),
                'source_blob_sha' => $this->g($v, 'source_blob_sha'), 'analysis_status' => $this->g($m['status'], 'status'),
                'structural_change' => null, 'resumo' => null,
            ]];
        }
        $h = '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Data</th><th ' . self::TH . '>GMUD</th><th ' . self::TH . '>Responsável</th><th ' . self::TH . '>Commit</th><th ' . self::TH . '>Blob</th><th ' . self::TH . '>Status</th><th ' . self::TH . '>Mudança</th></tr>';
        foreach ($versions as $ver) {
            $sc = $this->g($ver, 'structural_change');
            $mud = $sc === false ? 'Não estrutural' : ($sc === true ? 'Estrutural' : '—');
            $h .= '<tr><td ' . self::TD . '>' . $this->e(substr((string) $this->g($ver, 'created_at', ''), 0, 10) ?: '—') . '</td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($ver, 'ticket_number', '—')) . '</td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($ver, 'responsavel', '—')) . '</td>'
                . '<td ' . self::TD . '>' . $this->short($this->g($ver, 'source_commit_sha'), 8) . '</td>'
                . '<td ' . self::TD . '>' . $this->short($this->g($ver, 'source_blob_sha'), 8) . '</td>'
                . '<td ' . self::TD . '>' . $this->e((string) $this->g($ver, 'analysis_status', '—')) . '</td>'
                . '<td ' . self::TD . '>' . $this->e($mud) . '</td></tr>';
            $resumo = (string) $this->g($ver, 'resumo', '');
            if ($resumo !== '') {
                $h .= '<tr><td ' . self::TD . ' colspan="7"><span style="color:#3a4a4d">' . $this->e($resumo) . '</span></td></tr>';
            }
        }
        return $h . '</table>';
    }

    private function secDiff(array $m): string
    {
        $ds = (array) $this->g($m['diff'], 'diff_stats', []);
        if (empty($ds)) {
            return $this->ni('Sem diff estrutural para esta versão.');
        }
        if (($this->g($ds, 'change_type') === 'initial')) {
            return '<p>Documentação inicial do fonte (primeira versão).</p>';
        }
        if ($this->g($ds, 'structural_change') === false) {
            return '<p>Alteração não estrutural (comentário/formatação). Linhas: +' . (int) $this->g($ds, 'lines_added', 0) . ' / −' . (int) $this->g($ds, 'lines_removed', 0) . '.</p>';
        }
        $fmt = fn ($a, $r, $c = null) => '+' . (int) $this->g($ds, $a, 0) . ' / −' . (int) $this->g($ds, $r, 0) . ($c !== null ? ' / ~' . (int) $this->g($ds, $c, 0) : '');
        $rows = [
            ['Funções', $fmt('functions_added', 'functions_removed', 'functions_changed')],
            ['Tabelas', $fmt('tables_added', 'tables_removed', 'tables_changed')],
            ['Campos', $fmt('fields_added', 'fields_removed', 'fields_changed')],
            ['SQL', $fmt('sql_operations_added', 'sql_operations_removed', 'sql_operations_changed')],
            ['Dependências', '+' . (int) $this->g($ds, 'dependencies_added', 0) . ' / −' . (int) $this->g($ds, 'dependencies_removed', 0)],
            ['Chamadas', '+' . (int) $this->g($ds, 'calls_added', 0) . ' / −' . (int) $this->g($ds, 'calls_removed', 0)],
            ['Linhas', '+' . (int) $this->g($ds, 'lines_added', 0) . ' / −' . (int) $this->g($ds, 'lines_removed', 0)],
        ];
        $h = '<table ' . self::TABLE . '><tr><th ' . self::TH . '>Categoria</th><th ' . self::TH . '>Adicionado / Removido / Alterado</th></tr>';
        foreach ($rows as [$k, $val]) {
            $h .= '<tr><td ' . self::TD . '><b>' . $this->e($k) . '</b></td><td ' . self::TD . '>' . $this->e($val) . '</td></tr>';
        }
        return $h . '</table>';
    }

    // ── DOCX (principal) — headings nativos (TOC + keepNext) + page-break pesado ──
    public function docx(array $doc, bool $isOutdated = false, array $customer = [], array $context = []): string
    {
        $m = $this->model($doc, $context, $customer);
        $php = new PhpWord();
        $php->setDefaultFontName('Arial');
        $php->setDefaultFontSize(10.5);
        $php->addTitleStyle(1, ['size' => 13, 'bold' => true, 'color' => '0D4766'], ['spaceBefore' => 240, 'spaceAfter' => 80, 'keepNext' => true]);
        $php->addTitleStyle(2, ['size' => 11, 'bold' => true, 'color' => '12232B'], ['spaceBefore' => 140, 'spaceAfter' => 40, 'keepNext' => true]);

        $sec = $php->addSection(['marginTop' => 1400, 'marginBottom' => 1000, 'marginLeft' => 900, 'marginRight' => 900]);
        $logo = base_path('resources/templates/erpserv-logo.png');
        if (is_file($logo)) {
            $sec->addHeader()->addImage($logo, ['width' => 460, 'height' => 50, 'alignment' => 'center']);
        }
        $sec->addFooter()->addText('www.erpserv.com.br    ·    +55 11 3230.9647    ·    contato@erpserv.com.br', ['size' => 8, 'color' => '6b7d80'], ['alignment' => 'center']);

        // Capa (nativa, controlada)
        $sec->addText('Documentação Técnica de Fonte', ['size' => 20, 'bold' => true, 'color' => '0D4766']);
        $sec->addText((string) $this->g($m['id'], 'filename', '—'), ['size' => 15, 'bold' => true, 'color' => '0E7C86'], ['spaceAfter' => 120]);
        $capBody = preg_replace('#<style>.*?</style>#s', '', $this->cover($m));
        // remove o <h1>/subtítulo já emitidos nativamente
        $capBody = preg_replace('#<h1>.*?</h1>#s', '', $capBody);
        $capBody = preg_replace('#<div style="font-size:15pt.*?</div>#s', '', $capBody, 1);
        PhpWordHtml::addHtml($sec, $capBody, false, false);

        // Sumário
        $sec->addTextBreak(1);
        $sec->addText('Sumário', ['size' => 13, 'bold' => true, 'color' => '0D4766']);
        $sec->addTOC(['size' => 10], ['tabLeader' => 'dot']);
        $sec->addPageBreak();

        $large = count((array) $this->g($m['d'], 'functions', [])) > 12 || count((array) $this->g($m['d'], 'tables', [])) > 15;
        $first = true;
        foreach ($this->sections($m) as $s) {
            if (!$first && $s['heavy'] && $large) {
                $sec->addPageBreak();
            }
            $first = false;
            $sec->addTitle($s['n'] . '. ' . $s['title'], 1);
            $body = preg_replace('#<style>.*?</style>#s', '', $s['html']);
            try {
                PhpWordHtml::addHtml($sec, $body, false, false);
            } catch (\Throwable $e) {
                $sec->addText('(seção não renderizável neste formato)', ['italic' => true, 'color' => '6b7d80']);
            }
        }
        $sec->addTextBreak(1);
        $sec->addText('Documentação gerada automaticamente pelo Minutor — representação do estado estruturado do fonte.', ['size' => 8, 'color' => '6b7d80']);

        $tmp = tempnam(sys_get_temp_dir(), 'srcdoc') . '.docx';
        $php->save($tmp, 'Word2007');
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    public function pdf(array $doc, bool $isOutdated = false, array $customer = [], array $context = []): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($this->html($doc, $isOutdated, $customer, $context))->output();
    }

    public function markdown(array $doc, bool $isOutdated = false, array $customer = [], array $context = []): string
    {
        $html = preg_replace('#<style>.*?</style>#s', '', $this->html($doc, $isOutdated, $customer, $context));
        $md = $html;
        $md = preg_replace('#<h1[^>]*>(.*?)</h1>#is', "# $1\n", $md);
        $md = preg_replace('#<h2[^>]*>(.*?)</h2>#is', "\n## $1\n", $md);
        $md = preg_replace('#<h3[^>]*>(.*?)</h3>#is', "\n### $1\n", $md);
        $md = preg_replace('#<li[^>]*>(.*?)</li>#is', "- $1\n", $md);
        $md = preg_replace('#</?(ul|ol)[^>]*>#i', '', $md);
        $md = preg_replace('#<tr[^>]*>(.*?)</tr>#is', "$1|\n", $md);
        $md = preg_replace('#<t[hd][^>]*>(.*?)</t[hd]>#is', "| $1 ", $md);
        $md = preg_replace('#</?(table|b|div|span|ol)[^>]*>#i', '', $md);
        $md = preg_replace('#<p[^>]*>(.*?)</p>#is', "$1\n", $md);
        $md = html_entity_decode(strip_tags($md), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $md)) . "\n";
    }
}
