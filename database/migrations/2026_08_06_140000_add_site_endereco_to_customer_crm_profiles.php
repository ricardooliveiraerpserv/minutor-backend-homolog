<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Perfil comercial da empresa: site/URL + endereço (exibidos na Ficha da Empresa). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $t) {
            $t->string('site', 200)->nullable()->after('indicacao');
            $t->string('endereco', 255)->nullable()->after('site');
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $t) {
            $t->dropColumn(['site', 'endereco']);
        });
    }
};
