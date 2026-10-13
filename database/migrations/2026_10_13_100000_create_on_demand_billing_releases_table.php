<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberação de FATURAMENTO de um contrato On Demand (projeto pai) × competência (mês).
 * A existência da linha (project_id, year_month) = liberado para faturar.
 * O coordenador responsável (ou admin) marca a liberação; o envio do fechamento ao
 * cliente fica BLOQUEADO enquanto o On Demand com horas no mês não estiver liberado.
 * Espelha on_demand_invoiced_months.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('on_demand_billing_releases')) {
            return;
        }
        Schema::create('on_demand_billing_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('year_month', 7); // YYYY-MM
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_demand_billing_releases');
    }
};
