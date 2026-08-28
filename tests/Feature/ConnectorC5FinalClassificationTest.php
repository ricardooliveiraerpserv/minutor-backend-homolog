<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C5-FINAL — classificação test|demo|operational (metadata). Prova que é SÓ classificação/auditoria: uma
 * operação classification=test continua sujeita a permissions → approvals (maker-checker/N-of-M) → locks
 * (1-op-viva) → journal → at-most-once → reconciliation. Metadata NUNCA relaxa segurança.
 */
class ConnectorC5FinalClassificationTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private string $r1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $r2 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $hA; private string $hB;

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
            'connector.operations.require_approval' => true, 'connector.operations.observed_freshness' => 120,
            'connector.operations.rpo.executable_activation_modes' => ['hot'],
            'connector.operations.rpo.required_approvals' => ['prod' => 1, 'default' => 1],
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
            'connector.presence_online' => 75,
        ]);
        $this->custA = Customer::factory()->create();
        $this->hA = str_repeat('a', 64); $this->hB = str_repeat('b', 64);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) { if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); } }
        return '';
    }
    private function makeEnv(): int
    {
        $vault = Vault::create(['type' => 'client', 'name' => 'Amb', 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $this->custA->id, 'vault_id' => $vault->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId(['customer_id' => $this->custA->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
    }
    private function admin(): User { return User::factory()->create(['type' => 'admin']); }
    private function userWith(array $perms): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }
    private function enroll(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $id = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$id, sodium_crypto_sign_secretkey($kp)];
    }
    private function observe(string $a, string $sk): void
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        $sigH = fn ($m, $p, $j) => ['X-Agent-Id' => $a, 'X-Timestamp' => (string) time(), 'X-Nonce' => bin2hex(random_bytes(9))];
        $apps = [];
        foreach ([$this->r1, $this->r2] as $ref) { $apps[] = ['ref' => $ref, 'name' => 'APP', 'up' => true, 'version' => '12.1.2410', 'build' => '9', 'patch' => '1', 'uptime_s' => 50, 'process_instance_id' => 'PI' . substr(md5($ref), 0, 18)]; }
        $rpo = [['appserver_ref' => $this->r1, 'hash' => $this->hA, 'version' => 'T', 'publish_unit_id' => 'U1'], ['appserver_ref' => $this->r2, 'hash' => $this->hA, 'version' => 'T', 'publish_unit_id' => 'U1']];
        $this->signed($a, $sk, 'POST', '/connector/heartbeat', ['observed_at' => time()]);
        $this->signed($a, $sk, 'POST', '/connector/inventory', ['observed_at' => time(), 'appservers' => $apps, 'rest' => [], 'rpo' => $rpo, 'capabilities' => [['name' => 'rpo_publish', 'adapter' => 'x', 'contract_version' => 1, 'operations' => ['promote'], 'activation_mode' => 'hot']]]);
    }
    private function signed(string $a, string $sk, string $m, string $p, array $b): \Illuminate\Testing\TestResponse
    {
        $full = "/api/v1{$p}"; $ts = time(); $nonce = bin2hex(random_bytes(9)); $j = json_encode($b);
        $h = ['X-Agent-Id' => $a, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => base64_encode(sodium_crypto_sign_detached(app(ConnectorIdentity::class)->canonicalString($a, $m, $full, $j, $ts, $nonce), $sk)), 'Content-Type' => 'application/json'];
        return $this->postJson($full, $b, $h);
    }
    private function register(int $env, string $hash, ?string $cls): array { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", array_filter(['hash' => $hash, 'provenance' => 'G', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'version' => 'T', 'classification' => $cls]))->json('data'); }
    private function createTarget(int $env, ?string $cls): array { return $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", array_filter(['name' => 'fin', 'appserver_refs' => [$this->r1, $this->r2], 'classification' => $cls]))->json('data'); }

    public function test_classification_stored_and_exposed_but_never_bypasses_security(): void
    {
        $env = $this->makeEnv(); [$a, $sk] = $this->enroll($env);
        $this->observe($a, $sk);
        // registros com classification=test são armazenados e expostos
        $artB = $this->register($env, $this->hB, 'test');
        $this->assertSame('test', $artB['classification']);
        $tgt = $this->createTarget($env, 'test');
        $this->assertSame('test', $tgt['classification']);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tgt['id']}/confirm")->assertOk();

        // promote classification=test → NÃO auto-executa: nasce pending_approval (segurança intacta)
        $maker = $this->userWith(['prosight.operations.rpo.promote', 'prosight.operations.rpo.approve']);
        $op = $this->actingAs($maker, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tgt['id']}/promote", ['to_artifact_id' => $artB['id'], 'reason' => 'gate test', 'classification' => 'test'])->assertStatus(201)->json('data');
        $this->assertSame('test', $op['classification']);
        $this->assertSame('pending_approval', $op['status']); // metadata NÃO libera execução

        // maker-checker AINDA vale: maker não aprova a própria op (mesmo test)
        $this->actingAs($maker, 'sanctum')->postJson("/api/v1/prosight/operations/{$op['id']}/approve")->assertStatus(422)->assertJsonPath('error', 'maker_cannot_approve');

        // lock 1-op-viva AINDA vale: 2ª destrutiva (mesmo test) bloqueada
        $this->actingAs($this->userWith(['prosight.operations.rpo.promote']), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tgt['id']}/promote", ['to_artifact_id' => $artB['id'], 'reason' => 'x', 'classification' => 'test'])->assertStatus(409)->assertJsonPath('error', 'operation_in_flight');

        // sem permissão: 403 mesmo com classification=test
        $this->actingAs($this->userWith([]), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tgt['id']}/promote", ['to_artifact_id' => $artB['id'], 'reason' => 'x', 'classification' => 'test'])->assertStatus(403);
    }

    public function test_classification_invalid_value_rejected(): void
    {
        $env = $this->makeEnv(); [$a, $sk] = $this->enroll($env);
        $this->observe($a, $sk);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $this->hB, 'provenance' => 'G', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'classification' => 'bypass'])->assertStatus(422);
    }

    public function test_classification_defaults_null_operational(): void
    {
        $env = $this->makeEnv(); [$a, $sk] = $this->enroll($env);
        $this->observe($a, $sk);
        $art = $this->register($env, $this->hB, null); // sem classification
        $this->assertNull($art['classification']); // null = operational
    }
}
