<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — perfil empresarial 1:1 da empresa (customers). Mantém o core `customers` enxuto;
 * campos comerciais/firmográficos ficam aqui. customer_id é PK (1 perfil por empresa).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_crm_profiles')) {
            return;
        }
        Schema::create('customer_crm_profiles', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('region', 100)->nullable();
            $table->string('segment', 100)->nullable();
            $table->string('porte', 30)->nullable();              // MEI/Pequeno/Médio/Grande
            $table->decimal('faturamento_estimado', 16, 2)->nullable();
            $table->integer('num_funcionarios')->nullable();
            $table->string('erp_atual', 120)->nullable();
            $table->string('indicacao', 160)->nullable();          // origem/indicação
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_crm_profiles');
    }
};
