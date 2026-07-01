<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enriquecimento do catálogo screen_actions: além do CRUD genérico, cada tela declara as ações
 * ESPECÍFICAS que a rotina oferece (ex.: Usuários → resetar senha, reenviar boas-vindas).
 * Aditivo/idempotente — dá pra ir acrescentando telas conforme mapeamos os controllers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dicionário (label + descrição) das ações usadas abaixo.
        $L = [
            'view'                => ['Visualizar', 'Ver a tela e seus dados'],
            'create'              => ['Criar', 'Incluir novos registros'],
            'edit'                => ['Editar', 'Alterar registros existentes'],
            'delete'              => ['Excluir', 'Remover registros'],
            'reset_password'      => ['Resetar senha', 'Enviar redefinição de senha ao usuário'],
            'resend_welcome'      => ['Reenviar boas-vindas', 'Reenviar e-mail de boas-vindas / acesso'],
            'resend_welcome_bulk' => ['Reenviar boas-vindas (lote)', 'Reenviar boas-vindas para vários usuários'],
            'bulk_contract_type'  => ['Alterar tipo (lote)', 'Alterar tipo de contrato em massa'],
            'bulk_delete'         => ['Excluir (lote)', 'Excluir vários registros de uma vez'],
            'manage_rate'         => ['Valor-hora', 'Ver/editar histórico de valor-hora'],
        ];

        // Ações específicas por tela (href). Ordem = ordem exibida.
        $specific = [
            '/users' => [
                'view', 'create', 'edit', 'delete',
                'reset_password', 'resend_welcome', 'resend_welcome_bulk',
                'bulk_contract_type', 'bulk_delete', 'manage_rate',
            ],
        ];

        $now = now();
        foreach ($specific as $screen => $actions) {
            $i = 0;
            foreach ($actions as $a) {
                if (!isset($L[$a])) continue;
                DB::table('screen_actions')->updateOrInsert(
                    ['screen_key' => $screen, 'action_key' => $a],
                    ['label' => $L[$a][0], 'description' => $L[$a][1], 'sort_order' => $i++, 'updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('screen_actions')->where('screen_key', '/users')
            ->whereIn('action_key', ['reset_password', 'resend_welcome', 'resend_welcome_bulk', 'bulk_contract_type', 'bulk_delete', 'manage_rate'])
            ->delete();
    }
};
