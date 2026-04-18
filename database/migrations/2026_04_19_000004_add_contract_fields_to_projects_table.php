<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('id')->constrained('contracts')->nullOnDelete();
            $table->string('tipo_faturamento')->nullable()->after('contract_id');
            $table->string('tipo_alocacao')->nullable()->after('tipo_faturamento');
            $table->foreignId('architect_id')->nullable()->after('tipo_alocacao')->constrained('users')->nullOnDelete();
            $table->text('condicao_pagamento')->nullable()->after('architect_id');
            $table->longText('observacoes_contrato')->nullable()->after('condicao_pagamento');
            $table->boolean('cobra_despesa_cliente')->default(false)->after('observacoes_contrato');
            $table->json('permissoes_despesa')->nullable()->after('cobra_despesa_cliente');
            $table->foreignId('executivo_conta_id')->nullable()->after('permissoes_despesa')->constrained('users')->nullOnDelete();
            $table->foreignId('vendedor_id')->nullable()->after('executivo_conta_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn(['tipo_faturamento', 'tipo_alocacao']);
            $table->dropConstrainedForeignId('architect_id');
            $table->dropColumn(['condicao_pagamento', 'observacoes_contrato', 'cobra_despesa_cliente', 'permissoes_despesa']);
            $table->dropConstrainedForeignId('executivo_conta_id');
            $table->dropConstrainedForeignId('vendedor_id');
        });
    }
};
