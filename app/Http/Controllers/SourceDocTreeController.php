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

        return response()->json(['data' => $data]);
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
