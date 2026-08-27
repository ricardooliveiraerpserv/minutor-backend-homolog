<?php

namespace App\Http\Controllers;

use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Central de Fontes — C1. Read-model ÚNICO de Atividade & Auditoria (timeline transversal).
 *
 * NÃO consolida nem correlaciona no servidor: devolve LINHAS NATIVAS reais (no shape que os
 * adapters C4.2 do FE já esperam) e o FE roda adapters + correlate() — o contrato homologado
 * permanece autoridade. Só LEITURA; nenhuma mutação; zero-schema (reusa tabelas existentes).
 *
 * 7 fontes: source_doc_versions (fontes) · source_doc_versions c/ GMUD (publicacoes) ·
 * source_doc_action_log (fontes/publicacoes/governança) · source_doc_cost_approvals (enriquece
 * as ações cost_approval_*) · source_doc_quality_analyses (qualidade) ·
 * source_semantic_campaign_events (qualidade/campanha) · source_repo_coverage (inventario).
 *
 * Escopo por cliente = SourceDocCustomerScope (deny-by-default, SQL). A família operacoes NÃO é
 * emitida aqui (pertence à Trilha Conector/Bloco B) — vai em pending_families, nunca fixture.
 */
class SourceDocActivityController extends Controller
{
    /** Ordem estável de desempate quando o timestamp coincide entre famílias. */
    private const FAMILY_RANK = ['operacoes' => 0, 'fontes' => 1, 'publicacoes' => 2, 'qualidade' => 3, 'inventario' => 4];

    public function __construct(private SourceDocCustomerScope $scope)
    {
    }

