<?php

namespace Tests\Feature;

use App\Connector\BaseSeed\LiveBaseSeeder;
use App\Connector\BaseSeed\SimulatedBaseSeeder;
use App\Connector\CompileService;
use App\Connector\PatchExecutionService;
use App\Connector\PatchService;
use App\Connector\WorkspaceLockService;
use App\Models\ArtifactCandidate;
use App\Models\CompileRequest;
use App\Models\ConnectorWorkspaceLock;
use App\Models\Customer;
use App\Models\EnvEnvironment;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchInput;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CP-PREPHYSICAL — resolução dos blockers de SOFTWARE. Prova (fixture/simulated, ZERO TOTVS): C6 usa o MESMO
 * connector_workspace_locks do Patch (mutex cross-producer), fencing do Compile, lease/old-owner/indeterminate,
 * Base Seed Contract fail-closed, capability física não comprovada → unavailable, readiness read-only, e que os
 * Live adapters permanecem indisponíveis mesmo com simulated passando. Zero RPO ativo alterado, zero C5 publish.
 */
class CompilePatchPrephysicalTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private int $envA;
    private User $actor;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.patch.executable_modes' => ['simulated', 'live'], 'connector.patch.allow_fixture' => false, 'connector.patch.live_ready' => false, 'connector.patch.transport_lease' => 120,
            'connector.compile.executable_modes' => ['simulated', 'live'], 'connector.compile.allow_fixture' => false, 'connector.compile.live_ready' => false, 'connector.compile.transport_lease' => 120, 'connector.compile.simulated_outcome' => 'succeeded',
            'connector.base_seed.executable_modes' => ['simulated'], 'connector.base_seed.live_ready' => false]);
        $this->custA = Customer::factory()->create();
        $this->envA = $this->makeEnv($this->custA);
        $this->actor = User::factory()->create(['type' => 'admin']);
    }

    private function envValue(string $k): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (str_starts_with($l, "{$k}=")) { return trim(substr($l, strlen($k) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c): int
    {
        $v = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $v->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId(['customer_id' => $c->id, 'vault_id' => $v->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function locks(): WorkspaceLockService { return app(WorkspaceLockService::class); }

    /** Adquire um lock direto pelo serviço compartilhado (simula um produtor segurando o workspace). */
    private function hold(string $producer, string $ws): ConnectorWorkspaceLock
    {
        $r = $this->locks()->acquire($this->envA, $ws, (string) Str::uuid(), $producer, 120);
        $this->assertTrue($r['ok'], "hold {$producer} falhou: " . json_encode($r));
        return $r['lock'];
    }

    private function patchDispatch(string $ws): array
    {
        $ids = [PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => 'P' . bin2hex(random_bytes(2)), 'digest' => hash('sha256', $ws . bin2hex(random_bytes(3))), 'created_by' => $this->actor->id])->id];
        $req = app(PatchService::class)->createRequest(EnvEnvironment::find($this->envA), ['base_rpo_hash' => hash('sha256', 'b' . $ws), 'execution_mode' => 'simulated', 'workspace_unit_id' => $ws, 'patch_input_ids' => $ids], $this->actor->id)['request'];
        return app(PatchExecutionService::class)->dispatch($req->fresh(), 'simulated', $this->actor->id);
    }

    private function compileReq(string $ws): CompileRequest
    {
        return CompileRequest::create([
            'customer_id' => $this->custA->id, 'environment_id' => $this->envA,
            'repository' => 'acme/erp', 'branch' => 'main', 'source_path' => 'src/x.prw',
            'source_blob_sha' => hash('sha256', 'blob' . $ws), 'language' => 'advpl',
            'workspace_unit_id' => $ws, 'execution_mode' => 'simulated', 'classification' => 'test',
            'status' => 'open', 'correlation_id' => (string) Str::uuid(), 'requested_by' => $this->actor->id, 'requested_at' => now(),
        ]);
    }

    private function compileExec(string $ws): array { return app(CompileService::class)->execute($this->compileReq($ws), $this->actor->id); }

    // ── 1. Patch segurando o workspace BLOQUEIA Compile (integração real do C6 ao lock). ──
    public function test_patch_lock_blocks_compile(): void
    {
        $this->patchDispatch('WS-1'); // dispatch deixa o lock ativo (execução CLAIMED)
        $res = $this->compileExec('WS-1');
        $this->assertFalse($res['ok']);
        $this->assertSame('workspace_busy', $res['error']);
    }

    // ── 2. Compile segurando o workspace BLOQUEIA Patch. ──
    public function test_compile_lock_blocks_patch(): void
    {
        $this->hold(ConnectorWorkspaceLock::PRODUCER_COMPILE, 'WS-2');
        $res = $this->patchDispatch('WS-2');
        $this->assertFalse($res['ok']);
        $this->assertSame('workspace_busy', $res['error']);
    }

    // ── 3. Compile/Compile no mesmo workspace conflita. ──
    public function test_compile_compile_conflict(): void
    {
        $this->hold(ConnectorWorkspaceLock::PRODUCER_COMPILE, 'WS-3');
        $res = $this->compileExec('WS-3');
        $this->assertFalse($res['ok']);
        $this->assertSame('workspace_busy', $res['error']);
    }

    // ── 4. Workspaces diferentes operam independentemente. ──
    public function test_different_workspaces_independent(): void
    {
        $this->hold(ConnectorWorkspaceLock::PRODUCER_COMPILE, 'WS-A');
        $this->assertTrue($this->patchDispatch('WS-B')['ok']);           // Patch em WS-B ok
        $c = $this->compileExec('WS-C');                                  // Compile em WS-C ok → candidate
        $this->assertTrue($c['ok']);
        $this->assertNotNull($c['candidate'] ?? null);
    }

    // ── 5. Fencing do Compile: fence antigo não é mais autoridade. ──
    public function test_compile_fencing_old_owner_rejected(): void
    {
        $l1 = $this->hold(ConnectorWorkspaceLock::PRODUCER_COMPILE, 'WS-F');
        ConnectorWorkspaceLock::whereKey($l1->id)->update(['lease_expires_at' => now()->subMinutes(5)]); // lease morta pré-barreira
        $l2 = $this->hold(ConnectorWorkspaceLock::PRODUCER_PATCH, 'WS-F');  // reap → novo fence
        $this->assertGreaterThan((int) $l1->fence_token, (int) $l2->fence_token);
        // fence antigo (l1) não valida; fence atual (l2) valida.
        $this->assertFalse($this->locks()->validateFence($this->envA, 'WS-F', $l1->execution_ref, (int) $l1->fence_token));
        $this->assertTrue($this->locks()->validateFence($this->envA, 'WS-F', $l2->execution_ref, (int) $l2->fence_token));
    }

    // ── 6. Indeterminate cross-producer: Compile mid-efeito (barrier) + crash CONGELA p/ Patch. ──
    public function test_indeterminate_freezes_cross_producer(): void
    {
        $l = $this->hold(ConnectorWorkspaceLock::PRODUCER_COMPILE, 'WS-IND');
        $this->locks()->markBarrier($l->id, 120);                          // efeito iniciado
        ConnectorWorkspaceLock::whereKey($l->id)->update(['lease_expires_at' => now()->subMinutes(5)]); // crash
        $res = $this->patchDispatch('WS-IND');
        $this->assertFalse($res['ok']);
        $this->assertSame('workspace_indeterminate', $res['error']);       // segura o workspace
        $this->assertTrue((bool) ConnectorWorkspaceLock::find($l->id)->reconcile_required);
    }

    // ── 7. Base Seed Contract fail-closed + prova de semântica (SIMULADO) + zero material sensível. ──
    public function test_base_seed_contract(): void
    {
        $approved = hash('sha256', 'base-approved');
        $live = app(LiveBaseSeeder::class);
        $this->assertFalse($live->availability($this->envA)['available']);           // unavailable
        $this->assertSame('unavailable', $live->prepareBase($this->envA, 'WS', $approved)['result']);
        $sim = app(SimulatedBaseSeeder::class);
        $ok = $sim->prepareBase($this->envA, 'WS', $approved, $approved);
        $this->assertSame('prepared', $ok['result']);                                // match → prepared
        $this->assertTrue($ok['simulated']);
        $bad = $sim->prepareBase($this->envA, 'WS', $approved, hash('sha256', 'other'));
        $this->assertSame('base_mismatch', $bad['result']);                          // mismatch → base_mismatch
        $this->assertSame('reseeded', $sim->reseedBase($this->envA, 'WS', $approved, $approved)['result']);
        // zero path/byte/INI na evidência
        $this->assertDoesNotMatchRegularExpression('#(/[A-Za-z0-9_.\-]+){3,}|[A-Za-z]:\\\\|SpecialKey#i', json_encode([$ok, $bad, $live->prepareBase($this->envA, 'WS', $approved)]));
    }

    // ── 8. Readiness read-model: physical_ready false; locks prontos em software; NÃO habilita live. ──
    public function test_physical_readiness_readonly(): void
    {
        $j = $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/environments/{$this->envA}/physical-readiness")->assertOk()->json('data');
        $this->assertTrue($j['compile_lock_ready']);
        $this->assertTrue($j['patch_lock_ready']);
        $this->assertFalse($j['base_seed_ready']);
        $this->assertFalse($j['compile_capability_ready']);
        $this->assertFalse($j['patch_capability_ready']);
        $this->assertFalse($j['live_ready']);
        $this->assertFalse($j['physical_ready']);                                    // SEMPRE false nesta fase
        $this->assertNotEmpty($j['blocking_reasons']);
        // NÃO habilitou live: compile/patch availability live seguem false.
        $this->assertFalse(app(CompileService::class)->adapterFor('live')->availability($this->compileReq('WS-R'))['available']);
    }

    // ── 9. Fail-closed: Live adapters indisponíveis mesmo com simulated passando. ──
    public function test_live_adapters_remain_unavailable(): void
    {
        $c = $this->compileExec('WS-SIMOK');
        $this->assertTrue($c['ok']);                                                 // simulated passa
        $this->assertFalse(app(CompileService::class)->adapterFor('live')->availability($this->compileReq('WS-L'))['available']);
        $pav = app(PatchService::class)->availability(EnvEnvironment::find($this->envA));
        $this->assertFalse($pav['live']['available']);                               // patch live indisponível
    }

    // ── 10. Zero RPO ativo alterado / zero C5 publish. ──
    public function test_zero_side_effects(): void
    {
        $before = RpoArtifact::count();
        $c = $this->compileExec('WS-Z');
        $this->assertTrue($c['ok']);
        $cand = ArtifactCandidate::where('compile_execution_id', $c['execution']->id)->first();
        $this->assertSame('none', $cand->handoff_status);                            // não registrado automaticamente
        $this->assertSame($before, RpoArtifact::count());                            // nenhum artefato C5
        $this->assertSame(0, DB::table('rpo_targets')->where('environment_id', $this->envA)->count());
        $this->assertSame(0, PatchArtifactCandidate::count());
    }
}
