<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_diretoria')) {
            return;
        }

        // Cabeçalho do fechamento de UM diretor numa competência (status + auditoria).
        // As linhas ficam em fechamento_diretoria_itens (por user_id + year_month).
        Schema::create('fechamento_diretoria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('year_month', 7);
            $table->string('status', 12)->default('aberto'); // aberto | finalizado
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_diretoria');
    }
};
