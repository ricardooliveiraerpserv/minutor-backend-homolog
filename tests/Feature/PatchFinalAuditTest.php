<?php

namespace Tests\Feature;

use App\Connector\PatchExecutionService;
use App\Connector\PatchService;
use App\Models\Customer;
use App\Models\EnvEnvironment;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchInput;
use App\Models\PatchRequest;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PATCH-FINAL — auditoria de congelamento do produto Patch (P1-P3). SEM funcionalidade nova. Prova as
 * propriedades de closure que não estavam explicitamente cobertas: DURABILIDADE da proveniência (resolver a
 * cadeia Patch a partir SÓ de um rpo_artifact), ausência de cascade que quebre a cadeia, honest-mode sem
 * fallback live→simulated, security-scan das respostas (zero path/secret/PTM/RPO bytes/cred), C5 boundary.
 */
class PatchFinalAuditTest extends TestCase
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

    /** Produz um candidate registrado no C5 e devolve [candidate, rpo_artifact_id]. */
    private function registeredArtifact(string $ws): array
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => "PTM-$i-" . bin2hex(random_bytes(2)),
                'digest' => hash('sha256', $ws . $i . bin2hex(random_bytes(3))), 'version' => '12.1.33', 'classification' => 'test', 'created_by' => $this->actor->id])->id;
        }
        $req = app(PatchService::class)->createRequest(EnvEnvironment::find($this->envA), ['base_rpo_hash' => hash('sha256', 'base' . $ws),
            'execution_mode' => 'simulated', 'workspace_unit_id' => $ws, 'patch_input_ids' => $ids, 'classification' => 'test'], $this->actor->id)['request']->fresh();
        $ex = $this->svc()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $s = $this->svc();
        $s->ack($ex, 'base_verified', null); $s->ack($ex->fresh(), 'patch_effect_started', null);
        foreach ($ex->items as $it) { $s->ack($ex->fresh(), 'patch_item_started', $it->batch_order); $s->ack($ex->fresh(), 'patch_item_committed', $it->batch_order); }
        $s->ack($ex->fresh(), 'patch_effect_committed', null); $s->ack($ex->fresh(), 'artifact_verified', null);
        $s->result($ex->fresh(), 'success', hash('sha256', 'cand' . $ws), $this->actor->id);
        $cand = PatchArtifactCandidate::where('patch_execution_id', $ex->id)->firstOrFail();
        $artId = $this->svc()->handoff($cand, $this->actor->id)['rpo_artifact_id'];
        return [$cand->fresh(), $artId];
    }

    // ── (item 4) DURABILIDADE: da rpo_artifact SÓ, resolver a cadeia imutável completa. ──
    public function test_provenance_durability_from_artifact_alone(): void
    {
        [$cand, $artId] = $this->registeredArtifact('FINAL-PROV');
        $art = RpoArtifact::find($artId);
        // O próprio artefato C5 se identifica como producer=patch.
        $this->assertStringContainsString('producer=patch', $art->provenance);
        // Referência durável artefato → candidate (imutável, append-only).
        $resolved = PatchArtifactCandidate::where('rpo_artifact_id', $artId)->firstOrFail();
        $this->assertSame($cand->id, $resolved->id);
        // Cadeia completa e inequívoca, sem depender de dado efêmero:
        $p = $resolved->provenance;
        $this->assertSame('patch', $art->compatibility['producer']);          // producer=patch
        $this->assertSame($art->hash, $resolved->candidate_digest);           // → candidate
        $ex = PatchExecution::find($resolved->patch_execution_id);
        $this->assertSame($ex->execution_id, $p['execution_id']);             // → execution
        $this->assertSame($resolved->base_rpo_digest, $p['base_rpo_hash']);   // → base_rpo_digest
        $this->assertSame($resolved->batch_digest, $p['batch_digest']);       // → batch_digest
        $this->assertCount(3, $p['item_digests']);                            // → ordered patch identities/digests
        $this->assertNotEmpty($resolved->capability_adapter_version);         // → capability/adapter version
        $this->assertSame('test', $resolved->classification);                 // → classification
        $this->assertTrue($p['simulated']);                                   // → simulated
    }

    // ── (item 4) A cadeia NÃO pode quebrar por cascade: nenhuma FK ON DELETE CASCADE toca a proveniência. ──
    public function test_no_cascade_can_break_provenance_chain(): void
    {
        $rows = DB::select("
            SELECT tc.table_name, rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.referential_constraints rc ON tc.constraint_name = rc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_name IN ('patch_artifact_candidates','patch_executions','patch_execution_items','patch_requests','patch_inputs','rpo_artifacts')
              AND rc.delete_rule = 'CASCADE'");
        $this->assertCount(0, $rows, 'Nenhuma FK CASCADE pode existir nas tabelas da cadeia de proveniência.');
        // E não há delete no domínio (append-only): confirmado por ausência de rota/serviço de delete (auditoria estática).
    }

    // ── (item 6) HONEST-MODE: live NUNCA cai para simulated; execução live bloqueada, sem efeito. ──
    public function test_honest_mode_no_live_to_simulated_fallback(): void
    {
        $env = EnvEnvironment::find($this->envA);
        $av = app(PatchService::class)->availability($env);
        $this->assertFalse($av['live']['available']);
        $this->assertSame('live_unavailable', $av['live']['reason']);
        // request live é criável mas a EXECUÇÃO bloqueia (não coage para simulated).
        $ids = [PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => 'PTM-L', 'digest' => hash('sha256', 'l'), 'created_by' => $this->actor->id])->id];
        $req = app(PatchService::class)->createRequest($env, ['base_rpo_hash' => hash('sha256', 'bl'), 'execution_mode' => 'live', 'workspace_unit_id' => 'WS-LIVE', 'patch_input_ids' => $ids], $this->actor->id)['request'];
        $this->actingAs($this->actor, 'sanctum')->postJson("/api/v1/prosight/patch/requests/{$req->id}/execute")
            ->assertStatus(422)->assertJson(['error' => 'live_unavailable']);
        $this->assertSame(0, PatchExecution::where('patch_request_id', $req->id)->count()); // nenhuma execução simulated "disfarçada"
    }

    // ── (item 5) SECURITY: respostas/proveniência sem path/INI/SpecialKey/PTM bytes/credenciais. ──
    public function test_security_scan_no_sensitive_material(): void
    {
        [$cand, $artId] = $this->registeredArtifact('FINAL-SEC');
        $ex = PatchExecution::find($cand->patch_execution_id);
        $blobs = [
            $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/patch/candidates/{$cand->id}")->content(),
            $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/patch/executions/{$ex->id}")->content(),
            $this->actingAs($this->actor, 'sanctum')->getJson("/api/v1/prosight/rpo/artifacts/{$artId}")->content(),
            json_encode(RpoArtifact::find($artId)->toArray()),
            json_encode($cand->provenance),
        ];
        $forbidden = [
            '#(/[A-Za-z0-9_.\-]+){3,}#',              // caminho unix
            '#[A-Za-z]:\\\\#',                        // caminho windows
            '#\bSpecialKey\b#i', '#CheckSpecialKey#i',// segredo Protheus INI
            '#BEGIN [A-Z ]+PRIVATE KEY#',             // chave privada
            '#\b(?:password|secret|token|api[_-]?key|connection ?string)\b\s*[:=]#i',
            '#\.ptm\b#i', '#\bAppServer\b#',          // artefato físico / binário
        ];
        foreach ($blobs as $bi => $blob) {
            foreach ($forbidden as $rx) {
                $this->assertDoesNotMatchRegularExpression($rx, (string) $blob, "material sensível no blob #$bi ($rx)");
            }
        }
    }

    // ── (item 7) C5 BOUNDARY: registrar NÃO qualifica/promove; nenhum target/qualification automáticos. ──
    public function test_c5_boundary_register_only(): void
    {
        [$cand, $artId] = $this->registeredArtifact('FINAL-BND');
        $art = RpoArtifact::find($artId);
        $this->assertSame('registered', $art->status);   // registered, nunca known_good/qualified/published
        $this->assertSame(0, DB::table('rpo_qualifications')->where('rpo_artifact_id', $artId)->count());
        $this->assertSame(0, DB::table('rpo_targets')->where('environment_id', $this->envA)->count());
        // idempotência final: repetir handoff não cria artefato novo.
        $n = RpoArtifact::count();
        $again = $this->svc()->handoff($cand->fresh(), $this->actor->id);
        $this->assertFalse($again['ok']);
        $this->assertSame($n, RpoArtifact::count());
    }

    // ── (item 2) INVARIANTE: partial/failed/indeterminate → zero candidate → nada registra. ──
    public function test_invariant_incomplete_never_registers(): void
    {
        $req = app(PatchService::class)->createRequest(EnvEnvironment::find($this->envA), ['base_rpo_hash' => hash('sha256', 'bi'),
            'execution_mode' => 'simulated', 'workspace_unit_id' => 'WS-INC', 'patch_input_ids' => [
                PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => 'X', 'digest' => hash('sha256', 'x'), 'created_by' => $this->actor->id])->id,
            ]], $this->actor->id)['request'];
        $ex = $this->svc()->dispatch($req, 'simulated', $this->actor->id)['execution'];
        $this->svc()->result($ex, 'failed', null, $this->actor->id);
        $this->assertSame(PatchExecution::ST_FAILED, $ex->fresh()->status);
        $this->assertSame(0, PatchArtifactCandidate::where('patch_execution_id', $ex->id)->count());
    }

    // ── (item 3) WORKSPACE SAFETY reafirmada: fence monotônico; old owner NÃO cruza; indeterminate segura. ──
    public function test_workspace_safety_reaffirmed(): void
    {
        $mk = fn ($ws) => app(PatchService::class)->createRequest(EnvEnvironment::find($this->envA), ['base_rpo_hash' => hash('sha256', 'b' . $ws),
            'execution_mode' => 'simulated', 'workspace_unit_id' => $ws, 'patch_input_ids' => [
                PatchInput::create(['environment_id' => $this->envA, 'customer_id' => $this->custA->id, 'patch_id' => 'P' . bin2hex(random_bytes(2)), 'digest' => hash('sha256', $ws . bin2hex(random_bytes(3))), 'created_by' => $this->actor->id])->id,
            ]], $this->actor->id)['request'];
        // fence monotônico + old owner fenced out
        $e1 = $this->svc()->dispatch($mk('WS-SAFE')->fresh(), 'simulated', $this->actor->id)['execution'];
        $this->svc()->ack($e1, 'base_verified', null);
        DB::table('connector_workspace_locks')->where('id', $e1->lock_id)->update(['lease_expires_at' => now()->subMinutes(5)]);
        $e2 = $this->svc()->dispatch($mk('WS-SAFE')->fresh(), 'simulated', $this->actor->id)['execution'];
        $this->assertGreaterThan((int) $e1->fence_token, (int) $e2->fence_token); // fence monotônico
        $this->assertSame('fenced_out', $this->svc()->ack($e1->fresh(), 'patch_effect_started', null)['error']); // old owner barrado
        // indeterminate segura o workspace
        $this->svc()->ack($e2->fresh(), 'base_verified', null);
        $this->svc()->ack($e2->fresh(), 'patch_effect_started', null);
        DB::table('connector_workspace_locks')->where('id', $e2->lock_id)->update(['lease_expires_at' => now()->subMinutes(5)]);
        $blocked = $this->svc()->dispatch($mk('WS-SAFE')->fresh(), 'simulated', $this->actor->id);
        $this->assertFalse($blocked['ok']);
        $this->assertSame('workspace_indeterminate', $blocked['error']);
    }
}
