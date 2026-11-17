<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Emissor de NFS-e por empresa: simulado (testes) | nacional (API SEFIN Nacional). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_company_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('fin_company_settings', 'nfse_emissor')) {
                $t->string('nfse_emissor', 20)->default('simulado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fin_company_settings', function (Blueprint $t) {
            if (Schema::hasColumn('fin_company_settings', 'nfse_emissor')) $t->dropColumn('nfse_emissor');
        });
    }
};
