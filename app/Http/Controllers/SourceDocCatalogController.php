<?php

namespace App\Http\Controllers;

use App\Models\SourceDoc;
use App\Models\SourceDocVersion;
use App\SourceCode\SourceDocCustomerScope;
use App\SourceCode\SourceDocStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C1 (SOMENTE LEITURA). Catálogo de documentação de código-fonte:
 * lista, ficha (meta enxuta), documentação pesada sob demanda e histórico de versões.
 *
 * Consome o motor de doc v2.1 (CONGELADO) sem alterá-lo: lê source_docs/source_doc_versions
 * e usa o SourceDocStatusResolver (situação por blob SHA, em bulk por árvore cacheada).
 *
 * Gate: middleware permission.or.admin:source_docs.view (data-driven — nada de perfil hardcoded).
 * Fora do escopo do C1: busca técnica, validar agora, reprocessar, downloads, diff detalhado.
 */
class SourceDocCatalogController extends Controller
{
    public function __construct(
        private SourceDocStatusResolver $resolver,
        private SourceDocCustomerScope $scope,
    ) {
    }

    /**
     * GET /source-docs — catálogo paginado + indicadores (100% do banco) + situação Git
     * em bulk SÓ da página. Nenhum JSON pesado (deterministic/semantic) trafega por linha.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 50) ?: 50, 1), 100);
        $q = trim((string) $request->query('q', ''));

        $query = SourceDoc::query()
            ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
            // C2: functions_count vem do read-model (O(1)), não mais do json_array_length do JSON pesado.
            ->leftJoin('source_doc_index as si', 'si.source_doc_id', '=', 'source_docs.id')
            ->with([
                'customer:id,name',
                'sourceRepo:id,owner,repository,branch',
            ])
            ->when($request->filled('customer_id'), fn ($x) => $x->where('source_docs.customer_id', (int) $request->query('customer_id')))
            ->when($request->filled('source_repo_id'), fn ($x) => $x->where('source_docs.source_repo_id', (int) $request->query('source_repo_id')))
            ->when($request->filled('repository'), fn ($x) => $x->where('source_docs.repository', (string) $request->query('repository')))
            ->when($request->filled('analysis_status'), fn ($x) => $x->where('source_docs.analysis_status', (string) $request->query('analysis_status')))
            ->when($request->filled('lang'), fn ($x) => $x->where('source_docs.lang', (string) $request->query('lang')))
            ->when($request->filled('tipo'), fn ($x) => $x->where('source_docs.tipo', (string) $request->query('tipo')))
            ->when($request->filled('semantic'), function ($x) use ($request) {
                switch ((string) $request->query('semantic')) {
                    case 'none':      $x->whereNull('cv.semantic_json'); break;
                    case 'completed': $x->whereNotNull('cv.semantic_json')->where('cv.analysis_status', 'completed'); break;
                    case 'partial':   $x->whereNotNull('cv.semantic_json')->where('cv.analysis_status', '<>', 'completed'); break;
                }
            })
            ->when($q !== '', fn ($x) => $x->where(function ($w) use ($q, $request) {
                $w->where('source_docs.filename', 'ilike', "%{$q}%")
                    ->orWhere('source_docs.path', 'ilike', "%{$q}%");
                // F5 — busca por CONHECIMENTO já documentado (objetivo/regras/processo/deps): texto do
                // semantic_json. Rápido quando escopado (customer/repo/path); global detoasta mais (medido).
                if ($request->query('in') === 'knowledge') {
                    // Busca no texto do semantic_json (rápido, ~17ms escopado). LIMITAÇÃO: o JSON é
                    // armazenado com escape unicode (ç), então termos ACENTUADOS podem não casar
                    // (ASCII casa). Normalizar (::jsonb::text) resolveria o acento mas custa ~40x (medido) —
                    // fix adequado = índice GIN/coluna normalizada, adiado sem evidência de uso frequente.
                    $w->orWhereRaw('(cv.semantic_json is not null and cv.semantic_json::text ilike ?)', ["%{$q}%"]);
                }
            }))
            // F2 (Acervo): conteúdo de uma pasta (recursivo) via prefixo do path Git.
            ->when($request->filled('path_prefix'), fn ($x) => $x->where(
                'source_docs.path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $request->query('path_prefix')) . '%'
            ));

        // C4a — ESCOPO POR CLIENTE (enforcement em SQL, deny-by-default). O filtro customer_id
        // acima é apenas recorte de UI; a segurança é este applyScope, que intersecta com os
        // clientes que o usuário PODE ver. Fora do escopo simplesmente não aparece.
        $this->scope->applyScope($query, $request->user(), 'source_docs.customer_id');
        $this->scope->applyRepoVisibility($query, 'source_docs', $request->boolean('include_hidden'));

        switch ((string) $request->query('sort', 'last_change')) {
            case 'filename':  $query->orderBy('source_docs.filename'); break;
            case 'situation': $query->orderBy('source_docs.analysis_status')->orderByRaw('cv.created_at desc nulls last'); break;
            default:          $query->orderByRaw('cv.created_at desc nulls last');
        }

        // SELECT enxuto: nada de deterministic/semantic/documentation JSON por linha.
        // has_semantic é booleano (não detoasta o JSON). functions_count vem do READ-MODEL C2
        // (source_doc_index) — O(1), sem ler o JSON pesado; null enquanto o fonte não foi indexado.
        // Colunas explícitas — NUNCA source_docs.* (traria documentation_json, ~360KB/linha).
        $query->select([
            'source_docs.id', 'source_docs.customer_id', 'source_docs.source_repo_id',
            'source_docs.owner', 'source_docs.repository', 'source_docs.branch', 'source_docs.path',
            'source_docs.filename', 'source_docs.tipo', 'source_docs.lang', 'source_docs.size_bytes',
            'source_docs.current_version_id', 'source_docs.analysis_status',
            DB::raw('cv.created_at as last_change_at'),
            DB::raw('cv.analysis_status as cv_status'),
            DB::raw('cv.gmud_id as cv_gmud_id'),
            DB::raw('cv.ticket_number as cv_ticket_number'),
            DB::raw('cv.responsavel as cv_responsavel'),
            DB::raw('(cv.semantic_json is not null) as has_semantic'),
            DB::raw('si.functions_count as functions_count'),
        ]);

        $page = $query->paginate($perPage);

        /** @var \Illuminate\Database\Eloquent\Collection<int,SourceDoc> $docs */
        $docs = $page->getCollection();

