<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra a ação "Definir gestor do cliente" (set_manager) na tela /users, para
 * que ela apareça na CFG de menus e possa ser liberada/bloqueada por perfil/usuário.
 * A capacidade em si é de admin + coordenador; o bloqueio aqui só RESTRINGE.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('screen_actions')) {
            return;
        }
        $exists = DB::table('screen_actions')
            ->where('screen_key', '/users')->where('action_key', 'set_manager')->exists();
        if ($exists) {
            return;
        }
        $max = (int) DB::table('screen_actions')->where('screen_key', '/users')->max('sort_order');
        DB::table('screen_actions')->insert([
            'screen_key'  => '/users',
            'action_key'  => 'set_manager',
            'label'       => 'Definir gestor do cliente',
            'description' => 'Marca/desmarca o gestor do cliente (admin e coordenador).',
            'sort_order'  => $max + 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('screen_actions')) {
            DB::table('screen_actions')->where('screen_key', '/users')->where('action_key', 'set_manager')->delete();
        }
    }
};
