<?php

namespace App\Http\Controllers;

use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C2. Busca técnica sobre o READ-MODEL (source_doc_entities).
 * NUNCA faz scan do deterministic_json — só o índice. Situação Git NÃO é filtrada aqui
 * (é resolvida ao vivo pelo SourceDocStatusResolver na camada do catálogo/ficha).
 * Gate: middleware permission.or.admin:source_docs.view.
 */
class SourceDocSearchController extends Controller
{
    private const MAX_OCC = 20; // ocorrências mostradas por fonte

    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /** GET /source-docs/search */
    public function search(Request $request): JsonResponse
    {
        $entity = (string) $request->query('entity', '');
        if (! in_array($entity, SourceDocEntity::TYPES, true)) {
            return response()->json(['message' => 'Parâmetro entity inválido.', 'allowed' => SourceDocEntity::TYPES], 422);
        }
        $perPage = min(max((int) $request->query('per_page', 30) ?: 30, 1), 100);

        $base = $this->baseQuery($request, $entity);
        $page = max(1, (int) $request->query('page', 1));

        // Página de fontes DISTINTAS que casam. total = count(distinct source_doc_id) — o paginate()
        // padrão contaria as LINHAS de entidade (várias por fonte), inflando o total; por isso é manual.
        $total = (clone $base)->distinct()->count('source_doc_id');
        $docIds = (clone $base)->select('source_doc_id')->distinct()
            ->orderBy('source_doc_id')->forPage($page, $perPage)->pluck('source_doc_id')->all();
        $lastPage = (int) max(1, ceil($total / $perPage));

        $occByDoc = collect();
        $docsMeta = collect();
        if (! empty($docIds)) {
            $occByDoc = (clone $base)->whereIn('source_doc_id', $docIds)
                ->orderBy('source_doc_id')->orderBy('name')
                ->get(['source_doc_id', 'entity_type', 'name', 'parent', 'access', 'risk_flags', 'line_start', 'line_end'])
                ->groupBy('source_doc_id');
            $docsMeta = SourceDoc::whereIn('id', $docIds)
                ->with('customer:id,name')
                ->get(['id', 'filename', 'path', 'owner', 'repository', 'branch', 'customer_id'])
                ->keyBy('id');
        }

        $data = collect($docIds)->map(function ($id) use ($occByDoc, $docsMeta) {
            $occ = $occByDoc->get($id, collect());
            $d = $docsMeta->get($id);
            return [
                'source_doc' => $d ? [
                    'id' => $d->id, 'filename' => $d->filename, 'path' => $d->path,
                    'owner' => $d->owner, 'repository' => $d->repository, 'branch' => $d->branch,
                    'customer' => $d->customer ? ['id' => $d->customer->id, 'name' => $d->customer->name] : null,
                ] : ['id' => $id],
                'match_count' => $occ->count(),
                'occurrences' => $occ->take(self::MAX_OCC)->map(fn ($e) => [
                    'name' => $e->name, 'parent' => $e->parent, 'access' => $e->access,
                    'risk_flags' => $e->risk_flags, 'line_start' => $e->line_start, 'line_end' => $e->line_end,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'pagination' => [
                'current_page' => $page, 'per_page' => $perPage,
                'total' => $total, 'last_page' => $lastPage,
            ],
            'query' => [
                'entity' => $entity, 'q' => $request->query('q'), 'match' => $request->query('match', 'prefix'),
                'access' => $request->query('access'), 'risk' => $request->query('risk'),
            ],
        ]);
    }

    /** GET /source-docs/search/suggest — autocomplete de nomes (via índice). */
    public function suggest(Request $request): JsonResponse
    {
        $entity = (string) $request->query('entity', '');
        if (! in_array($entity, SourceDocEntity::TYPES, true)) {
            return response()->json(['data' => []]);
        }
        $q = trim((string) $request->query('q', ''));

        $query = SourceDocEntity::query()
            ->where('entity_type', $entity)
            ->when($q !== '', fn ($x) => $x->whereRaw('lower(name) like ?', [mb_strtolower($q) . '%']))
            ->when($request->filled('customer_id'), fn ($x) => $x->where('customer_id', (int) $request->query('customer_id')));

        // C4a: autocomplete não pode vazar nomes de entidades de clientes fora do escopo.
        $this->scope->applyScope($query, $request->user(), 'source_doc_entities.customer_id');

        $rows = $query->select('name')->distinct()->orderBy('name')->limit(20)->pluck('name');

        return response()->json(['data' => $rows]);
    }

    /** GET /source-docs/{id}/entities — entidades de UM fonte (deep-link da ficha). */
    public function entities(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::whereKey($sourceDoc)->select('id', 'customer_id')->first();
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $rows = SourceDocEntity::query()
            ->where('source_doc_id', $sourceDoc)
            ->when($request->filled('type'), fn ($x) => $x->where('entity_type', (string) $request->query('type')))
            ->orderBy('entity_type')->orderBy('name')
            ->get(['entity_type', 'name', 'parent', 'access', 'risk_flags', 'line_start', 'line_end']);

        return response()->json(['data' => $rows->groupBy('entity_type')]);
    }

    // ── base query (filtros comuns) ────────────────────────────────────────────

    private function baseQuery(Request $request, string $entity)
    {
        $q = trim((string) $request->query('q', ''));
        $match = (string) $request->query('match', 'prefix'); // exact | prefix | contains

        $query = SourceDocEntity::query()
            ->where('entity_type', $entity)
            ->when($q !== '', function ($x) use ($q, $match) {
                $lq = mb_strtolower($q);
                match ($match) {
                    'exact'    => $x->whereRaw('lower(name) = ?', [$lq]),
                    'contains' => $x->whereRaw('lower(name) like ?', ['%' . $lq . '%']),
                    default    => $x->whereRaw('lower(name) like ?', [$lq . '%']), // prefix
                };
            })
            ->when($request->filled('access'), function ($x) use ($request) {
                foreach (explode(',', (string) $request->query('access')) as $a) {
                    $a = strtoupper(trim($a));
                    if ($a !== '') { $x->whereJsonContains('access', $a); }
                }
            })
            ->when($request->filled('risk'), fn ($x) => $x->whereJsonContains('risk_flags', (string) $request->query('risk')))
            ->when($request->filled('customer_id'), fn ($x) => $x->where('customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('owner'), fn ($x) => $x->where('owner', (string) $request->query('owner')))
            ->when($request->filled('repository'), fn ($x) => $x->where('repository', (string) $request->query('repository')));

        // C4a: busca técnica NUNCA varre entidades de clientes fora do escopo (deny-by-default).
        $this->scope->applyScope($query, $request->user(), 'source_doc_entities.customer_id');

        return $query;
    }
}
