<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SourceDoc;
use App\Models\User;
use App\SourceCode\SourceDocCustomerScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C4a.1 — Prova o resolver ÚNICO de escopo por cliente (SourceDocCustomerScope) ANTES de
 * aplicá-lo aos 16 endpoints. Deny-by-default + precedência de segurança:
 *   cliente externo NUNCA global (nem com admin/permissão/config indevida).
 *
 * Roda contra Postgres local minutor_c1test (mesmo harness dos demais testes da Central).
 */
class SourceDocCustomerScopeTest extends TestCase
{
    use DatabaseTransactions;

    private SourceDocCustomerScope $scope;

    protected function setUp(): void
    {
        $pw = $this->envValue('DB_PASSWORD');
        foreach ([
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'minutor_c1test', 'DB_USERNAME' => 'minutor', 'DB_PASSWORD' => $pw,
        ] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        parent::setUp();
        config(['multiempresa.scoping_enabled' => false]);
        SourceDocCustomerScope::flush();
        $this->scope = new SourceDocCustomerScope();
    }

    private function envValue(string $key): string
    {
        $file = dirname(__DIR__, 2) . '/.env';
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, "{$key}=")) {
                return trim(substr($line, strlen($key) + 1));
            }
        }
        return '';
    }

    // ── (1) Admin → global ──────────────────────────────────────────────────
    public function test_admin_is_global(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $this->assertNull($this->scope->accessibleCustomerIds($admin));
        $this->assertTrue($this->scope->isGlobal($admin));
    }

    // ── (2) Interno com view_all_customers → global ─────────────────────────
    public function test_internal_with_view_all_is_global(): void
    {
        $u = User::factory()->create([
            'type' => 'coordenador',
            'extra_permissions' => ['source_docs.view_all_customers'],
        ]);
        $this->assertNull($this->scope->accessibleCustomerIds($u));
    }

    // ── (3) Cliente externo → somente o próprio cliente ─────────────────────
    public function test_cliente_externo_sees_only_own_customer(): void
    {
        $c = Customer::factory()->create();
        $other = Customer::factory()->create();
        $u = User::factory()->create(['type' => 'cliente', 'customer_id' => $c->id]);

        $this->assertSame([$c->id], $this->scope->accessibleCustomerIds($u));
        $this->assertTrue($this->scope->canAccessCustomerId($u, $c->id));
        $this->assertFalse($this->scope->canAccessCustomerId($u, $other->id));
    }

    // ── (4) ★ Cliente externo + view_all indevido → CONTINUA só o próprio ───
    public function test_cliente_externo_with_view_all_misconfigured_is_not_global(): void
    {
        $c = Customer::factory()->create();
        $u = User::factory()->create([
            'type' => 'cliente',
            'customer_id' => $c->id,
            'extra_permissions' => ['source_docs.view_all_customers'],
        ]);

        $this->assertNotNull($this->scope->accessibleCustomerIds($u), 'cliente externo NUNCA pode ser global');
        $this->assertSame([$c->id], $this->scope->accessibleCustomerIds($u));
    }

    // ── (5) ★ Cliente externo (customer_id) mesmo com type=admin → só próprio ─
    public function test_customer_bound_user_never_global_even_if_admin_type(): void
    {
        $c = Customer::factory()->create();
        // Config indevida: usuário vinculado a cliente porém marcado admin.
        $u = User::factory()->create(['type' => 'admin', 'customer_id' => $c->id]);

        $this->assertNotNull($this->scope->accessibleCustomerIds($u), 'usuário vinculado a cliente NUNCA global');
        $this->assertSame([$c->id], $this->scope->accessibleCustomerIds($u));
    }

    // ── (6) Executivo de conta → clientes onde é o executivo ────────────────
    public function test_executivo_sees_own_customers(): void
    {
        $exec = User::factory()->create(['type' => 'coordenador']);
        $mine = Customer::factory()->create(['executive_id' => $exec->id]);
        $notMine = Customer::factory()->create();

        $ids = $this->scope->accessibleCustomerIds($exec);
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($notMine->id, $ids);
    }

    // ── (7) Coordenador via project_coordinators ────────────────────────────
    public function test_coordenador_sees_project_customers(): void
    {
        $coord = User::factory()->create(['type' => 'coordenador']);
        $proj = Project::factory()->create();
        $otherProj = Project::factory()->create();
        DB::table('project_coordinators')->insert([
            'project_id' => $proj->id, 'user_id' => $coord->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = $this->scope->accessibleCustomerIds($coord);
        $this->assertContains($proj->customer_id, $ids);
        $this->assertNotContains($otherProj->customer_id, $ids);
    }

    // ── (8) Consultor via project_consultants ───────────────────────────────
    public function test_consultor_sees_project_customers(): void
    {
        $cons = User::factory()->create(['type' => 'consultor']);
        $proj = Project::factory()->create();
        DB::table('project_consultants')->insert([
            'project_id' => $proj->id, 'user_id' => $cons->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([$proj->customer_id], $this->scope->accessibleCustomerIds($cons));
    }

    // ── (9a) Consultor via grupo de consultores ─────────────────────────────
    public function test_consultor_sees_customers_via_group(): void
    {
        $cons = User::factory()->create(['type' => 'consultor']);
        $proj = Project::factory()->create();
        $groupId = DB::table('consultant_groups')->insertGetId([
            'name' => 'Grupo Teste', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('consultant_group_user')->insert([
            'consultant_group_id' => $groupId, 'user_id' => $cons->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('project_consultant_groups')->insert([
            'project_id' => $proj->id, 'consultant_group_id' => $groupId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains($proj->customer_id, $this->scope->accessibleCustomerIds($cons));
    }

    // ── (9b) Consultor via alocação de etapa ────────────────────────────────
    public function test_consultor_sees_customers_via_stage_allocation(): void
    {
        $cons = User::factory()->create(['type' => 'consultor']);
        $proj = Project::factory()->create();
        $stageId = DB::table('project_stages')->insertGetId([
            'project_id' => $proj->id, 'name' => 'Etapa 1', 'order_index' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stage_allocations')->insert([
            'stage_id' => $stageId, 'user_id' => $cons->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains($proj->customer_id, $this->scope->accessibleCustomerIds($cons));
    }

    // ── (10) Interno sem nenhum vínculo → nega tudo ([]) ────────────────────
    public function test_internal_without_links_denies_all(): void
    {
        $u = User::factory()->create(['type' => 'consultor']);
        $this->assertSame([], $this->scope->accessibleCustomerIds($u));
        $this->assertFalse($this->scope->canAccessCustomerId($u, Customer::factory()->create()->id));
    }

    // ── (11) applyScope em SQL: global=tudo, []=nada, [ids]=whereIn ─────────
    public function test_apply_scope_filters_in_sql(): void
    {
        $c1 = Customer::factory()->create();
        $c2 = Customer::factory()->create();
        $d1 = $this->makeDoc($c1->id);
        $d2 = $this->makeDoc($c2->id);

        $admin = User::factory()->create(['type' => 'admin']);
        $cliente = User::factory()->create(['type' => 'cliente', 'customer_id' => $c1->id]);
        $denied = User::factory()->create(['type' => 'consultor']); // sem vínculo → []

        // global: enxerga os dois
        $globalIds = $this->scope->applyScope(SourceDoc::query(), $admin, 'source_docs.customer_id')
            ->pluck('id')->all();
        $this->assertContains($d1->id, $globalIds);
        $this->assertContains($d2->id, $globalIds);

        // cliente externo: só o próprio
        $clienteIds = $this->scope->applyScope(SourceDoc::query(), $cliente, 'source_docs.customer_id')
            ->pluck('id')->all();
        $this->assertSame([$d1->id], $clienteIds);

        // denied: nenhum
        $deniedCount = $this->scope->applyScope(SourceDoc::query(), $denied, 'source_docs.customer_id')
            ->count();
        $this->assertSame(0, $deniedCount);
    }

    // ── (12) canAccessDoc: doc sem customer_id só é visto por global ─────────
    public function test_doc_without_customer_only_visible_to_global(): void
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $c = Customer::factory()->create();
        $cliente = User::factory()->create(['type' => 'cliente', 'customer_id' => $c->id]);
        $doc = $this->makeDoc(null);

        $this->assertTrue($this->scope->canAccessDoc($admin, $doc));
        $this->assertFalse($this->scope->canAccessDoc($cliente, $doc));
    }

    private function makeDoc(?int $customerId): SourceDoc
    {
        return SourceDoc::create([
            'owner' => 'erpserv-clientes', 'repository' => 'concreserv', 'branch' => 'main',
            'path' => 'src/' . uniqid() . '.prw', 'filename' => 'X.prw',
            'lang' => 'advpl', 'tipo' => 'protheus', 'size_bytes' => 1000,
            'analysis_status' => 'completed', 'customer_id' => $customerId,
        ]);
    }
}
