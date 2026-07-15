<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de reajustes das inclusões MANUAIS (espelha contract_value_changes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('manual_reajuste_value_changes')) {
            return;
        }

        Schema::create('manual_reajuste_value_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_reajuste_id')->constrained('manual_reajustes')->cascadeOnDelete();
            $table->decimal('valor_anterior', 14, 2);
            $table->decimal('valor_novo', 14, 2);
            $table->decimal('percentual', 8, 4);
            $table->string('indice', 12)->nullable();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->string('periodo_formatado', 60)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('manual_reajuste_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_reajuste_value_changes');
    }
};
