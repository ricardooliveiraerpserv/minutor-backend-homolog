<?php

namespace Tests\Feature;

use App\Connector\ConnectorCommandService;
use App\Connector\ConnectorIdentity;
use App\Models\ConnectorAgent;
use App\Models\ConnectorCommand;
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
 * Connector-3 — orquestração de comandos assíncronos NÃO destrutivos (collect_inventory_now).
 * Prova: enfileira→claim→result; attempts++ NO CLAIM (uma vez); retry BOUNDED (lease perdido → no
 * máx. um retry → expired); stale_result após re-claim; correlação FORTE por trigger.command_id
 * (temporal NÃO basta; cross-env/agent não correlaciona); reexecução idempotente no C-2; cancelamento
 * simples (queued→canceled, claimed→409); allowlist (destrutivos→422); autorização execute; anti-IDOR;
 * timeline operacoes; duplicado coalesce. AT-LEAST-ONCE (não exactly-once) explícito.
 */
class ConnectorCommandTest extends TestCase
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
        config([
            'cache.default' => 'array', 'multiempresa.scoping_enabled' => false, 'connector.nonce_store' => null,
            'connector.commands.longpoll_hold' => 0, // short-poll nos testes (sem sleep)
            'connector.commands.max_attempts' => 2, 'connector.commands.claim_lease' => 15,
            'connector.commands.debounce' => 30,
        ]);
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

    private function agentModel(string $agentId): ConnectorAgent { return ConnectorAgent::where('agent_id', $agentId)->firstOrFail(); }

    /** Admin cria comando (perm execute via admin). */
    private function createCmd(int $envId, string $type = 'collect_inventory_now', ?User $as = null, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin(), 'sanctum')
            ->postJson("/api/v1/prosight/environments/{$envId}/commands", array_merge(['command_type' => $type], $extra));
    }

    /** Agente faz long-poll (short-poll com hold=0) e reivindica. GET assinado sobre body VAZIO (get(), não getJson). */
    private function next(string $agentId, string $sk): \Illuminate\Testing\TestResponse
    {
        return $this->get('/api/v1/connector/commands/next', $this->signed($agentId, $sk, 'GET', '/api/v1/connector/commands/next', ''));
    }

    private function postResult(string $agentId, string $sk, int $cid, array $body): \Illuminate\Testing\TestResponse
    {
        $path = "/api/v1/connector/commands/{$cid}/result"; $json = json_encode($body);
        return $this->postJson("/api/v1/connector/commands/{$cid}/result", $body, $this->signed($agentId, $sk, 'POST', $path, $json));
    }

    private function inventory(string $agentId, string $sk, array $body): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($body);
        return $this->postJson('/api/v1/connector/inventory', $body, $this->signed($agentId, $sk, 'POST', '/api/v1/connector/inventory', $json));
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

    public function test_enqueue_claim_result_and_timeline(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $cid = $this->createCmd($env)->assertStatus(201)->assertJsonPath('data.status', 'queued')->json('data.id');
        $this->assertSame(1, ConnectorEvent::where('environment_id', $env)->where('event_type', 'command_enqueued')->count());

        $claim = $this->next($id, $sk)->assertOk()->json('data');
        $this->assertSame($cid, $claim['id']);
        $this->assertSame(1, $claim['attempt']); // attempts++ NO CLAIM
        $this->assertNotEmpty($claim['claim_token']);

        // agente executa: sobe inventário pelo canal C-2 com CAUSALIDADE (trigger.command_id) + result.
        $this->inventory($id, $sk, $this->inv(['trigger' => ['type' => 'command', 'command_id' => $cid]]))->assertOk();
        $this->postResult($id, $sk, $cid, ['claim_token' => $claim['claim_token'], 'outcome' => 'ok', 'duration_ms' => 42])->assertOk()->assertJsonPath('status', 'succeeded');

        $cmd = ConnectorCommand::find($cid);
        $this->assertSame('succeeded', $cmd->status);
        $this->assertNotNull($cmd->inventory_applied_at); // correlação FORTE registrada
        // timeline operacoes tem os connector-events do comando.
        $items = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/v1/source-docs/activity?family=operacoes')->assertOk()->json('data.items');
        $this->assertContains('connector-event', array_column($items, 'kind'));
        $types = collect($items)->pluck('native.event_type')->all();
        $this->assertContains('command_succeeded', $types);
    }

    public function test_attempts_increments_once_per_claim_no_double(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $cid = $this->createCmd($env)->json('data.id');
        $this->next($id, $sk)->assertOk(); // claim 1
        $this->assertSame(1, ConnectorCommand::find($cid)->attempts);
        // segundo poll: nada a reivindicar (não incrementa de novo) → 204.
        $this->next($id, $sk)->assertNoContent();
        $this->assertSame(1, ConnectorCommand::find($cid)->attempts);
        // concorrência real garantida por FOR UPDATE SKIP LOCKED no claim atômico.
    }

    public function test_lease_lost_allows_one_bounded_retry_then_expires(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $agent = $this->agentModel($id);
        $svc = app(ConnectorCommandService::class);
        $cid = $this->createCmd($env)->json('data.id');

        $c1 = $svc->claimNext($agent); // attempts=1
        $this->assertSame(1, $c1->attempts);
        ConnectorCommand::whereKey($cid)->update(['claim_expires_at' => now()->subSeconds(5)]); // lease perdido
        $svc->reapEnvironment($env);
        $this->assertSame('queued', ConnectorCommand::find($cid)->status); // reenfileirado (1<2)

        $c2 = $svc->claimNext($agent); // attempts=2
        $this->assertSame(2, $c2->attempts);
        ConnectorCommand::whereKey($cid)->update(['claim_expires_at' => now()->subSeconds(5)]);
        $svc->reapEnvironment($env);
        $final = ConnectorCommand::find($cid);
        $this->assertSame('expired', $final->status); // retries esgotados
        $this->assertSame(2, $final->attempts);       // NUNCA ultrapassa max_attempts
    }

    public function test_stale_result_after_new_claim_is_409(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $agent = $this->agentModel($id);
        $svc = app(ConnectorCommandService::class);
        $cid = $this->createCmd($env)->json('data.id');

        $c1 = $svc->claimNext($agent); $token1 = $c1->claim_token;
        ConnectorCommand::whereKey($cid)->update(['claim_expires_at' => now()->subSeconds(5)]);
        $svc->reapEnvironment($env); // re-queued (attempts=1)
        $c2 = $svc->claimNext($agent); $token2 = $c2->claim_token; // novo claim (attempts=2), novo token
        $this->assertNotSame($token1, $token2);

        // resultado do CLAIM ANTIGO → 409 stale_result, sem alterar estado.
        $this->postResult($id, $sk, $cid, ['claim_token' => $token1, 'outcome' => 'ok'])->assertStatus(409)->assertJsonPath('error', 'stale_result');
        $this->assertSame('claimed', ConnectorCommand::find($cid)->status);
        // resultado do claim VIGENTE → ok.
        $this->postResult($id, $sk, $cid, ['claim_token' => $token2, 'outcome' => 'ok'])->assertOk();
        $this->assertSame('succeeded', ConnectorCommand::find($cid)->status);
    }

    public function test_periodic_inventory_does_not_satisfy_correlation(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        $svc = app(ConnectorCommandService::class);
        $cid = $this->createCmd($env)->json('data.id');
        $svc->claimNext($this->agentModel($id)); // claimed

        // inventário PERIÓDICO (scheduled) durante o comando → NÃO correlaciona.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 1, 'trigger' => ['type' => 'scheduled']]))->assertOk();
        $this->assertNull(ConnectorCommand::find($cid)->inventory_applied_at);
        // inventário com trigger.command_id correto → correlaciona.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 2, 'trigger' => ['type' => 'command', 'command_id' => $cid]]))->assertOk();
        $this->assertNotNull(ConnectorCommand::find($cid)->inventory_applied_at);
    }

    public function test_cross_env_command_id_does_not_correlate(): void
    {
        $envA = $this->makeEnv($this->custA);
        $envB = $this->makeEnv($this->custB);
        [$idA] = $this->enrollAgent($envA);
        [$idB, $skB] = $this->enrollAgent($envB);
        $svc = app(ConnectorCommandService::class);
        $cidA = $this->createCmd($envA)->json('data.id');
        $svc->claimNext($this->agentModel($idA)); // comando de A reivindicado por A

        // agente de B tenta correlacionar o command_id de A → NÃO vincula.
        $this->inventory($idB, $skB, $this->inv(['trigger' => ['type' => 'command', 'command_id' => $cidA]]))->assertOk();
        $this->assertNull(ConnectorCommand::find($cidA)->inventory_applied_at);
    }

    public function test_reexecution_after_lease_lost_stays_idempotent_in_c2(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // duas execuções (at-least-once) da MESMA coleta → C-2 idempotente: 1 snapshot, sem regressão.
        $this->inventory($id, $sk, $this->inv(['observed_at' => time(), 'trigger' => ['type' => 'command', 'command_id' => 1]]))->assertOk();
        $this->inventory($id, $sk, $this->inv(['observed_at' => time() + 1, 'trigger' => ['type' => 'command', 'command_id' => 1]]))->assertOk();
        $this->assertSame(1, ConnectorRpoSnapshot::where('environment_id', $env)->count());
    }

    public function test_cancel_queued_and_409_after_claim(): void
    {
        $env = $this->makeEnv($this->custA);
        [$id, $sk] = $this->enrollAgent($env);
        // queued → canceled
        $cid = $this->createCmd($env)->json('data.id');
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/commands/{$cid}/cancel")->assertOk()->assertJsonPath('data.status', 'canceled');
        // outro comando: após CLAIM → 409 command_already_running, execução não alterada
        $cid2 = $this->createCmd($env)->json('data.id');
        app(ConnectorCommandService::class)->claimNext($this->agentModel($id));
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/prosight/commands/{$cid2}/cancel")->assertStatus(409)->assertJsonPath('error', 'command_already_running');
        $this->assertSame('claimed', ConnectorCommand::find($cid2)->status);
    }

    public function test_allowlist_rejects_destructive_types(): void
    {
        $env = $this->makeEnv($this->custA);
        foreach (['restart', 'stop', 'start', 'compile', 'patch', 'promote', 'rollback'] as $t) {
            $this->createCmd($env, $t)->assertStatus(422)->assertJsonPath('error', 'command_type_not_allowed');
        }
        $this->assertSame(0, ConnectorCommand::where('environment_id', $env)->count());
    }

    public function test_authorization_execute_required(): void
    {
        $env = $this->makeEnv($this->custA);
        // parceiro_admin (sem execute) → 403 ao criar.
        $partner = User::factory()->create(['type' => 'parceiro_admin']);
        $this->createCmd($env, 'collect_inventory_now', $partner)->assertStatus(403);
        // usuário com só view: lista (200) mas NÃO cria (403).
        $viewer = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.view']]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $viewer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/prosight/environments/{$env}/commands")->assertOk();
        $this->createCmd($env, 'collect_inventory_now', $viewer)->assertStatus(403);
    }

    public function test_anti_idor_admin_and_agent(): void
    {
        $envA = $this->makeEnv($this->custA);
        $envB = $this->makeEnv($this->custB);
        [$idA, $skA] = $this->enrollAgent($envA);
        $cidB = $this->createCmd($envB)->json('data.id'); // comando de B

        // coord de A não cria/lista/vê/cancela recursos de B → 404.
        $coordA = User::factory()->create(['type' => 'consultor', 'extra_permissions' => ['prosight.operations.view', 'prosight.operations.execute']]);
        $proj = Project::factory()->create(['customer_id' => $this->custA->id]);
        DB::table('project_consultants')->insert(['project_id' => $proj->id, 'user_id' => $coordA->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/environments/{$envB}/commands")->assertStatus(404);
        $this->actingAs($coordA, 'sanctum')->getJson("/api/v1/prosight/commands/{$cidB}")->assertStatus(404);
        $this->actingAs($coordA, 'sanctum')->postJson("/api/v1/prosight/environments/{$envB}/commands", ['command_type' => 'collect_inventory_now'])->assertStatus(404);

        // agente de A não pode reportar resultado de comando de B → 404 (escopo server-side).
        $this->postResult($idA, $skA, $cidB, ['claim_token' => 'x', 'outcome' => 'ok'])->assertStatus(404);
    }

    public function test_duplicate_coalesces_into_single_inflight(): void
    {
        $env = $this->makeEnv($this->custA);
        $r1 = $this->createCmd($env)->assertStatus(201)->json('data.id');
        $r2 = $this->createCmd($env)->assertStatus(200); // coalesced
        $r2->assertJsonPath('coalesced', true)->assertJsonPath('data.id', $r1);
        $this->assertSame(1, ConnectorCommand::where('environment_id', $env)->whereIn('status', ConnectorCommand::IN_FLIGHT)->count());
    }
}
