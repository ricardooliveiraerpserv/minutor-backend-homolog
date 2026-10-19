<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite CADASTRAR a credencial da IA pela tela (Configurador → IA), guardando
 * a chave CRIPTOGRAFADA no banco (cast `encrypted` no model). Quando preenchida,
 * ela vira a fonte da verdade (sobrepõe o .env em runtime). Vazia = cai no .env.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_providers', 'api_key')) {
                $table->text('api_key')->nullable()->after('api_key_env')
                    ->comment('Chave de API CRIPTOGRAFADA (cast encrypted). Se preenchida, sobrepõe o .env.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            if (Schema::hasColumn('ai_providers', 'api_key')) {
                $table->dropColumn('api_key');
            }
        });
    }
};
