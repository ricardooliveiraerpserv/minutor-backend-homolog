<?php

namespace Tests\Feature;

use App\Models\SkillHireCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\TestHelpers;

/**
 * Finalizar contratação por usuário NÃO-admin (administrativo) deve criar o usuário
 * com o perfil do card. Antes, a trava A1 do UserController@store descartava `type`
 * e o usuário nascia sem perfil (sumia da lista de usuários).
 */
class SkillHireCompletePerfilTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    /** @test */
    public function administrativo_finaliza_contratacao_e_usuario_nasce_com_perfil(): void
    {
        Mail::fake();
        $adm = User::factory()->create(['type' => 'administrativo', 'email' => 'adm-hire-' . uniqid() . '@test.local']);

        $card = SkillHireCard::create([
            'title' => 'Fulano Teste', 'bucket' => 'novo', 'modalidade' => 'pj', 'created_by' => $adm->id,
            'form' => ['perfil' => 'consultor', 'email' => 'fulano.teste@erpserv.com.br', 'consultant_type' => 'horista', 'valor' => '65', 'empresa' => 'erpserv'],
        ]);

        $r = $this->postJson($this->apiUrl("/competencias/contratacao/{$card->id}/complete"), [], $this->authHeaders($adm));
        $r->assertSuccessful();

        $u = User::where('email', 'fulano.teste@erpserv.com.br')->first();
        $this->assertNotNull($u, 'usuário não foi criado');
        $this->assertSame('consultor', $u->type);
    }
}
