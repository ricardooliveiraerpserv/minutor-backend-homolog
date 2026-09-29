<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Seed do playbook "Cobrar Retorno" — sugerido pelo diagnóstico de chamado parado aguardando cliente. */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('playbooks')->where('scope', 'help_desk')->where('name', 'Cobrar Retorno')->exists()) {
            return;
        }
        $aguard = DB::table('helpdesk_statuses')->where('key', 'aguardando_cliente')->value('id');
        DB::table('playbooks')->insert([
            'scope' => 'help_desk', 'name' => 'Cobrar Retorno', 'category' => 'SLA', 'color' => '#f59e0b', 'icon' => 'clock',
            'active' => true, 'sort_order' => 25,
            'actions' => json_encode([
                'reply' => 'Olá! Ainda aguardamos seu retorno para prosseguir com o atendimento. Poderia nos dar um retorno quando possível?',
                'status_id' => $aguard,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('playbooks')->where('scope', 'help_desk')->where('name', 'Cobrar Retorno')->delete();
    }
};
