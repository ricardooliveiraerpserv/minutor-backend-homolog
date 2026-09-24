<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formulário TRAVADO: campos existentes não podem ser alterados/removidos — só é possível
 * ADICIONAR novos campos (syncFields entra em modo append-only). Protege regras configuradas
 * (rule/min_chars/require_attachment) de serem apagadas ao editar no construtor.
 * Trava os formulários de GMUD (status solucao_gmud), que têm regras sensíveis.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('helpdesk_forms', 'locked')) {
            Schema::table('helpdesk_forms', function (Blueprint $table) {
                $table->boolean('locked')->default(false)->after('active');
            });
        }
        DB::table('helpdesk_forms')
            ->whereIn('status_id', DB::table('helpdesk_statuses')->where('key', 'solucao_gmud')->pluck('id'))
            ->update(['locked' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('helpdesk_forms', 'locked')) {
            Schema::table('helpdesk_forms', function (Blueprint $table) {
                $table->dropColumn('locked');
            });
        }
    }
};
