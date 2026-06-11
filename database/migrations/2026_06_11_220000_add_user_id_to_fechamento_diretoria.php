<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (!Schema::hasColumn('fechamento_diretoria_itens', 'user_id')) {
                // Diretor da linha — vinculado a um usuário.
                $table->foreignId('user_id')->nullable()->after('year_month')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_diretoria_itens', function (Blueprint $table) {
            if (Schema::hasColumn('fechamento_diretoria_itens', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
