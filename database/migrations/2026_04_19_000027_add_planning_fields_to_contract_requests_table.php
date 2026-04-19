<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->foreignId('linked_contract_id')->nullable()->constrained('contracts')->nullOnDelete()->after('contract_id');
            $table->foreignId('linked_coordinator_id')->nullable()->constrained('users')->nullOnDelete()->after('linked_contract_id');
            $table->string('req_decision')->nullable()->after('linked_coordinator_id'); // 'novo_contrato' | 'contrato_existente'
        });
    }

    public function down(): void
    {
        Schema::table('contract_requests', function (Blueprint $table) {
            $table->dropForeign(['linked_contract_id']);
            $table->dropForeign(['linked_coordinator_id']);
            $table->dropColumn(['linked_contract_id', 'linked_coordinator_id', 'req_decision']);
        });
    }
};
