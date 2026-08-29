<?php

namespace Tests\Feature;

use App\Connector\PatchExecutionService;
use App\Connector\PatchService;
use App\Models\Customer;
use App\Models\EnvEnvironment;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchExecutionItem;
use App\Models\PatchInput;
use App\Models\PatchRequest;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * PATCH P3 — C5 HANDOFF + PRODUCT FLOW (fixture/simulated). Prova o boundary de produto:
 * candidate → handoff EXPLÍCITO → C5 REGISTERED → STOP. Idempotência, só-candidate-válido, proveniência C5
 * (producer=patch, digests, execution_id, simulated), anti-IDOR, candidate IMUTÁVEL, ZERO qualify/promote,
 * RPO ativo inalterado, navegação C5, labels honestos, e o GUARD de isolamento de testes (req 11).
 */
class PatchP3Test extends TestCase
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
            'connector.patch.executable_modes' => ['simulated', 'live'], 'connector.patch.allow_fixture' => false,
            'connector.patch.live_ready' => false, 'connector.patch.transport_lease' => 120]);
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

    private function svc(): PatchExecutionService { return app(PatchExecutionService::class); }

    private function mkRequest(string $ws, int $env, ?Customer $c = null): PatchRequest
    {
        $c = $c ?: $this->custA;
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = PatchInput::create(['environment_id' => $env, 'customer_id' => $c->id, 'patch_id' => "PTM-$i-" . bin2hex(random_bytes(2)),
                'digest' => hash('sha256', $ws . $i . bin2hex(random_bytes(3))), 'classification' => 'test', 'created_by' => $this->actor->id])->id;
        }
        return app(PatchService::class)->createRequest(EnvEnvironment::find($env), ['base_rpo_hash' => hash('sha256', 'base' . $ws),
            'execution_mode' => 'simulated', 'workspace_unit_id' => $ws, 'patch_input_ids' => $ids, 'classification' => 'test'], $this->actor->id)['request']->fresh();
    }

    /** Executa o fluxo P2 completo até candidate e devolve a PatchArtifactCandidate. */
    private function produceCandidate(string $ws, ?int $env = null, ?Customer $c = null, ?string $digest = null): PatchArtifactCandidate
    {
        $env = $env ?: $this->envA;
        $req = $this->mkRequest($ws, $env, $c);
        $ex = $this->svc()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svc();
        $s->ack($ex, 'base_verified', null);
        $s->ack($ex->fresh(), 'patch_effect_started', null);
        foreach ($ex->items as $it) {
            $s->ack($ex->fresh(), 'patch_item_started', $it->batch_order);
            $s->ack($ex->fresh(), 'patch_item_committed', $it->batch_order);
        }
        $s->ack($ex->fresh(), 'patch_effect_committed', null);
        $s->ack($ex->fresh(), 'artifact_verified', null);
        $s->result($ex->fresh(), 'success', $digest ?: hash('sha256', 'cand' . $ws), $this->actor->id);
        return PatchArtifactCandidate::where('patch_execution_id', $ex->id)->firstOrFail();
    }

    private function actingRegister(): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.patch.view', 'prosight.operations.patch.register']]);
        $p = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $p->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    // ── 1. Handoff explícito: candidate → C5 REGISTERED (registered, NÃO qualificado/publicado). ──
    public function test_handoff_registers_candidate_in_c5(): void
    {
        $before = RpoArtifact::count();
        $cand = $this->produceCandidate('P3-OK', null, null, hash('sha256', 'digest-ok'));
        $this->assertSame('none', $cand->handoff_status); // NÃO registra automaticamente
        $res = $this->svc()->handoff($cand, $this->actor->id);
        $this->assertTrue($res['ok']);
        $art = RpoArtifact::find($res['rpo_artifact_id']);
        $this->assertNotNull($art);
        $this->assertSame(hash('sha256', 'digest-ok'), $art->hash);   // hash = candidate_digest
        $this->assertSame('registered', $art->status);                // registered (não qualificado)
        $this->assertSame($before + 1, RpoArtifact::count());
        $cand->refresh();
        $this->assertSame('registered', $cand->handoff_status);
        $this->assertSame($art->id, $cand->rpo_artifact_id);          // vínculo inequívoco
    }

    // ── 2. Idempotência: repetir handoff NÃO cria novo artefato; 409 com o mesmo rpo_artifact_id. ──
    public function test_handoff_idempotent(): void
    {
        $cand = $this->produceCandidate('P3-IDEM');
        $r1 = $this->svc()->handoff($cand, $this->actor->id);
        $this->assertTrue($r1['ok']);
        $count = RpoArtifact::count();
        $r2 = $this->svc()->handoff($cand->fresh(), $this->actor->id);
        $this->assertFalse($r2['ok']);
        $this->assertSame('already_registered', $r2['error']);
        $this->assertSame($r1['rpo_artifact_id'], $r2['rpo_artifact_id']);
        $this->assertSame($count, RpoArtifact::count());              // nenhum artefato/revisão extra
    }

    // ── 3. Só candidate TERMINAL válido: failed/partial/indeterminate NÃO produzem candidate → nada a registrar. ──
    public function test_forbidden_statuses_have_no_candidate(): void
    {
        foreach (['failed', 'partial', 'indeterminate'] as $bad) {
            $req = $this->mkRequest("P3-BAD-$bad", $this->envA);
            $ex = $this->svc()->dispatch($req, 'simulated', $this->actor->id)['execution'];
            if ($bad === 'failed') {
                $this->svc()->result($ex, 'failed', null, $this->actor->id);
            } elseif ($bad === 'partial') {
                $s = $this->svc();
                $s->ack($ex, 'base_verified', null); $s->ack($ex->fresh(), 'patch_effect_started', null);
                $s->ack($ex->fresh(), 'patch_item_started', 1); $s->ack($ex->fresh(), 'patch_item_committed', 1);
                $s->result($ex->fresh(), 'partial', null, $this->actor->id);
            } else {
                $s = $this->svc();
                $s->ack($ex, 'base_verified', null); $s->ack($ex->fresh(), 'patch_effect_started', null);
                $s->reconcile($ex->fresh(), null, $this->actor->id); // indeterminate
            }
            $this->assertSame(0, PatchArtifactCandidate::where('patch_execution_id', $ex->id)->count(), "sem candidate p/ $bad");
        }
        $this->assertSame(0, RpoArtifact::whereNotNull('id')->where('status', 'registered')->count()); // nada registrado
    }

    // ── 4. Proveniência C5: producer=patch + digests + execution_id + simulated. ──
    public function test_c5_provenance_producer_patch(): void
    {
        $cand = $this->produceCandidate('P3-PROV');
        $art = RpoArtifact::find($this->svc()->handoff($cand, $this->actor->id)['rpo_artifact_id']);
        $this->assertStringContainsString('producer=patch', $art->provenance);
        $this->assertStringContainsString('simulated=1', $art->provenance);
        $this->assertStringContainsString(substr($cand->provenance['execution_id'], 0, 12), $art->provenance);
        $this->assertSame('patch', $art->compatibility['producer']);
        $this->assertTrue($art->compatibility['simulated']);
        // ZERO path/bytes/PTM na proveniência.
        $this->assertDoesNotMatchRegularExpression('#(/[A-Za-z0-9_.\-]+){2,}|[A-Za-z]:\\\\#', (string) $art->provenance);
    }

    // ── 5. Anti-IDOR: outro cliente/ambiente não vê nem registra o candidate. ──
    public function test_anti_idor_cross_customer_and_environment(): void
    {
        $cand = $this->produceCandidate('P3-IDOR');
        $custB = Customer::factory()->create();
        $intruder = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.patch.view', 'prosight.operations.patch.register']]);
        $pb = Project::factory()->create(['customer_id' => $custB->id]);
        DB::table('project_consultants')->insert(['project_id' => $pb->id, 'user_id' => $intruder->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($intruder, 'sanctum')->getJson("/api/v1/prosight/patch/candidates/{$cand->id}")->assertStatus(404);
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/prosight/patch/candidates/{$cand->id}/handoff")->assertStatus(404);
        $this->assertSame('none', $cand->fresh()->handoff_status); // não registrado pelo intruso
    }

    // ── 6. Candidate IMUTÁVEL: handoff não altera digest/proveniência/base/batch. ──
    public function test_candidate_immutable_after_handoff(): void
    {
        $cand = $this->produceCandidate('P3-IMMU');
        $snap = $cand->only(['candidate_digest', 'base_rpo_digest', 'batch_digest', 'provenance', 'patch_execution_id']);
        $this->svc()->handoff($cand, $this->actor->id);
        $cand->refresh();
        $this->assertSame($snap['candidate_digest'], $cand->candidate_digest);
        $this->assertSame($snap['base_rpo_digest'], $cand->base_rpo_digest);
        $this->assertSame($snap['batch_digest'], $cand->batch_digest);
        $this->assertSame($snap['provenance'], $cand->provenance);       // proveniência congelada
    }

    // ── 7. Boundary: registered NÃO é qualificado/promovido; nenhum rpo_target; RPO ativo inalterado. ──
    public function test_boundary_no_qualify_no_promote_no_target(): void
    {
        $targetsBefore = DB::table('rpo_targets')->where('environment_id', $this->envA)->count();
        $cand = $this->produceCandidate('P3-BOUND');
        $art = RpoArtifact::find($this->svc()->handoff($cand, $this->actor->id)['rpo_artifact_id']);
        $this->assertSame('registered', $art->status);                   // registered, NUNCA known_good/qualified/published
        $this->assertSame(0, DB::table('rpo_qualifications')->where('rpo_artifact_id', $art->id)->count());
        $this->assertSame($targetsBefore, DB::table('rpo_targets')->where('environment_id', $this->envA)->count()); // sem target novo
    }

    // ── 8. HTTP: jornada + labels honestos + navegação C5 + permissão patch.register. ──
    public function test_http_journey_labels_and_nav(): void
    {
        $cand = $this->produceCandidate('P3-HTTP');
        // candidato ainda não registrado
        $before = $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/patch/candidates/{$cand->id}")->assertOk()->json('data.candidate');
        $this->assertTrue($before['is_simulated']);
        $this->assertFalse($before['is_registered']);
        $this->assertStringContainsString('ainda não registrado', $before['label']);
        $this->assertNull($before['c5_artifact_nav']);
        // usuário só com execute (sem register) → 403
        $execOnly = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.patch.execute']]);
        $pe = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $pe->id, 'user_id' => $execOnly->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($execOnly, 'sanctum')->postJson("/api/v1/prosight/patch/candidates/{$cand->id}/handoff")->assertStatus(403);
        // handoff explícito (patch.register)
        $after = $this->actingAs($this->actingRegister(), 'sanctum')->postJson("/api/v1/prosight/patch/candidates/{$cand->id}/handoff")->assertOk()->json('data.candidate');
        $this->assertTrue($after['is_registered']);
        $this->assertFalse($after['is_qualified']);
        $this->assertFalse($after['is_published']);
        $this->assertStringContainsString('Registrado no C5', $after['label']);
        $this->assertStringContainsString('ainda não qualificado', $after['label']);
        $this->assertStringNotContainsString('Publicado', $after['label']);
        $this->assertStringNotContainsString('Aplicado', $after['label']);
        // navegação para o artefato C5 resolve (não executa operação)
        $this->assertSame("/prosight/rpo/artifacts/{$after['rpo_artifact_id']}", $after['c5_artifact_nav']);
        $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/rpo/artifacts/{$after['rpo_artifact_id']}")->assertOk();
    }

    // ── 9. GUARD de isolamento de testes (req 11): reset destrutivo em banco NÃO descartável é BLOQUEADO. ──
    public function test_destructive_db_reset_guard(): void
    {
        $this->assertSame('minutor_c1test', config('database.connections.pgsql.database'));
        $this->assertNotContains('minutor_c1test', (array) config('database.disposable_test_databases')); // fixture NÃO é descartável
        // Disparar o evento do comando destrutivo → guard lança (sem executar nada).
        $threw = false;
        try {
            Event::dispatch(new \Illuminate\Console\Events\CommandStarting('migrate:fresh', new ArrayInput([]), new NullOutput()));
        } catch (\RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'BLOQUEADO');
        }
        $this->assertTrue($threw, 'guard deve bloquear migrate:fresh contra minutor_c1test');
        // Banco intacto (nada foi resetado).
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('customers'));
    }
}
