<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de vigência das HORAS GARANTIDAS / mês do consultor (piso mínimo de cobrança).
 * Mesmo padrão do user_hourly_rate_logs: cada linha é uma mudança com effective_from.
 * ⚠️ new_guaranteed_hours NULL = piso REMOVIDO (limpo) a partir da vigência — é um valor
 * real, não "sem alteração". A resolução (UserGuaranteedHoursLog::effectiveValueAt) trata null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_guaranteed_hours_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->decimal('old_guaranteed_hours', 8, 2)->nullable();
            $table->decimal('new_guaranteed_hours', 8, 2)->nullable();
            $table->text('reason')->nullable();
            $table->date('effective_from')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guaranteed_hours_logs');
    }
};
