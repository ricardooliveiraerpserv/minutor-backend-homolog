<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_hour_bank_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7)->comment('YYYY-MM');

            // Parâmetros do cálculo
            $table->decimal('daily_hours', 5, 2)->default(8.00)->comment('Horas/dia útil (parametrizável)');
            $table->unsignedInteger('working_days')->comment('Dias úteis do mês (excluindo FDS e feriados)');
            $table->unsignedInteger('holidays_count')->default(0)->comment('Feriados em dias úteis');

            // Valores calculados (snapshot imutável após fechamento)
            $table->decimal('expected_hours', 8, 2)->comment('HP = dias_uteis * daily_hours');
            $table->decimal('worked_hours', 8, 2)->comment('HT = soma dos apontamentos do mês');
            $table->decimal('month_balance', 8, 2)->comment('SM = HT - HP');
            $table->decimal('previous_balance', 8, 2)->default(0)->comment('SA = SaldoFinal do mês anterior');
            $table->decimal('accumulated_balance', 8, 2)->comment('SA + SM');
            $table->decimal('paid_hours', 8, 2)->default(0)->comment('Horas pagas (apenas se acumulado > 0)');
            $table->decimal('final_balance', 8, 2)->comment('0 se houve pagamento, acumulado se negativo');

            // Status e auditoria
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_hour_bank_closings');
    }
};
