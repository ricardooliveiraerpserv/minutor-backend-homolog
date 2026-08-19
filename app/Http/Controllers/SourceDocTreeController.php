<?php

namespace App\Http\Controllers;

use App\Models\SourceDoc;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — F2 · Acervo (árvore lazy Empresa → Repositório → Diretório Git → Fonte).
 * SOMENTE LEITURA. Diretórios DERIVADOS de source_docs.path (sem tabela de diretórios). Segurança =
 * SourceDocCustomerScope (SQL, deny-by-default): customer_id da URL é só recorte; applyScope/canAccess
 * intersectam com o que o usuário PODE ver. Cliente externo → só a própria empresa. Não toca motor.
 */
class SourceDocTreeController extends Controller
{
    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** GET /source-docs/tree/customers — L1: empresas acessíveis + rollup barato (sem Git/JSON pesado). */
    public function customers(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = SourceDoc::query()
            ->join('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
            ->groupBy('source_docs.customer_id', 'customers.name');
        $this->scope->applyScope($q, $user, 'source_docs.customer_id');
        $rows = $q->select([
            'source_docs.customer_id',
            DB::raw('customers.name as name'),
            DB::raw('count(*) as fontes'),
            DB::raw('count(distinct source_docs.repository) as repos'),
            DB::raw("count(*) filter (where cv.semantic_json is not null) as documentadas"),
            DB::raw("count(*) filter (where cv.semantic_json is not null and cv.analysis_status = 'completed') as completas"),
            DB::raw("count(*) filter (where source_docs.analysis_status = 'partial') as parciais"),
            DB::raw("count(*) filter (where source_docs.analysis_status not in ('completed','partial')) as pendentes"),
        ])->orderByDesc('fontes')->get();

        $pending = $this->pendingApprovalsByCustomer($rows->pluck('customer_id')->all());

        $data = $rows->map(fn ($r) => [
            'customer_id' => (int) $r->customer_id,
            'name' => $r->name,
            'repos' => (int) $r->repos,
            'fontes' => (int) $r->fontes,
            'documentadas' => (int) $r->documentadas,
            'completas' => (int) $r->completas,
            'parciais' => (int) $r->parciais,
            'pendentes' => (int) $r->pendentes,
            'aguardando_aprovacao' => (int) ($pending[$r->customer_id] ?? 0),
            // 'desatualizadas' (situação Git) exige o resolver (caro em rollup) — omitido nesta fase.
        ]);

        // include_empty=1: anexa clientes configurados no git (ClientSourceRepo ativo) que ainda
        // NÃO têm fontes indexadas (fontes=0), respeitando o escopo. Default OFF (não muda Acervo/Visão Geral).
        if ($request->boolean('include_empty')) {
            $haveDocs = $rows->pluck('customer_id')->all() ?: [0];
            $gitQ = \App\Models\ClientSourceRepo::query()
                ->join('customers', 'customers.id', '=', 'client_source_repos.customer_id')
                ->where('client_source_repos.active', true)
                ->whereNotIn('client_source_repos.customer_id', $haveDocs)
                ->groupBy('client_source_repos.customer_id', 'customers.name')
                ->select([
                    'client_source_repos.customer_id',
                    DB::raw('customers.name as name'),
                    DB::raw('count(distinct client_source_repos.repository) as repos'),
                ]);
            $this->scope->applyScope($gitQ, $user, 'client_source_repos.customer_id');
            $gitData = $gitQ->orderBy('customers.name')->get()->map(fn ($r) => [
                'customer_id' => (int) $r->customer_id, 'name' => $r->name, 'repos' => (int) $r->repos,
                'fontes' => 0, 'documentadas' => 0, 'completas' => 0, 'parciais' => 0, 'pendentes' => 0, 'aguardando_aprovacao' => 0,
            ]);
            $data = $data->concat($gitData);
        }

        return response()->json(['data' => $data->values()]);
    }

    /** GET /source-docs/tree/customers/{customer}/repos — L2: repos com fontes do cliente. */
    public function repos(int $customer, Request $request): JsonResponse
    {
        if (! $this->scope->canAccessCustomerId($request->user(), $customer)) {
            return response()->json(['message' => 'Empresa não encontrada.'], 404);
        }
        $q = SourceDoc::query()
            ->where('source_docs.customer_id', $customer)
            ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
            ->groupBy('source_docs.repository', 'source_docs.source_repo_id', 'source_docs.branch', 'source_docs.owner');
        $this->scope->applyScope($q, $request->user(), 'source_docs.customer_id'); // defesa em profundidade
        $rows = $q->select([
            'source_docs.repository', 'source_docs.source_repo_id', 'source_docs.branch', 'source_docs.owner',
            DB::raw('count(*) as fontes'),
            DB::raw("count(*) filter (where cv.semantic_json is not null) as documentadas"),
            DB::raw("count(*) filter (where source_docs.analysis_status = 'partial') as parciais"),
            DB::raw('max(source_docs.updated_at) as ultima_atualizacao_acervo'),
        ])->orderBy('source_docs.repository')->get();

        $data = $rows->map(fn ($r) => [
            'repository' => $r->repository,
            'source_repo_id' => $r->source_repo_id ? (int) $r->source_repo_id : null,
            'branch' => $r->branch,
            'owner' => $r->owner, // provider = GitHub implícito (contexto derivado, não cadastro)
            'fontes' => (int) $r->fontes,
            'documentadas' => (int) $r->documentadas,
            'parciais' => (int) $r->parciais,
            'cobertura_semantica' => (int) $r->fontes > 0 ? round(((int) $r->documentadas) / ((int) $r->fontes) * 100) : 0,
            'ultima_atualizacao_acervo' => $r->ultima_atualizacao_acervo, // "Última atualização do acervo" (NÃO sync do GitHub)
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /source-docs/tree/nodes?customer_id=&repository=&path= — L3+: filhos imediatos de um prefixo.
     * Diretórios derivados do path (split em PHP), preservando espaço/acento/case/profundidade.
     */
    public function nodes(Request $request): JsonResponse
    {
        $customer = (int) $request->query('customer_id');
        $repository = (string) $request->query('repository', '');
        $prefix = (string) $request->query('path', ''); // '' = raiz do repo
        if (! $customer || $repository === '' || ! $this->scope->canAccessCustomerId($request->user(), $customer)) {
            return response()->json(['message' => 'Nó não encontrado.'], 404);
        }

        $q = SourceDoc::query()
            ->where('source_docs.customer_id', $customer)
            ->where('source_docs.repository', $repository)
            ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
            ->leftJoin('source_doc_index as si', 'si.source_doc_id', '=', 'source_docs.id')
            ->leftJoin('source_doc_cost_ledger as cl', 'cl.source_doc_id', '=', 'source_docs.id');
        if ($prefix !== '') {
            $q->where('source_docs.path', 'like', $this->escapeLike($prefix) . '/%');
        }
        $this->scope->applyScope($q, $request->user(), 'source_docs.customer_id'); // defesa em profundidade
        $rows = $q->select([
            'source_docs.id', 'source_docs.path', 'source_docs.filename', 'source_docs.tipo', 'source_docs.lang',
            'source_docs.size_bytes', 'source_docs.analysis_status', 'source_docs.updated_at',
            DB::raw('cv.created_at as last_change_at'),
            DB::raw('(cv.semantic_json is not null) as has_semantic'),
            DB::raw('cv.analysis_status as cv_status'),
            DB::raw('si.functions_count as functions_count'),
            DB::raw('cl.actual_cost_usd as cost_usd'),
        ])->get();

        $plen = $prefix === '' ? 0 : strlen($prefix) + 1; // pula o '/' após o prefixo
        $dirs = [];   // seg => acumulador
        $files = [];
        foreach ($rows as $r) {
            $rest = $plen === 0 ? $r->path : substr($r->path, $plen);
            if ($rest === '' || $rest === false) {
                continue;
            }
            $slash = strpos($rest, '/');
            if ($slash === false) {
                // arquivo diretamente neste nível
                $files[] = [
                    'id' => (int) $r->id,
                    'filename' => $r->filename ?: $rest,
                    'name' => $rest,
                    'path' => $r->path,
                    'tipo' => $r->tipo, 'lang' => $r->lang, 'size_bytes' => $r->size_bytes ? (int) $r->size_bytes : null,
                    'analysis_status' => $r->analysis_status,
                    'semantic' => $r->has_semantic ? ($r->cv_status === 'completed' ? 'completed' : 'partial') : 'none',
                    'functions_count' => $r->functions_count !== null ? (int) $r->functions_count : null,
                    'last_change_at' => $r->last_change_at,
                    'cost_usd' => $r->cost_usd !== null ? (float) $r->cost_usd : null,
                ];
            } else {
                // subpasta: primeiro segmento
                $seg = substr($rest, 0, $slash);
                if (! isset($dirs[$seg])) {
                    $dirs[$seg] = ['name' => $seg, 'path' => ($prefix === '' ? $seg : $prefix . '/' . $seg), 'fontes' => 0, 'documentadas' => 0, 'parciais' => 0];
                }
                $dirs[$seg]['fontes']++;
                if ($r->has_semantic) {
                    $dirs[$seg]['documentadas']++;
                }
                if ($r->analysis_status === 'partial') {
                    $dirs[$seg]['parciais']++;
                }
            }
        }
        // ordena pastas por nome (case-insensitive, estável) e arquivos por nome
        $dirs = array_values($dirs);
        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json(['data' => [
            'customer_id' => $customer, 'repository' => $repository, 'path' => $prefix,
            'dirs' => $dirs, 'files' => $files,
        ]]);
    }

    /**
     * GET /source-docs/tree/knowledge?customer_id=&repository=&path= — F4 · inteligência AGREGADA do
     * patrimônio JÁ produzido (custo IA adicional = US$0). Counts baratos vêm do read-model
     * source_doc_index (O(1), sem detoast); regras/deps/processos/custo só sobre o subconjunto
     * semântico (poucas linhas); cross-source das edges resolvidas. Escopo C4a aplicado.
     */
    public function knowledge(Request $request): JsonResponse
    {
        $customer = (int) $request->query('customer_id');
        $repository = trim((string) $request->query('repository', ''));
        $prefix = trim((string) $request->query('path', ''));
        if (! $customer || ! $this->scope->canAccessCustomerId($request->user(), $customer)) {
            return response()->json(['message' => 'Escopo não encontrado.'], 404);
        }
        $scoped = function ($q) use ($customer, $repository, $prefix, $request) {
            $q->where('source_docs.customer_id', $customer);
            if ($repository !== '') {
                $q->where('source_docs.repository', $repository);
            }
            if ($prefix !== '') {
                $q->where('source_docs.path', 'like', $this->escapeLike($prefix) . '/%');
            }
            $this->scope->applyScope($q, $request->user(), 'source_docs.customer_id');
            return $q;
        };

        // 1) Counts baratos (source_doc_index + versions; sem detoast do JSON pesado).
        $c = $scoped(SourceDoc::query()
            ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
            ->leftJoin('source_doc_index as si', 'si.source_doc_id', '=', 'source_docs.id'))
            ->selectRaw("count(*) fontes,
                count(*) filter (where cv.semantic_json is not null) documentadas,
                count(*) filter (where cv.semantic_json is not null and cv.analysis_status='completed') completas,
                count(*) filter (where source_docs.analysis_status='partial') parciais,
                count(*) filter (where source_docs.analysis_status not in ('completed','partial')) pendentes,
                coalesce(sum(si.functions_count),0) funcoes,
                coalesce(sum(si.tables_count),0) tabelas,
                coalesce(sum(si.queries_count),0) queries,
                count(*) filter (where si.has_risk) com_risco")
            ->first();

        // 2) Conhecimento do JSON — SÓ sobre semantic não-nulo (poucas linhas).
        $k = $scoped(SourceDoc::query()->join('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')->whereNotNull('cv.semantic_json'))
            ->selectRaw("coalesce(sum(jsonb_array_length(coalesce(cv.semantic_json::jsonb->'regras_negocio','[]'::jsonb))),0) regras,
                coalesce(sum(jsonb_array_length(coalesce(cv.semantic_json::jsonb->'dependencias_criticas','[]'::jsonb))),0) deps,
                coalesce(sum((cv.semantic_json::jsonb->'usage'->>'total_cost_usd')::numeric),0) custo,
                count(*) filter (where cv.semantic_json::jsonb->'documentary_completeness'->>'level' = 'parcial') com_gaps")
            ->first();

        // processos/módulos identificados (top) — do subconjunto semântico.
        $procs = $scoped(SourceDoc::query()->join('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')->whereNotNull('cv.semantic_json'))
            ->selectRaw("coalesce(nullif(cv.semantic_json::jsonb->'entendimento_funcional'->'processo_modulo'->>'modulo',''),'—') modulo, count(*) n")
            ->groupBy('modulo')->orderByDesc('n')->limit(8)->get()
            ->map(fn ($r) => ['modulo' => $r->modulo, 'fontes' => (int) $r->n]);

        // linguagens
        $langs = $scoped(SourceDoc::query())->selectRaw("coalesce(source_docs.lang,'?') lang, count(*) n")->groupBy('lang')->orderByDesc('n')->get()
            ->map(fn ($r) => ['lang' => $r->lang, 'fontes' => (int) $r->n]);

        // 3) Cross-source: relações resolvidas cujo DEPENDENTE está no escopo (target real).
        $edges = DB::table('source_semantic_context_edge as e')
            ->join('source_docs as dep', 'dep.id', '=', 'e.dependent_source_doc_id')
            ->join('source_docs as tgt', 'tgt.id', '=', 'e.target_source_doc_id')
            ->join('customers as tc', 'tc.id', '=', 'tgt.customer_id')
            ->where('dep.customer_id', $customer)
            ->when($repository !== '', fn ($q) => $q->where('dep.repository', $repository))
            ->when($prefix !== '', fn ($q) => $q->where('dep.path', 'like', $this->escapeLike($prefix) . '/%'))
            ->whereNotNull('e.target_source_doc_id')
            ->orderByDesc('e.relevance_score')->limit(30)
            ->get(['e.dependent_source_doc_id as from_id', 'dep.filename as from_name', 'e.symbol', 'e.relation', 'e.state', 'e.evidence_level', 'e.target_source_doc_id as to_id', 'tgt.filename as to_name', 'tgt.path as to_path', 'tc.name as to_customer']);

        $aguardando = (int) DB::table('source_doc_cost_approvals as a')->join('source_docs as d', 'd.id', '=', 'a.source_doc_id')
            ->where('a.status', 'pending')->where('d.customer_id', $customer)
            ->when($repository !== '', fn ($q) => $q->where('d.repository', $repository))
            ->when($prefix !== '', fn ($q) => $q->where('d.path', 'like', $this->escapeLike($prefix) . '/%'))
            ->count();

        $fontes = (int) $c->fontes;
        return response()->json(['data' => [
            'scope' => ['customer_id' => $customer, 'repository' => $repository ?: null, 'path' => $prefix ?: null],
            'fontes' => $fontes,
            'documentadas' => (int) $c->documentadas,
            'completas' => (int) $c->completas,
            'parciais' => (int) $c->parciais,
            'pendentes' => (int) $c->pendentes,
            'cobertura_semantica' => $fontes > 0 ? round(((int) $c->documentadas) / $fontes * 100) : 0,
            'funcoes' => (int) $c->funcoes, 'tabelas' => (int) $c->tabelas, 'queries' => (int) $c->queries,
            'com_risco' => (int) $c->com_risco,
            'regras' => (int) $k->regras, 'dependencias' => (int) $k->deps,
            'com_gaps' => (int) $k->com_gaps,
            'custo_ia_usd' => round((float) $k->custo, 4),
            'aguardando_aprovacao' => $aguardando,
            'saude' => ['sem_documentacao' => (int) $c->pendentes, 'parcial' => (int) $c->parciais, 'completa' => (int) $c->completas, 'com_gaps' => (int) $k->com_gaps, 'aguardando' => $aguardando],
            'linguagens' => $langs,
            'processos_modulos' => $procs,
            'cross_source' => $edges->map(fn ($e) => [
                'from_id' => (int) $e->from_id, 'from_name' => $e->from_name, 'symbol' => $e->symbol,
                'relation' => $e->relation, 'state' => $e->state, 'evidence_level' => $e->evidence_level,
                'to_id' => (int) $e->to_id, 'to_name' => $e->to_name, 'to_path' => $e->to_path, 'to_customer' => $e->to_customer,
            ]),
        ]]);
    }

    /** Contagem de aprovações de IA ABERTAS por cliente (join leve, escopo já aplicado à lista de ids). */
    private function pendingApprovalsByCustomer(array $customerIds): array
    {
        if (! $customerIds) {
            return [];
        }
        return DB::table('source_doc_cost_approvals as a')
            ->join('source_docs as d', 'd.id', '=', 'a.source_doc_id')
            ->where('a.status', 'pending')
            ->whereIn('d.customer_id', $customerIds)
            ->groupBy('d.customer_id')
            ->selectRaw('d.customer_id as customer_id, count(*) as c')
            ->pluck('c', 'customer_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
