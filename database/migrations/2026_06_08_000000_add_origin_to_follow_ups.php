<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow Up como camada da execução: registra o nível de ORIGEM (company/contract/
 * project/stage/activity) + vínculo a contrato. As FKs tipadas (project/stage/delivery/
 * customer) seguem como vínculo; o store denormaliza stage/project pra filtro trivial.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            if (!Schema::hasColumn('follow_ups', 'origin_type')) {
                $table->string('origin_type', 16)->nullable()->after('delivery_id'); // company|contract|project|stage|activity
            }
            if (!Schema::hasColumn('follow_ups', 'contract_id')) {
                $table->foreignId('contract_id')->nullable()->after('customer_id')->constrained('contracts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            if (Schema::hasColumn('follow_ups', 'contract_id')) $table->dropConstrainedForeignId('contract_id');
            if (Schema::hasColumn('follow_ups', 'origin_type')) $table->dropColumn('origin_type');
        });
    }
};