    /**
     * GET /source-docs/activity
     *   ?customer_id=&from=&to=&family=fontes,publicacoes,...&actor_id=&outcome=&cursor=&limit=
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── Empresa: o parâmetro NUNCA é autoridade — validado contra o escopo real (anti-IDOR).
        $customerId = null;
        if ($request->filled('customer_id')) {
            $customerId = (int) $request->query('customer_id');
            if (! $this->scope->canAccessCustomerId($user, $customerId)) {
                return response()->json(['message' => 'Empresa fora do seu escopo.'], 403);
            }
        }

        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $buffer = 50; // folga p/ o merge keyset acomodar empates de timestamp entre famílias
        $fetch = $limit + $buffer;

        $from = $request->filled('from') ? (string) $request->query('from') : null;
        $to = $request->filled('to') ? (string) $request->query('to') : null;
        $actorId = $request->filled('actor_id') ? (int) $request->query('actor_id') : null;
        $outcome = $request->filled('outcome') ? (string) $request->query('outcome') : null;
        $cursor = $this->decodeCursor((string) $request->query('cursor', ''));

        // Famílias pedidas (subconjunto); operacoes é sempre ignorada (Bloco B/Conector).
        $requested = collect(explode(',', (string) $request->query('family', '')))
            ->map(fn ($f) => trim($f))->filter()->values()->all();
        $wants = fn (string $fam) => $requested === [] || in_array($fam, $requested, true);

        // Gate por família — ESPELHA o FAMILY_CAP do FE (o servidor também decide; UI não é segurança).
        $isAdmin = $user->isAdmin();
        $can = fn (string $p) => $isAdmin || $user->hasAccess($p);
        $capFontes = $can('source_docs.view') || $can('source_docs.quality.view');
        $capQualidade = $can('source_docs.quality.view') || $can('source_docs.view');
        $capPublic = $isAdmin || $can('source_docs.gmud_publish');
        $capInventario = $isAdmin; // Inventário Git×RPO é admin-only (igual ao nav/FE)
        $canCost = $isAdmin || $can('source_docs.cost_approval.view');
        $canCampaign = $isAdmin || $can('source_docs.semantic_campaign');

        $rows = [];

        // ── (1) fontes — TODAS as versões (source_doc_versions).
        if ($wants('fontes') && $capFontes && $this->outcomeAllows($outcome, ['info'])) {
            $rows = array_merge($rows, $this->fetchVersions($user, $customerId, $from, $to, $actorId, $cursor, $fetch));
        }

        // ── (2) publicacoes — versões oriundas de GMUD (gmud_id/ticket_number).
        if ($wants('publicacoes') && $capPublic && $this->outcomeAllows($outcome, ['ok'])) {
            $rows = array_merge($rows, $this->fetchGmud($user, $customerId, $from, $to, $actorId, $cursor, $fetch));
        }

        // ── (3)/(6) ações + governança de custo (source_doc_action_log [+enriquecimento]).
        if (($wants('fontes') || $wants('publicacoes')) && $capFontes) {
            $rows = array_merge($rows, $this->fetchActions($user, $customerId, $from, $to, $actorId, $outcome, $cursor, $fetch, $canCost, $wants, $capPublic));
        }

        // ── (4) qualidade — análises (source_doc_quality_analyses).
        if ($wants('qualidade') && $capQualidade) {
            $rows = array_merge($rows, $this->fetchQuality($user, $customerId, $from, $to, $actorId, $outcome, $cursor, $fetch));
        }

        // ── (5) qualidade/campanha — eventos de campanha (GLOBAIS, sem customer_id).
        //     Ajuste #1: só em contexto "Todas" (sem customer_id) + usuário GLOBAL autorizado. Externo nunca.
        if ($wants('qualidade') && $canCampaign && $customerId === null && $this->scope->isGlobal($user)) {
            $rows = array_merge($rows, $this->fetchCampaign($user, $from, $to, $actorId, $outcome, $cursor, $fetch));
        }

        // ── (7) inventario — cobertura por repo (source_repo_coverage).
        if ($wants('inventario') && $capInventario && $actorId === null) {
            $rows = array_merge($rows, $this->fetchCoverage($user, $customerId, $from, $to, $outcome, $cursor, $fetch));
        }

        // ── Merge k-way + keyset. Ordem global: (occurred desc, familyRank asc, nativeId desc).
        usort($rows, fn ($a, $b) => $this->cmpDesc($a, $b));

        // Descarta tudo que NÃO é estritamente "depois" do cursor nessa ordenação (sem pular/duplicar).
        if ($cursor) {
            $rows = array_values(array_filter($rows, fn ($r) => $this->afterCursor($r, $cursor)));
        }

        $hasMore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        // Enriquecimento de governança só nos itens DA PÁGINA (evita fanout de join).
        $this->enrichCostApprovals($page);

        $nextCursor = $hasMore && $page
            ? $this->encodeCursor(end($page))
            : null;

        $items = array_map(fn ($r) => ['family' => $r['family'], 'kind' => $r['kind'], 'native' => $r['native']], $page);

        return response()->json(['data' => [
            'items' => $items,
            'next_cursor' => $nextCursor,
            'pending_families' => ['operacoes'], // Bloco B / Trilha Conector — nunca preenchido com fixture
            'mode' => 'live',
        ]]);
    }

    // ── Fontes ────────────────────────────────────────────────────────────────

    private function fetchVersions($user, ?int $cid, ?string $from, ?string $to, ?int $actor, ?array $cursor, int $n): array
    {
        $col = 'source_doc_versions.created_at';
        $q = DB::table('source_doc_versions')
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_versions.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->when($cid !== null, fn ($x) => $x->where('source_docs.customer_id', $cid))
            ->when($from, fn ($x) => $x->whereDate($col, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($col, '<=', $to))
            ->when($actor !== null, fn ($x) => $x->where('source_doc_versions.responsible_user_id', $actor))
            ->when($cursor, fn ($x) => $x->where($col, '<=', $cursor['t']));
        $this->scope->applyScope($q, $user, 'source_docs.customer_id');
        $this->scope->applyRepoVisibility($q, 'source_docs');

        return $q->orderByDesc($col)->limit($n)->get([
            'source_doc_versions.id', 'source_doc_versions.source_doc_id', 'source_doc_versions.source_commit_sha',
            'source_doc_versions.source_blob_sha', 'source_doc_versions.gmud_id', 'source_doc_versions.ticket_number',
            'source_doc_versions.responsavel', 'source_doc_versions.analysis_status', 'source_doc_versions.diff_summary',
            'source_doc_versions.created_at',
            'source_docs.filename', 'source_docs.owner', 'source_docs.repository', 'source_docs.customer_id',
            'customers.name as customer_name',
        ])->map(fn ($r) => $this->norm('fontes', 'source-version', (int) $r->id, $r->created_at, [
            'id' => (int) $r->id, 'source_doc_id' => (int) $r->source_doc_id, 'filename' => $r->filename,
            'owner' => $r->owner, 'repository' => $r->repository,
            'source_commit_sha' => $r->source_commit_sha, 'source_blob_sha' => $r->source_blob_sha,
            'gmud_id' => $r->gmud_id !== null ? (int) $r->gmud_id : null, 'ticket_number' => $r->ticket_number,
            'responsavel' => $r->responsavel, 'analysis_status' => $r->analysis_status, 'diff_summary' => $r->diff_summary,
            'created_at' => $this->iso($r->created_at),
            'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null, 'customer_name' => $r->customer_name,
        ]))->all();
    }

    private function fetchGmud($user, ?int $cid, ?string $from, ?string $to, ?int $actor, ?array $cursor, int $n): array
    {
        $col = 'source_doc_versions.created_at';
        $q = DB::table('source_doc_versions')
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_versions.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->leftJoin('helpdesk_tickets as ht', 'ht.ticket_number', '=', 'source_doc_versions.ticket_number')
            ->where(fn ($w) => $w->whereNotNull('source_doc_versions.gmud_id')->orWhereNotNull('source_doc_versions.ticket_number'))
            ->when($cid !== null, fn ($x) => $x->where('source_docs.customer_id', $cid))
            ->when($from, fn ($x) => $x->whereDate($col, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($col, '<=', $to))
            ->when($actor !== null, fn ($x) => $x->where('source_doc_versions.responsible_user_id', $actor))
            ->when($cursor, fn ($x) => $x->where($col, '<=', $cursor['t']));
        $this->scope->applyScope($q, $user, 'source_docs.customer_id');
        $this->scope->applyRepoVisibility($q, 'source_docs');

        return $q->orderByDesc($col)->limit($n)->get([
            'source_doc_versions.id', 'source_doc_versions.source_doc_id', 'source_doc_versions.source_commit_sha',
            'source_doc_versions.gmud_id', 'source_doc_versions.ticket_number', 'source_doc_versions.responsavel',
            'source_doc_versions.diff_summary', 'source_doc_versions.created_at',
            'source_docs.filename', 'source_docs.owner', 'source_docs.repository', 'source_docs.customer_id',
            'customers.name as customer_name', 'ht.id as hd_ticket_id', 'ht.subject as hd_subject',
        ])->map(fn ($r) => $this->norm('publicacoes', 'gmud-commit', (int) $r->id, $r->created_at, [
            'id' => (int) $r->id, 'source_doc_id' => (int) $r->source_doc_id, 'filename' => $r->filename,
            'owner' => $r->owner, 'repository' => $r->repository, 'source_commit_sha' => $r->source_commit_sha,
            'gmud_id' => $r->gmud_id !== null ? (int) $r->gmud_id : null, 'ticket_number' => $r->ticket_number,
            'responsavel' => $r->responsavel, 'diff_summary' => $r->diff_summary, 'created_at' => $this->iso($r->created_at),
            'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null, 'customer_name' => $r->customer_name,
            'hd_ticket_id' => $r->hd_ticket_id !== null ? (int) $r->hd_ticket_id : null, 'hd_subject' => $r->hd_subject,
        ]))->all();
    }

    private function fetchActions($user, ?int $cid, ?string $from, ?string $to, ?int $actor, ?string $outcome, ?array $cursor, int $n, bool $canCost, callable $wants, bool $capPublic): array
    {
        $col = 'source_doc_action_log.created_at';
        $q = DB::table('source_doc_action_log')
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_action_log.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->leftJoin('users', 'users.id', '=', 'source_doc_action_log.actor_user_id')
            ->when($cid !== null, fn ($x) => $x->where('source_docs.customer_id', $cid))
            ->when($from, fn ($x) => $x->whereDate($col, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($col, '<=', $to))
            ->when($actor !== null, fn ($x) => $x->where('source_doc_action_log.actor_user_id', $actor))
            ->when(! $canCost, fn ($x) => $x->where('source_doc_action_log.action', 'not like', 'cost_approval_%'))
            ->when(! $wants('publicacoes'), fn ($x) => $x->where('source_doc_action_log.action', '!=', 'publish_git'))
            ->when(! $wants('fontes'), fn ($x) => $x->where('source_doc_action_log.action', '=', 'publish_git'))
            ->when($cursor, fn ($x) => $x->where($col, '<=', $cursor['t']));

        // Filtro de resultado (mapeado do status nativo).
        if ($outcome !== null) {
            $st = $this->actionStatusesForOutcome($outcome);
            if ($st === []) {
                return [];
            }
            $q->whereIn('source_doc_action_log.status', $st);
        }
        // publish_git só entra p/ quem pode publicacoes.
        if (! $capPublic) {
            $q->where('source_doc_action_log.action', '!=', 'publish_git');
        }

        $this->scope->applyScope($q, $user, 'source_docs.customer_id');
        $this->scope->applyRepoVisibility($q, 'source_docs');

        return $q->orderByDesc($col)->limit($n)->get([
            'source_doc_action_log.id', 'source_doc_action_log.source_doc_id', 'source_doc_action_log.version_id',
            'source_doc_action_log.action', 'source_doc_action_log.layer', 'source_doc_action_log.status',
            'source_doc_action_log.reason', 'source_doc_action_log.cost_usd', 'source_doc_action_log.duration_ms',
            'source_doc_action_log.actor_user_id', 'source_doc_action_log.created_at',
            'source_docs.filename', 'source_docs.owner', 'source_docs.repository', 'source_docs.customer_id',
            'customers.name as customer_name', 'users.name as actor_name',
        ])->map(function ($r) {
            $family = $r->action === 'publish_git' ? 'publicacoes' : 'fontes';
            return $this->norm($family, 'source-action', (int) $r->id, $r->created_at, [
                'id' => (int) $r->id, 'source_doc_id' => (int) $r->source_doc_id,
                'version_id' => $r->version_id !== null ? (int) $r->version_id : null,
                'action' => $r->action, 'layer' => $r->layer, 'status' => $r->status,
                'denied' => $r->status === 'denied', 'reason' => $r->reason,
                'cost_usd' => $r->cost_usd !== null ? (float) $r->cost_usd : null,
                'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                'actor_user_id' => $r->actor_user_id !== null ? (int) $r->actor_user_id : null,
                'actor_name' => $r->actor_name, 'created_at' => $this->iso($r->created_at),
                'filename' => $r->filename, 'owner' => $r->owner, 'repository' => $r->repository,
                'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null, 'customer_name' => $r->customer_name,
                'approval' => null, // preenchido por enrichCostApprovals() só p/ ações cost_approval_*
            ]);
        })->all();
    }

    private function fetchQuality($user, ?int $cid, ?string $from, ?string $to, ?int $actor, ?string $outcome, ?array $cursor, int $n): array
    {
        $occ = 'COALESCE(source_doc_quality_analyses.completed_at, source_doc_quality_analyses.requested_at, source_doc_quality_analyses.created_at)';
        $q = DB::table('source_doc_quality_analyses')
            ->join('source_docs', 'source_docs.id', '=', 'source_doc_quality_analyses.source_doc_id')
            ->leftJoin('customers', 'customers.id', '=', 'source_docs.customer_id')
            ->when($cid !== null, fn ($x) => $x->where('source_docs.customer_id', $cid))
            ->when($from, fn ($x) => $x->whereRaw("$occ >= ?", ["$from 00:00:00"]))
            ->when($to, fn ($x) => $x->whereRaw("$occ <= ?", ["$to 23:59:59"]))
            ->when($actor !== null, fn ($x) => $x->where('source_doc_quality_analyses.requested_by', $actor))
            ->when($cursor, fn ($x) => $x->whereRaw("$occ <= ?", [$cursor['t']]));

        if ($outcome !== null) {
            $st = $this->qualityStatusesForOutcome($outcome);
            if ($st === []) {
                return [];
            }
            $q->whereIn('source_doc_quality_analyses.status', $st);
        }

        $this->scope->applyScope($q, $user, 'source_docs.customer_id');
        $this->scope->applyRepoVisibility($q, 'source_docs');

        return $q->orderByRaw("$occ desc")->limit($n)->get([
            'source_doc_quality_analyses.id', 'source_doc_quality_analyses.source_doc_id',
            'source_doc_quality_analyses.source_blob_sha', 'source_doc_quality_analyses.status',
            'source_doc_quality_analyses.score', 'source_doc_quality_analyses.grade', 'source_doc_quality_analyses.risk',
            'source_doc_quality_analyses.requested_at', 'source_doc_quality_analyses.completed_at',
            DB::raw("$occ as occurred_at"),
            'source_docs.filename', 'source_docs.owner', 'source_docs.repository', 'source_docs.customer_id',
            'customers.name as customer_name',
        ])->map(fn ($r) => $this->norm('qualidade', 'quality', (int) $r->id, $r->occurred_at, [
            'id' => (int) $r->id, 'source_doc_id' => (int) $r->source_doc_id, 'filename' => $r->filename,
            'owner' => $r->owner, 'repository' => $r->repository, 'source_blob_sha' => $r->source_blob_sha,
            'status' => $r->status, 'score' => $r->score !== null ? (int) $r->score : null, 'grade' => $r->grade, 'risk' => $r->risk,
            'requested_at' => $this->iso($r->requested_at), 'completed_at' => $this->iso($r->completed_at),
            'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null, 'customer_name' => $r->customer_name,
        ]))->all();
    }

    private function fetchCampaign($user, ?string $from, ?string $to, ?int $actor, ?string $outcome, ?array $cursor, int $n): array
    {
        // Só eventos de campanha coerentes com o filtro de resultado.
        if ($outcome !== null && ! in_array($outcome, ['info', 'ok'], true)) {
            return [];
        }
        $col = 'source_semantic_campaign_events.created_at';
        $q = DB::table('source_semantic_campaign_events')
            ->join('source_semantic_campaign as c', 'c.id', '=', 'source_semantic_campaign_events.campaign_id')
            ->leftJoin('users', 'users.id', '=', 'source_semantic_campaign_events.actor_user_id')
            ->when($from, fn ($x) => $x->whereDate($col, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($col, '<=', $to))
            ->when($actor !== null, fn ($x) => $x->where('source_semantic_campaign_events.actor_user_id', $actor))
            ->when($outcome === 'ok', fn ($x) => $x->where('source_semantic_campaign_events.event', 'completed'))
            ->when($cursor, fn ($x) => $x->where($col, '<=', $cursor['t']));

        return $q->orderByDesc($col)->limit($n)->get([
            'source_semantic_campaign_events.id', 'source_semantic_campaign_events.campaign_id',
            'source_semantic_campaign_events.event', 'source_semantic_campaign_events.actor_user_id',
            'source_semantic_campaign_events.created_at', 'c.name as campaign_name',
            'users.name as actor_name',
        ])->map(fn ($r) => $this->norm('qualidade', 'campaign', (int) $r->id, $r->created_at, [
            'id' => (int) $r->id, 'campaign_id' => (int) $r->campaign_id, 'campaign_name' => $r->campaign_name,
            'event' => $r->event, 'actor_user_id' => $r->actor_user_id !== null ? (int) $r->actor_user_id : null,
            'actor_name' => $r->actor_name, 'created_at' => $this->iso($r->created_at),
        ]))->all();
    }

    private function fetchCoverage($user, ?int $cid, ?string $from, ?string $to, ?string $outcome, ?array $cursor, int $n): array
    {
        $occ = 'COALESCE(source_repo_coverage.scan_finished_at, source_repo_coverage.last_synced_at, source_repo_coverage.scan_started_at, source_repo_coverage.updated_at)';
        $q = DB::table('source_repo_coverage')
            ->leftJoin('customers', 'customers.id', '=', 'source_repo_coverage.customer_id')
            ->when($cid !== null, fn ($x) => $x->where('source_repo_coverage.customer_id', $cid))
            ->when($from, fn ($x) => $x->whereRaw("$occ >= ?", ["$from 00:00:00"]))
            ->when($to, fn ($x) => $x->whereRaw("$occ <= ?", ["$to 23:59:59"]))
            ->when($cursor, fn ($x) => $x->whereRaw("$occ <= ?", [$cursor['t']]));

        if ($outcome !== null) {
            $st = $this->coverageStatusesForOutcome($outcome);
            if ($st === []) {
                return [];
            }
            $q->whereIn('source_repo_coverage.scan_status', $st);
        }

        $this->scope->applyScope($q, $user, 'source_repo_coverage.customer_id');
        $this->scope->applyRepoVisibility($q, 'source_repo_coverage'); // coverage tem customer_id + repository

        return $q->orderByRaw("$occ desc")->limit($n)->get([
            'source_repo_coverage.source_repo_id', 'source_repo_coverage.owner', 'source_repo_coverage.repository',
            'source_repo_coverage.branch', 'source_repo_coverage.customer_id', 'source_repo_coverage.scan_status',
            'source_repo_coverage.scan_started_at', 'source_repo_coverage.scan_finished_at', 'source_repo_coverage.last_synced_at',
            'source_repo_coverage.github_files', 'source_repo_coverage.eligible_source_files', 'source_repo_coverage.cataloged',
            'source_repo_coverage.deterministic', 'source_repo_coverage.semantic', 'source_repo_coverage.indexed',
            'source_repo_coverage.changed_files',
            DB::raw("$occ as occurred_at"), 'customers.name as customer_name',
        ])->map(fn ($r) => $this->norm('inventario', 'coverage-scan', (int) $r->source_repo_id, $r->occurred_at, [
            'source_repo_id' => (int) $r->source_repo_id, 'owner' => $r->owner, 'repository' => $r->repository,
            'branch' => $r->branch, 'customer_id' => $r->customer_id !== null ? (int) $r->customer_id : null,
            'customer_name' => $r->customer_name, 'scan_status' => $r->scan_status,
            'scan_started_at' => $this->iso($r->scan_started_at), 'scan_finished_at' => $this->iso($r->scan_finished_at),
            'last_synced_at' => $this->iso($r->last_synced_at), 'occurred_at' => $this->iso($r->occurred_at),
            'github_files' => (int) $r->github_files, 'eligible' => (int) $r->eligible_source_files,
            'cataloged' => (int) $r->cataloged, 'deterministic' => (int) $r->deterministic,
            'semantic' => (int) $r->semantic, 'indexed' => (int) $r->indexed, 'changed' => (int) $r->changed_files,
        ]))->all();
    }

    // ── Enriquecimento de governança (só itens da página) ─────────────────────

    private function enrichCostApprovals(array &$page): void
    {
        $docIds = [];
        foreach ($page as $r) {
            if ($r['kind'] === 'source-action' && str_starts_with((string) ($r['native']['action'] ?? ''), 'cost_approval_')) {
                $docIds[(int) $r['native']['source_doc_id']] = true;
            }
        }
        if ($docIds === []) {
            return;
        }
        // Última aprovação por fonte (resumo p/ a faceta — não é fonte de verdade da timeline).
        $approvals = DB::table('source_doc_cost_approvals')
            ->whereIn('source_doc_id', array_keys($docIds))
            ->orderByDesc('created_at')
            ->get(['source_doc_id', 'status', 'next_step', 'completeness_level', 'recommendation', 'authorized_limit_usd', 'actual_cost_usd'])
            ->groupBy('source_doc_id');

        foreach ($page as &$r) {
            if ($r['kind'] === 'source-action' && str_starts_with((string) ($r['native']['action'] ?? ''), 'cost_approval_')) {
                $a = $approvals->get((int) $r['native']['source_doc_id'])?->first();
                if ($a) {
                    $r['native']['approval'] = [
                        'status' => $a->status, 'next_step' => $a->next_step, 'completeness_level' => $a->completeness_level,
                        'recommendation' => $a->recommendation,
                        'authorized_limit_usd' => $a->authorized_limit_usd !== null ? (float) $a->authorized_limit_usd : null,
                        'actual_cost_usd' => $a->actual_cost_usd !== null ? (float) $a->actual_cost_usd : null,
                    ];
                }
            }
        }
        unset($r);
    }

    // ── Outcome → status nativo (por fonte) ───────────────────────────────────

    /** Uma fonte de outcome FIXO (versions='info', gmud='ok') só entra se o filtro casar. */
    private function outcomeAllows(?string $outcome, array $fixed): bool
    {
        return $outcome === null || in_array($outcome, $fixed, true);
    }

