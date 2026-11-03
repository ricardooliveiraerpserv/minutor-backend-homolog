<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log de AUDITORIA das liberações de faturamento On Demand (append-only).
 * Cada liberação/estorno grava uma linha aqui (quem, quando, ação, motivo),
 * sem sobrescrever nem apagar — ao contrário de on_demand_billing_releases,
 * que guarda só o estado atual (project_id, year_month). Serve de histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('on_demand_billing_release_logs')) {
            return;
        }
        Schema::create('on_demand_billing_release_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->string('action', 16);     // 'release' | 'revoke'
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_demand_billing_release_logs');
    }
};
