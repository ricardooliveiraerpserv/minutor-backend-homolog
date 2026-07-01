<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Item 5 — renovação automática. `renewal_contract_id` = contrato que ESTA
 * oportunidade vai renovar (distinto de `contract_id`, que é o contrato GERADO pela
 * conversão). Índice sustenta a proteção contra renovação duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('crm_opportunities', 'renewal_contract_id')) {
            Schema::table('crm_opportunities', function (Blueprint $table) {
                $table->foreignId('renewal_contract_id')->nullable()->after('contract_id')
                    ->constrained('contracts')->nullOnDelete();
                $table->index(['renewal_contract_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_opportunities', 'renewal_contract_id')) {
            Schema::table('crm_opportunities', function (Blueprint $table) {
                $table->dropConstrainedForeignId('renewal_contract_id');
            });
        }
    }
};
