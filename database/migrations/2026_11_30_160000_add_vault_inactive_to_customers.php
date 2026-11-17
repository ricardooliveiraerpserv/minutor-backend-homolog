<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cofre de Senhas: inativar o cliente SÓ NO COFRE (não mexe em customers.active do cadastro).
// Cliente inativo no cofre vai p/ a aba "Inativos" e para de gerar alertas (certificados/senhas).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (!Schema::hasColumn('customers', 'vault_inactive')) {
                $t->boolean('vault_inactive')->default(false);
                $t->timestamp('vault_inactive_at')->nullable();
                $t->unsignedBigInteger('vault_inactive_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['vault_inactive', 'vault_inactive_at', 'vault_inactive_by']);
        });
    }
};
