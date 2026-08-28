<?php

namespace Tests\Feature;

use App\Models\ConnectorEnvironmentState;
use App\Models\Customer;
use App\Models\PatchArtifactCandidate;
use App\Models\PatchExecution;
use App\Models\PatchInput;
use App\Models\PatchRequestItem;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PATCH P1 — FUNDAÇÃO. Prova: PatchInput seguro (metadados, zero bytes), lote ORDENADO/IMUTÁVEL + digests,
 * base_rpo_hash congelado, mode-gating fail-closed, availability (live unavailable / capability incompatível),
 * anti-IDOR, sanitização, permissões. E que P1 NÃO executa, NÃO cria candidate, NÃO registra no C5.
 */
class PatchP1Test extends TestCase
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
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.patch.executable_modes' => ['simulated', 'live'],
            'connector.patch.allow_fixture' => false,
            'connector.patch.live_ready' => false,
            'connector.patch.supported_capabilities' => [['name' => 'rpo_patch', 'contract_version' => 1]]]);
        $this->custA = Customer::factory()->create();
        $this->envA = $this->makeEnv($this->custA);
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

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function userWith(array $perms, ?Customer $c = null): User
    {
        $c = $c ?: $this->custA;
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $p = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_consultants')->insert(['project_id' => $p->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    private function h64(string $s): string { return hash('sha256', $s); }

    private function mkInput(User $u, array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/patch/inputs", array_merge([
            'patch_id' => 'PTM-' . bin2hex(random_bytes(3)), 'digest' => $this->h64('p' . bin2hex(random_bytes(4))),
            'provenance' => 'GMUD-123', 'version' => '1.0', 'classification' => 'test',
        ], $o));
    }

    private function avail(int $env): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/patch/availability")->json('data'); }

    // ── 1. PatchInput seguro + digest inválido rejeitado. ──
    public function test_create_input_safe_and_digest_validated(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request', 'prosight.operations.patch.view']);
        $id = $this->mkInput($u)->assertStatus(201)->json('data.id');
        $this->assertNotNull($id);
        $this->mkInput($u, ['digest' => str_repeat('z', 64)])->assertStatus(422)->assertJson(['error' => 'invalid_digest']);
    }

    // ── 2. Sanitização: path/secret não persiste em metadados. ──
    public function test_sanitization_strips_path_and_secret(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request', 'prosight.operations.patch.view']);
        $id = $this->mkInput($u, ['patch_id' => 'PTM /opt/protheus/patch.ptm', 'provenance' => 'SpecialKey=SK-SECRET C:\\TOTVS'])->json('data.id');
        $blob = json_encode(PatchInput::find($id)->only(['patch_id', 'source_ref', 'provenance']));
        $this->assertStringNotContainsString('/opt/protheus', $blob);
        $this->assertStringNotContainsString('SK-SECRET', $blob);
        $this->assertStringNotContainsString('SpecialKey', $blob);
        $this->assertStringNotContainsString('C:\\', $blob);
    }

    // ── 3. Request congela base + lote ORDENADO/IMUTÁVEL + batch_digest. ──
    public function test_request_freezes_base_and_ordered_batch(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request', 'prosight.operations.patch.view']);
        $a = $this->mkInput($u, ['digest' => str_repeat('a', 64)])->json('data.id');
        $b = $this->mkInput($u, ['digest' => str_repeat('b', 64)])->json('data.id');
        $c = $this->mkInput($u, ['digest' => str_repeat('c', 64)])->json('data.id');
        $base = str_repeat('e', 64);
        $res = $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/patch/requests", [
            'base_rpo_hash' => $base, 'execution_mode' => 'simulated', 'patch_input_ids' => [$a, $b, $c],
        ])->assertStatus(201)->json('data');
        $this->assertSame($base, $res['base_rpo_hash']);          // base congelada
        $this->assertSame(hash('sha256', str_repeat('a', 64) . '|' . str_repeat('b', 64) . '|' . str_repeat('c', 64)), $res['batch_digest']);
        $this->assertFalse($res['is_registered']);
        $this->assertFalse($res['is_published']);
        // ordem imutável 1,2,3 com item_digest
        $items = PatchRequestItem::where('patch_request_id', $res['id'])->orderBy('batch_order')->get();
        $this->assertSame([1, 2, 3], $items->pluck('batch_order')->all());
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)], $items->pluck('item_digest')->all());
    }

    // ── 4. Lote: duplicado 422, input de outro ambiente/inexistente 404, base inválida 422. ──
    public function test_batch_and_base_validation(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request']);
        $a = $this->mkInput($u)->json('data.id');
        $mk = fn ($body) => $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/patch/requests", $body);
        $mk(['base_rpo_hash' => str_repeat('e', 64), 'execution_mode' => 'simulated', 'patch_input_ids' => [$a, $a]])->assertStatus(422)->assertJson(['error' => 'duplicate_in_batch']);
        $mk(['base_rpo_hash' => str_repeat('e', 64), 'execution_mode' => 'simulated', 'patch_input_ids' => [999999]])->assertStatus(404)->assertJson(['error' => 'input_not_found']);
        $mk(['base_rpo_hash' => str_repeat('z', 64), 'execution_mode' => 'simulated', 'patch_input_ids' => [$a]])->assertStatus(422)->assertJson(['error' => 'invalid_base_rpo_hash']);
    }

    // ── 5. Mode-gating fail-closed: fixture desabilitado. ──
    public function test_fixture_fail_closed(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request']);
        $a = $this->mkInput($u)->json('data.id');
        $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/patch/requests", [
            'base_rpo_hash' => str_repeat('e', 64), 'execution_mode' => 'fixture', 'patch_input_ids' => [$a],
        ])->assertStatus(422)->assertJson(['error' => 'mode_not_executable']);
    }

    // ── 6. Availability: live unavailable (sem fallback) + capability incompatível. ──
    public function test_availability_live_and_capability(): void
    {
        $v = $this->avail($this->envA);
        $this->assertTrue($v['simulated']['available']);
        $this->assertFalse($v['live']['available']);
        $this->assertSame('live_unavailable', $v['live']['reason']);
        // live_ready=true mas sem capability → patch_capability_absent
        config(['connector.patch.live_ready' => true]);
        $this->assertSame('patch_capability_absent', $this->avail($this->envA)['live']['reason']);
        // capability com contract_version incompatível → patch_contract_unsupported
        ConnectorEnvironmentState::updateOrCreate(['environment_id' => $this->envA], ['agent_id' => '11111111-1111-4111-8111-111111111111', 'patch_capability' => ['name' => 'rpo_patch', 'contract_version' => 99]]);
        $this->assertSame('patch_contract_unsupported', $this->avail($this->envA)['live']['reason']);
    }

    // ── 7. Permissão + anti-IDOR. ──
    public function test_permission_and_idor(): void
    {
        $this->mkInput($this->userWith(['prosight.operations.patch.view']))->assertStatus(403); // sem request
        $custB = Customer::factory()->create();
        $this->actingAs($this->userWith(['prosight.operations.patch.view'], $custB), 'sanctum')
            ->getJson("/api/v1/prosight/environments/{$this->envA}/patch/availability")->assertStatus(404);
    }

    // ── 8. P1 NÃO executa, NÃO cria candidate, NÃO registra no C5. ──
    public function test_p1_produces_nothing_downstream(): void
    {
        $u = $this->userWith(['prosight.operations.patch.request', 'prosight.operations.patch.view']);
        $a = $this->mkInput($u)->json('data.id');
        $artBefore = RpoArtifact::count();
        $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/patch/requests", [
            'base_rpo_hash' => str_repeat('e', 64), 'execution_mode' => 'simulated', 'patch_input_ids' => [$a],
        ])->assertStatus(201);
        $this->assertSame(0, PatchExecution::count());               // sem execução
        $this->assertSame(0, PatchArtifactCandidate::count());       // sem candidate
        $this->assertSame(0, DB::table('connector_workspace_locks')->count()); // sem lock adquirido
        $this->assertSame($artBefore, RpoArtifact::count());         // NENHUM artefato C5 criado
    }
}