    private function actionStatusesForOutcome(string $o): array
    {
        return match ($o) {
            'ok' => ['ok'],
            'fail' => ['failed', 'denied'],
            'partial' => ['skipped'],
            'pending' => ['queued', 'running'],
            'info' => [],
            default => [],
        };
    }

    private function qualityStatusesForOutcome(string $o): array
    {
        return match ($o) {
            'ok' => ['completed'],
            'fail' => ['failed'],
            'pending' => ['queued', 'running'],
            default => [],
        };
    }

    private function coverageStatusesForOutcome(string $o): array
    {
        return match ($o) {
            'ok' => ['completed'],
            'fail' => ['failed'],
            'partial' => ['partial'],
            'pending' => ['pending', 'running'],
            'info' => ['rate_limited'],
            default => [],
        };
    }

    // ── Normalização / keyset ─────────────────────────────────────────────────

    private function norm(string $family, string $kind, int $nid, $occurred, array $native): array
    {
        $ms = $occurred ? Carbon::parse($occurred)->getTimestampMs() : -1;
        return ['family' => $family, 'kind' => $kind, 'nid' => $nid, 'ms' => $ms,
            't' => $occurred ? Carbon::parse($occurred)->toDateTimeString() : null, 'native' => $native];
    }

    private function iso($v): ?string
    {
        return $v ? Carbon::parse($v)->toIso8601String() : null;
    }

