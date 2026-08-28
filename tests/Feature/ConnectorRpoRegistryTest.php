<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\ConnectorOperation;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RpoArtifact;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Connector-5.1 — fundação de publicação de RPO (registro/target/qualificação/preview; ZERO publicação).
 * Cobre as 13 gates: mesmo SHA não agrupa target; 1 appserver_ref/target; target inconsistente inelegível;
 * discovered não é destino de preview; registered é; rollback só known_good; known_good contextual;
 * histórico + last_known_good; registered imutável (revisão preserva); capability desconhecida → indisponível
 * (sem fallback); preview NÃO cria operação/execution_id; sem bytes/path.
 */
class ConnectorRpoRegistryTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;
    private string $a1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $a2 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $hA; private string $hB; private string $hX;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.operations.observed_freshness' => 120,
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
            'connector.operations.rpo.required_approvals' => ['prod' => 2, 'default' => 1]]);
        $this->custA = Customer::factory()->create(); $this->custB = Customer::factory()->create();
        $this->hA = str_repeat('a', 64); $this->hB = str_repeat('b', 64); $this->hX = str_repeat('c', 64);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c, string $type = 'prod'): int
    {
        $vault = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $vault->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId(['customer_id' => $c->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => $type, 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function userWith(array $perms, ?Customer $c = null): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => ($c ?? $this->custA)->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    private function enrollAgent(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    /** Publica inventário C-2: appservers (com version) + rpo (hash por appserver_ref) + capabilities. */
    private function observe(string $a, string $sk, array $appRpo, array $caps = [['name' => 'rpo_publish', 'contract_version' => 1, 'adapter' => 'totvs_x', 'operations' => ['promote', 'rollback']]]): void
    {
        $apps = []; $rpo = [];
        foreach ($appRpo as $ref => [$ver, $hash]) {
            $apps[] = ['ref' => $ref, 'name' => 'APP', 'up' => true, 'version' => $ver, 'build' => '9999', 'patch' => '12', 'uptime_s' => 50, 'process_instance_id' => 'PI' . substr(md5($ref), 0, 20)];
            if ($hash) { $rpo[] = ['appserver_ref' => $ref, 'hash' => $hash, 'version' => 'TTTP']; }
        }
        $body = ['observed_at' => time(), 'appservers' => $apps, 'rest' => [], 'rpo' => $rpo, 'capabilities' => $caps];
        $json = json_encode($body); $ts = time(); $nonce = bin2hex(random_bytes(9));
        $sig = base64_encode(sodium_crypto_sign_detached(app(ConnectorIdentity::class)->canonicalString($a, 'POST', '/api/v1/connector/inventory', $json, $ts, $nonce), $sk));
        $this->postJson('/api/v1/connector/inventory', $body, ['X-Agent-Id' => $a, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => $sig, 'Content-Type' => 'application/json'])->assertOk();
    }

    private function mgr(): User { return $this->userWith(['prosight.operations.rpo.manage', 'prosight.operations.rpo.qualify', 'prosight.operations.view']); }
    private function reg(int $env, string $hash, array $over = []): int
    {
        return $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register",
            array_merge(['hash' => $hash, 'provenance' => 'GMUD-123', 'compatibility' => ['appserver_versions' => ['12.1.2410']], 'version' => 'TTTP001'], $over))->assertStatus(201)->json('data.id');
    }
    private function target(int $env, array $refs): int
    {
        return $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'fin', 'appserver_refs' => $refs])->assertStatus(201)->json('data.id');
    }
    private function preview(int $tid, int $to, bool $rb = false): array
    {
        return $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/preview", ['to_artifact_id' => $to, 'is_rollback' => $rb])->json('data');
    }

    // ── testes ──────────────────────────────────────────────────────────────

    public function test_capability_unknown_version_unavailable_no_fallback(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]], [['name' => 'rpo_publish', 'contract_version' => 1]]);
        $this->assertTrue($this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/rpo/capability")->json('data.available'));
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]], [['name' => 'rpo_publish', 'contract_version' => 99]]); // versão desconhecida
        $this->assertFalse($this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/rpo/capability")->json('data.available'));
    }

    public function test_discovered_not_registered_and_no_auto_target(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA], $this->a2 => ['12.1.2410', $this->hA]]); // mesmo SHA
        $arts = $this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/rpo/artifacts")->json('data');
        $this->assertContains($this->hA, array_column($arts['discovered'], 'hash'));
        $this->assertEmpty($arts['registered']);
        // mesmo SHA em A1 e A2 NÃO cria target automaticamente (targets são cadastrais).
        $this->assertEmpty($this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/rpo/targets")->json('data.targets'));
    }

    public function test_register_immutable_and_revision_preserves(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $id = $this->reg($env, $this->hA);
        // correção → NOVA revisão (não edita)
        $newId = $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/artifacts/{$id}/revise", ['provenance' => 'GMUD-123-fix', 'compatibility' => ['appserver_versions' => ['12.1.2410', '12.1.2500']]])->assertStatus(201)->json('data');
        $this->assertSame(2, $newId['revision']);
        $this->assertSame($this->hA, $newId['hash']); // hash imutável (mesma identidade)
        $this->assertSame($id, $newId['supersedes_id']);
        $this->assertNotNull(RpoArtifact::find($id)->superseded_by_id); // anterior PRESERVADA e superseded
        // segunda revisão sobre a antiga → 409
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/artifacts/{$id}/revise", ['provenance' => 'x', 'compatibility' => ['appserver_versions' => ['12.1.2410']]])->assertStatus(409);
    }

    public function test_register_requires_provenance_and_compatibility(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $this->hA, 'compatibility' => ['appserver_versions' => ['12.1.2410']]])->assertStatus(422); // sem provenance
    }

    public function test_appserver_exclusive_to_one_target(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA], $this->a2 => ['12.1.2410', $this->hA]]);
        $this->target($env, [$this->a1]);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/targets", ['name' => 'x', 'appserver_refs' => [$this->a1]])->assertStatus(409)->assertJsonPath('error', 'appserver_already_in_target');
    }

    public function test_inconsistent_target_not_confirmable_nor_eligible(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA], $this->a2 => ['12.1.2410', $this->hX]]); // A1=hA, A2=hX (inconsistente)
        $tid = $this->target($env, [$this->a1, $this->a2]);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertStatus(422)->assertJsonPath('error', 'target_not_consistent');
        $to = $this->reg($env, $this->hB);
        $this->assertFalse($this->preview($tid, $to)['eligible']);
        $this->assertContains('target_not_consistent', $this->preview($tid, $to)['reasons']);
    }

    public function test_discovered_not_preview_target_registered_is(): void
    {
        $env = $this->makeEnv($this->custA, 'homolog'); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $tid = $this->target($env, [$this->a1]);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk();
        // preview p/ hash discovered (sem artefato) → 404 (não é destino válido)
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/preview", ['to_artifact_id' => 999999])->assertStatus(404);
        // registered compatível → elegível
        $to = $this->reg($env, $this->hB);
        $p = $this->preview($tid, $to);
        $this->assertTrue($p['eligible'], json_encode($p['reasons']));
        $this->assertSame($this->hA, $p['from']['hash']); $this->assertSame($this->hB, $p['to']['hash']);
        $this->assertSame(1, $p['policy_snapshot']['required_approvals']); // homolog=1
    }

    public function test_rollback_requires_known_good(): void
    {
        $env = $this->makeEnv($this->custA, 'homolog'); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $tid = $this->target($env, [$this->a1]);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk();
        $to = $this->reg($env, $this->hB);
        $this->assertContains('rollback_target_not_known_good', $this->preview($tid, $to, true)['reasons']); // registered ≠ rollback elegível
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $to, 'reason' => 'validado'])->assertStatus(201);
        $this->assertNotContains('rollback_target_not_known_good', $this->preview($tid, $to, true)['reasons']); // agora known_good
    }

    public function test_known_good_is_contextual(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA], $this->a2 => ['12.1.2410', $this->hB]]);
        $t1 = $this->target($env, [$this->a1]); $t2 = $this->target($env, [$this->a2]);
        $art = $this->reg($env, $this->hX);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$t1}/qualify", ['artifact_id' => $art, 'reason' => 'ok'])->assertStatus(201);
        // known_good em t1 NÃO torna known_good em t2
        $this->assertNotNull($this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$t1}/qualifications")->json('data.last_known_good'));
        $this->assertNull($this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$t2}/qualifications")->json('data.last_known_good'));
    }

    public function test_qualification_history_and_last_known_good(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $tid = $this->target($env, [$this->a1]);
        $A = $this->reg($env, $this->hA); $B = $this->reg($env, $this->hB);
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $A, 'reason' => 'A ok'])->assertStatus(201);
        $qB = $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $B, 'reason' => 'B ok'])->assertStatus(201)->json('data.id');
        $d = $this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$tid}/qualifications")->json('data');
        $this->assertCount(2, $d['history']); // histórico preservado
        $this->assertSame($B, $d['last_known_good']['rpo_artifact_id']); // deriva da mais recente
        // revoga B → last_known_good volta a A
        $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/qualifications/{$qB}/revoke")->assertOk();
        $this->assertSame($A, $this->actingAs($this->mgr(), 'sanctum')->getJson("/api/v1/prosight/rpo/targets/{$tid}/qualifications")->json('data.last_known_good.rpo_artifact_id'));
    }

    public function test_incompatible_version_blocks_preview(): void
    {
        $env = $this->makeEnv($this->custA, 'homolog'); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]); // observado 12.1.2410
        $tid = $this->target($env, [$this->a1]); $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk();
        $to = $this->reg($env, $this->hB, ['compatibility' => ['appserver_versions' => ['12.1.9999']]]); // incompatível
        $this->assertContains('incompatible_appserver_version', $this->preview($tid, $to)['reasons']);
    }

    public function test_preview_creates_no_operation(): void
    {
        $env = $this->makeEnv($this->custA, 'homolog'); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $tid = $this->target($env, [$this->a1]); $this->actingAs($this->mgr(), 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/confirm")->assertOk();
        $to = $this->reg($env, $this->hB);
        $before = ConnectorOperation::count();
        $this->preview($tid, $to);
        $this->assertSame($before, ConnectorOperation::count()); // ZERO operação/execution_id/claim
    }

    public function test_permissions_manage_and_qualify(): void
    {
        $env = $this->makeEnv($this->custA); [$a, $sk] = $this->enrollAgent($env);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $viewer = $this->userWith(['prosight.operations.view']);
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/prosight/environments/{$env}/rpo/artifacts/register", ['hash' => $this->hA, 'provenance' => 'p', 'compatibility' => ['appserver_versions' => ['12.1.2410']]])->assertStatus(403);
        $onlyManage = $this->userWith(['prosight.operations.rpo.manage']);
        $tid = $this->target($env, [$this->a1]);
        $art = $this->reg($env, $this->hA);
        $this->actingAs($onlyManage, 'sanctum')->postJson("/api/v1/prosight/rpo/targets/{$tid}/qualify", ['artifact_id' => $art, 'reason' => 'x'])->assertStatus(403); // manage ≠ qualify
    }

    public function test_anti_idor_cross_customer(): void
    {
        $envB = $this->makeEnv($this->custB); [$a, $sk] = $this->enrollAgent($envB);
        $this->observe($a, $sk, [$this->a1 => ['12.1.2410', $this->hA]]);
        $coordA = $this->userWith(['prosight.operations.rpo.manage', 'prosight.operations.view'], $this->custA);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/{$envB}/rpo/artifacts")->assertStatus(404);
        $this->actingAs($coordA, 'sanctum')->postJson("/api/v1/prosight/environments/{$envB}/rpo/targets", ['name' => 'x', 'appserver_refs' => [$this->a1]])->assertStatus(404);
    }
}
