<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAppserverBinding;
use App\Models\Customer;
use App\Models\EnvAppserver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ENV-HUB E1+E2 — operational-status (readiness/journey) + reconciliação cadastral↔observado (binding HUMANO).
 * Prova: sem auto-binding, sugestão não-autoritativa, rebind supersede não-destrutivo, conflito 1:1, persistência
 * pós-restart (ref estável), re-enroll SEM auto-supersede (estado derivado connector_replaced), perm granular, anti-IDOR.
 */
class EnvironmentHubTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private int $envA;
    private string $rA = 'aaaaaaaa-1111-4bbb-8ccc-aaaaaaaaaaaa';
    private string $rB = 'bbbbbbbb-2222-4bbb-8ccc-bbbbbbbbbbbb';

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.presence_online' => 75, 'connector.presence_offline' => 300]);
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

    private function cad(string $name, int $port): int
    {
        return EnvAppserver::create(['environment_id' => $this->envA, 'name' => $name, 'port' => $port, 'version' => '12.1.2410', 'root_path' => '/x'])->id;
    }

    private function enroll(int $env): array
    {
        $tok = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $aid = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $tok, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$aid, sodium_crypto_sign_secretkey($kp)];
    }

    private function sigPost(string $a, string $sk, string $p, array $b): \Illuminate\Testing\TestResponse
    {
        $ts = time(); $n = bin2hex(random_bytes(9));
        $h = ['X-Agent-Id' => $a, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $n, 'X-Signature' => base64_encode(sodium_crypto_sign_detached(app(ConnectorIdentity::class)->canonicalString($a, 'POST', "/api/v1{$p}", json_encode($b), $ts, $n), $sk)), 'Content-Type' => 'application/json'];
        return $this->postJson("/api/v1{$p}", $b, $h);
    }

    /** posta inventário com appservers observados (ref=>[up,piid]). */
    private function postInv(string $a, string $sk, array $apps): void
    {
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $arr = [];
        foreach ($apps as $ref => $o) {
            $arr[] = ['ref' => $ref, 'name' => $o['name'] ?? ('APP-' . substr($ref, 0, 4)), 'up' => $o['up'] ?? true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 60, 'process_instance_id' => $o['piid'] ?? ('PI' . substr(md5($ref), 0, 16))];
        }
        $this->sigPost($a, $sk, '/connector/inventory', ['observed_at' => time(), 'appservers' => $arr, 'rest' => [], 'rpo' => []])->assertOk();
    }

    private function opStatus(int $env): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/operational-status")->json('data'); }
    private function recon(int $env): array { return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/appservers/reconciliation")->json('data'); }
    private function bindAs(User $u, int $env, int $cadId, string $ref): \Illuminate\Testing\TestResponse { return $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/appservers/{$cadId}/bind", ['appserver_ref' => $ref]); }

    // ── 1. Sem Connector → setup_required + jornada. ──
    public function test_status_setup_required_when_no_connector(): void
    {
        $s = $this->opStatus($this->envA);
        $this->assertSame('not_enrolled', $s['connector']['status']);
        $this->assertSame('setup_required', $s['readiness']);
        $this->assertContains('connector_not_enrolled', $s['blocking_reasons']);
        $this->assertSame('connector', $s['journey']['next_step']);
        $this->assertSame(1, $s['journey']['progress']);
    }

    // ── 2. Observado sem binding → detectado_não_vinculado + appservers_unbound. ──
    public function test_observed_without_binding_is_detected_unbound(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postInv($a, $sk, [$this->rA => [], $this->rB => []]);
        $s = $this->opStatus($this->envA);
        $this->assertSame(2, $s['appservers']['observed']);
        $this->assertSame(0, $s['appservers']['bound']);
        $this->assertSame(2, $s['appservers']['unbound']);
        $this->assertContains('appservers_unbound', $s['blocking_reasons']);
        $states = collect($this->recon($this->envA)['rows'])->pluck('state')->all();
        $this->assertEquals(['detected_unbound', 'detected_unbound'], $states);
    }

    // ── 3. Vínculo HUMANO → healthy + bound. ──
    public function test_confirm_binding_makes_it_healthy(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $this->postInv($a, $sk, [$this->rA => ['name' => 'APP01']]);
        $this->bindAs($this->userWith(['prosight.operations.appserver.bind']), $this->envA, $cad, $this->rA)->assertStatus(200);
        $this->assertSame(1, $this->opStatus($this->envA)['appservers']['bound']);
        $row = collect($this->recon($this->envA)['rows'])->firstWhere('env_appserver_id', $cad);
        $this->assertSame('healthy', $row['state']);
        $this->assertSame($this->rA, $row['appserver_ref']);
    }

    // ── 4. Sugestão por nome NÃO auto-vincula. ──
    public function test_suggestion_never_auto_binds(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $this->postInv($a, $sk, [$this->rA => ['name' => 'APP01']]);
        $rows = collect($this->recon($this->envA)['rows']);
        $cRow = $rows->firstWhere('env_appserver_id', $cad);
        $this->assertSame('unbound_cadastral', $cRow['state']);          // NÃO vinculado
        $this->assertSame($this->rA, $cRow['suggestion']['appserver_ref']); // só sugere
        $this->assertSame(0, $this->opStatus($this->envA)['appservers']['bound']);
    }

    // ── 5. Rebind supersede NÃO-destrutivo (histórico). ──
    public function test_rebind_supersedes_without_deleting(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $u = $this->userWith(['prosight.operations.appserver.bind']);
        $this->postInv($a, $sk, [$this->rA => [], $this->rB => []]);
        $this->bindAs($u, $this->envA, $cad, $this->rA)->assertStatus(200);
        $this->bindAs($u, $this->envA, $cad, $this->rB)->assertStatus(200); // rebind
        $all = ConnectorAppserverBinding::where('env_appserver_id', $cad)->get();
        $this->assertCount(2, $all);
        $this->assertSame(1, $all->where('status', 'active')->count());
        $active = $all->firstWhere('status', 'active');
        $this->assertSame($this->rB, $active->appserver_ref);
        $this->assertNotNull($active->supersedes_binding_id);          // cadeia de auditoria
        $this->assertSame($this->rA, $all->firstWhere('status', 'superseded')->appserver_ref);
    }

    // ── 6. Conflito 1:1: ref já vinculado a outro cadastral → 409. ──
    public function test_conflict_ref_already_bound(): void
    {
        $c1 = $this->cad('APP01', 1234); $c2 = $this->cad('APP02', 1235);
        [$a, $sk] = $this->enroll($this->envA);
        $u = $this->userWith(['prosight.operations.appserver.bind']);
        $this->postInv($a, $sk, [$this->rA => []]);
        $this->bindAs($u, $this->envA, $c1, $this->rA)->assertStatus(200);
        $this->bindAs($u, $this->envA, $c2, $this->rA)->assertStatus(409)->assertJson(['error' => 'ref_already_bound']);
    }

    // ── 7. Binding persiste após RESTART (piid muda, ref estável). ──
    public function test_binding_persists_after_restart(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $this->postInv($a, $sk, [$this->rA => ['piid' => 'PIaaaaaaaaaaaaaaaa']]);
        $this->bindAs($this->userWith(['prosight.operations.appserver.bind']), $this->envA, $cad, $this->rA)->assertStatus(200);
        // restart: MESMO ref, novo process_instance_id
        $this->postInv($a, $sk, [$this->rA => ['piid' => 'PIbbbbbbbbbbbbbbbb']]);
        $row = collect($this->recon($this->envA)['rows'])->firstWhere('env_appserver_id', $cad);
        $this->assertSame('healthy', $row['state']);
        $this->assertSame(1, ConnectorAppserverBinding::where('env_appserver_id', $cad)->where('status', 'active')->count());
    }

    // ── 8. Re-enroll do Connector NÃO auto-supersede (derivado connector_replaced). ──
    public function test_connector_reenroll_does_not_auto_supersede(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a1, $sk1] = $this->enroll($this->envA);
        $this->postInv($a1, $sk1, [$this->rA => []]);
        $this->bindAs($this->userWith(['prosight.operations.appserver.bind']), $this->envA, $cad, $this->rA)->assertStatus(200);
        // revoga + re-enroll (novo connector_id) e coleta com OUTRO ref
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$a1}");
        [$a2, $sk2] = $this->enroll($this->envA);
        $this->postInv($a2, $sk2, [$this->rB => []]);
        // binding antigo CONTINUA active (não apagado, não superseded automaticamente)
        $this->assertSame(1, ConnectorAppserverBinding::where('env_appserver_id', $cad)->where('status', 'active')->count());
        $row = collect($this->recon($this->envA)['rows'])->firstWhere('env_appserver_id', $cad);
        $this->assertSame('connector_replaced', $row['state']); // projeção, não status persistido
    }

    // ── 9. Permissão granular obrigatória + anti-IDOR + ref não observado. ──
    public function test_permission_and_idor_and_ref_not_observed(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $this->postInv($a, $sk, [$this->rA => []]);
        // sem appserver.bind → 403
        $this->bindAs($this->userWith(['prosight.operations.view']), $this->envA, $cad, $this->rA)->assertStatus(403);
        // anti-IDOR: user de custB não vê status de custA
        $custB = Customer::factory()->create();
        $this->actingAs($this->userWith(['prosight.operations.view'], $custB), 'sanctum')->getJson("/api/v1/prosight/environments/{$this->envA}/operational-status")->assertStatus(404);
        // ref não observado → 422
        $this->bindAs($this->userWith(['prosight.operations.appserver.bind']), $this->envA, $cad, '99999999-9999-4999-8999-999999999999')->assertStatus(422)->assertJson(['error' => 'ref_not_observed']);
    }

    // ── 10. Supersede explícito não-destrutivo (linha permanece). ──
    public function test_explicit_supersede_is_non_destructive(): void
    {
        $cad = $this->cad('APP01', 1234);
        [$a, $sk] = $this->enroll($this->envA);
        $u = $this->userWith(['prosight.operations.appserver.bind']);
        $this->postInv($a, $sk, [$this->rA => []]);
        $bid = $this->bindAs($u, $this->envA, $cad, $this->rA)->json('data.binding_id');
        $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$this->envA}/appserver-bindings/{$bid}/supersede", ['reason' => 'trocado manualmente'])->assertStatus(200);
        $b = ConnectorAppserverBinding::find($bid);
        $this->assertSame('superseded', $b->status); // linha permanece, só muda status
        $this->assertNotNull($b->superseded_at);
    }
}
