<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Etapa que EXIGE o relatório de qualificação ao entrar (qualidade + aceite executivos + estrelas). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $t) {
            if (!Schema::hasColumn('crm_pipeline_stages', 'requer_qualificacao')) {
                $t->boolean('requer_qualificacao')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_pipeline_stages', function (Blueprint $t) {
            if (Schema::hasColumn('crm_pipeline_stages', 'requer_qualificacao')) $t->dropColumn('requer_qualificacao');
        });
    }
};
