<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Usuário do parceiro que representa a apuração consolidada dele na Folha da
     * Cooperativa (quem "sobe pra folha"). Escolha explícita no cadastro do parceiro;
     * sem ela a folha cai no fallback (is_executive → 1º usuário).
     */
    public function up(): void
    {
        if (Schema::hasColumn('partners', 'folha_user_id')) {
            return;
        }
        Schema::table('partners', function (Blueprint $table) {
            $table->foreignId('folha_user_id')->nullable()->after('contract_type')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('partners', 'folha_user_id')) {
            return;
        }
        Schema::table('partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folha_user_id');
        });
    }
};
