<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança de HORAS EXCEDENTES (BH Mensal / BH Fixo).
 *
 * - projects.charge_excess_hours: flag "cobrar excedente" por contrato/projeto
 *   (default true — a regra de negócio é cobrar; o administrativo desliga quem não cobra).
 * - excess_hour_charges: apuração + status de cobrança por competência.
 *     BH Mensal: excess = consumo do mês − contratadas do mês (por competência).
 *     BH Fixo:   excess = estado atual (saldo negativo) − já cobrado (incremental).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'charge_excess_hours')) {
                $table->boolean('charge_excess_hours')->default(true)->after('additional_hourly_rate');
            }
        });

        if (!Schema::hasTable('excess_hour_charges')) {
            Schema::create('excess_hour_charges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->string('year_month', 7);                 // competência AAAA-MM
                $table->string('basis', 10);                     // 'monthly' | 'fixed'
                $table->decimal('contracted_hours', 12, 2)->default(0);
                $table->decimal('consumed_hours', 12, 2)->default(0);
                $table->decimal('excess_hours', 12, 2)->default(0);
                $table->decimal('additional_hourly_rate', 14, 2)->default(0);
                $table->decimal('excess_value', 14, 2)->default(0);
                $table->string('status', 20)->default('pendente'); // pendente | cobrado | nao_cobrar
                $table->text('notes')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['project_id', 'year_month']);
                $table->index(['year_month', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('excess_hour_charges');
        Schema::table('projects', function (Blueprint $table) {
            if (Schema::hasColumn('projects', 'charge_excess_hours')) {
                $table->dropColumn('charge_excess_hours');
            }
        });
    }
};
