<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado da varredura de fonte da GMUD por chamado:
 *  atualizado | sem_fonte | sem_repo | pendente_permissao | erro | null (não é GMUD ainda).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_tickets', 'gmud_source_status')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->string('gmud_source_status', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_tickets', 'gmud_source_status')) {
            Schema::table('helpdesk_tickets', function (Blueprint $table) {
                $table->dropColumn('gmud_source_status');
            });
        }
    }
};
