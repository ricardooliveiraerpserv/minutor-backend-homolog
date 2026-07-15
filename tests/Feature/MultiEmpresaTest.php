<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\CompanyContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-empresa (multi-tenant interno) — cenários do spec:
 *  1. Mesmo usuário, 2 empresas → dados e permissões diferentes.
 *  2. Trocar de empresa muda os dados/permissões.
 *  3. Segurança: não acessa dado de outra empresa via URL.
 */
class MultiEmpresaTest extends TestCase
{
    use DatabaseTransactions;

    private Company $erp;
    private Company $biz;

    protected function setUp(): void
    {
        parent::setUp();
        config(['multiempresa.scoping_enabled' => true]);
        $this->erp = Company::create(['name' => 'ERPSERV', 'slug' => 'erpserv', 'type' => 'internal', 'status' => 'active']);
        $this->biz = Company::create(['name' => 'BIZIFY', 'slug' => 'bizify', 'type' => 'internal', 'status' => 'active']);
    }

    private function userInBoth(string $erpRole = 'admin', string $bizRole = 'consultor'): User
    {
        $u = User::factory()->create(['type' => 'consultor', 'current_company_id' => $this->erp->id]);
        $u->companies()->attach([
            $this->erp->id => ['role' => $erpRole],
            $this->biz->id => ['role' => $bizRole],
        ]);
        return $u;
    }

    public function test_dados_isolados_por_empresa_ativa(): void
    {
        Project::factory()->create(['company_id' => $this->erp->id]);
        Project::factory()->create(['company_id' => $this->erp->id]);
        Project::factory()->create(['company_id' => $this->biz->id]);

        $ctx = app(CompanyContext::class);

        $ctx->set($this->erp->id);
        $this->assertSame(2, Project::count(), 'ERPSERV só vê os seus');

        $ctx->set($this->biz->id);
        $this->assertSame(1, Project::count(), 'BIZIFY só vê os seus');

        $this->assertSame(3, Project::withoutCompanyScope()->count(), 'sem scope = todos');
    }

    public function test_permissoes_diferentes_por_empresa(): void
    {
        $u  = $this->userInBoth('admin', 'consultor');
        $ctx = app(CompanyContext::class);

        $ctx->set($this->erp->id);
        $this->assertSame('admin', $u->effectiveType());
        $this->assertTrue($u->isAdmin());
        $this->assertTrue($u->hasAccess('users.delete'));

        $ctx->set($this->biz->id);
        $this->assertSame('consultor', $u->effectiveType());
        $this->assertFalse($u->isAdmin());
        $this->assertFalse($u->hasAccess('users.delete'));
    }

    public function test_nao_acessa_projeto_de_outra_empresa_via_url(): void
    {
        // admin nas DUAS empresas → isola o efeito do SCOPE de empresa (sem gating de papel).
        $u = $this->userInBoth('admin', 'admin');
        $erpProject = Project::factory()->create(['company_id' => $this->erp->id]);
        $bizProject = Project::factory()->create(['company_id' => $this->biz->id]);
        Sanctum::actingAs($u);

        // ERPSERV ativa: vê o SEU (200); o da BIZIFY é BLOQUEADO pelo scope (route-model
        // binding lança ModelNotFound → o handler de API deste app renderiza como 422).
        $this->getJson("/api/v1/projects/{$erpProject->id}", ['X-Company-ID' => $this->erp->id])->assertOk();
        $this->getJson("/api/v1/projects/{$bizProject->id}", ['X-Company-ID' => $this->erp->id])->assertStatus(422);

        // Trocar pra BIZIFY inverte.
        $this->getJson("/api/v1/projects/{$bizProject->id}", ['X-Company-ID' => $this->biz->id])->assertOk();
        $this->getJson("/api/v1/projects/{$erpProject->id}", ['X-Company-ID' => $this->biz->id])->assertStatus(422);
    }

    public function test_troca_de_empresa_e_vinculo(): void
    {
        $u = $this->userInBoth();
        Sanctum::actingAs($u);

        $this->getJson('/api/v1/my-companies')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/set-company', ['company_id' => $this->biz->id])
            ->assertOk()->assertJsonPath('active_company_id', $this->biz->id);

        // Não pode ativar empresa onde não está vinculado.
        $other = Company::create(['name' => 'X', 'slug' => 'x', 'type' => 'internal', 'status' => 'active']);
        $this->postJson('/api/v1/set-company', ['company_id' => $other->id])->assertStatus(403);
    }
}