        // Situação Git — SÓ da página, em bulk (1 árvore por repo, cacheada). Degrada sem quebrar.
        $withSituation = $request->query('with_situation', 'true') !== 'false';
        $situations = [];
        if ($withSituation && $docs->isNotEmpty()) {
            $docs->load(['currentVersion', 'sourceRepo']); // 2 queries batch — sem N+1
            $situations = $this->resolver->resolveMany($docs);
        }

        $data = $docs->map(function (SourceDoc $d) use ($situations) {
            $sit = $situations[$d->id] ?? null;

            return [
                'id'              => $d->id,
                'filename'        => $d->filename,
                'path'            => $d->path,
                'owner'           => $d->owner,
                'repository'      => $d->repository,
                'branch'          => $d->branch,
                'lang'            => $d->lang,
                'tipo'            => $d->tipo,
                'size_bytes'      => $d->size_bytes,
                'customer'        => $d->customer ? ['id' => $d->customer->id, 'name' => $d->customer->name] : null,
                'source_repo'     => $d->sourceRepo ? [
                    'id' => $d->sourceRepo->id, 'owner' => $d->sourceRepo->owner,
                    'repository' => $d->sourceRepo->repository, 'branch' => $d->sourceRepo->branch,
                ] : null,
                'analysis_status'  => $d->analysis_status,
                'functions_count'  => $d->getAttribute('functions_count') === null ? null : (int) $d->getAttribute('functions_count'),
                'semantic_quality' => $this->semanticQuality($d),
                'last_change_at'   => $d->getAttribute('last_change_at'),
                'last_gmud'        => ($d->getAttribute('cv_gmud_id') || $d->getAttribute('cv_ticket_number')) ? [
                    'id' => $d->getAttribute('cv_gmud_id'),
                    'ticket_number' => $d->getAttribute('cv_ticket_number'),
                    'responsavel' => $d->getAttribute('cv_responsavel'),
                ] : null,
                'situation' => $sit ? [
                    'status'              => $sit['status'],
                    'reason'              => $sit['reason'],
                    'documented_blob_sha' => $sit['documented_blob_sha'],
                    'current_blob_sha'    => $sit['current_blob_sha'],
                    'checked_at'          => $sit['checked_at'],
                ] : null,
            ];
        })->values();

