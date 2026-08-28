<?php

namespace Tests\Feature;

use App\Connector\Compile\CompileAdapter;
use App\Models\ArtifactCandidate;
use App\Models\ClientSourceRepo;
use App\Models\CompileExecution;
use App\Models\CompileRequest;
use App\Models\ConnectorEvent;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * C6 — COMPILE. Produto completo em torno da interface (fixture/simulated/live). Compile PRODUZ artefato
 * candidato; NÃO publica RPO. Prova: state machine, isolamento customer/env, anti-IDOR, modos sem fallback,
 * live unavailable, failed sem artifact, succeeded com candidate, zero secrets/paths, auditoria C1, handoff
 * ao C5 (register, nunca promote), sem dedup por digest, at-most-once por execution_id.
 */
class ConnectorC6CompileTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private int $envA;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config([
            'cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.compile.executable_modes' => ['simulated', 'live'],
            'connector.compile.allow_fixture' => true,
            'connector.compile.live_ready' => false, // live indisponível (validação física pendente)
            'connector.compile.simulated_outcome' => 'succeeded',
            'connector.compile.supported_languages' => ['advpl', 'tlpp'],
        ]);
        $this->custA = Customer::factory()->create();
        ClientSourceRepo::create(['customer_id' => $this->custA->id, 'owner' => 'erpserv', 'repository' => 'cliente', 'branch' => 'main', 'base_path' => '', 'active' => true]);
        $this->envA = $this->makeEnv($this->custA);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c): int
    {
        $vault = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $vault->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId([
            'customer_id' => $c->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function userWith(array $perms, ?Customer $c = null): User
    {
        $c = $c ?: $this->custA;
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    private function body(array $o = []): array
    {
        return array_merge([
            'repository' => 'erpserv/cliente', 'branch' => 'main', 'source_path' => 'src/TESTE.prw',
            'source_blob_sha' => str_repeat('a', 64), 'source_commit_sha' => str_repeat('b', 64),
            'language' => 'advpl', 'target' => 'appserver', 'execution_mode' => 'simulated', 'classification' => 'test',
        ], $o);
    }

    private function create(User $u, array $o = [], ?int $env = null): TestResponse
    {
        $env = $env ?: $this->envA;
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/compile/requests", $this->body($o));
    }

    private function exec(User $u, int $id): TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/compile/requests/{$id}/execute");
    }

    // ── 1. simulated succeeded → candidate + eventos C1, marcado simulated, labels honestos. ──
    public function test_simulated_succeeded_produces_candidate_and_c1_events(): void
    {
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u)->assertStatus(201)->json('data.id');
        $res = $this->exec($u, $reqId)->assertStatus(200)->json('data');

        $this->assertSame('succeeded', $res['execution']['status']);
        $this->assertNotNull($res['candidate']);
        $this->assertSame('simulated', $res['execution']['execution_mode']);
        $this->assertTrue($res['candidate']['artifact_metadata']['simulated'] ?? false);
        $this->assertFalse($res['candidate']['is_known_good']);      // artefato ≠ known-good
        $this->assertFalse($res['candidate']['is_published']);       // compilado ≠ publicado
        $this->assertSame('none', $res['candidate']['handoff_status']);
        $this->assertSame(64, strlen($res['candidate']['artifact_digest']));

        $types = ConnectorEvent::where('environment_id', $this->envA)->pluck('event_type')->all();
        foreach (['compile.requested', 'compile.started', 'compile.succeeded', 'artifact.created'] as $t) {
            $this->assertContains($t, $types, "faltou evento C1 {$t}");
        }
        // succeeded → status da request completed
        $this->assertSame('completed', CompileRequest::find($reqId)->status);
    }

    // ── 2. simulated failed → SEM artifact. ──
    public function test_simulated_failed_produces_no_candidate(): void
    {
        config(['connector.compile.simulated_outcome' => 'failed']);
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u)->json('data.id');
        $res = $this->exec($u, $reqId)->assertStatus(200)->json('data');
        $this->assertSame('failed', $res['execution']['status']);
        $this->assertNull($res['candidate']);
        $this->assertSame(0, ArtifactCandidate::where('compile_request_id', $reqId)->count());
        $this->assertNotContains('artifact.created', ConnectorEvent::where('environment_id', $this->envA)->pluck('event_type')->all());
    }

    // ── 3. simulated timed_out → SEM artifact. ──
    public function test_simulated_timed_out_produces_no_candidate(): void
    {
        config(['connector.compile.simulated_outcome' => 'timed_out']);
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u)->json('data.id');
        $res = $this->exec($u, $reqId)->assertStatus(200)->json('data');
        $this->assertSame('timed_out', $res['execution']['status']);
        $this->assertNull($res['candidate']);
    }

    // ── 4. live unavailable → BLOQUEIO explícito, sem fallback, sem fake, sem artifact. ──
    public function test_live_unavailable_blocks_without_fallback(): void
    {
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u, ['execution_mode' => 'live'])->assertStatus(201)->json('data.id');
        $r = $this->exec($u, $reqId)->assertStatus(200);
        $r->assertJson(['blocked' => true, 'reason' => 'live_unavailable']);
        $this->assertStringContainsString('aguardando conector TOTVS', $r->json('message'));
        $this->assertSame('failed', $r->json('data.status'));
        $this->assertSame(0, ArtifactCandidate::where('compile_request_id', $reqId)->count());
        // NÃO caiu para simulated: o modo persistido continua live.
        $this->assertSame('live', CompileExecution::where('compile_request_id', $reqId)->first()->execution_mode);
    }

    // ── 5. fixture desabilitado → fail-closed na criação (nunca vira live/simulated por fallback). ──
    public function test_fixture_disabled_is_fail_closed(): void
    {
        config(['connector.compile.allow_fixture' => false]);
        $u = $this->userWith(['prosight.compile.request']);
        $this->create($u, ['execution_mode' => 'fixture'])->assertStatus(422)->assertJson(['error' => 'mode_not_executable']);
    }

    // ── 6. anti-IDOR na FONTE: repo não pertencente ao cliente → 404. ──
    public function test_source_not_authorized_is_404(): void
    {
        $u = $this->userWith(['prosight.compile.request']);
        $this->create($u, ['repository' => 'outrodono/repo'])->assertStatus(404)->assertJson(['error' => 'source_not_authorized']);
    }

    // ── 7. isolamento por cliente: userB (custB) NÃO vê request de custA. ──
    public function test_customer_isolation_blocks_cross_access(): void
    {
        $custB = Customer::factory()->create();
        $this->makeEnv($custB);
        $ua = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($ua)->json('data.id');
        $ub = $this->userWith(['prosight.compile.view'], $custB);
        $this->actingAs($ub, 'sanctum')->getJson("/api/v1/prosight/compile/requests/{$reqId}")->assertStatus(404);
    }

    // ── 8. permissão obrigatória: sem compile.request → 403. ──
    public function test_permission_required_to_create(): void
    {
        $u = $this->userWith(['prosight.compile.view']); // só view
        $this->create($u)->assertStatus(403);
    }

    // ── 9. handoff GOVERNADO → register no C5 (RpoArtifact) e NENHUM promote. ──
    public function test_handoff_registers_in_c5_and_never_promotes(): void
    {
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view', 'prosight.compile.handoff']);
        $reqId = $this->create($u)->json('data.id');
        $candId = $this->exec($u, $reqId)->json('data.candidate.id');
        $digest = ArtifactCandidate::find($candId)->artifact_digest;

        $opsBefore = ConnectorOperation::count();
        $r = $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/compile/candidates/{$candId}/handoff")->assertStatus(200);
        $rpoArtId = $r->json('data.rpo_artifact_id');

        $this->assertNotNull($rpoArtId);
        $this->assertSame('registered', ArtifactCandidate::find($candId)->handoff_status);
        $art = RpoArtifact::find($rpoArtId);
        $this->assertSame($digest, $art->hash);                 // digest do C6 = hash registrado no C5
        $this->assertSame('registered', $art->status);
        // NENHUMA operação de publicação criada pelo handoff (C6 não promove).
        $this->assertSame($opsBefore, ConnectorOperation::count());
        $this->assertContains('artifact.handoff_requested', ConnectorEvent::where('environment_id', $this->envA)->pluck('event_type')->all());
    }

    // ── 10. sem dedup por digest + at-most-once por execution_id: recompilar é permitido e gera nova execução. ──
    public function test_recompile_allowed_no_dedup_by_digest(): void
    {
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u)->json('data.id');
        $c1 = $this->exec($u, $reqId)->json('data.candidate.id');
        $c2 = $this->exec($u, $reqId)->json('data.candidate.id'); // recompila (compile ≠ publish)
        $this->assertNotSame($c1, $c2);
        $execIds = CompileExecution::where('compile_request_id', $reqId)->pluck('execution_id')->all();
        $this->assertCount(2, array_unique($execIds));           // execution_id imutável/único por execução
        // Mesmo digest em 2 candidatos distintos (NÃO deduplica por digest).
        $digests = ArtifactCandidate::whereIn('id', [$c1, $c2])->pluck('artifact_digest')->unique();
        $this->assertCount(1, $digests);
        $this->assertSame(2, ArtifactCandidate::where('compile_request_id', $reqId)->count());
    }

    // ── 11. sanitização defensiva: adapter que VAZA path/secret → nada disso cruza a fronteira. ──
    public function test_diagnostics_and_metadata_are_sanitized(): void
    {
        $this->app->bind(\App\Connector\Compile\SimulatedCompileAdapter::class, fn () => new class implements CompileAdapter {
            public function mode(): string { return 'simulated'; }
            public function availability($r): array { return ['available' => true, 'reason' => null]; }
            public function compile($req, $exec): array
            {
                return [
                    'outcome' => 'succeeded',
                    'artifact' => ['digest' => str_repeat('e', 64), 'unit' => 'standalone', 'metadata' => ['leak_path' => '/etc/appserver/appserver.ini', 'leak_token' => 'token=abcdef']],
                    'context' => ['factors' => ['include' => '/opt/protheus/includes/protheus.ch']],
                    'diagnostics' => ['msg' => 'build ok em /var/build/out password=hunter2'],
                    'error' => null,
                ];
            }
        });
        $u = $this->userWith(['prosight.compile.request', 'prosight.compile.view']);
        $reqId = $this->create($u)->json('data.id');
        $candId = $this->exec($u, $reqId)->json('data.candidate.id');

        $cand = ArtifactCandidate::find($candId);
        $exec = CompileExecution::where('compile_request_id', $reqId)->first();
        $blob = json_encode([$cand->artifact_metadata, $cand->provenance, $exec->diagnostics]);
        $this->assertStringNotContainsString('/etc/', $blob);
        $this->assertStringNotContainsString('/opt/protheus', $blob);
        $this->assertStringNotContainsString('hunter2', $blob);
        $this->assertStringNotContainsString('abcdef', $blob);
        $this->assertStringContainsString('[redacted]', $blob);
    }
}
