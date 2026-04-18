<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('service_type_id')->nullable()->after('categoria');
            $table->unsignedBigInteger('contract_type_id')->nullable()->after('tipo_faturamento');
            $table->decimal('valor_projeto', 15, 2)->nullable()->after('horas_contratadas');
            $table->decimal('valor_hora', 10, 2)->nullable()->after('valor_projeto');
            $table->decimal('hora_adicional', 10, 2)->nullable()->after('valor_hora');
            $table->decimal('pct_horas_coordenador', 5, 2)->nullable()->after('hora_adicional');
            $table->integer('horas_consultor')->nullable()->after('pct_horas_coordenador');

            $table->foreign('service_type_id')->references('id')->on('service_types')->nullOnDelete();
            $table->foreign('contract_type_id')->references('id')->on('contract_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['service_type_id']);
            $table->dropForeign(['contract_type_id']);
            $table->dropColumn(['service_type_id', 'contract_type_id', 'valor_projeto', 'valor_hora', 'hora_adicional', 'pct_horas_coordenador', 'horas_consultor']);
        });
    }
};
