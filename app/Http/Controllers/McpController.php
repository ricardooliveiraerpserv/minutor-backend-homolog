<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Conector MCP do Minutor (Claude: chat, PowerPoint, Excel) — SOMENTE LEITURA e SÓ ADMIN.
 *
 * Transporte "Streamable HTTP" do MCP com respostas JSON (sem SSE): POST /mcp/{token} recebe
 * JSON-RPC 2.0 (initialize, tools/list, tools/call, ping). O {token} é um token pessoal Sanctum
 * com a habilidade 'mcp:read', emitido por `php artisan mcp:token {user}` — revogável a qualquer
 * momento. Cada ferramenta reaproveita os MESMOS cálculos das telas do Minutor, executados como o
 * próprio usuário dono do token (as mesmas regras de acesso valem).
 */
class McpController extends Controller
{
    private const PROTOCOL = '2025-06-18';

    public function handle(Request $request, string $token)
    {
        $user = $this->authenticate($token);
        if (!$user) return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Token inválido, revogado ou usuário sem permissão (só admin).']], 401);

        if ($request->isMethod('get')) return response('', 405)->header('Allow', 'POST');
        if ($request->isMethod('delete')) return response('', 204);

        $payload = $request->json()->all();
        $batch = array_is_list($payload) ? $payload : [$payload];
        $out = [];
        foreach ($batch as $msg) {
            $res = $this->dispatchMessage($user, (array) $msg);
            if ($res !== null) $out[] = $res;
        }
        if (!$out) return response('', 202);
        return response()->json(array_is_list($payload) ? $out : $out[0]);
    }

    private function authenticate(string $token): ?User
    {
        $pat = PersonalAccessToken::findToken(urldecode($token));
        if (!$pat || !$pat->can('mcp:read')) return null;
        if ($pat->expires_at && $pat->expires_at->isPast()) return null;
        $user = $pat->tokenable;
        if (!$user instanceof User || !$user->enabled || !$user->isAdmin()) return null;
        $pat->forceFill(['last_used_at' => now()])->saveQuietly();
        Auth::setUser($user);
        auth('sanctum')->setUser($user);
        return $user;
    }

