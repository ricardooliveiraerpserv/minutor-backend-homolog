<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag de "recebe alerta de consumo" nos contatos FIXOS do cliente (customer_contacts).
 * Esses valem para TODOS os contratos/projetos do cliente, somados aos contatos por-contrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customer_contacts', 'recebe_alerta_consumo')) {
            Schema::table('customer_contacts', function (Blueprint $table) {
                $table->boolean('recebe_alerta_consumo')->default(false)->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_contacts', 'recebe_alerta_consumo')) {
            Schema::table('customer_contacts', function (Blueprint $table) {
                $table->dropColumn('recebe_alerta_consumo');
            });
        }
    }
};
