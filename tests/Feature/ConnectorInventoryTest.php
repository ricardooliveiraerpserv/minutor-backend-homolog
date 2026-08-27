<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorEnvironmentState;
use App\Models\ConnectorEvent;
use App\Models\ConnectorRpoSnapshot;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Conector-2 — inventário Protheus observado (read-only). Prova: observado via endpoint,
 * snapshot RPO só em mudança de hash (dedup mtime/restart), eventos só em transições
 * (uptime-only não gera), anti-regressão de inventário atrasado, idempotência, divergência
 * cadastral×observado, timeline operacoes, presença INDEPENDENTE do inventário, sem secret.
 */
class ConnectorInventoryTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;
    private string $ref1 = '11111111-aaaa-4bbb-8ccc-111111111111';

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

    private function cadastralAppserver(int $envId, string $name, string $version): void
    {
        DB::table('env_appservers')->insert(['environment_id' => $envId, 'name' => $name, 'version' => $version,
            'build' => '9999', 'patch' => '12', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function enrollAgent(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function signed(string $agentId, string $sk, string $method, string $path, string $json): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, $method, $path, $json, $ts, $nonce);
        $sig = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        return ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Content-Type' => 'application/json'];
    }

    private function inventory(string $agentId, string $sk, array $body): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($body);
        return $this->postJson('/api/v1/connector/inventory', $body, $this->signed($agentId, $sk, 'POST', '/api/v1/connector/inventory', $json));
    }

    private function heartbeat(string $agentId, string $sk, array $body): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($body);
        return $this->postJson('/api/v1/connector/heartbeat', $body, $this->signed($agentId, $sk, 'POST', '/api/v1/connector/heartbeat', $json));
    }

    private function observed(int $envId): array
    {
        return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$envId}/observed")->assertOk()->json('data');
    }

    private function inv(array $overrides = []): array
    {
        return array_merge([
            'observed_at' => time(),
            'appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 100]],
            'rest' => [['name' => 'REST01', 'healthy' => true]],
            'rpo' => [['appserver_ref' => $this->ref1, 'hash' => str_repeat('a', 64), 'version' => 'TTTP', 'size' => 1000, 'mtime' => time()]],
        ], $overrides);
    }

    // ── testes ────────────────────────────────────────────────────────────────

    public function test_inventory_and_observed(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv())->assertOk();
        $o = $this->observed($env);
        $this->assertTrue($o['has_inventory']);
        $this->assertSame('12.1.2410', $o['inventory']['appservers'][0]['version']);
        $this->assertTrue($o['inventory']['appservers'][0]['up']);
        $this->assertTrue($o['inventory']['rest'][0]['healthy']);
        $this->assertSame(str_repeat('a', 64), $o['inventory']['rpo'][0]['hash']);
    }

    public function test_rpo_snapshot_on_change_and_dedup(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv())->assertOk(); // hash a…
        $this->assertSame(1, ConnectorRpoSnapshot::where('environment_id', $env)->count());
        // mesmo hash, mtime diferente → NÃO é novo RPO.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 1, 'rpo' => [['appserver_ref' => $this->ref1, 'hash' => str_repeat('a', 64), 'mtime' => time() + 999]]]))->assertOk();
        $this->assertSame(1, ConnectorRpoSnapshot::where('environment_id', $env)->count());
        // hash NOVO → +1 snapshot + 1 evento rpo_changed.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 2, 'rpo' => [['appserver_ref' => $this->ref1, 'hash' => str_repeat('b', 64)]]]))->assertOk();
        $this->assertSame(2, ConnectorRpoSnapshot::where('environment_id', $env)->count());
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'rpo_changed')->count());
    }

    public function test_uptime_only_change_generates_no_event(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv())->assertOk();
        $before = ConnectorEvent::where('environment_id', $env)->count();
        // só uptime muda → nenhum evento novo.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 1, 'appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 999]], 'rpo' => []]))->assertOk();
        $this->assertSame($before, ConnectorEvent::where('environment_id', $env)->count());
    }

    public function test_version_and_process_changes_emit_events(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv())->assertOk();
        // versão muda → version_changed; processo cai → process_changed.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 1, 'appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => false, 'version' => '12.1.2500', 'build' => '9999', 'patch' => '12']], 'rpo' => []]))->assertOk();
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'version_changed')->count());
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'process_changed')->count());
    }

    public function test_delayed_inventory_does_not_regress(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $t = time();
        $this->inventory($id, $sk, $this->inv(['observed_at' => $t, 'appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => 'V2']], 'rpo' => []]))->assertOk();
        // inventário ATRASADO (observed_at anterior) → descartado, estado não regride.
        $r = $this->inventory($id, $sk, $this->inv(['observed_at' => $t - 100, 'appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => 'V1']], 'rpo' => []]));
        $r->assertOk()->assertJsonPath('applied', false);
        $this->assertSame('V2', $this->observed($env)['inventory']['appservers'][0]['version']);
    }

    public function test_idempotent_identical_inventory(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $body = $this->inv();
        $this->inventory($id, $sk, $body)->assertOk();
        $ev = ConnectorEvent::where('environment_id', $env)->count();
        $sn = ConnectorRpoSnapshot::where('environment_id', $env)->count();
        // inventário idêntico (mesmos valores, observed_at ≥) → nenhum evento/snapshot novo.
        $this->inventory($id, $sk, array_merge($body, ['observed_at' => $body['observed_at'] + 1]))->assertOk();
        $this->assertSame($ev, ConnectorEvent::where('environment_id', $env)->count());
        $this->assertSame($sn, ConnectorRpoSnapshot::where('environment_id', $env)->count());
    }

    public function test_divergence_cadastral_vs_observed(): void
    {
        $env = $this->makeEnv($this->custA);
        $this->cadastralAppserver($env, 'APP01', '12.1.2410'); // cadastral
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv(['appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => '12.1.2500', 'build' => '9999', 'patch' => '12']], 'rpo' => []]))->assertOk();
        $o = $this->observed($env);
        $div = collect($o['divergence'])->firstWhere('field', 'version');
        $this->assertNotNull($div);
        $this->assertSame('12.1.2410', $div['cadastral']);
        $this->assertSame('12.1.2500', $div['observed']);
    }

    public function test_presence_independent_from_inventory(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // heartbeat → presença online; inventário existe mas envelhecido → presença NÃO fica offline.
        $this->heartbeat($id, $sk, ['observed_at' => time()])->assertOk();
        $this->inventory($id, $sk, $this->inv())->assertOk();
        ConnectorEnvironmentState::where('environment_id', $env)->update(['inventory_received_at' => now()->subSeconds(1000)]);
        $pres = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/presence")->json('data');
        $this->assertSame('online', $pres['observed']['status']); // presença segue heartbeat
        $this->assertGreaterThan(500, $this->observed($env)['stale_s']); // inventário envelhecido, independente
    }

    public function test_timeline_operacoes_from_connector_events(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv())->assertOk(); // gera appserver_up + rpo_changed
        $admin = $this->admin();
        $items = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/activity?family=operacoes')->assertOk()->json('data.items');
        $this->assertNotEmpty($items);
        $this->assertContains('connector-event', array_column($items, 'kind'));
        // operacoes deixou de estar pendente (tem transição real).
        $pending = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/activity')->json('data.pending_families');
        $this->assertNotContains('operacoes', $pending);
    }

    public function test_observed_has_no_secrets_and_anti_idor(): void
    {
        $envA = $this->makeEnv($this->custA);
        $envB = $this->makeEnv($this->custB);
        [$id, $sk] = $this->enrollAgent($envA);
        // tenta enviar campos sensíveis (path/ini/password) — validate() é allowlist e descarta.
        $this->inventory($id, $sk, $this->inv(['appservers' => [['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => 'v', 'root_path' => 'C:\\SECRETPATH', 'ini' => 'senha=X']], 'rpo' => []]))->assertOk();
        $o = $this->observed($envA);
        $json = json_encode($o);
        foreach (['SECRETPATH', 'root_path', 'ini', 'senha'] as $s) {
            $this->assertStringNotContainsString($s, $json);
        }
        // anti-IDOR: coord de A não vê observed de B.
        $coordA = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.view']]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $coordA->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/{$envB}/observed")->assertStatus(404);
    }
}