    private function dispatchMessage(User $user, array $m): ?array
    {
        $id = $m['id'] ?? null;
        $method = (string) ($m['method'] ?? '');
        if ($id === null && str_starts_with($method, 'notifications/')) return null; // notificação: sem resposta
        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => 'minutor', 'title' => 'Minutor', 'version' => '1.0.0'],
                    'instructions'    => 'Dados do Minutor (ERPSERV/BIZIFY): projetos, rentabilidade (receita, custo, margem) e horas apontadas. Somente leitura. Valores em R$; horas em horas decimais.',
                ],
                'ping'       => (object) [],
                'tools/list' => ['tools' => array_merge($this->tools(), $this->isFull($user) ? $this->fullTools() : [])],
                'tools/call' => $this->callTool($user, (string) ($m['params']['name'] ?? ''), (array) ($m['params']['arguments'] ?? [])),
                default      => throw new \InvalidArgumentException("Método não suportado: {$method}"),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\InvalidArgumentException $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            report($e);
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'Erro ao consultar o Minutor: ' . $e->getMessage()]]]];
        }
    }

    private function tools(): array
    {
        $ym = ['type' => 'string', 'description' => 'Competência no formato AAAA-MM (ex.: 2026-10).', 'pattern' => '^\d{4}-\d{2}$'];
        $ro = ['readOnlyHint' => true, 'openWorldHint' => false];
        return [
            ['name' => 'listar_projetos', 'title' => 'Listar projetos', 'annotations' => $ro,
             'description' => 'Lista projetos/contratos do Minutor com cliente, status, tipo de contrato, tipo de serviço, horas vendidas, horas consumidas, saldo e valores. Use filtros para reduzir.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'busca'   => ['type' => 'string', 'description' => 'Texto no nome ou código do projeto.'],
                 'cliente' => ['type' => 'string', 'description' => 'Nome (ou parte) do cliente.'],
                 'status'  => ['type' => 'string', 'enum' => ['ativos', 'encerrados', 'todos'], 'description' => 'Padrão: ativos.'],
                 'limite'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Padrão 200.'],
             ]]],
            ['name' => 'rentabilidade_projetos', 'title' => 'Rentabilidade por projeto (acumulada)', 'annotations' => $ro,
             'description' => 'Rentabilidade ACUMULADA (todo o histórico) por projeto: receita = horas faturáveis × R$/h do projeto; custo = horas × custo/h do consultor + despesas; margem e margem%. Mesmo cálculo do dashboard gerencial do Minutor.',
             'inputSchema' => ['type' => 'object', 'properties' => [
                 'cliente' => ['type' => 'string', 'description' => 'Filtra pelo nome (ou parte) do cliente.'],
                 'busca'   => ['type' => 'string', 'description' => 'Texto no nome ou código do projeto.'],
             ]]],
            ['name' => 'rentabilidade_clientes_mes', 'title' => 'Rentabilidade por cliente no mês', 'annotations' => $ro,
             'description' => 'Rentabilidade por CLIENTE numa competência (receita, custo, margem), cruzada com o recebimento do mês seguinte (Keruak). Mesmo relatório da tela Rentabilidade de clientes.',
             'inputSchema' => ['type' => 'object', 'required' => ['competencia'], 'properties' => ['competencia' => $ym]]],
            ['name' => 'rentabilidade_consultor_projeto_mes', 'title' => 'Rentabilidade consultor × projeto no mês', 'annotations' => $ro,
             'description' => 'Rentabilidade por consultor × projeto numa competência: horas, receita (R$/h do projeto), custo (R$/h do consultor) e margem.',
             'inputSchema' => ['type' => 'object', 'required' => ['competencia'], 'properties' => ['competencia' => $ym]]],
            ['name' => 'horas_apontadas', 'title' => 'Horas apontadas', 'annotations' => $ro,
             'description' => 'Soma das horas apontadas (válidas: sem reprovados/conflitos/ajuste) num período, agrupadas por cliente, projeto, consultor ou mês.',
             'inputSchema' => ['type' => 'object', 'required' => ['de', 'ate'], 'properties' => [
                 'de'        => ['type' => 'string', 'description' => 'Data inicial AAAA-MM-DD.'],
                 'ate'       => ['type' => 'string', 'description' => 'Data final AAAA-MM-DD.'],
                 'agrupar'   => ['type' => 'string', 'enum' => ['cliente', 'projeto', 'consultor', 'mes'], 'description' => 'Padrão: projeto.'],
                 'cliente'   => ['type' => 'string', 'description' => 'Filtra pelo nome (ou parte) do cliente.'],
                 'projeto'   => ['type' => 'string', 'description' => 'Filtra pelo código ou nome do projeto.'],
             ]]],
        ];
    }

    /** Acesso COMPLETO (só leitura) — sócios/donos (Ricardo 17/11/2026). Setting mcp_full_access_user_ids. */
    private function isFull(User $u): bool
    {
        $ids = \App\Models\SystemSetting::get('mcp_full_access_user_ids', [1, 3]);
        if (is_string($ids)) $ids = json_decode($ids, true) ?: explode(',', $ids);
        return in_array((int) $u->id, array_map('intval', (array) $ids), true);
    }

    private function fullTools(): array
    {
        $ro = ['readOnlyHint' => true, 'openWorldHint' => false];
        return [
            ['name' => 'listar_tabelas', 'title' => 'Listar tabelas do Minutor', 'annotations' => $ro,
             'description' => 'Lista as tabelas do banco do Minutor (PostgreSQL) com a quantidade aproximada de linhas. Use antes de consultar_sql para descobrir onde estão os dados.',
             'inputSchema' => ['type' => 'object', 'properties' => ['filtro' => ['type' => 'string', 'description' => 'Parte do nome da tabela (ex.: project, timesheet, helpdesk, crm, folha).']]]],
            ['name' => 'descrever_tabela', 'title' => 'Descrever tabela', 'annotations' => $ro,
             'description' => 'Mostra as colunas (nome e tipo) de uma tabela e 3 linhas de exemplo.',
             'inputSchema' => ['type' => 'object', 'required' => ['tabela'], 'properties' => ['tabela' => ['type' => 'string']]]],
            ['name' => 'consultar_sql', 'title' => 'Consulta SQL (somente leitura)', 'annotations' => $ro,
             'description' => 'Executa uma consulta SELECT/WITH no banco do Minutor (PostgreSQL), SOMENTE LEITURA, até 2000 linhas e 20 s. Dicas: projects (code,name,customer_id,status,sold_hours,hourly_rate,project_value), customers (name), timesheets (date,user_id,project_id,customer_id,effort_minutes,status; ignorar deleted_at não nulo e status rejected/conflicted/adjustment_requested), expenses, users (name,type), helpdesk_tickets, contracts. Senhas, tokens e o Cofre de Senhas não são acessíveis.',
             'inputSchema' => ['type' => 'object', 'required' => ['sql'], 'properties' => ['sql' => ['type' => 'string', 'description' => 'Uma única consulta SELECT ou WITH.']]]],
        ];
    }

    /** Tabelas com segredo (senhas, tokens, cofre, chaves) — nunca expostas. */
    private const BLOCKED_TABLES = '/\b(vault\w*|env_secrets|env_credentials|env_client_vaults|env_access_logs|password_reset_tokens|personal_access_tokens|sessions|connector_enrollment_tokens|user_integrations|ai_providers|system_settings|helpdesk_email_accounts|prosight_rpo_configs|_bak_users\w*|pg_\w+|information_schema)\b/i';
    /** Colunas sensíveis — removidas de QUALQUER resultado. */
    private const BLOCKED_COLS = '/(passw|senha|secret|token|api_key|apikey|private_key|totp|recovery|smtp_app|pfx_pass|remember_)/i';

    private function guardSql(string $sql): string
    {
        $sql = trim(rtrim(trim($sql), ';'));
        if ($sql === '' || str_contains($sql, ';')) throw new \RuntimeException('Envie UMA única consulta, sem ponto e vírgula.');
        if (!preg_match('/^(select|with)\b/i', $sql)) throw new \RuntimeException('Somente consultas SELECT/WITH são permitidas.');
        // Escrita é impedida pelo próprio banco (transação READ ONLY); aqui só barra funções perigosas.
        if (preg_match('/\b(dblink\w*|lo_\w+|pg_read\w*|pg_ls\w*|pg_sleep\w*|pg_terminate\w*|pg_cancel\w*|pg_reload\w*|current_setting|set_config|query_to_xml\w*|table_to_xml\w*)\s*\(/i', $sql))
            throw new \RuntimeException('Comando não permitido (somente leitura).');
        if (preg_match(self::BLOCKED_TABLES, $sql)) throw new \RuntimeException('Esta tabela guarda senhas/tokens/cofre e não está disponível no conector.');
        return $sql;
    }

    private function runReadOnly(string $sql, int $limit = 2000): array
    {
        return DB::transaction(function () use ($sql, $limit) {
            DB::statement('SET TRANSACTION READ ONLY');
            DB::statement("SET LOCAL statement_timeout = '20s'");
            $rows = DB::select("SELECT * FROM ({$sql}) AS q LIMIT {$limit}");
            return array_map(function ($r) {
                $r = (array) $r;
                foreach (array_keys($r) as $k) if (preg_match(self::BLOCKED_COLS, (string) $k)) unset($r[$k]);
                return $r;
            }, $rows);
        });
    }

    private function callTool(User $user, string $name, array $a): array
    {
        $data = match ($name) {
            'listar_projetos'                     => $this->listarProjetos($a),
            'rentabilidade_projetos'              => $this->rentabilidadeProjetos($a),
            'rentabilidade_clientes_mes'          => $this->viaController('clientes', $this->ym($a)),
            'rentabilidade_consultor_projeto_mes' => $this->viaController('rentabilidade', $this->ym($a)),
            'horas_apontadas'                     => $this->horas($a),
            'listar_tabelas'   => $this->full($user, fn () => $this->listarTabelas($a)),
            'descrever_tabela' => $this->full($user, fn () => $this->descreverTabela($a)),
            'consultar_sql'    => $this->full($user, fn () => ['linhas' => $this->runReadOnly($this->guardSql((string) ($a['sql'] ?? '')))]),
            default => throw new \InvalidArgumentException("Ferramenta desconhecida: {$name}"),
        };
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 400000) $json = substr($json, 0, 400000) . "\n…(resultado truncado — use filtros para reduzir)";
        return ['content' => [['type' => 'text', 'text' => $json]], 'isError' => false];
    }

    private function full(User $u, \Closure $fn): mixed
    {
        if (!$this->isFull($u)) throw new \RuntimeException('Ferramenta disponível só para acesso completo.');
        return $fn();
    }

    private function listarTabelas(array $a): array
    {
        $f = trim((string) ($a['filtro'] ?? ''));
        return collect(DB::select("select relname as tabela, reltuples::bigint as linhas_aprox from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='r' order by relname"))
            ->map(fn ($r) => (array) $r)
            ->reject(fn ($r) => preg_match(self::BLOCKED_TABLES, $r['tabela']))
            ->filter(fn ($r) => $f === '' || stripos($r['tabela'], $f) !== false)->values()->all();
    }

    private function descreverTabela(array $a): array
    {
        $t = (string) ($a['tabela'] ?? '');
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $t) || preg_match(self::BLOCKED_TABLES, $t)) throw new \RuntimeException('Tabela inválida ou indisponível.');
        $cols = collect(DB::select("select column_name as coluna, data_type as tipo from information_schema.columns where table_schema='public' and table_name=? order by ordinal_position", [$t]))
            ->map(fn ($r) => (array) $r)->reject(fn ($r) => preg_match(self::BLOCKED_COLS, $r['coluna']))->values()->all();
        if (!$cols) throw new \RuntimeException('Tabela não encontrada.');
        return ['colunas' => $cols, 'exemplo' => $this->runReadOnly("select * from \"{$t}\"", 3)];
    }

    private function ym(array $a): string
    {
        $ym = (string) ($a['competencia'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new \RuntimeException('Informe a competência no formato AAAA-MM.');
        return $ym;
    }

    private function viaController(string $method, string $ym): mixed
    {
        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => Auth::user());
        $resp = app(RelatorioRentabilidadeController::class)->{$method}($req, $ym);
        return json_decode($resp->getContent(), true);
    }

    private function projectQuery(array $a)
    {
        $q = Project::withoutGlobalScopes()->whereNull('projects.deleted_at')
            ->with(['customer:id,name', 'contractType:id,name', 'serviceType:id,name']);
        if (!empty($a['busca'])) { $b = '%' . $a['busca'] . '%'; $q->where(fn ($w) => $w->where('name', 'ilike', $b)->orWhere('code', 'ilike', $b)); }
        if (!empty($a['cliente'])) $q->whereHas('customer', fn ($c) => $c->where('name', 'ilike', '%' . $a['cliente'] . '%'));
        return $q;
    }

    private function listarProjetos(array $a): array
    {
        $status = $a['status'] ?? 'ativos';
        $q = $this->projectQuery($a)
            ->when($status === 'ativos', fn ($w) => $w->whereNotIn('status', ['finished', 'cancelled']))
            ->when($status === 'encerrados', fn ($w) => $w->whereIn('status', ['finished', 'cancelled']))
            ->orderBy('name')->limit(min(500, max(1, (int) ($a['limite'] ?? 200))));
        $projects = $q->get();
        $mins = DB::table('timesheets')->whereNull('deleted_at')->whereIn('project_id', $projects->pluck('id'))
            ->whereNotIn('status', ['rejected', 'conflicted', 'adjustment_requested'])
            ->selectRaw('project_id, SUM(effort_minutes) m')->groupBy('project_id')->pluck('m', 'project_id');
        return $projects->map(function ($p) use ($mins) {
            $cons = round(((int) ($mins[$p->id] ?? 0)) / 60, 2);
            $sold = (float) ($p->sold_hours ?? 0);
            return [
                'codigo' => $p->code, 'projeto' => $p->name, 'cliente' => $p->customer?->name, 'status' => $p->status,
                'tipo_contrato' => $p->contractType?->name, 'tipo_servico' => $p->serviceType?->name,
                'inicio' => optional($p->start_date)->format('Y-m-d') ?? $p->start_date, 'fim' => optional($p->end_date)->format('Y-m-d') ?? $p->end_date,
                'horas_vendidas' => $sold ?: null, 'horas_consumidas' => $cons, 'saldo_horas' => $sold ? round($sold - $cons, 2) : null,
                'valor_hora' => $p->hourly_rate !== null ? (float) $p->hourly_rate : null,
                'valor_projeto' => $p->project_value !== null ? (float) $p->project_value : null,
            ];
        })->values()->all();
    }

    private function rentabilidadeProjetos(array $a): array
    {
        $req = Request::create('/', 'GET');
        $req->setUserResolver(fn () => Auth::user());
        $rows = json_decode(app(RelatorioRentabilidadeController::class)->projetos($req)->getContent(), true);
        $rows = $rows['data'] ?? $rows;
        $projects = $this->projectQuery($a)->get()->keyBy('id');
        $out = [];
        foreach ((array) $rows as $r) {
            $p = $projects[$r['project_id'] ?? 0] ?? null;
            if (!$p) continue; // fora do filtro
            $out[] = ['codigo' => $p->code, 'projeto' => $p->name, 'cliente' => $p->customer?->name, 'status' => $p->status,
                'tipo_contrato' => $p->contractType?->name] + array_diff_key($r, ['project_id' => 1]);
        }
        usort($out, fn ($x, $y) => ($y['receita'] ?? 0) <=> ($x['receita'] ?? 0));
        return $out;
    }

    private function horas(array $a): array
    {
        $de = (string) ($a['de'] ?? ''); $ate = (string) ($a['ate'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) throw new \RuntimeException('Informe de/ate no formato AAAA-MM-DD.');
        $g = $a['agrupar'] ?? 'projeto';
        $q = DB::table('timesheets as t')->whereNull('t.deleted_at')->whereBetween('t.date', [$de, $ate])
            ->whereNotIn('t.status', ['rejected', 'conflicted', 'adjustment_requested'])
            ->leftJoin('projects as p', 'p.id', '=', 't.project_id')
            ->leftJoin('customers as c', 'c.id', '=', 't.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id');
        if (!empty($a['cliente'])) $q->where('c.name', 'ilike', '%' . $a['cliente'] . '%');
        if (!empty($a['projeto'])) { $b = '%' . $a['projeto'] . '%'; $q->where(fn ($w) => $w->where('p.code', 'ilike', $b)->orWhere('p.name', 'ilike', $b)); }
        [$cols, $sel] = match ($g) {
            'cliente'   => [['c.name'], 'c.name as cliente'],
            'consultor' => [['u.name'], 'u.name as consultor'],
            'mes'       => [[DB::raw("to_char(t.date,'YYYY-MM')")], "to_char(t.date,'YYYY-MM') as mes"],
            default     => [['p.code', 'p.name', 'c.name'], 'p.code as codigo, p.name as projeto, c.name as cliente'],
        };
        return $q->selectRaw($sel . ', ROUND(SUM(t.effort_minutes)/60.0, 2) as horas, COUNT(*) as apontamentos')
            ->groupBy(...$cols)->orderByDesc('horas')->limit(1000)->get()->map(fn ($r) => (array) $r)->all();
    }
}
