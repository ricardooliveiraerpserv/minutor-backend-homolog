<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OkrAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\TestHelpers;

/**
 * Fluxo da Fase 1 do módulo OKR: ciclos, áreas, objetivos, KRs, distribuição
 * mensal e permissões. Autenticação por users.type (como a autorização real).
 */
class OkrModuleTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected function tearDown(): void
    {
        OkrAutomationService::fake(null);
        parent::tearDown();
    }

    private function userOfType(string $type): User
    {
        return User::factory()->create([
            'type' => $type,
            'email' => $type . '-okr-' . uniqid() . '@test-okr.local',
        ]);
    }

    /** @test */
    public function admin_cria_ciclo_area_objetivos_kr_e_distribui_metas(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);

        // Ciclo
        $r = $this->postJson($this->apiUrl('/okr/cycles'), [
            'year' => 2027, 'name' => 'Planejamento 2027',
        ], $h);
        $r->assertStatus(201);
        $cycleId = $r->json('data.id');
        $this->assertDatabaseHas('okr_cycles', ['year' => 2027, 'name' => 'Planejamento 2027']);

        // Área
        $r = $this->postJson($this->apiUrl('/okr/areas'), ['name' => 'Comercial'], $h);
        $r->assertStatus(201);
        $areaId = $r->json('data.id');

        // Objetivo corporativo
        $r = $this->postJson($this->apiUrl('/okr/objectives'), [
            'okr_cycle_id' => $cycleId, 'level' => 'corporativo',
            'title' => 'Expandir a carteira de clientes',
        ], $h);
        $r->assertStatus(201);
        $corpId = $r->json('data.id');
        $this->assertNull($r->json('data.okr_area_id'));

        // Objetivo de área vinculado ao corporativo
        $r = $this->postJson($this->apiUrl('/okr/objectives'), [
            'okr_cycle_id' => $cycleId, 'level' => 'area', 'okr_area_id' => $areaId,
            'parent_objective_id' => $corpId, 'title' => 'Aumentar a aquisição de novos clientes',
        ], $h);
        $r->assertStatus(201);
        $objId = $r->json('data.id');
        $this->assertEquals($corpId, $r->json('data.parent_objective_id'));

        // KR com meta anual acumulativa
        $r = $this->postJson($this->apiUrl('/okr/key-results'), [
            'okr_objective_id' => $objId, 'name' => 'Conquistar 36 novos clientes',
            'unit' => 'quantidade', 'direction' => 'aumentar', 'aggregation' => 'acumulativo',
            'baseline_value' => 0, 'target_value' => 36,
        ], $h);
        $r->assertStatus(201);
        $krId = $r->json('data.id');

        // Distribuição automática → 12 metas somando 36
        $r = $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), ['mode' => 'auto'], $h);
        $r->assertStatus(200);
        $this->assertDatabaseCount('okr_monthly_targets', 12);
        $sum = (float) \DB::table('okr_monthly_targets')->where('okr_key_result_id', $krId)->sum('target_value');
        $this->assertSame(36.0, round($sum, 2));
        $this->assertDatabaseHas('okr_monthly_targets', ['okr_key_result_id' => $krId, 'year_month' => '2027-01']);
    }

    /** @test */
    public function distribuicao_manual_substitui_as_metas(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);

        $cycleId = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'P2027'], $h)->json('data.id');
        $objId = $this->postJson($this->apiUrl('/okr/objectives'), [
            'okr_cycle_id' => $cycleId, 'level' => 'corporativo', 'title' => 'Obj',
        ], $h)->json('data.id');
        $krId = $this->postJson($this->apiUrl('/okr/key-results'), [
            'okr_objective_id' => $objId, 'name' => 'KR', 'target_value' => 36,
        ], $h)->json('data.id');

        $r = $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), [
            'mode' => 'manual',
            'targets' => [
                ['year_month' => '2027-01', 'target_value' => 1],
                ['year_month' => '2027-02', 'target_value' => 2],
                ['year_month' => '2027-03', 'target_value' => 2],
            ],
        ], $h);
        $r->assertStatus(200);
        $this->assertDatabaseCount('okr_monthly_targets', 3);
        $this->assertDatabaseHas('okr_monthly_targets', ['okr_key_result_id' => $krId, 'year_month' => '2027-02', 'target_value' => 2]);
    }

    /** @test */
    public function copiar_planejamento_clona_estrutura_para_novo_ano(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);

        $cycleId = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'P2027'], $h)->json('data.id');
        $objId = $this->postJson($this->apiUrl('/okr/objectives'), [
            'okr_cycle_id' => $cycleId, 'level' => 'corporativo', 'title' => 'Obj 2027',
        ], $h)->json('data.id');
        $krId = $this->postJson($this->apiUrl('/okr/key-results'), [
            'okr_objective_id' => $objId, 'name' => 'KR', 'target_value' => 12,
        ], $h)->json('data.id');
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), ['mode' => 'auto'], $h)->assertStatus(200);

        $r = $this->postJson($this->apiUrl("/okr/cycles/{$cycleId}/copy"), ['year' => 2028], $h);
        $r->assertStatus(201);
        $newId = $r->json('data.id');

        $this->assertNotEquals($cycleId, $newId);
        $this->assertDatabaseHas('okr_cycles', ['year' => 2028, 'status' => 'rascunho']);
        $this->assertDatabaseHas('okr_objectives', ['okr_cycle_id' => $newId, 'title' => 'Obj 2027']);
        // Metas copiadas com o ano trocado.
        $this->assertDatabaseHas('okr_monthly_targets', ['year_month' => '2028-01']);
        $this->assertEquals(1, \App\Models\OkrCycle::where('id', $newId)->count());
    }

    /** Cria ciclo + objetivo + KR (acumulativo, meta 36) com metas auto. Retorna o KR id. */
    private function seedKr(array $h): int
    {
        $cycleId = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'P2027'], $h)->json('data.id');
        $objId = $this->postJson($this->apiUrl('/okr/objectives'), ['okr_cycle_id' => $cycleId, 'level' => 'corporativo', 'title' => 'Obj'], $h)->json('data.id');
        $krId = $this->postJson($this->apiUrl('/okr/key-results'), [
            'okr_objective_id' => $objId, 'name' => 'Novos clientes',
            'direction' => 'aumentar', 'aggregation' => 'acumulativo', 'baseline_value' => 0, 'target_value' => 36,
        ], $h)->json('data.id');
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), ['mode' => 'auto'], $h)->assertStatus(200);
        return $krId;
    }

    /** @test */
    public function responsavel_registra_realizado_e_acompanhamento_calcula_progresso(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);
        $krId = $this->seedKr($h);

        // Consultor (responsável, tem okr.checkin) informa jan..jun.
        $consultor = $this->userOfType('consultor');
        $hc = $this->authHeaders($consultor);
        foreach (['2027-01' => 2, '2027-02' => 4, '2027-03' => 3, '2027-04' => 2, '2027-05' => 4, '2027-06' => 3] as $ym => $val) {
            $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => $ym, 'actual_value' => $val], $hc)->assertStatus(200);
        }

        $r = $this->getJson($this->apiUrl("/okr/key-results/{$krId}/monthly"), $h);
        $r->assertStatus(200);
        $this->assertEquals(50, $r->json('data.summary.achievement'));   // 18 / 36
        $this->assertEquals(100, $r->json('data.summary.adherence'));    // 18 realizado = 18 planejado até junho
        $this->assertSame('verde', $r->json('data.summary.status'));
        $this->assertSame(6, $r->json('data.summary.reference_month'));
        $this->assertDatabaseHas('okr_monthly_results', ['okr_key_result_id' => $krId, 'year_month' => '2027-02']);
    }

    /** @test */
    public function alteracao_do_realizado_gera_log_de_historico(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);
        $krId = $this->seedKr($h);

        // 1ª gravação = criação (sem log). 2ª = alteração → log.
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => '2027-01', 'actual_value' => 2], $h)->assertStatus(200);
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => '2027-01', 'actual_value' => 5, 'reason' => 'ajuste de fechamento'], $h)->assertStatus(200);

        $this->assertDatabaseHas('okr_result_change_logs', [
            'okr_key_result_id' => $krId, 'year_month' => '2027-01', 'field_name' => 'actual_value', 'changed_by' => $admin->id,
        ]);
    }

    /** @test */
    public function dashboard_consolida_por_area_e_lista_kr_em_risco(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);

        $cycleId = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'P'], $h)->json('data.id');
        $areaId = $this->postJson($this->apiUrl('/okr/areas'), ['name' => 'Comercial'], $h)->json('data.id');
        $objId = $this->postJson($this->apiUrl('/okr/objectives'), ['okr_cycle_id' => $cycleId, 'level' => 'area', 'okr_area_id' => $areaId, 'title' => 'Obj'], $h)->json('data.id');
        $krId = $this->postJson($this->apiUrl('/okr/key-results'), ['okr_objective_id' => $objId, 'name' => 'KR', 'direction' => 'aumentar', 'aggregation' => 'acumulativo', 'baseline_value' => 0, 'target_value' => 36], $h)->json('data.id');
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), ['mode' => 'auto'], $h);
        foreach (['2027-01' => 2, '2027-02' => 2, '2027-03' => 2, '2027-04' => 2, '2027-05' => 1, '2027-06' => 1] as $ym => $val) {
            $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => $ym, 'actual_value' => $val], $h);
        }

        $r = $this->getJson($this->apiUrl("/okr/dashboard?cycle_id={$cycleId}"), $h);
        $r->assertStatus(200);
        $this->assertEquals(1, $r->json('data.totals.key_results'));
        $this->assertEquals(1, $r->json('data.totals.krs_at_risk'));
        $this->assertSame('Comercial', $r->json('data.by_area.0.area_name'));
        $this->assertSame('vermelho', $r->json('data.by_area.0.status'));
    }

    /** @test */
    public function fechamento_bloqueia_edicao_e_reabertura_libera(): void
    {
        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);
        $krId = $this->seedKr($h);
        $cycleId = $this->getJson($this->apiUrl('/okr/cycles'), $h)->json('data.0.id');

        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => '2027-01', 'actual_value' => 2], $h)->assertStatus(200);

        // Fecha o mês (global).
        $this->postJson($this->apiUrl('/okr/closures/close'), ['cycle_id' => $cycleId, 'year_month' => '2027-01'], $h)->assertStatus(201);
        // Edição bloqueada.
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => '2027-01', 'actual_value' => 9], $h)->assertStatus(422);
        // Reabre com justificativa.
        $this->postJson($this->apiUrl('/okr/closures/reopen'), ['cycle_id' => $cycleId, 'year_month' => '2027-01', 'reason' => 'correção'], $h)->assertStatus(200);
        // Agora edita.
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/results"), ['year_month' => '2027-01', 'actual_value' => 9], $h)->assertStatus(200);

        $this->assertDatabaseHas('okr_period_closures', ['okr_cycle_id' => $cycleId, 'year_month' => '2027-01', 'status' => 'aberto', 'reopen_reason' => 'correção', 'reopened_by' => $admin->id]);
    }

    /** @test */
    public function automacao_lista_catalogo_de_fontes(): void
    {
        $admin = $this->userOfType('admin');
        $r = $this->getJson($this->apiUrl('/okr/automation/sources'), $this->authHeaders($admin));
        $r->assertStatus(200);
        $this->assertNotEmpty($r->json('data'));
        $this->assertContains('consultoria.horas_apontadas', array_column($r->json('data'), 'key'));
    }

    /** @test */
    public function sync_automatico_preenche_realizado_e_respeita_mes_fechado(): void
    {
        OkrAutomationService::fake(['test.fixed' => fn (string $ym) => ['ok' => true, 'value' => 7.0, 'error' => null]]);

        $admin = $this->userOfType('admin');
        $h = $this->authHeaders($admin);
        $cycleId = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'P'], $h)->json('data.id');
        $objId = $this->postJson($this->apiUrl('/okr/objectives'), ['okr_cycle_id' => $cycleId, 'level' => 'corporativo', 'title' => 'O'], $h)->json('data.id');
        $krId = $this->postJson($this->apiUrl('/okr/key-results'), [
            'okr_objective_id' => $objId, 'name' => 'KR', 'target_value' => 36,
            'data_source_type' => 'automatico', 'data_source_key' => 'test.fixed',
        ], $h)->json('data.id');
        $this->putJson($this->apiUrl("/okr/key-results/{$krId}/targets"), ['mode' => 'auto'], $h)->assertStatus(200);

        // Fecha janeiro → deve ser pulado pela sincronização.
        $this->postJson($this->apiUrl('/okr/closures/close'), ['cycle_id' => $cycleId, 'year_month' => '2027-01'], $h)->assertStatus(201);

        $r = $this->postJson($this->apiUrl('/okr/automation/sync'), ['cycle_id' => $cycleId], $h);
        $r->assertStatus(200);
        $this->assertSame(11, $r->json('data.synced_count')); // 12 meses - janeiro fechado
        $this->assertDatabaseHas('okr_monthly_results', ['okr_key_result_id' => $krId, 'year_month' => '2027-02', 'source' => 'automatico']);
        $this->assertDatabaseMissing('okr_monthly_results', ['okr_key_result_id' => $krId, 'year_month' => '2027-01']);
    }

    /** @test */
    public function consultor_nao_pode_gerenciar_okr(): void
    {
        $consultor = $this->userOfType('consultor'); // tem okr.view, não okr.manage
        $r = $this->postJson($this->apiUrl('/okr/cycles'), ['year' => 2027, 'name' => 'X'], $this->authHeaders($consultor));
        $r->assertStatus(403);
    }

    /** @test */
    public function perfil_sem_okr_view_e_bloqueado(): void
    {
        $cliente = $this->userOfType('cliente'); // sem okr.view
        $r = $this->getJson($this->apiUrl('/okr/cycles'), $this->authHeaders($cliente));
        $r->assertStatus(403);
    }
}