    /** Ordem global desc: occurred desc, familyRank asc, nativeId desc. */
    private function cmpDesc(array $a, array $b): int
    {
        if ($a['ms'] !== $b['ms']) {
            return $b['ms'] <=> $a['ms'];
        }
        $ra = self::FAMILY_RANK[$a['family']] ?? 9;
        $rb = self::FAMILY_RANK[$b['family']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return $b['nid'] <=> $a['nid'];
    }

    /** true se $r vem ESTRITAMENTE depois do cursor na ordenação desc (evita duplicar/pular). */
    private function afterCursor(array $r, array $cursor): bool
    {
        if ($r['ms'] !== $cursor['ms']) {
            return $r['ms'] < $cursor['ms'];
        }
        $rr = self::FAMILY_RANK[$r['family']] ?? 9;
        if ($rr !== $cursor['rank']) {
            return $rr > $cursor['rank'];
        }
        return $r['nid'] < $cursor['nid'];
    }

    private function encodeCursor(array $r): string
    {
        return base64_encode(json_encode([
            't' => $r['t'], 'ms' => $r['ms'],
            'rank' => self::FAMILY_RANK[$r['family']] ?? 9, 'nid' => $r['nid'],
        ]));
    }

    private function decodeCursor(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }
        $j = json_decode((string) base64_decode($raw, true), true);
        if (! is_array($j) || ! isset($j['t'], $j['ms'], $j['rank'], $j['nid'])) {
            return null;
        }
        return ['t' => (string) $j['t'], 'ms' => (int) $j['ms'], 'rank' => (int) $j['rank'], 'nid' => (int) $j['nid']];
    }
}
