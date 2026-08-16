<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\SourceDocEntity;
use App\Models\SourceDocVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4b.2 — Endpoint GET /source-docs/impact: validação, gate de permissão (source_docs.view),
 * escopo/IDOR ao vivo via HTTP, cross via query, paginação, sanitização de integração.
 */
class SourceDocImpactEndpointTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $custA;
    private Customer $custB;

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

    private function makeDoc(int $customerId): SourceDoc
    {
        $doc = SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'rep' . $customerId . uniqid(), 'branch' => 'main',
            'path' => 'x/' . uniqid() . '.prw', 'filename' => 'F.PRW', 'lang' => 'advpl',
            'tipo' => 'protheus', 'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
        $ver = SourceDocVersion::create([
            'source_doc_id' => $doc->id, 'source_commit_sha' => 'c' . uniqid(), 'source_blob_sha' => 'b',
            'analysis_status' => 'completed', 'deterministic_json' => ['functions' => []], 'documentation_json' => ['identity' => []],
        ]);
        $doc->forceFill(['current_version_id' => $ver->id])->save();
        return $doc->refresh();
    }

    private function ent(SourceDoc $doc, array $attrs): void
    {
        SourceDocEntity::create(array_merge([
            'source_doc_id' => $doc->id, 'source_doc_version_id' => $doc->current_version_id,
            'customer_id' => $doc->customer_id, 'owner' => $doc->owner, 'repository' => $doc->repository,
            'branch' => 'main', 'access' => null, 'risk_flags' => null,
        ], $attrs));
    }

    private function coordFor(Customer $c): User
    {
        $u = User::factory()->create(['type' => 'coordenador']);
        $proj = Project::factory()->create(['customer_id' => $c->id]);
        DB::table('project_coordinators')->insert(['project_id' => $proj->id, 'user_id' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        return $u;
    }

    public function test_validation_and_permission(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        // entity inválido
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=xpto&name=A')->assertStatus(422);
        // name obrigatório
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=field')->assertStatus(422);
        // consultor sem source_docs.view → 403
        $this->actingAs(User::factory()->create(['type' => 'consultor']), 'sanctum')
            ->getJson('/api/v1/source-docs/impact?entity=field&name=A')->assertForbidden();
    }

    public function test_scope_and_lateral_via_http(): void
    {
        $docA = $this->makeDoc($this->custA->id);
        $docB = $this->makeDoc($this->custB->id);
        $this->ent($docA, ['entity_type' => 'field', 'name' => 'STATUSMAIL', 'parent' => 'SPED050', 'access' => ['UPDATE']]);
        $this->ent($docB, ['entity_type' => 'field', 'name' => 'STATUSMAIL', 'parent' => 'SPED050', 'access' => ['UPDATE']]);
        $this->ent($docB, ['entity_type' => 'field', 'name' => 'UNIQUE_B', 'parent' => 'ZZB', 'access' => ['UPDATE']]);

        $coordA = $this->coordFor($this->custA);

        // impacto de STATUSMAIL: coordenador A só vê o cliente A (1 fonte), não o B
        $r = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=field&name=STATUSMAIL')->assertOk();
        $this->assertSame(1, $r->json('summary.clientes'));
        $this->assertSame(1, $r->json('summary.fontes'));

        // teste lateral: campo exclusivo de B → 0, sem metadata
        $r2 = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=field&name=UNIQUE_B')->assertOk();
        $this->assertSame(0, $r2->json('summary.fontes'));
        $this->assertSame([], $r2->json('data'));

        // admin vê os dois clientes
        $ra = $this->actingAs(User::factory()->create(['type' => 'admin']), 'sanctum')
            ->getJson('/api/v1/source-docs/impact?entity=field&name=STATUSMAIL')->assertOk();
        $this->assertSame(2, $ra->json('summary.clientes'));
    }

    public function test_cross_gate_via_http(): void
    {
        $coordA = $this->coordFor($this->custA);
        // cross sem permissão → notice presente, query.cross=false
        $r = $this->actingAs($coordA, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=field&name=X&cross=true')->assertOk();
        $this->assertFalse($r->json('query.cross'));
        $this->assertNotNull($r->json('notice'));

        // admin → cross global liberado
        $ra = $this->actingAs(User::factory()->create(['type' => 'admin']), 'sanctum')
            ->getJson('/api/v1/source-docs/impact?entity=field&name=X&cross=true')->assertOk();
        $this->assertTrue($ra->json('query.cross'));
    }

    public function test_integration_sanitized_via_http(): void
    {
        $docA = $this->makeDoc($this->custA->id);
        $raw = 'http://10.0.0.12:9093/rest/oauth/token?apikey=SEGREDO123';
        $this->ent($docA, ['entity_type' => 'integration', 'name' => $raw]);
        $admin = User::factory()->create(['type' => 'admin']);

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=integration&name=' . urlencode($raw))->assertOk();
        $body = $resp->getContent();
        $this->assertStringNotContainsString('SEGREDO123', $body);
        $this->assertStringNotContainsString('10.0.0.12', $body);
        $this->assertStringNotContainsString('/rest/oauth/token', $body);
    }

    public function test_pagination(): void
    {
        // 3 fontes do cliente A com o mesmo campo → paginação por fonte, per_page=2
        for ($i = 0; $i < 3; $i++) {
            $d = $this->makeDoc($this->custA->id);
            $this->ent($d, ['entity_type' => 'field', 'name' => 'PAGED', 'parent' => 'SX1', 'access' => ['READ']]);
        }
        $admin = User::factory()->create(['type' => 'admin']);
        $r = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/source-docs/impact?entity=field&name=PAGED&per_page=2&page=1')->assertOk();
        $this->assertSame(3, $r->json('pagination.total_sources'));
        $this->assertSame(2, $r->json('pagination.last_page'));
    }
}
