<?php

namespace Tests\Feature;

use App\Models\ClientSourceRepo;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C1 — Atividade & Auditoria real (read-model /source-docs/activity).
 * Prova: escopo por cliente (deny-by-default), 7 fontes, denied distinguível, ator nulo,
 * campanha global só no contexto "Todas"+interno, filtros, keyset sem dup/skip, anti-IDOR,
 * operacoes sempre pendente (nunca fixture).
 */
class SourceDocActivityTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false]);
        $this->custA = Customer::factory()->create();
        $this->custB = Customer::factory()->create();
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    // ── helpers de dados ──────────────────────────────────────────────────────

    private function doc(Customer $c, string $repo = 'r', string $file = 'A.PRW'): int
    {
        return DB::table('source_docs')->insertGetId([
            'customer_id' => $c->id, 'owner' => 'o', 'repository' => $repo, 'branch' => 'main',
            'path' => "src/{$c->id}/$file", 'filename' => $file, 'tipo' => 'protheus', 'lang' => 'advpl',
            'analysis_status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function version(int $docId, string $sha, ?string $blob, string $when, ?int $gmud = null, ?string $ticket = null, ?int $respUser = null): int
    {
        return DB::table('source_doc_versions')->insertGetId([
            'source_doc_id' => $docId, 'source_commit_sha' => $sha, 'source_blob_sha' => $blob,
            'gmud_id' => $gmud, 'ticket_number' => $ticket, 'responsible_user_id' => $respUser, 'responsavel' => $respUser ? 'Fulano' : null,
            'diff_summary' => '+1 -0', 'analysis_status' => 'completed', 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function action(int $docId, string $action, string $status, ?int $actor, string $when): int
    {
        return DB::table('source_doc_action_log')->insertGetId([
            'source_doc_id' => $docId, 'action' => $action, 'layer' => 'semantic', 'actor_user_id' => $actor,
            'status' => $status, 'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function quality(int $docId, ?string $blob, string $status, string $when, ?int $reqBy = null): int
    {
        return DB::table('source_doc_quality_analyses')->insertGetId([
            'source_doc_id' => $docId, 'source_blob_sha' => $blob, 'status' => $status, 'score' => 80, 'grade' => 'B', 'risk' => 'baixo',
            'requested_by' => $reqBy, 'requested_at' => $when, 'completed_at' => $status === 'completed' ? $when : null,
            'created_at' => $when, 'updated_at' => $when,
        ]);
    }

    private function coverage(Customer $c, string $when, string $status = 'completed'): int
    {
        $repo = ClientSourceRepo::create([
            'customer_id' => $c->id, 'owner' => 'o', 'repository' => 'cov' . uniqid(), 'branch' => 'main',
            'base_path' => '', 'tipo' => 'protheus', 'active' => true,
        ]);
        DB::table('source_repo_coverage')->insert([
            'source_repo_id' => $repo->id, 'customer_id' => $c->id, 'owner' => 'o', 'repository' => $repo->repository, 'branch' => 'main',
            'scan_status' => $status, 'scan_finished_at' => $when, 'last_synced_at' => $when,
            'github_files' => 10, 'eligible_source_files' => 8, 'cataloged' => 8, 'deterministic' => 8, 'semantic' => 4, 'indexed' => 8, 'changed_files' => 1,
            'created_at' => $when, 'updated_at' => $when,
        ]);
        return $repo->id;
    }

    private function campaignEvent(string $event, ?int $actor, string $when): int
    {
        $cid = DB::table('source_semantic_campaign')->insertGetId([
            'name' => 'Baseline', 'status' => 'running', 'created_at' => $when, 'updated_at' => $when,
        ]);
        return DB::table('source_semantic_campaign_events')->insertGetId([
            'campaign_id' => $cid, 'actor_user_id' => $actor, 'event' => $event, 'created_at' => $when,
        ]);
    }

    private function internal(array $perms, ?Customer $link = null): User
    {
        $u = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => $perms]);
        if ($link) {
            $proj = Project::factory()->create(['customer_id' => $link->id]);
            DB::table('project_coordinators')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $u;
    }

    private function hit(User $u, string $qs = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->getJson('/api/v1/source-docs/activity' . ($qs ? "?$qs" : ''));
    }

    private function kinds(array $items): array
    {
        return array_map(fn ($i) => $i['kind'], $items);
    }

    // ── testes ────────────────────────────────────────────────────────────────

    public function test_scope_isolation_between_customers(): void
    {
        $dA = $this->doc($this->custA); $this->version($dA, 'sha_a', 'blob_a', '2026-08-20T10:00:00');
        $dB = $this->doc($this->custB); $this->version($dB, 'sha_b', 'blob_b', '2026-08-20T11:00:00');

        // Admin (global) vê ambos.
        $admin = User::factory()->create(['type' => 'admin']);
        $all = $this->hit($admin)->assertOk()->json('data.items');
        $this->assertGreaterThanOrEqual(2, count($all));

        // Coordenador de A só vê A (deny-by-default) — nenhum evento de B.
        $coordA = $this->internal(['source_docs.view'], $this->custA);
        $items = $this->hit($coordA)->assertOk()->json('data.items');
        $this->assertNotEmpty($items);
        foreach ($items as $i) {
            $this->assertSame($this->custA->id, $i['native']['customer_id'] ?? $this->custA->id);
        }
        $shas = array_map(fn ($i) => $i['native']['source_commit_sha'] ?? null, $items);
        $this->assertContains('sha_a', $shas);
        $this->assertNotContains('sha_b', $shas);
    }

    public function test_external_customer_sees_only_own(): void
    {
        $dA = $this->doc($this->custA); $this->version($dA, 'sha_a', 'blob_a', '2026-08-20T10:00:00');
        $dB = $this->doc($this->custB); $this->version($dB, 'sha_b', 'blob_b', '2026-08-20T11:00:00');

        // Cliente externo com acesso ao portal de fontes (source_docs.view) — escopo força "só o próprio".
        $ext = User::factory()->create(['type' => 'cliente', 'customer_id' => $this->custA->id, 'extra_permissions' => ['source_docs.view']]);
        $items = $this->hit($ext, 'family=fontes')->assertOk()->json('data.items');
        $shas = array_map(fn ($i) => $i['native']['source_commit_sha'] ?? null, $items);
        $this->assertContains('sha_a', $shas);
        $this->assertNotContains('sha_b', $shas);
    }

    public function test_anti_idor_customer_param_out_of_scope(): void
    {
        $coordA = $this->internal(['source_docs.view'], $this->custA);
        $this->hit($coordA, "customer_id={$this->custB->id}")->assertStatus(403);
        $this->hit($coordA, "customer_id={$this->custA->id}")->assertOk();
    }

    public function test_denied_action_is_distinguishable_from_technical_failure(): void
    {
        $d = $this->doc($this->custA);
        $this->action($d, 'reprocess', 'denied', null, '2026-08-20T10:00:00');
        $this->action($d, 'reprocess', 'failed', null, '2026-08-20T09:00:00');

        $coordA = $this->internal(['source_docs.view'], $this->custA);
        $items = $this->hit($coordA, 'family=fontes')->assertOk()->json('data.items');
        $actions = array_values(array_filter($items, fn ($i) => $i['kind'] === 'source-action'));
        $this->assertCount(2, $actions);
        $denied = array_values(array_filter($actions, fn ($i) => $i['native']['status'] === 'denied'));
        $failed = array_values(array_filter($actions, fn ($i) => $i['native']['status'] === 'failed'));
        $this->assertTrue($denied[0]['native']['denied']);
        $this->assertFalse($failed[0]['native']['denied']);
    }

    public function test_quality_actor_is_null_never_inferred(): void
    {
        $d = $this->doc($this->custA);
        $this->quality($d, 'blob_q', 'completed', '2026-08-20T10:00:00'); // requested_by null

        $coordA = $this->internal(['source_docs.view', 'source_docs.quality.view'], $this->custA);
        $items = $this->hit($coordA, 'family=qualidade')->assertOk()->json('data.items');
        $q = array_values(array_filter($items, fn ($i) => $i['kind'] === 'quality'));
        $this->assertNotEmpty($q);
        // A qualidade nunca carrega ator (CodeAnalysis não expõe) — o shape nativo sequer tem campo de ator.
        $this->assertArrayNotHasKey('actor_user_id', $q[0]['native']);
        $this->assertArrayNotHasKey('actor_name', $q[0]['native']);
    }

    public function test_campaign_only_in_todas_context_for_global_internal(): void
    {
        $this->campaignEvent('started', null, '2026-08-20T10:00:00');
        $admin = User::factory()->create(['type' => 'admin']);

        // "Todas" + admin (global) → campanha aparece.
        $todas = $this->hit($admin, 'family=qualidade')->assertOk()->json('data.items');
        $this->assertContains('campaign', $this->kinds($todas));

        // Contexto de empresa específica → campanha global NÃO aparece.
        $specific = $this->hit($admin, "family=qualidade&customer_id={$this->custA->id}")->assertOk()->json('data.items');
        $this->assertNotContains('campaign', $this->kinds($specific));

        // Coordenador não-global nunca vê campanha, mesmo em "Todas".
        $coordA = $this->internal(['source_docs.view', 'source_docs.quality.view', 'source_docs.semantic_campaign'], $this->custA);
        $coordItems = $this->hit($coordA, 'family=qualidade')->assertOk()->json('data.items');
        $this->assertNotContains('campaign', $this->kinds($coordItems));
    }

    public function test_cost_facet_gated_by_permission(): void
    {
        $d = $this->doc($this->custA);
        $this->action($d, 'cost_approval_approve_step', 'ok', null, '2026-08-20T10:00:00');

        // Sem cost_approval.view → não vê a ação de governança.
        $noCost = $this->internal(['source_docs.view'], $this->custA);
        $items = $this->hit($noCost, 'family=fontes')->assertOk()->json('data.items');
        $this->assertEmpty(array_filter($items, fn ($i) => str_starts_with($i['native']['action'] ?? '', 'cost_approval_')));

        // Com cost_approval.view → vê.
        $withCost = $this->internal(['source_docs.view', 'source_docs.cost_approval.view'], $this->custA);
        $items2 = $this->hit($withCost, 'family=fontes')->assertOk()->json('data.items');
        $this->assertNotEmpty(array_filter($items2, fn ($i) => str_starts_with($i['native']['action'] ?? '', 'cost_approval_')));
    }

    public function test_family_and_outcome_filters(): void
    {
        $d = $this->doc($this->custA);
        $this->version($d, 'sha_v', 'blob_v', '2026-08-20T10:00:00');
        $this->action($d, 'validate', 'ok', null, '2026-08-20T10:05:00');
        $this->action($d, 'reprocess', 'failed', null, '2026-08-20T10:06:00');

        $coordA = $this->internal(['source_docs.view'], $this->custA);

        // family=fontes traz versão + ações; publicacoes vazio (sem gmud).
        $pub = $this->hit($coordA, 'family=publicacoes')->assertOk()->json('data.items');
        $this->assertEmpty($pub);

        // outcome=fail → só a ação failed.
        $fail = $this->hit($coordA, 'family=fontes&outcome=fail')->assertOk()->json('data.items');
        $this->assertCount(1, $fail);
        $this->assertSame('failed', $fail[0]['native']['status']);
    }

    public function test_keyset_pagination_same_timestamp_no_dup_no_skip(): void
    {
        $d = $this->doc($this->custA);
        $ts = '2026-08-20T10:00:00';
        // 3 eventos de famílias diferentes no MESMO timestamp.
        $this->version($d, 'sha_same', 'blob_same', $ts);
        $this->action($d, 'validate', 'ok', null, $ts);
        $this->quality($d, 'blob_same', 'completed', $ts);

        $coordA = $this->internal(['source_docs.view', 'source_docs.quality.view'], $this->custA);

        $p1 = $this->hit($coordA, 'limit=2')->assertOk()->json('data');
        $this->assertCount(2, $p1['items']);
        $this->assertNotNull($p1['next_cursor']);

        $p2 = $this->hit($coordA, 'limit=2&cursor=' . urlencode($p1['next_cursor']))->assertOk()->json('data');
        $this->assertCount(1, $p2['items']);

        // União = 3 ids distintos por (kind:nativeId), sem sobreposição.
        $key = fn ($i) => $i['kind'] . ':' . ($i['native']['id'] ?? $i['native']['source_repo_id'] ?? '');
        $k1 = array_map($key, $p1['items']);
        $k2 = array_map($key, $p2['items']);
        $this->assertEmpty(array_intersect($k1, $k2), 'páginas não podem repetir evento');
        $this->assertCount(3, array_unique(array_merge($k1, $k2)), 'não pode pular evento');
    }

    public function test_operacoes_always_pending_never_present(): void
    {
        $d = $this->doc($this->custA);
        $this->version($d, 'sha_v', 'blob_v', '2026-08-20T10:00:00');
        $admin = User::factory()->create(['type' => 'admin']);
        $data = $this->hit($admin)->assertOk()->json('data');
        $this->assertSame(['operacoes'], $data['pending_families']);
        foreach ($data['items'] as $i) {
            $this->assertNotSame('operacoes', $i['family']);
        }
        $this->assertSame('live', $data['mode']);
    }
}
