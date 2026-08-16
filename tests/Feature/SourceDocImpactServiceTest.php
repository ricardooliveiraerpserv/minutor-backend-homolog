<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\Models\SourceDocVersion;
use App\Models\User;
use App\SourceCode\SourceDocCustomerScope;
use App\SourceCode\SourceDocImpactService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4b.1 — Motor de impacto: fatos determinísticos, governança C4a (deny-by-default),
 * teste lateral de vazamento, cross-customer controlado, sanitização backend.
 */
class SourceDocImpactServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SourceDocImpactService $svc;
    private Customer $custA;
    private Customer $custB;
    private SourceDoc $docA;
    private SourceDoc $docB;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw] as $k => $v) {
            putenv("{$k}={$v}"); $_ENV[$k] = $v; $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['multiempresa.scoping_enabled' => false]);
        $this->svc = new SourceDocImpactService(new SourceDocCustomerScope());

        $this->custA = Customer::factory()->create();
        $this->custB = Customer::factory()->create();
        $this->docA = $this->makeDoc($this->custA->id);
        $this->docB = $this->makeDoc($this->custB->id);
    }

    private function envValue(string $key): string
    {
        foreach (file(dirname(__DIR__, 2) . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) { return trim(substr($line, strlen($key) + 1)); }
        }
        return '';
    }

    private function makeDoc(int $customerId): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'rep' . $customerId, 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'CCSPCP.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'b',
            'analysis_status' => 'completed', 'deterministic_json' => ['functions' => []],
            'documentation_json' => ['identity' => []],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    private function ent(SourceDoc $doc, array $attrs): void
    {
        SourceDocEntity::create(array_merge([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'customer_id' => $doc->customer_id, 'owner' => $doc->owner, 'repository' => $doc->repository,
            'branch' => 'main', 'access' => null, 'risk_flags' => null, 'line_start' => null, 'line_end' => null,
        ], $attrs));
    }

    /** Coordenador com escopo ao cliente (via project_coordinators). */
    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador']);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert([
            'project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $u;
    }

    // ── field: leitores × escritores + agrupamento ──────────────────────────
    public function test_field_readers_and_writers(): void
    {
        // docA: STATUSMAIL na SPED050, um READ e um UPDATE (2 ocorrências)
        $this->ent($this->docA, ['entity_type' => 'field', 'name' => 'STATUSMAIL', 'parent' => 'SPED050', 'access' => ['READ']]);
        $this->ent($this->docA, ['entity_type' => 'field', 'name' => 'STATUSMAIL', 'parent' => 'SPED050', 'access' => ['UPDATE']]);

        $admin = User::factory()->create(['type' => 'admin']);
        $r = $this->svc->impact($admin, ['entity' => 'field', 'name' => 'STATUSMAIL', 'table' => 'SPED050']);

        $this->assertSame(1, $r['summary']['clientes']);
        $this->assertSame(1, $r['summary']['fontes']);
        $this->assertSame(1, $r['summary']['leitores']);
        $this->assertSame(1, $r['summary']['escritores']);
        $this->assertCount(1, $r['data']); // 1 cliente
        $this->assertCount(1, $r['data'][0]['sources']); // 1 fonte
        $this->assertCount(2, $r['data'][0]['sources'][0]['occurrences']);
    }

    public function test_access_filter_write_only(): void
    {
        $this->ent($this->docA, ['entity_type' => 'field', 'name' => 'CAMPO', 'parent' => 'SX1', 'access' => ['READ']]);
        $this->ent($this->docA, ['entity_type' => 'field', 'name' => 'CAMPO', 'parent' => 'SX1', 'access' => ['UPDATE']]);
        $admin = User::factory()->create(['type' => 'admin']);

        $w = $this->svc->impact($admin, ['entity' => 'field', 'name' => 'CAMPO', 'access' => 'write']);
        $this->assertSame(1, $w['summary']['ocorrencias']); // só o UPDATE
    }

    // ── ★ teste lateral: A consulta campo exclusivo de B → 0, sem metadata ──
    public function test_lateral_no_leak_between_customers(): void
    {
        $this->ent($this->docB, ['entity_type' => 'field', 'name' => 'UNIQUE_FIELD_B', 'parent' => 'ZZB', 'access' => ['UPDATE']]);
        $coordA = $this->coordFor($this->custA);

        $r = $this->svc->impact($coordA, ['entity' => 'field', 'name' => 'UNIQUE_FIELD_B']);

        $this->assertSame(0, $r['summary']['clientes']);
        $this->assertSame(0, $r['summary']['fontes']);
        $this->assertSame(0, $r['summary']['ocorrencias']);
        $this->assertSame([], $r['data']);

        // após ganhar acesso a B (coordenador de B), aparece:
        $projB = Project::factory()->create(['customer_id' => $this->custB->id]);
        DB::table('project_coordinators')->insert(['project_id' => $projB->id, 'user_id' => $coordA->id, 'created_at' => now(), 'updated_at' => now()]);
        $svc2 = new SourceDocImpactService(new SourceDocCustomerScope()); // memo fresh
        $r2 = $svc2->impact($coordA, ['entity' => 'field', 'name' => 'UNIQUE_FIELD_B']);
        $this->assertSame(1, $r2['summary']['fontes']);
    }

    // ── cross-customer: sem permissão → rejeitado (dentro do escopo); com → ok ─
    public function test_cross_customer_gate(): void
    {
        $coordA = $this->coordFor($this->custA);
        // sem view_cross_customer → cross_rejected, notice presente, dados só do escopo
        $r = $this->svc->impact($coordA, ['entity' => 'field', 'name' => 'X', 'cross' => true]);
        $this->assertFalse($r['query']['cross']);
        $this->assertNotNull($r['notice']);

        // com view_cross_customer → cross liberado
        $coordA->extra_permissions = ['source_docs.view_cross_customer'];
        $coordA->save();
        $svc2 = new SourceDocImpactService(new SourceDocCustomerScope());
        $r2 = $svc2->impact($coordA, ['entity' => 'field', 'name' => 'X', 'cross' => true]);
        $this->assertTrue($r2['query']['cross']);
        $this->assertNull($r2['notice']);
    }

    // ── cliente externo NUNCA cruza (nem com permissão indevida) ──────────────
    public function test_cliente_externo_never_cross(): void
    {
        $cli = User::factory()->create([
            'type' => 'cliente', 'customer_id' => $this->custA->id,
            'extra_permissions' => ['source_docs.view_cross_customer'],
        ]);
        $scope = $this->svc->resolveScope($cli, true);
        $this->assertFalse($scope['cross'], 'cliente externo nunca cruza');
        $this->assertTrue($scope['cross_rejected']);
        $this->assertSame([$this->custA->id], $scope['ids']);
    }

    // ── risk: só o nome da flag (nunca o valor/segredo) ──────────────────────
    public function test_risk_flag_names_only(): void
    {
        $this->ent($this->docA, ['entity_type' => 'risk', 'name' => 'aws_access_key']);
        $this->ent($this->docA, ['entity_type' => 'field', 'name' => 'C', 'parent' => 'SX1', 'access' => ['UPDATE'], 'risk_flags' => ['write_without_where']]);
        $admin = User::factory()->create(['type' => 'admin']);

        $r = $this->svc->impact($admin, ['entity' => 'field', 'name' => 'C']);
        $this->assertArrayHasKey('write_without_where', $r['summary']['risk_flags']);
        $this->assertSame(['write_without_where'], $r['data'][0]['sources'][0]['occurrences'][0]['risk_flags']);
    }

    // ── sanitização de integração (BACKEND) ──────────────────────────────────
    public function test_sanitize_integration(): void
    {
        // IP privado → host mascarado, path presente sinalizado, sem vazar path/token
        $s1 = $this->svc->sanitizeIntegration('http://10.0.0.12:9093/rest')['integration'];
        $this->assertSame('endpoint', $s1['kind']);
        $this->assertSame('rede-interna', $s1['host']);
        $this->assertTrue($s1['has_path']);
        $this->assertArrayNotHasKey('path', $s1);
        $this->assertArrayNotHasKey('query', $s1);

        // oauth/token no path → has_credential, sem vazar o token
        $s2 = $this->svc->sanitizeIntegration('http://52.54.53.226:8085/comlubws/oauth/token')['integration'];
        $this->assertTrue($s2['has_credential']);
        $this->assertStringNotContainsStringIgnoringCase('token', json_encode($s2)); // 'oauth/token' não vaza

        // label técnico seguro
        $s3 = $this->svc->sanitizeIntegration('MsExecAuto')['integration'];
        $this->assertSame('label', $s3['kind']);
        $this->assertSame('MsExecAuto', $s3['value']);

        // token bruto como nome → redacted
        $s4 = $this->svc->sanitizeIntegration('secret_api_token_abc')['integration'];
        $this->assertSame('redacted', $s4['kind']);
    }

    // ── integração via impact(): ocorrência já projetada, sem URL bruta ──────
    public function test_integration_impact_never_returns_raw_url(): void
    {
        $raw = 'http://10.31.0.11:8080/api/import/empresa?token=abc123';
        $this->ent($this->docA, ['entity_type' => 'integration', 'name' => $raw]);
        $admin = User::factory()->create(['type' => 'admin']);

        $r = $this->svc->impact($admin, ['entity' => 'integration', 'name' => $raw]);
        $json = json_encode($r);
        $this->assertStringNotContainsString('abc123', $json);
        $this->assertStringNotContainsString('/api/import/empresa', $json);
        $this->assertStringNotContainsString('10.31.0.11', $json);
    }
}
