<?php

namespace Tests\Feature;

use App\Http\Controllers\FechamentoConsultorController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Consultor PJ com horas na ERPSERV e na BIZIFY: o Meu Painel mostra o recebimento somado
 * (as duas empresas) e a conferência do valor da NFS-e tem de usar a MESMA soma — antes
 * pegava só a linha da ERPSERV e devolvia 422 com o valor certo.
 */
class FechamentoNotaRecebimentoBizifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_valor_da_nota_confere_com_recebimento_somado_erpserv_mais_bizify(): void
    {
        Storage::fake('public');
        $u = User::factory()->create(['type' => 'consultor', 'contract_type' => 'pj', 'partner_id' => null]);

        $ctrl = \Mockery::mock(FechamentoConsultorController::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $ctrl->shouldReceive('buildConsultoresData')->andReturn([
            'horistas' => [['user_id' => $u->id, 'recebimento' => 3000.00, 'total' => 3000.00]],
            'banco_horas' => [], 'fixos' => [],
            'bizify' => ['horistas' => [['user_id' => $u->id, 'recebimento' => 3961.45, 'total' => 3961.45]], 'banco_horas' => [], 'fixos' => []],
        ]);
        $this->app->instance(FechamentoConsultorController::class, $ctrl);

        $ym = now('America/Sao_Paulo')->format('Y-m');
        $this->actingAs($u)->getJson("/api/v1/my-closing/{$ym}")
            ->assertOk()->assertJsonPath('data.recebimento', 6961.45);

        $this->actingAs($u)->post("/api/v1/fechamento/notas/consultor/{$u->id}/{$ym}", [
            'tipo' => 'nfse', 'valor' => '6961.45',
            'file' => UploadedFile::fake()->create('nf.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('ok', true);

        // E um valor diferente da soma continua recusado.
        $this->actingAs($u)->post("/api/v1/fechamento/notas/consultor/{$u->id}/{$ym}", [
            'tipo' => 'nfse', 'valor' => '3000.00',
            'file' => UploadedFile::fake()->create('nf.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