        return response()->json([
            'data'       => $data,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
            'indicators' => $this->indicators($request),
            // Roll-up SÓ da página atual — nunca apresentado como total do catálogo (ajuste #1).
            'page_situation' => $withSituation ? $this->pageSituation($situations) : null,
        ]);
    }

    /**
     * GET /source-docs/{id} — ficha META (enxuta). Retorna identidade, os 4 status separados,
     * meta da versão vigente e a documentação SEM o bloco 'deterministic' (o pesado: ~370KB em
     * fontes grandes). O 'deterministic' vem sob demanda em /documentation (ajuste #3, medido).
     */
    public function show(Request $request, int $sourceDoc): JsonResponse
    {
        $doc = SourceDoc::query()
            ->select([
                'id', 'customer_id', 'source_repo_id', 'owner', 'repository', 'branch', 'path',
                'filename', 'tipo', 'lang', 'size_bytes', 'current_source_sha', 'current_version_id',
                'analysis_status', 'schema_version', 'created_at', 'updated_at',
            ])
            ->with([
                'customer:id,name',
                // 'active' é OBRIGATÓRIO: o SourceDocStatusResolver::preChecks lê $repo->active;
                // sem essa coluna o repo parece inativo e a situação vira NAO_VALIDADO indevido.
                'sourceRepo:id,owner,repository,branch,active',
                'currentVersion:id,source_doc_id,analysis_status,source_commit_sha,source_blob_sha,gmud_id,ticket_number,responsavel,created_at',
            ])
            ->find($sourceDoc);

        // 404 explícito (o handler global da API converte exceções em 422; um RESPONSE não-lançado
        // passa como 404 de verdade). C4a: recurso fora do escopo do cliente também retorna 404
        // (mesma mensagem — não vaza existência de fonte de outro cliente).
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        $situation = $this->resolver->resolve($doc);

        // documentation_json MENOS o 'deterministic' — recortado no Postgres (só ~13KB trafega).
        $metaJson = DB::table('source_docs')->where('id', $doc->id)
            ->value(DB::raw("(documentation_json::jsonb - 'deterministic')::text"));
        $documentationMeta = $metaJson ? json_decode($metaJson, true) : null;

        $ver = $doc->currentVersion;

        return response()->json(['data' => [
            'id'          => $doc->id,
            'filename'    => $doc->filename,
            'path'        => $doc->path,
            'owner'       => $doc->owner,
            'repository'  => $doc->repository,
            'branch'      => $doc->branch,
            'lang'        => $doc->lang,
            'tipo'        => $doc->tipo,
            'size_bytes'  => $doc->size_bytes,
            'customer'    => $doc->customer ? ['id' => $doc->customer->id, 'name' => $doc->customer->name] : null,
            'source_repo' => $doc->sourceRepo ? [
                'id' => $doc->sourceRepo->id, 'owner' => $doc->sourceRepo->owner,
                'repository' => $doc->sourceRepo->repository, 'branch' => $doc->sourceRepo->branch,
            ] : null,
            'analysis_status' => $doc->analysis_status,
            'situation'       => $situation, // resolve() completo: status/reason/shas/checked_at/message
            'current_version' => $ver ? [
                'id'                => $ver->id,
                'analysis_status'   => $ver->analysis_status,
                'semantic_quality'  => $this->semanticQualityFromVersion($ver, $documentationMeta),
                'source_commit_sha' => $ver->source_commit_sha,
                'source_blob_sha'   => $ver->source_blob_sha,
                'gmud'              => ($ver->gmud_id || $ver->ticket_number) ? [
                    'id' => $ver->gmud_id, 'ticket_number' => $ver->ticket_number,
                ] : null,
                'responsavel' => $ver->responsavel,
                'created_at'  => $ver->created_at,
            ] : null,
            // Leve (~13KB mesmo em fontes grandes): identity + semantic + diff + version + status.
            'documentation_meta' => $documentationMeta,
            // F6 — estado de governança de custo (para a ficha F3 mostrar "Aguardando aprovação de IA").
            'open_cost_approval' => ($ap = \App\Models\SourceDocCostApproval::query()->where('source_doc_id', $doc->id)->where('status', 'pending')->first(['id', 'next_step']))
                ? ['id' => $ap->id, 'next_step' => $ap->next_step] : null,
        ]]);
    }

    /**
     * GET /source-docs/{id}/documentation — bloco 'deterministic' (o pesado), sob demanda,
     * para as abas Estrutura / Dados & SQL / Dependências. Carregado só quando o usuário abre.
     */
    public function documentation(Request $request, int $sourceDoc): JsonResponse
    {
        $doc = SourceDoc::whereKey($sourceDoc)->select('id', 'customer_id')->first();
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }

        $detJson = DB::table('source_docs')->where('id', $sourceDoc)
            ->value(DB::raw("(documentation_json::jsonb -> 'deterministic')::text"));

        return response()->json(['data' => [
            'deterministic' => $detJson ? json_decode($detJson, true) : null,
        ]]);
    }

    /**
     * GET /source-docs/{id}/versions — histórico básico (imutável), paginado, mais recente
     * primeiro. Sem diff detalhado (isso é C3). Paginator cru padrão da base.
     */
    public function versions(int $sourceDoc, Request $request): JsonResponse
    {
        $doc = SourceDoc::whereKey($sourceDoc)->select('id', 'customer_id')->first();
        if (! $doc || ! $this->scope->canAccessDoc($request->user(), $doc)) {
            return response()->json(['message' => 'Fonte não encontrada.'], 404);
        }
        $perPage = min(max((int) $request->query('per_page', 20) ?: 20, 1), 100);

        $page = SourceDocVersion::query()
            ->where('source_doc_id', $sourceDoc)
            ->select([
                'id', 'source_commit_sha', 'source_blob_sha', 'gmud_id', 'ticket_number',
                'responsavel', 'analysis_status', 'diff_summary', 'diff_stats', 'created_at',
            ])
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($page);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    /** none | partial | completed — derivado sem carregar o JSON semântico. */
    private function semanticQuality(SourceDoc $d): string
    {
        if (! $d->getAttribute('has_semantic')) {
            return 'none';
        }

        return $d->getAttribute('cv_status') === 'completed' ? 'completed' : 'partial';
    }

    private function semanticQualityFromVersion(SourceDocVersion $ver, ?array $meta): string
    {
        $hasSem = is_array($meta) && ! empty($meta['semantic']);
        if (! $hasSem) {
            return 'none';
        }

        return $ver->analysis_status === 'completed' ? 'completed' : 'partial';
    }

    /** Indicadores 100% do banco (ajuste #1) — SEM situação Git catalog-wide. */
    private function indicators(Request $request): array
    {
        // C4a: indicadores TAMBÉM escopados por cliente (não podem contar fontes fora do escopo).
        $scope = fn ($qb) => $this->scope->applyRepoVisibility($this->scope->applyScope(
            $qb
                ->when($request->filled('customer_id'), fn ($x) => $x->where('source_docs.customer_id', (int) $request->query('customer_id')))
                ->when($request->filled('source_repo_id'), fn ($x) => $x->where('source_docs.source_repo_id', (int) $request->query('source_repo_id'))),
            $request->user(),
            'source_docs.customer_id'
        ), 'source_docs', $request->boolean('include_hidden'));

        $byAnalysis = $scope(DB::table('source_docs'))
            ->select('analysis_status', DB::raw('count(*) as c'))
            ->groupBy('analysis_status')->pluck('c', 'analysis_status');

        $sem = $scope(
            DB::table('source_docs')
                ->leftJoin('source_doc_versions as cv', 'cv.id', '=', 'source_docs.current_version_id')
        )->selectRaw("
            count(*) as total,
            count(*) filter (where cv.semantic_json is not null and cv.analysis_status = 'completed') as sem_completed,
            count(*) filter (where cv.semantic_json is not null and cv.analysis_status <> 'completed') as sem_partial,
            count(*) filter (where cv.semantic_json is null) as sem_none
        ")->first();

        return [
            'total'       => (int) ($sem->total ?? 0),
            'by_analysis' => [
                'pending'    => (int) ($byAnalysis['pending'] ?? 0),
                'extracting' => (int) ($byAnalysis['extracting'] ?? 0),
                'analyzing'  => (int) ($byAnalysis['analyzing'] ?? 0),
                'completed'  => (int) ($byAnalysis['completed'] ?? 0),
                'partial'    => (int) ($byAnalysis['partial'] ?? 0),
                'failed'     => (int) ($byAnalysis['failed'] ?? 0),
            ],
            'by_semantic' => [
                'completed' => (int) ($sem->sem_completed ?? 0),
                'partial'   => (int) ($sem->sem_partial ?? 0),
                'none'      => (int) ($sem->sem_none ?? 0),
            ],
        ];
    }

    /** Roll-up da situação SÓ da página (rotulado como tal; nunca vira total do catálogo). */
    private function pageSituation(array $situations): array
    {
        $out = ['ATUALIZADA' => 0, 'DESATUALIZADA' => 0, 'NAO_VALIDADO' => 0];
        foreach ($situations as $s) {
            $st = $s['status'] ?? null;
            if (isset($out[$st])) {
                $out[$st]++;
            }
        }
        $out['scope'] = 'current_page';

        return $out;
    }
}
