<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorEvent;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fix cirúrgico C-2: AppServer sem version/build/patch é OPCIONAL — não derruba a coleta (era 422
 * "Undefined array key") e não gera version_changed FALSO por ausência. Só emite quando há informação
 * comparável suficiente (mesmo campo presente nos dois lados e realmente diferente). Sem schema, sem C4.0.
 */
class ConnectorInventoryOptionalFieldsTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
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

    private function inventory(string $agentId, string $sk, array $app, int $obsAt): \Illuminate\Testing\TestResponse
    {
        $body = ['observed_at' => $obsAt, 'appservers' => [$app], 'rest' => [], 'rpo' => []];
        $json = json_encode($body);
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, 'POST', '/api/v1/connector/inventory', $json, $ts, $nonce);
        $sig = base64_encode(sodium_crypto_sign_detached($canonical, $sk));
        return $this->postJson('/api/v1/connector/inventory', $body, ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Content-Type' => 'application/json']);
    }

    /** AppServer base up; remova chaves para simular ausência. */
    private function app(array $over = [], array $unset = []): array
    {
        $a = array_merge(['ref' => $this->ref1, 'name' => 'APP01', 'up' => true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 100], $over);
        foreach ($unset as $k) { unset($a[$k]); }
        return $a;
    }

    private function vc(int $env): int
    {
        return ConnectorEvent::where('environment_id', $env)->where('event_type', 'version_changed')->count();
    }

    // ── gate ────────────────────────────────────────────────────────────────

    public function test_1_full_fields_behave_as_before(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env); $t = time();
        $this->inventory($id, $sk, $this->app(), $t)->assertOk();
        $this->inventory($id, $sk, $this->app(), $t + 1)->assertOk(); // idêntico → sem version_changed
        $this->assertSame(0, $this->vc($env));
        $this->inventory($id, $sk, $this->app(['version' => '12.1.2500']), $t + 2)->assertOk(); // muda → 1
        $this->assertSame(1, $this->vc($env));
    }

    public function test_2_missing_version_applies(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->app([], ['version']), time())->assertOk()->assertJsonPath('applied', true);
    }

    public function test_3_missing_build_applies(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->app([], ['build']), time())->assertOk()->assertJsonPath('applied', true);
    }

    public function test_4_missing_patch_applies(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->app([], ['patch']), time())->assertOk()->assertJsonPath('applied', true);
    }

    public function test_5_missing_all_three_applies(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env);
        $this->inventory($id, $sk, $this->app([], ['version', 'build', 'patch']), time())->assertOk()->assertJsonPath('applied', true);
    }

    public function test_6_absence_does_not_emit_false_version_changed(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env); $t = time();
        $this->inventory($id, $sk, $this->app(), $t)->assertOk();                 // 2410·9999·12
        $this->inventory($id, $sk, $this->app([], ['version']), $t + 1)->assertOk(); // some a version → NÃO é mudança
        $this->inventory($id, $sk, $this->app([], ['build']), $t + 2)->assertOk();   // some o build → NÃO é mudança
        $this->inventory($id, $sk, $this->app([], ['version', 'build', 'patch']), $t + 3)->assertOk();
        $this->assertSame(0, $this->vc($env)); // nenhuma mudança FALSA por ausência de dado
    }

    public function test_7_reappearing_real_difference_emits(): void
    {
        $env = $this->makeEnv($this->custA); [$id, $sk] = $this->enrollAgent($env); $t = time();
        $this->inventory($id, $sk, $this->app(), $t)->assertOk();                    // 2410
        // valor presente nos dois lados e REALMENTE diferente → version_changed funciona.
        $this->inventory($id, $sk, $this->app(['version' => '12.1.2600']), $t + 1)->assertOk();
        $this->assertSame(1, $this->vc($env));
    }
}
