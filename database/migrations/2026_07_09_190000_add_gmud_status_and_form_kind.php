<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Status "Solução com GMUD" (resolução com Gestão de Mudanças) + coluna form_kind nas
 * interações para distinguir o tipo de formulário estruturado ('solution' | 'gmud').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('helpdesk_ticket_comments') && !Schema::hasColumn('helpdesk_ticket_comments', 'form_kind')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->string('form_kind', 20)->nullable()->after('solution');
            });
        }

        if (Schema::hasTable('helpdesk_statuses') && !DB::table('helpdesk_statuses')->where('key', 'solucao_gmud')->exists()) {
            DB::table('helpdesk_statuses')->insert([
                'key' => 'solucao_gmud', 'label' => 'Solução com GMUD', 'color' => '#7c3aed',
                'sort_order' => 45, 'is_default' => false, 'is_open' => false, 'is_resolved' => true,
                'is_terminal' => false, 'sla_paused' => false, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('helpdesk_statuses')->where('key', 'solucao_gmud')->delete();
        if (Schema::hasColumn('helpdesk_ticket_comments', 'form_kind')) {
            Schema::table('helpdesk_ticket_comments', function (Blueprint $table) {
                $table->dropColumn('form_kind');
            });
        }
    }
};
