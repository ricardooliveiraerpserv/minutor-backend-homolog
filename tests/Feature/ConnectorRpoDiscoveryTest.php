<?php

namespace Tests\Feature;

use App\Connector\ConnectorIdentity;
use App\Models\Customer;
use App\Models\Project;
use App\Models\RpoTargetAppserver;
use App\Models\RpoTopologyObservation;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RPO-DISCOVERY (C5.0) D1+D2 — descoberta observacional + sugestão + confirmação governada. Porta a lógica
 * legada (ini-parser) via Connector, sanitizada. Prova: observação versionada, denylist (zero path), agrupamento
 * por publish_unit_id (multi-target), confirm stale-safe → C5.1, divergência SEM auto-update, isolamento.
 */
class ConnectorRpoDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private int $envA;
    private string $r1 = '11111111-aaaa-4bbb-8ccc-111111111111';
    private string $r2 = '22222222-aaaa-4bbb-8ccc-222222222222';
    private string $r3 = '33333333-aaaa-4bbb-8ccc-333333333333';
    private string $hA;

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
            'connector.operations.observed_freshness' => 120,
            'connector.operations.rpo.executable_activation_modes' => ['hot'],
            'connector.operations.rpo.supported_capabilities' => [['name' => 'rpo_publish', 'contract_version' => 1]],
        ]);
        $this->custA = Customer::factory()->create();
        $this->envA = $this->makeEnv($this->custA);
        $this->hA = str_repeat('a', 64);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeEnv(Customer $c): int
    {
        $vault = Vault::create(['type' => 'client', 'name' => 'Amb ' . $c->id, 'created_by' => null]);
        DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $vault->id, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('env_environments')->insertGetId(['customer_id' => $c->id, 'vault_id' => $vault->id, 'name' => 'Produção', 'type' => 'prod', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(): User { return User::factory()->create(['type' => 'admin']); }

    private function userWith(array $perms, ?Customer $c = null): User
    {
        $c = $c ?: $this->custA;
        $u = User::factory()->create(['type' => 'consultor', 'extra_permissions' => $perms]);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    private function enroll(int $envId): array
    {
        $token = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/environments/{$envId}/connector/enrollment-token")->json('data.enrollment_token');
        $kp = sodium_crypto_sign_keypair();
        $agentId = $this->postJson('/api/v1/connector/enroll', ['enrollment_token' => $token, 'public_key' => base64_encode(sodium_crypto_sign_publickey($kp))])->json('agent_id');
        return [$agentId, sodium_crypto_sign_secretkey($kp)];
    }

    private function sig(string $a, string $sk, string $m, string $p, string $j): array
    {
        $ts = time(); $nonce = bin2hex(random_bytes(9));
        return ['X-Agent-Id' => $a, 'X-Timestamp' => (string) $ts, 'X-Nonce' => $nonce, 'X-Signature' => base64_encode(sodium_crypto_sign_detached(app(ConnectorIdentity::class)->canonicalString($a, $m, $p, $j, $ts, $nonce), $sk)), 'Content-Type' => 'application/json'];
    }

    private function sigPost(string $a, string $sk, string $p, array $b): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1{$p}", $b, $this->sig($a, $sk, 'POST', "/api/v1{$p}", json_encode($b)));
    }

    /** Posta um inventário com bloco topology. $spec: ref => [pu, env, role, role_source, extra?]. */
    private function postTopology(string $a, string $sk, array $spec): \Illuminate\Testing\TestResponse
    {
        $apps = []; $rpo = []; $members = [];
        foreach ($spec as $ref => $s) {
            $apps[] = ['ref' => $ref, 'name' => 'APP-' . substr($ref, 0, 4), 'up' => true, 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12', 'uptime_s' => 50, 'process_instance_id' => 'PI' . substr(md5($ref), 0, 16)];
            $rpo[] = ['appserver_ref' => $ref, 'hash' => $s['hash'] ?? $this->hA, 'version' => 'TTTP', 'publish_unit_id' => $s['pu']];
            $members[] = array_merge([
                'appserver_ref' => $ref, 'environment_name' => $s['env'] ?? 'PRODUCAO',
                'role' => $s['role'] ?? 'slave', 'role_source' => $s['role_source'] ?? 'observed', 'service_name' => $s['svc'] ?? 'AppServerProd',
            ], $s['extra'] ?? []);
        }
        $this->sigPost($a, $sk, '/connector/heartbeat', ['observed_at' => time()])->assertOk();
        $body = ['observed_at' => time(), 'appservers' => $apps, 'rest' => [], 'rpo' => $rpo,
            'capabilities' => [['name' => 'rpo_publish', 'adapter' => 'totvs_x', 'contract_version' => 1, 'operations' => ['promote'], 'activation_mode' => 'hot']],
            'topology' => ['observation_id' => 'obs-' . bin2hex(random_bytes(4)), 'observed_at' => time(), 'members' => $members]];
        return $this->sigPost($a, $sk, '/connector/inventory', $body);
    }

    private function topologyView(int $env): array
    {
        return $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/rpo/topology")->json('data');
    }

    // ── 1. Observação persistida + versionada + join com rpo/appservers. ──
    public function test_topology_observation_persisted_and_versioned(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $obs = RpoTopologyObservation::where('environment_id', $this->envA)->orderByDesc('topology_revision')->first();
        $this->assertNotNull($obs);
        $this->assertSame(1, $obs->topology_revision);        // revisão atribuída pelo BACKEND
        $this->assertSame(64, strlen($obs->topology_fingerprint));
        $this->assertNotNull($obs->backend_received_at);       // autoridade server-side
        $this->assertCount(2, $obs->members);
        $this->assertSame('U1', $obs->members[0]['publish_unit_id']); // join do bloco rpo
        $this->assertTrue($obs->members[0]['up']);                    // join dos appservers
        // segunda coleta → revisão 2
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $this->assertSame(2, RpoTopologyObservation::where('environment_id', $this->envA)->max('topology_revision'));
    }

    // ── 2. Denylist: campos de path extras NÃO persistem (allowlist descarta). ──
    public function test_denylist_no_path_in_observation(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1', 'extra' => ['source_path' => '/opt/protheus/apo', 'ini_path' => 'C:\\TOTVS\\appserver.ini', 'SpecialKey' => 'abc123']]])->assertOk();
        $obs = RpoTopologyObservation::where('environment_id', $this->envA)->first();
        $blob = json_encode($obs->members);
        $this->assertStringNotContainsString('/opt/protheus', $blob);
        $this->assertStringNotContainsString('appserver.ini', $blob);
        $this->assertStringNotContainsString('SpecialKey', $blob);
        $this->assertStringNotContainsString('abc123', $blob);
    }

    // ── 3. Sugestão agrupa por publish_unit_id (multi-target). ──
    public function test_suggestions_group_by_publish_unit(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1'], $this->r3 => ['pu' => 'U2']])->assertOk();
        $v = $this->topologyView($this->envA);
        $sug = collect($v['suggestions']);
        $this->assertCount(2, $sug);
        $u1 = $sug->firstWhere('publish_unit_id', 'U1');
        $u2 = $sug->firstWhere('publish_unit_id', 'U2');
        $this->assertCount(2, $u1['member_refs']);
        $this->assertCount(1, $u2['member_refs']);
        $this->assertSame('suggested_new', $u1['state']);
        // capability é fonte SEPARADA (não misturada na sugestão)
        $this->assertArrayNotHasKey('activation_mode', $u1);
        $this->assertSame('hot', $v['capability']['activation_mode'] ?? null);
    }

    // ── 4. Confirm stale-safe → cria+confirma target C5.1 com EXATAMENTE os membros. ──
    public function test_confirm_creates_c5_target_with_exact_members(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $v = $this->topologyView($this->envA);
        $u = collect($v['suggestions'])->firstWhere('publish_unit_id', 'U1');
        $res = $this->actingAs($this->userWith(['prosight.operations.rpo.manage']), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology/confirm", [
                'publish_unit_id' => 'U1', 'member_refs' => $u['member_refs'],
                'topology_revision' => $v['observation']['topology_revision'], 'topology_fingerprint' => $v['observation']['topology_fingerprint'],
            ])->assertStatus(200);
        $tid = $res->json('data.target_id');
        $this->assertSame('confirmed', $res->json('data.status'));
        $refs = RpoTargetAppserver::where('rpo_target_id', $tid)->pluck('appserver_ref')->map('strval')->sort()->values()->all();
        $this->assertSame([$this->r1, $this->r2], $refs); // exatamente os membros confirmados
        // agora a sugestão de U1 vira already_targeted
        $v2 = $this->topologyView($this->envA);
        $u2 = collect($v2['suggestions'])->firstWhere('publish_unit_id', 'U1');
        $this->assertSame('already_targeted', $u2['state']);
        $this->assertSame($tid, $u2['existing_target_id']);
    }

    // ── 5. Confirm com revisão velha → 409 stale (não confirma nova realidade). ──
    public function test_confirm_stale_revision_is_409(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $v = $this->topologyView($this->envA);
        // nova observação bump revision
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1'], $this->r3 => ['pu' => 'U1']])->assertOk();
        $this->actingAs($this->userWith(['prosight.operations.rpo.manage']), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology/confirm", [
                'publish_unit_id' => 'U1', 'member_refs' => [$this->r1, $this->r2],
                'topology_revision' => $v['observation']['topology_revision'], 'topology_fingerprint' => $v['observation']['topology_fingerprint'],
            ])->assertStatus(409)->assertJson(['error' => 'topology_observation_stale']);
    }

    // ── 6. Confirm com membros divergentes do grupo atual → 409. ──
    public function test_confirm_wrong_members_is_409(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $v = $this->topologyView($this->envA);
        $this->actingAs($this->userWith(['prosight.operations.rpo.manage']), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology/confirm", [
                'publish_unit_id' => 'U1', 'member_refs' => [$this->r1], // subconjunto
                'topology_revision' => $v['observation']['topology_revision'], 'topology_fingerprint' => $v['observation']['topology_fingerprint'],
            ])->assertStatus(409)->assertJson(['error' => 'topology_observation_stale']);
    }

    // ── 7. Divergência pós-confirmação: NÃO auto-altera membership. ──
    public function test_divergence_never_auto_updates(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1']])->assertOk();
        $v = $this->topologyView($this->envA);
        $tid = $this->actingAs($this->userWith(['prosight.operations.rpo.manage']), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology/confirm", [
                'publish_unit_id' => 'U1', 'member_refs' => [$this->r1, $this->r2],
                'topology_revision' => $v['observation']['topology_revision'], 'topology_fingerprint' => $v['observation']['topology_fingerprint'],
            ])->json('data.target_id');
        // nova observação: U1 ganhou APP03
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1'], $this->r2 => ['pu' => 'U1'], $this->r3 => ['pu' => 'U1']])->assertOk();
        $v2 = $this->topologyView($this->envA);
        $div = collect($v2['divergences'])->firstWhere('target_id', $tid);
        $this->assertNotNull($div);
        $this->assertSame('membership_changed', $div['reason']);
        $this->assertCount(3, $div['observed_refs']);
        $this->assertCount(2, $div['confirmed_refs']);
        // membership NÃO mudou (sem auto-update)
        $this->assertSame(2, RpoTargetAppserver::where('rpo_target_id', $tid)->count());
    }

    // ── 8. Isolamento: env de custB não acessível; confirm exige rpo.manage. ──
    public function test_isolation_and_permission(): void
    {
        [$a, $sk] = $this->enroll($this->envA);
        $this->postTopology($a, $sk, [$this->r1 => ['pu' => 'U1']])->assertOk();
        $custB = Customer::factory()->create();
        $ub = $this->userWith(['prosight.operations.rpo.manage'], $custB);
        $this->actingAs($ub, 'sanctum')->getJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology")->assertStatus(404);
        // sem rpo.manage → 403 no confirm
        $this->actingAs($this->userWith(['prosight.operations.rpo.qualify']), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$this->envA}/rpo/topology/confirm", ['publish_unit_id' => 'U1', 'member_refs' => [$this->r1], 'topology_revision' => 1, 'topology_fingerprint' => str_repeat('0', 64)])
            ->assertStatus(403);
    }
}
