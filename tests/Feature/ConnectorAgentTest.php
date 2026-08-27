<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAgent;
use App\Models\ConnectorEnrollmentToken;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Conector-0 — enrollment, identidade Ed25519, assinatura AGENT-V1, replay, revogação.
 * Prova: enroll atômico/uso-único, 1 agente ativo por ambiente, validação de chave, vetor de
 * assinatura estático, timestamp/nonce/assinatura/revogado, 401 genérico, permissão+escopo admin,
 * fail-closed do nonce. NÃO há heartbeat/estado/comando.
 */
class ConnectorAgentTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;
    private string $whoamiPath = '/api/v1/connector/whoami';

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

    private function admin(): User
    {
        return User::factory()->create(['type' => 'admin']);
    }

    /** Emite token via API admin e devolve o token em claro. */
    private function issueToken(User $u, int $envId): string
    {
        return $this->actingAs($u, 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")
            ->assertCreated()->json('data.enrollment_token');
    }

    /** @return array{0:string,1:string} [public_key_b64, secret_key_raw] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [base64_encode(sodium_crypto_sign_publickey($kp)), sodium_crypto_sign_secretkey($kp)];
    }

    private function enroll(string $token, string $pubKeyB64): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => $pubKeyB64, 'agent_version' => '0.1.0']);
    }

    private function signedHeaders(string $agentId, string $sk, int $ts, string $nonce, string $method = 'GET', string $path = null, string $body = ''): array
    {
        $path ??= $this->whoamiPath;
        $canonical = app(ConnectorIdentity::class)->canonicalString($agentId, $method, $path, $body, $ts, $nonce);
        $sig = sodium_crypto_sign_detached($canonical, $sk);
        return ['X-Agent-Id' => $agentId, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => base64_encode($sig)];
    }

    // ── enrollment ────────────────────────────────────────────────────────────

    public function test_issue_and_enroll_happy_path(): void
    {
        $env = $this->makeEnv($this->custA);
        $token = $this->issueToken($this->admin(), $env);
        [$pk] = $this->keypair();

        $r = $this->enroll($token, $pk)->assertCreated();
        $this->assertNotEmpty($r->json('agent_id'));
        $this->assertDatabaseCount('connector_agents', 1);
        $this->assertNotNull(ConnectorEnrollmentToken::where('token_hash', hash('sha256', $token))->first()->consumed_at);
    }

    public function test_enroll_token_expired(): void
    {
        $env = $this->makeEnv($this->custA);
        ConnectorEnrollmentToken::create(['token_hash' => hash('sha256', 'TOKEXP'), 'customer_id' => $this->custA->id, 'environment_id' => $env, 'expires_at' => now()->subMinute()]);
        [$pk] = $this->keypair();
        $this->enroll('TOKEXP', $pk)->assertStatus(401);
    }

    public function test_enroll_single_use(): void
    {
        $env = $this->makeEnv($this->custA);
        $token = $this->issueToken($this->admin(), $env);
        [$pk] = $this->keypair();
        $this->enroll($token, $pk)->assertCreated();
        // segundo uso do MESMO token → rejeitado; exatamente 1 agente.
        $this->enroll($token, $this->keypair()[0])->assertStatus(401);
        $this->assertDatabaseCount('connector_agents', 1);
    }

    public function test_one_active_agent_per_environment(): void
    {
        $env = $this->makeEnv($this->custA);
        $admin = $this->admin();
        $tokenA = $this->issueToken($admin, $env);
        $tokenB = $this->issueToken($admin, $env); // segundo token, mesmo ambiente
        $this->enroll($tokenA, $this->keypair()[0])->assertCreated();
        // token diferente, mesmo ambiente → 409 (unique parcial); só um agente ativo.
        $this->enroll($tokenB, $this->keypair()[0])->assertStatus(409);
        $this->assertSame(1, ConnectorAgent::where('environment_id', $env)->whereNull('revoked_at')->count());
    }

    public function test_reenroll_requires_revocation(): void
    {
        $env = $this->makeEnv($this->custA);
        $admin = $this->admin();
        $agentId = $this->enroll($this->issueToken($admin, $env), $this->keypair()[0])->json('agent_id');
        // revoga → ambiente liberado → novo enroll OK.
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/prosight/connector/agents/{$agentId}")->assertOk();
        $this->enroll($this->issueToken($admin, $env), $this->keypair()[0])->assertCreated();
    }

    public function test_public_key_validation(): void
    {
        $env = $this->makeEnv($this->custA);
        $admin = $this->admin();
        $this->enroll($this->issueToken($admin, $env), base64_encode(str_repeat('x', 16)))->assertStatus(422); // tamanho errado
        $this->enroll($this->issueToken($admin, $env), '@@@naobase64@@@')->assertStatus(422);
        $this->enroll($this->issueToken($admin, $env), "-----BEGIN PUBLIC KEY-----\nMFA...\n-----END PUBLIC KEY-----")->assertStatus(422); // PEM rejeitado
    }

    // ── assinatura AGENT-V1 (vetor estático) ──────────────────────────────────

    public function test_agent_v1_signature_static_vector(): void
    {
        // seed fixo 32×0x01 → pubkey/sig conhecidos (trava canonicalização Go↔PHP).
        $kp = sodium_crypto_sign_seed_keypair(str_repeat("\x01", 32));
        $pk = sodium_crypto_sign_publickey($kp);
        $id = app(ConnectorIdentity::class);
        $canonical = $id->canonicalString('11111111-1111-1111-1111-111111111111', 'GET', $this->whoamiPath, '', 1735689600, 'nonce0123456789abcd');
        $sigB64 = 'l0/k6Qui4mm23bV9hnKSp5HftDeBsU7t3vZMLuGb34tuM5OdLI2TAB9wtonMfI6VqjXVbx8+FrPzMAZvRycBDg==';
        $this->assertTrue($id->verify($pk, $sigB64, $canonical), 'vetor AGENT-V1 deve verificar');
        // canônica adulterada → falha.
        $this->assertFalse($id->verify($pk, $sigB64, $canonical . 'x'));
        $this->assertSame('34750f98bd59fcfc946da45aaabe933be154a4b5094e1c4abf42866505f3c97e', $id->fingerprint($pk));
    }

    // ── whoami (canal assinado) ───────────────────────────────────────────────

    private function enrollAgent(Customer $c): array
    {
        $env = $this->makeEnv($c);
        $token = $this->issueToken($this->admin(), $env);
        [$pk, $sk] = $this->keypair();
        $agentId = $this->enroll($token, $pk)->json('agent_id');
        return [$agentId, $sk, $env];
    }

    public function test_whoami_valid_signature(): void
    {
        [$agentId, $sk] = $this->enrollAgent($this->custA);
        $h = $this->signedHeaders($agentId, $sk, time(), bin2hex(random_bytes(9)));
        $this->get($this->whoamiPath, $h)->assertOk()->assertJsonPath('data.agent_id', $agentId);
    }

    public function test_whoami_bad_signature(): void
    {
        [$agentId, $sk] = $this->enrollAgent($this->custA);
        $h = $this->signedHeaders($agentId, $sk, time(), bin2hex(random_bytes(9)));
        $h['X-Signature'] = base64_encode(random_bytes(64)); // assinatura errada
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    public function test_whoami_expired_timestamp(): void
    {
        [$agentId, $sk] = $this->enrollAgent($this->custA);
        $h = $this->signedHeaders($agentId, $sk, time() - 3600, bin2hex(random_bytes(9))); // fora da janela
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    public function test_whoami_replayed_nonce(): void
    {
        [$agentId, $sk] = $this->enrollAgent($this->custA);
        $nonce = bin2hex(random_bytes(9));
        $h = $this->signedHeaders($agentId, $sk, time(), $nonce);
        $this->get($this->whoamiPath, $h)->assertOk();
        // mesmo nonce de novo → replay.
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    public function test_whoami_revoked_agent(): void
    {
        [$agentId, $sk, $env] = $this->enrollAgent($this->custA);
        ConnectorAgent::where('agent_id', $agentId)->update(['revoked_at' => now()]);
        $h = $this->signedHeaders($agentId, $sk, time(), bin2hex(random_bytes(9)));
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    public function test_whoami_malformed_and_unknown(): void
    {
        $this->get($this->whoamiPath, ['X-Agent-Id' => 'notauuid', 'X-Timestamp' => 'x', 'X-Nonce' => 'a', 'X-Signature' => 'b'])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
        // headers bem-formados, agente inexistente.
        $h = $this->signedHeaders('11111111-1111-1111-1111-111111111111', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), time(), bin2hex(random_bytes(9)));
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    public function test_whoami_nonce_store_unavailable_fails_closed(): void
    {
        [$agentId, $sk] = $this->enrollAgent($this->custA);
        config(['connector.nonce_store' => 'store_inexistente']); // Cache::store lança → fail closed
        $h = $this->signedHeaders($agentId, $sk, time(), bin2hex(random_bytes(9)));
        $this->get($this->whoamiPath, $h)->assertStatus(401)->assertJsonPath('error', 'invalid_agent_auth');
    }

    // ── admin: permissão + escopo ─────────────────────────────────────────────

    public function test_admin_permission_and_scope(): void
    {
        $env = $this->makeEnv($this->custA);
        // sem prosight.operations.manage → 403.
        $coord = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => ['prosight.environments.view']]);
        $this->actingAs($coord, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/connector/enrollment-token")->assertStatus(403);
        // admin com escopo → 201.
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/connector/enrollment-token")->assertCreated();
        // ambiente inexistente → 404.
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/v1/prosight/environments/999999/connector/enrollment-token')->assertStatus(404);
    }

    public function test_scoped_admin_cross_customer_404(): void
    {
        $envB = $this->makeEnv($this->custB);
        // administrativo com operations.manage, mas SEM escopo em B → 404 (não revela).
        $u = User::factory()->create(['type' => 'coordenador', 'extra_permissions' => ['prosight.operations.manage']]);
        $proj = \App\Models\Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_coordinators')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($u, 'sanctum')->postJson("/api/v1/prosight/environments/{$envB}/connector/enrollment-token")->assertStatus(404);
    }
}
