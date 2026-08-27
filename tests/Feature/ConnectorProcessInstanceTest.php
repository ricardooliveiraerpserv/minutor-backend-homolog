<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4.0 — process_instance_id (autoridade FUTURA de reconciliação do C-4). Aditivo ao C-2 (jsonb, zero
 * schema). Prova: armazenado/retornado; estável em coleta repetida e em uptime-only; NÃO muda quando só o
 * Conector reinicia (novo agente, mesma incarnação → mesmo id); muda no restart do AppServer; anti-reorder
 * não regride; sem vazar PID/start_epoch/boot_id/local_key; capability true só com o sinal; agente antigo
 * (sem o campo) continua compatível e capability=false.
 */
class ConnectorProcessInstanceTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $ref1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $instA = 'AAAA1111bbbb2222cccc33';
    private string $instB = 'BBBB4444dddd5555eeee66';

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

    private function enrollAgent(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function revoke(string $agentId): void
    {
        $this->actingAs($this->admin(), 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$agentId}");
    }

    private function signed(string $agentId, string $sk, string $json): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, 'POST', '/api/v1/connector/inventory', $json, $ts, $nonce);
        $sig = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        return ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Content-Type' => 'application/json'];
    }

    private function inventory(string $agentId, string $sk, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/connector/inventory', $body, $this->signed($agentId, $sk, json_encode($body)));
    }

    /** Monta um AppServer up com opções (uptime/instance) + extras (p/ testar allowlist). */
    private function app(array $over = []): array
    {
        return array_merge([
            'ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => '12.1.2410',
            'build' => '9999', 'patch' => '12', 'uptime_s' => 100, 'process_instance_id' => $this->instA,
        ], $over);
    }

    private function inv(array $app, int $obsAt): array
    {
        return ['observed_at' => $obsAt, 'appservers' => [$app], 'rest' => [], 'rpo' => []];
    }

    private function observed(int $envId): array
    {
        return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$envId}/observed")->assertOk()->json('data');
    }

    // ── testes ──────────────────────────────────────────────────────────────

    public function test_instance_id_stored_and_capability(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->inv($this->app(), time()))->assertOk();
        $o = $this->observed($env);
        $this->assertSame($this->instA, $o['inventory']['appservers'][0]['process_instance_id']);
        $this->assertTrue($o['process_instance_capability']);
    }

    public function test_repeated_and_uptime_only_keep_instance(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $t = time();
        $this->inventory($id, $sk, $this->inv($this->app(['uptime_s' => 100]), $t))->assertOk();
        // coleta repetida + só uptime muda → instance CONTINUA A.
        $this->inventory($id, $sk, $this->inv($this->app(['uptime_s' => 999]), $t + 1))->assertOk();
        $this->assertSame($this->instA, $this->observed($env)['inventory']['appservers'][0]['process_instance_id']);
    }

    public function test_connector_restart_keeps_same_instance(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id1, $sk1] = $this->enrollAgent($env);
        $this->inventory($id1, $sk1, $this->inv($this->app(), time()))->assertOk();
        $this->assertSame($this->instA, $this->observed($env)['inventory']['appservers'][0]['process_instance_id']);
        // "restart do Conector": novo agente (nova identidade AGENT-V1), MESMA incarnação do AppServer
        // (chave local persistente do agente → mesmo process_instance_id A). Não pode virar restart falso.
        $this->revoke($id1);
        [$id2, $sk2] = $this->enrollAgent($env);
        $this->inventory($id2, $sk2, $this->inv($this->app(['uptime_s' => 250]), time() + 2))->assertOk();
        $this->assertSame($this->instA, $this->observed($env)['inventory']['appservers'][0]['process_instance_id']);
    }

    public function test_appserver_restart_changes_instance(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $t = time();
        $this->inventory($id, $sk, $this->inv($this->app(['uptime_s' => 5000]), $t))->assertOk();
        // restart REAL do AppServer: nova incarnação → id B, uptime baixo.
        $this->inventory($id, $sk, $this->inv($this->app(['process_instance_id' => $this->instB, 'uptime_s' => 3]), $t + 1))->assertOk();
        $o = $this->observed($env);
        $this->assertSame($this->instB, $o['inventory']['appservers'][0]['process_instance_id']);
        $this->assertNotSame($this->instA, $o['inventory']['appservers'][0]['process_instance_id']);
    }

    public function test_anti_reorder_does_not_regress_instance(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $t = time();
        $this->inventory($id, $sk, $this->inv($this->app(['process_instance_id' => $this->instB]), $t))->assertOk();
        // inventário ATRASADO (observed_at menor) trazendo A → descartado, não regride B.
        $this->inventory($id, $sk, $this->inv($this->app(['process_instance_id' => $this->instA]), $t - 100))
            ->assertOk()->assertJsonPath('applied', false);
        $this->assertSame($this->instB, $this->observed($env)['inventory']['appservers'][0]['process_instance_id']);
    }

    public function test_no_pid_or_secret_leak(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // agente "malicioso"/ingênuo manda campos crus — allowlist descarta tudo menos process_instance_id.
        $this->inventory($id, $sk, $this->inv($this->app([
            'pid' => 4242, 'start_epoch' => 1787000000, 'boot_id' => 'BOOT-XYZ', 'local_key' => 'SECRETKEY', 'host' => 'srv-app01',
        ]), time()))->assertOk();
        $json = json_encode($this->observed($env));
        foreach (['4242', 'start_epoch', 'boot_id', 'BOOT-XYZ', 'local_key', 'SECRETKEY', 'srv-app01', 'pid'] as $s) {
            $this->assertStringNotContainsString($s, $json);
        }
        $this->assertSame($this->instA, $this->observed($env)['inventory']['appservers'][0]['process_instance_id']);
    }

    public function test_old_agent_without_field_is_compatible_and_capability_false(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // agente ANTIGO: sem process_instance_id → C-2 segue funcionando; capability=false (C-4 bloquearia).
        $app = $this->app(); unset($app['process_instance_id']);
        $this->inventory($id, $sk, $this->inv($app, time()))->assertOk();
        $o = $this->observed($env);
        $this->assertTrue($o['has_inventory']);
        $this->assertArrayNotHasKey('process_instance_id', $o['inventory']['appservers'][0]);
        $this->assertFalse($o['process_instance_capability']);
    }

    public function test_invalid_instance_id_rejected(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // formato inválido (curto / char proibido) → 422 (não persiste lixo como autoridade).
        $this->inventory($id, $sk, $this->inv($this->app(['process_instance_id' => 'short']), time()))->assertStatus(422);
        $this->inventory($id, $sk, $this->inv($this->app(['process_instance_id' => 'has space/and:bad!!']), time()))->assertStatus(422);
    }
}
