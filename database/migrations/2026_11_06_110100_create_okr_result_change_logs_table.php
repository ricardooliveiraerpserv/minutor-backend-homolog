<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico (append-only) de alterações do realizado mensal de um KR. Espelha o
 * padrão project_change_logs (valor antigo/novo, quem, quando). Preenchido por
 * OkrMonthlyResultObserver. Preserva o histórico de metas/realizações.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('okr_result_change_logs')) {
            Schema::create('okr_result_change_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('okr_key_result_id')->constrained('okr_key_results')->cascadeOnDelete();
                $t->string('year_month', 7);
                $t->string('field_name', 50);
                $t->text('old_value')->nullable();
                $t->text('new_value')->nullable();
                $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $t->text('reason')->nullable();
                $t->timestamp('created_at')->nullable();

                $t->index(['okr_key_result_id', 'year_month']);
                $t->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('okr_result_change_logs');
    }
};
