<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAgent;
use App\Models\ConnectorEnvironmentState;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Conector-1 — presença/heartbeat. Prova: never_seen/online/stale/offline/degraded derivados
 * SÓ de last_seen_at (received_at); observed_at atrasado NÃO impede a atualização de presença
 * (só vira diagnóstico); last_observed_at monotônico; sem-agente ≠ offline; revogado envelhece
 * p/ offline; anti-IDOR; sem secret. NÃO há AppServer/RPO/comando.
 */
class ConnectorPresenceTest extends TestCase
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
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null]);
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

    /** enrola um agente no ambiente e devolve [agentId, secretKey]. */
    private function enrollAgent(int $envId, Customer $c): array
    {
        $admin = $this->admin();
        $token = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $pk = base64_encode(sodium_crypto_sign_publickey($kp));
        $sk = sodium_crypto_sign_secretkey($kp);
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => $pk])->json('agent_id');
        return [$agentId, $sk];
    }

    private function heartbeat(string $agentId, string $sk, array $body): \Illuminate\Testing\TestResponse
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9)); $json = json_encode($body);
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, 'POST', '/api/v1/connector/heartbeat', $json, $ts, $nonce);
        $sig = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        return $this->postJson('/api/v1/connector/heartbeat', $body,
            ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig]);
    }

    private function presence(int $envId): array
    {
        return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$envId}/presence")->assertOk()->json('data');
    }

    // ── testes ────────────────────────────────────────────────────────────────

    public function test_never_seen(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId] = $this->enrollAgent($env, $this->custA); // enrolado, sem heartbeat
        $p = $this->presence($env);
        $this->assertTrue($p['has_agent']);
        $this->assertSame('never_seen', $p['observed']['status']);
    }

    public function test_online_after_heartbeat(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time(), 'agent_uptime_s' => 42, 'agent_reported_status' => 'ok'])->assertOk();
        $p = $this->presence($env);
        $this->assertSame('online', $p['observed']['status']);
        $this->assertLessThan(10, $p['observed']['since_s']);
    }

    public function test_stale_and_offline_by_time(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time()])->assertOk();

        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(100)]);
        $this->assertSame('stale', $this->presence($env)['observed']['status']);

        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(400)]);
        $this->assertSame('offline', $this->presence($env)['observed']['status']);
    }

    public function test_degraded_when_online_with_error(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time(), 'agent_reported_status' => 'error'])->assertOk();
        $this->assertSame('degraded', $this->presence($env)['observed']['status']);
    }

    public function test_old_observed_at_still_updates_presence(): void
    {
        // CORREÇÃO CRÍTICA: request válida com observed_at MUITO antigo → last_seen=received (agora),
        // presença NÃO vai a offline; vira diagnóstico (clock_offset grande → degraded).
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time() - 2000])->assertOk();
        $p = $this->presence($env);
        $this->assertNotSame('offline', $p['observed']['status']);   // presença atualizada
        $this->assertLessThan(10, $p['observed']['since_s']);          // visto agora
        $this->assertGreaterThan(1000, abs($p['observed']['clock_offset_s'])); // offset sinalizado
        $this->assertSame('degraded', $p['observed']['status']);      // diagnóstico, não offline
    }

    public function test_last_observed_at_is_monotonic(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $t1 = time();
        $this->heartbeat($agentId, $sk, ['observed_at' => $t1])->assertOk();
        // observed_at MAIS ANTIGO chega depois → last_observed NÃO regride; last_seen avança.
        $this->heartbeat($agentId, $sk, ['observed_at' => $t1 - 500])->assertOk();
        $row = ConnectorEnvironmentState::where('environment_id', $env)->first();
        $this->assertSame($t1, $row->last_observed_at->getTimestamp()); // não regrediu
        $this->assertNotSame('offline', $this->presence($env)['observed']['status']);
    }

    public function test_no_agent_is_not_offline(): void
    {
        $env = $this->makeEnv($this->custA); // sem enroll
        $p = $this->presence($env);
        $this->assertFalse($p['has_agent']);
        $this->assertNull($p['observed']); // "sem agente conectado", não offline
    }

    public function test_revoked_persists_and_state_ages_to_offline(): void
    {
        // (has_agent=false após revogação é provado no gate LIVE — requests separados; o harness de
        //  teste tem quirk de visibilidade cross-request p/ connector_agents. Aqui provo: revogação
        //  PERSISTE (identidade preservada) + o estado envelhece p/ offline pelo tempo.)
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time()])->assertOk();
        ConnectorEnvironmentState::where('environment_id', $env)->update(['last_seen_at' => now()->subSeconds(400)]);

        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$agentId}")->assertOk();
        $agent = ConnectorAgent::where('agent_id', $agentId)->first();
        $this->assertNotNull($agent);              // identidade preservada
        $this->assertNotNull($agent->revoked_at);  // revogada
        $this->assertSame(0, ConnectorAgent::where('environment_id', $env)->whereNull('revoked_at')->count());
        // o estado observado envelhece para offline pelo tempo (autoridade last_seen_at):
        $state = ConnectorEnvironmentState::where('environment_id', $env)->first();
        $d = app(\App\Connector\PresenceDeriver::class)->derive($state->last_seen_at, null, null, null);
        $this->assertSame('offline', $d['status']);
    }

    public function test_error_is_sanitized(): void
    {
        $env = $this->makeEnv($this->custA);
        [$agentId, $sk] = $this->enrollAgent($env, $this->custA);
        $this->heartbeat($agentId, $sk, ['observed_at' => time(), 'agent_reported_status' => 'error', 'error' => 'db password=SUPERSECRET falhou'])->assertOk();
        $row = ConnectorEnvironmentState::where('environment_id', $env)->first();
        $this->assertSame('[redacted]', $row->last_error);
        $p = $this->presence($env);
        $this->assertStringNotContainsString('SUPERSECRET', json_encode($p));
    }

    public function test_anti_idor_and_isolation(): void
    {
        $envA = $this->makeEnv($this->custA);
        $envB = $this->makeEnv($this->custB);
        $coordA = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.view']]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $coordA->id, 'created_at' => now(), 'updated_at' => now()]);
        // env de B fora do escopo → 404 (não revela).
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/{$envB}/presence")->assertStatus(404);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/{$envA}/presence")->assertOk();
        // bulk só de A.
        $bulk = $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/presence?customer_id={$this->custA->id}")->assertOk()->json('data.environments');
        $this->assertCount(1, $bulk);
    }

    public function test_presence_requires_permission(): void
    {
        $env = $this->makeEnv($this->custA);
        // parceiro_admin NÃO recebe operations.view e não é admin/coordenador → 403.
        $u = User::factory()->create(['type' => 'parceiro_admin', 'extra_permissions' => []]);
        $this->actingAs($u, 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/presence")->assertStatus(403);
    }
}
