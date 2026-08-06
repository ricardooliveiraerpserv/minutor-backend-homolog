<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Perfil comercial: CEP (busca automática de endereço via ViaCEP no front). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $t) {
            $t->string('cep', 9)->nullable()->after('indicacao');
        });
    }

    public function down(): void
    {
        Schema::table('customer_crm_profiles', function (Blueprint $t) {
            $t->dropColumn('cep');
        });
    }
};
