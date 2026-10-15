<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'cnpj')) {
                $table->string('cnpj', 18)->nullable()->after('cpf');
            }
            if (! Schema::hasColumn('users', 'tax_regime')) {
                // mei | simples_nacional | lucro_presumido | lucro_real | nao_aplica
                $table->string('tax_regime', 30)->nullable()->after('cnpj');
            }
            if (! Schema::hasColumn('users', 'tax_regime_checked_at')) {
                $table->timestamp('tax_regime_checked_at')->nullable()->after('tax_regime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['tax_regime_checked_at', 'tax_regime', 'cnpj'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
