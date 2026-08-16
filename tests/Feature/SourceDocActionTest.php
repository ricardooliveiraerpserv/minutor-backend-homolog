<?php

namespace Tests\Feature;

use App\Jobs\ReprocessSourceDocJob;
use App\Models\Customer;
use App\Models\SourceDoc;
use App\Models\SourceDocActionLog;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\GithubAppAuth;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Central de Fontes — C3. Ações operacionais: permissão por ação, auditoria sanitizada,
 * idempotência/anti-concorrência, mesmo-blob-no-op, gate semântico+custo, imutabilidade, diff/compare.
 */
class SourceDocActionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'cache.default' => 'array',
            'services.source_doc_ai.enabled' => true,
            'services.source_doc_ai.environment' => 'homolog',
            'services.source_doc_ai.allowed_environments' => ['homolog'],
            'services.source_doc_ai.hard_limit_usd' => 0.30,
        ]);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function admin(): User       { return User::factory()->create(['type' => 'admin']); }
    private function coordenador(): User { return User::factory()->create(['type' => 'coordenador']); }
    private function consultor(): User   { return User::factory()->create(['type' => 'consultor']); }

    /** Fake do GitHub: blob atual controlável (p/ testar same-blob × blob mudou). */
    private function fakeAuth(string $currentBlob): GithubAppAuth
    {
        return new class($currentBlob) extends GithubAppAuth {
            public function __construct(private string $blob) { parent::__construct(); }
            public function getFileWithSha(string $o, string $r, string $ref, string $p): ?array
            { return ['content' => 'code', 'blob_sha' => $this->blob]; }
            public function treeBlobShas(string $o, string $r, string $ref): array
            { return []; } // resolver → NAO_VALIDADO/ATUALIZADA conforme; não precisamos aqui
            public function getBranchHeadSha(string $o, string $r, string $b): ?string { return 'headsha'; }
            public function getFileContent(string $o, string $r, string $ref, string $p): ?string { return 'old'; }
        };
    }

    private function makeDoc(string $status = 'completed', string $blob = 'blobA', array $det = null): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'CCSPCP03.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => $status,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => $blob,
            'analysis_status' => $status,
            'deterministic_json' => $det ?? ['functions' => [['name' => 'F1'], ['name' => 'F2']], 'tables' => []],
            'documentation_json' => ['identity' => ['filename' => 'CCSPCP03.PRW'], 'semantic' => ['objetivo' => 'x'], 'deterministic' => ['functions' => []]],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id, 'documentation_json' => $ver->documentation_json])->save();
        return $doc->refresh();
    }

    // ── permissões (uma por ação) ───────────────────────────────────────────────

    public function test_permissions_per_action(): void
    {
        $doc = $this->makeDoc();
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));

        // validate: Coordenador OK, Consultor 403
        $this->actingAs($this->coordenador(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/validate")->assertOk();
        $this->actingAs($this->consultor(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/validate")->assertForbidden();
        // download: Coordenador OK
        $this->actingAs($this->coordenador(), 'sanctum')->get("/api/v1/source-docs/{$doc->id}/render?format=md")->assertOk();
        // reprocess: SÓ Admin — Coordenador 403
        $this->actingAs($this->coordenador(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/reprocess/plan")->assertForbidden();
        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/reprocess/plan")->assertOk();
        // view_git / compare: Coordenador OK
        $this->actingAs($this->coordenador(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/git-url")->assertOk();
    }

    // ── casos obrigatórios ──────────────────────────────────────────────────────

    /** (1)(2) clique duplo / simultâneas → 2ª 409 (idempotência inflight). */
    public function test_double_click_and_concurrency_blocked(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('partial', 'blobA'); // mutável → enfileira
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(409);
        Queue::assertPushed(ReprocessSourceDocJob::class, 1);
    }

    /** (3) completed + mesmo blob, sem force → reuse (no-op, sem job, sem versão). */
    public function test_same_blob_completed_no_force_is_reuse(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('completed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $r = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'both']);
        $r->assertOk()->assertJsonPath('data.action', 'reuse');
        Queue::assertNothingPushed();
    }

    /** (4) completed + mesmo blob, com force → 409 imutável. */
    public function test_same_blob_completed_force_is_immutable_noop(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('completed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic', 'force' => true])->assertStatus(409);
        Queue::assertNothingPushed();
    }

    /** (5) blob diferente → nova versão (enfileira). */
    public function test_different_blob_creates_new_version(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('completed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobDIFERENTE'));
        $r = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic']);
        $r->assertStatus(202)->assertJsonPath('data.plan.will_create_version', true);
        Queue::assertPushed(ReprocessSourceDocJob::class, 1);
    }

    /** (6)(7) partial e failed reprocessam a própria versão. */
    public function test_partial_and_failed_reprocess_in_place(): void
    {
        Queue::fake();
        foreach (['partial', 'failed'] as $st) {
            $doc = $this->makeDoc($st, 'blobA');
            $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
            $r = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic']);
            $r->assertStatus(202)->assertJsonPath('data.plan.action', 'reprocess_in_place');
        }
    }

    /** (8) hard limit → 422 sem chamar Anthropic (nada enfileirado). */
    public function test_hard_limit_blocks_without_calling_ai(): void
    {
        Queue::fake();
        config(['services.source_doc_ai.hard_limit_usd' => 0.0001]); // qualquer estimativa excede
        $doc = $this->makeDoc('failed', 'blobA'); // mutável + semantic
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'semantic'])->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /** (9) reprocess semântico sem permissão → 403. */
    public function test_semantic_reprocess_without_permission(): void
    {
        $doc = $this->makeDoc('failed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->coordenador(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'semantic'])->assertForbidden();
    }

    /** (10) reprocess semântico fora do ambiente permitido → 403. */
    public function test_semantic_reprocess_outside_allowed_env(): void
    {
        Queue::fake();
        config(['services.source_doc_ai.enabled' => false]); // ambiente não permite IA
        $doc = $this->makeDoc('failed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'semantic'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    /** (11) auditoria sem dado sensível. */
    public function test_audit_has_no_sensitive_data(): void
    {
        $doc = $this->makeDoc('completed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/validate")->assertOk();
        $log = SourceDocActionLog::where('source_doc_id', $doc->id)->where('action', 'validate')->first();
        $this->assertNotNull($log);
        $this->assertNotNull($log->actor_user_id);
        // params só tem chaves permitidas; nada de código/prompt/token
        foreach (array_keys($log->params ?? []) as $k) {
            $this->assertContains($k, ['blob_sha', 'commit_sha', 'situation', 'analysis_status', 'format', 'from_version', 'to_version', 'force', 'reused', 'new_version_id', 'estimated_cost_usd', 'hard_limit_usd', 'functions_count', 'bytes', 'ai_enabled', 'environment']);
        }
    }

    /** (12) lock liberado após falha → nova execução equivalente é aceita. */
    public function test_inflight_cleared_after_failure_allows_new_run(): void
    {
        Queue::fake();
        $doc = $this->makeDoc('partial', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
        // simula falha da execução (status sai de queued/running → índice inflight libera)
        SourceDocActionLog::where('source_doc_id', $doc->id)->where('action', 'reprocess')->update(['status' => 'failed']);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/source-docs/{$doc->id}/reprocess", ['layer' => 'deterministic'])->assertStatus(202);
    }

    /** (13) comparação A×B (determinística). */
    public function test_compare_two_versions(): void
    {
        $doc = $this->makeDoc('completed', 'blobA');
        $vA = $doc->current_version_id;
        $vB = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'cB', 'source_blob_sha' => 'blobB',
            'analysis_status' => 'completed', 'deterministic_json' => ['functions' => [['name' => 'F1'], ['name' => 'F3']], 'tables' => []],
        ])->id;
        $r = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/source-docs/{$doc->id}/compare?from={$vA}&to={$vB}");
        $r->assertOk()->assertJsonPath('data.from.id', $vA)->assertJsonPath('data.to.id', $vB);
        $this->assertArrayHasKey('diff', $r->json('data'));
    }

    /** (14) download (docx) grande retorna binário. */
    public function test_download_returns_file(): void
    {
        $doc = $this->makeDoc('completed', 'blobA');
        $this->app->instance(GithubAppAuth::class, $this->fakeAuth('blobA'));
        $r = $this->actingAs($this->admin(), 'sanctum')->get("/api/v1/source-docs/{$doc->id}/render?format=docx");
        $r->assertOk();
        $this->assertStringContainsString('attachment', $r->headers->get('content-disposition'));
        $this->assertGreaterThan(0, strlen($r->getContent()));
    }
}
