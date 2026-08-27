<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prosight C3 — projeção segura de Ambientes (/prosight/environments).
 * Prova: empresa obrigatória, anti-IDOR + escopo, permissão própria, cliente externo negado,
 * status cadastral normalizado, componentes conservadores (sem VPN/cert), e — o mais importante —
 * NENHUM secret/host/porta/URL/credencial no payload (inspeção recursiva).
 */
class ProsightEnvironmentTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;

    // valores sentinela sensíveis semeados no Cofre — NÃO podem aparecer na projeção.
    private const SENTINELS = ['SECRETHOST', 'SECRETINST', 'SECRETDB', 'SECRETUSER', 'SECRETPATH', 'SECRETURL', 'SECRETVPN', 'SECRETTHUMB', 'CIPHERTEXTBLOB'];

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['cache.default' => 'array', 'multiempresa.scoping_enabled' => false]);
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

    /** Cria vault + client-vault + 1 ambiente com filhos SENSÍVEIS semeados. Retorna o env id. */
    private function seedEnvironment(Customer $c, string $name, string $type, string $status, bool $withSensitive = true): int
    {
        $vaultId = DB::table('env_client_vaults')->where('customer_id', $c->id)->value('vault_id');
        if (! $vaultId) {
            $vaultId = Vault::create(['type' => 'client', 'name' => 'Ambientes — ' . $c->id, 'created_by' => null])->id;
            DB::table('env_client_vaults')->insert(['customer_id' => $c->id, 'vault_id' => $vaultId, 'created_at' => now(), 'updated_at' => now()]);
        }
        $envId = DB::table('env_environments')->insertGetId([
            'customer_id' => $c->id, 'vault_id' => $vaultId, 'name' => $name, 'type' => $type, 'status' => $status,
            'notes' => 'nota interna secreta', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $withSensitive) {
            return $envId;
        }
        // secret (ciphertext) — jamais deve sair.
        $secretId = DB::table('env_secrets')->insertGetId([
            'environment_id' => $envId, 'vault_id' => $vaultId, 'kind' => 'password', 'data' => 'CIPHERTEXTBLOB',
            'key_version' => 1, 'critical' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // appserver — só name/version/build/patch podem sair; root_path/port/ini_secret_id NÃO.
        DB::table('env_appservers')->insert([
            'environment_id' => $envId, 'name' => 'APP01', 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12',
            'root_path' => 'C:\\TOTVS\\SECRETPATH', 'port' => 1234, 'ini_secret_id' => $secretId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // database — só engine sai; server/instance/database/username/password_secret_id NÃO.
        DB::table('env_databases')->insert([
            'environment_id' => $envId, 'engine' => 'sqlserver', 'server' => 'SECRETHOST', 'instance' => 'SECRETINST',
            'database' => 'SECRETDB', 'username' => 'SECRETUSER', 'password_secret_id' => $secretId,
            'always_on' => false, 'critical' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // link permitido (portal) — label/kind saem, URL NÃO; link rdp NÃO deve entrar.
        DB::table('env_links')->insert([
            ['environment_id' => $envId, 'label' => 'Portal', 'kind' => 'portal', 'url' => 'https://SECRETURL/portal', 'created_at' => now(), 'updated_at' => now()],
            ['environment_id' => $envId, 'label' => 'RDP', 'kind' => 'rdp', 'url' => 'rdp://SECRETURL', 'created_at' => now(), 'updated_at' => now()],
        ]);
        // vpn + certificado — NÃO devem virar componente nem vazar.
        DB::table('env_vpns')->insert([
            'environment_id' => $envId, 'provider' => 'fortinet', 'server' => 'SECRETVPN', 'password_secret_id' => $secretId,
            'critical' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('env_certificates')->insert([
            'environment_id' => $envId, 'name' => 'cert-prod', 'type' => 'A1', 'thumbprint' => 'SECRETTHUMB',
            'pfx_pass_secret_id' => $secretId, 'critical' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $envId;
    }

    private function internal(array $perms, ?Customer $link = null, string $type = 'coordenador'): User
    {
        $u = User::factory()->create(['type' => $type, 'extra_permissions' => $perms]);
        if ($link) {
            $proj = Project::factory()->create(['customer_id' => $link->id]);
            DB::table('project_coordinators')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $u;
    }

    private function hit(User $u, string $qs = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u, 'sanctum')->getJson('/api/v1/prosight/environments' . ($qs ? "?$qs" : ''));
    }

    /** Caminha recursivamente e falha se aparecer chave proibida ou valor sentinela. */
    private function assertNoSecrets($node, string $path = 'root'): void
    {
        // chaves EXATAS proibidas (evita falso-positivo com containers 'appservers'/'databases').
        // (o VALOR do ciphertext env_secrets.data é coberto pelo sentinela CIPHERTEXTBLOB; 'data' aqui é
        //  só o envelope de resposta do Laravel, por isso não entra na lista de chaves proibidas.)
        $forbiddenKeys = ['password', 'secret', 'secret_id', 'ini_secret_id', 'password_secret_id',
            'pfx_pass_secret_id', 'username', 'host', 'rdp_host', 'rdp_port', 'server', 'root_path', 'url', 'port',
            'instance', 'database', 'thumbprint', 'inventory', 'notes', 'vault_id', 'connection', 'cipher',
            'credential', 'ovpn', 'pfx'];
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $this->assertNotContains(strtolower($k), $forbiddenKeys, "chave proibida em {$path}.{$k}");
                }
                $this->assertNoSecrets($v, "{$path}.{$k}");
            }
        } elseif (is_string($node)) {
            foreach (self::SENTINELS as $s) {
                $this->assertStringNotContainsString($s, $node, "valor sensível vazou em {$path}");
            }
        }
    }

    // ── testes ────────────────────────────────────────────────────────────────

    public function test_company_is_required(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $this->hit($admin)->assertStatus(422); // sem customer_id
    }

    public function test_permission_required(): void
    {
        // usuário SEM a permissão (parceiro_admin não recebe prosight.environments.view) → 403 no middleware.
        $noPerm = User::factory()->create(['type' => 'parceiro_admin', 'extra_permissions' => []]);
        $this->hit($noPerm, "customer_id={$this->custA->id}")->assertStatus(403);
    }

    public function test_external_customer_denied_by_default(): void
    {
        // cliente externo não recebe prosight.environments.view por padrão → 403.
        $ext = User::factory()->create(['type' => 'cliente', 'customer_id' => $this->custA->id, 'extra_permissions' => []]);
        $this->hit($ext, "customer_id={$this->custA->id}")->assertStatus(403);
    }

    public function test_anti_idor_scope(): void
    {
        $this->seedEnvironment($this->custA, 'Produção', 'prod', 'online');
        $coordA = $this->internal(['prosight.environments.view'], $this->custA);
        $this->hit($coordA, "customer_id={$this->custB->id}")->assertStatus(403);
        $this->hit($coordA, "customer_id={$this->custA->id}")->assertOk();
    }

    public function test_isolation_only_own_customer(): void
    {
        $this->seedEnvironment($this->custA, 'Produção', 'prod', 'online');
        $this->seedEnvironment($this->custB, 'Produção B', 'prod', 'online', false);
        $admin = User::factory()->create(['type' => 'admin']);
        $envs = $this->hit($admin, "customer_id={$this->custA->id}")->assertOk()->json('data.environments');
        $this->assertCount(1, $envs);
        $this->assertSame($this->custA->id, $envs[0]['customer_id']);
        $this->assertSame('Produção', $envs[0]['name']);
    }

    public function test_payload_has_no_secrets_and_conservative_components(): void
    {
        $this->seedEnvironment($this->custA, 'Produção', 'prod', 'online');
        $admin = User::factory()->create(['type' => 'admin']);
        $body = $this->hit($admin, "customer_id={$this->custA->id}")->assertOk()->json();

        // (1) inspeção recursiva: nenhuma chave proibida, nenhum valor sentinela.
        $this->assertNoSecrets($body);

        $env = $body['data']['environments'][0];
        // (2) allowlist positiva presente.
        $this->assertSame(['prod'], [$env['type']]);
        $this->assertSame('ativo', $env['status']['code']);
        $this->assertSame('Ativo (cadastral)', $env['status']['label']);
        $this->assertSame(['health' => 'aguardando_conector', 'rpo' => 'aguardando_conector'], $env['live']);
        // (3) appserver só com campos safe.
        $this->assertSame(['name' => 'APP01', 'version' => '12.1.2410', 'build' => '9999', 'patch' => '12'], $env['appservers'][0]);
        $this->assertArrayNotHasKey('root_path', $env['appservers'][0]);
        // (4) banco só engine.
        $this->assertSame([['engine' => 'sqlserver']], $env['databases']);
        // (5) links: só o portal (rdp excluído), sem URL.
        $this->assertCount(1, $env['links']);
        $this->assertSame(['label' => 'Portal', 'kind' => 'portal'], $env['links'][0]);
        // (6) componentes CONSERVADORES: protheus/appserver/dbaccess/portal; NUNCA vpn/certificado.
        $this->assertContains('appserver', $env['components']);
        $this->assertContains('dbaccess', $env['components']);
        $this->assertNotContains('vpn', $env['components']);
        $this->assertNotContains('certificado', $env['components']);
        $this->assertNotContains('certificate', $env['components']);
    }

    public function test_status_labels_and_empty(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        // sem ambientes → lista vazia (não erro).
        $empty = $this->hit($admin, "customer_id={$this->custA->id}")->assertOk()->json('data.environments');
        $this->assertSame([], $empty);

        $this->seedEnvironment($this->custA, 'Homolog', 'homolog', 'maintenance', false);
        $this->seedEnvironment($this->custA, 'Dev', 'dev', 'unknown', false);
        $envs = $this->hit($admin, "customer_id={$this->custA->id}")->assertOk()->json('data.environments');
        $labels = collect($envs)->keyBy('name');
        $this->assertSame('Em manutenção', $labels['Homolog']['status']['label']);
        $this->assertSame('Status técnico indisponível', $labels['Dev']['status']['label']);
    }
}
