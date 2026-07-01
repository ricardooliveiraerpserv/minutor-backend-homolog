<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquecimento do card de CRM (espelhando os campos do RD Station): qualificação + um mapa JSON `detalhes`
 * com os campos comerciais da negociação (indicação, arquiteto, categoria, tipo de alocação, expectativa de
 * início, observações, próximos passos, status/data do contrato, condição de pagamento, etc.).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            if (!Schema::hasColumn('crm_opportunities', 'qualificacao')) $table->string('qualificacao', 20)->nullable(); // frio | morno | quente
            if (!Schema::hasColumn('crm_opportunities', 'detalhes')) $table->json('detalhes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_opportunities', function (Blueprint $table) {
            foreach (['qualificacao', 'detalhes'] as $c) if (Schema::hasColumn('crm_opportunities', $c)) $table->dropColumn($c);
        });
    }
};
